<?php

declare(strict_types=1);

use App\Core\Blog\Configuration\BlogConfigException;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use PHPUnit\Framework\TestCase;

final class BlogPublicOriginTest extends TestCase
{
    public function testCanonicalHttpsOriginBuildsUrlsWithoutRequestHeaders(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::ENV => 'https://www.example.test/',
            'HTTP_HOST' => 'attacker.invalid',
            'HTTP_FORWARDED' => 'host=attacker.invalid',
        ]);

        self::assertSame('https://www.example.test', $origin->value());
        self::assertSame(
            BlogPublicOrigin::SOURCE_LEGACY,
            $origin->source()
        );
        self::assertTrue($origin->usesLegacyOrigin());
        self::assertSame(
            'https://www.example.test/es/noticias/post',
            $origin->absoluteUrl('/es/noticias/post')
        );
    }

    public function testProjectRaizIsTheCanonicalProductionOrigin(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                'https://www.example.test',
            'DEV_MODE' => '0',
            'HTTP_HOST' => 'attacker.invalid',
            'HTTP_X_FORWARDED_HOST' => 'attacker.invalid',
        ]);

        self::assertSame('https://www.example.test', $origin->value());
        self::assertSame(
            BlogPublicOrigin::SOURCE_PROJECT,
            $origin->source()
        );
        self::assertFalse($origin->usesLegacyOrigin());
    }

    public function testProjectRaizAllowsOnlyTheTypedDevelopmentLoopbackOrigin(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                'http://localhost:1309',
            'DEV_MODE' => '1',
        ]);

        self::assertSame('http://localhost:1309', $origin->value());
        self::assertSame(
            'http://localhost:1309/es/noticias/post',
            $origin->absoluteUrl('/es/noticias/post')
        );
    }

    public function testEmptyLegacyPlaceholderDoesNotOverrideProjectRaiz(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                'https://example.test',
            BlogPublicOrigin::ENV => '',
        ]);

        self::assertSame('https://example.test', $origin->value());
    }

    public function testDevelopmentRaizOverridesTheProductionMailAlias(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                'http://localhost:1309',
            'DEV_MODE' => '1',
            BlogPublicOrigin::ENV => 'https://www.example.test',
        ]);

        self::assertSame('http://localhost:1309', $origin->value());
    }

    public function testDifferentValidOriginsPreserveTheLegacyCanonicalUrl(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                'https://canonical.example.test',
            BlogPublicOrigin::ENV =>
                'https://legacy.example.test',
        ]);

        self::assertSame('https://legacy.example.test', $origin->value());
        self::assertTrue($origin->usesLegacyCompatibilityOverride());
        self::assertTrue($origin->usesLegacyOrigin());
        self::assertSame(
            BlogPublicOrigin::SOURCE_LEGACY_COMPATIBILITY,
            $origin->source()
        );
    }

    public function testSemanticallyEquivalentProductionOriginsDoNotConflict(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                'https://example.test',
            BlogPublicOrigin::ENV =>
                'https://EXAMPLE.test:443/',
        ]);

        self::assertSame('https://example.test', $origin->value());
        self::assertFalse($origin->usesLegacyCompatibilityOverride());
        self::assertSame(
            BlogPublicOrigin::SOURCE_PROJECT,
            $origin->source()
        );
    }

    public function testLegacyOriginRemainsCompatibleWithANonCanonicalRaiz(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::PROJECT_ORIGIN_ENV =>
                'https://example.test/legacy-path',
            BlogPublicOrigin::ENV => 'https://example.test',
        ]);

        self::assertSame('https://example.test', $origin->value());
    }

    /** @dataProvider invalidOriginProvider */
    public function testInvalidOrMissingOriginsFailClosed(mixed $value): void
    {
        $environment = $value === null
            ? []
            : [BlogPublicOrigin::ENV => $value];

        try {
            BlogPublicOrigin::fromEnvironment($environment);
            self::fail('Invalid public origins must fail closed.');
        } catch (BlogConfigException $exception) {
            self::assertContains($exception->issueCode(), [
                'environment.public_origin_missing',
                'environment.public_origin_invalid',
            ]);
            self::assertSame(
                $value === null
                    ? BlogPublicOrigin::PROJECT_ORIGIN_ENV
                    : BlogPublicOrigin::ENV,
                $exception->configKey()
            );
            self::assertStringNotContainsString(
                'attacker',
                $exception->getMessage()
            );
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidOriginProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'non string' => [['https://example.test']];
        yield 'http' => ['http://example.test'];
        yield 'uppercase scheme' => ['HTTPS://example.test'];
        yield 'credentials' => ['https://user:pass@example.test'];
        yield 'path' => ['https://example.test/base'];
        yield 'query' => ['https://example.test?secret=1'];
        yield 'fragment' => ['https://example.test/#fragment'];
        yield 'space' => [' https://example.test'];
        yield 'invalid host' => ['https://bad_host.invalid'];
    }

    public function testInvalidOutputPathIsRejected(): void
    {
        $origin = BlogPublicOrigin::fromEnvironment([
            BlogPublicOrigin::ENV => 'https://example.test',
        ]);

        $this->expectException(BlogConfigException::class);
        $origin->absoluteUrl('//attacker.invalid/path');
    }
}
