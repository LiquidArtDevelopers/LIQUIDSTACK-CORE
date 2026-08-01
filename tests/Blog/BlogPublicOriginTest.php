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
            'https://www.example.test/es/noticias/post',
            $origin->absoluteUrl('/es/noticias/post')
        );
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
            self::assertSame(BlogPublicOrigin::ENV, $exception->configKey());
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
