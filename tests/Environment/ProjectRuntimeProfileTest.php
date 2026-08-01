<?php

declare(strict_types=1);

use App\Core\Environment\ProjectRuntimeProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectRuntimeProfileTest extends TestCase
{
    public function testCanonicalHttpsOriginIsAvailableWithoutDevelopmentMode(): void
    {
        $profile = ProjectRuntimeProfile::fromEnvironment([
            'RAIZ' => 'https://www.example.test:8443',
        ]);

        self::assertSame(
            'https://www.example.test:8443',
            $profile->origin()
        );
        self::assertSame('www.example.test:8443', $profile->authority());
        self::assertFalse($profile->isDevelopmentLoopbackHttp());
    }

    public function testTrailingRootSlashIsNormalizedForLegacyStacks(): void
    {
        $profile = ProjectRuntimeProfile::fromEnvironment([
            'RAIZ' => 'http://localhost:1309/',
            'DEV_MODE' => '1',
        ]);

        self::assertSame('http://localhost:1309', $profile->origin());
        self::assertSame('localhost:1309', $profile->authority());
        self::assertTrue($profile->isDevelopmentLoopbackHttp());
    }

    #[DataProvider('developmentModeProvider')]
    public function testHttpLoopbackOriginRequiresExplicitDevelopmentMode(
        mixed $developmentMode
    ): void {
        $profile = ProjectRuntimeProfile::fromEnvironment([
            'RAIZ' => 'http://localhost:1309',
            'DEV_MODE' => $developmentMode,
        ]);

        self::assertSame('http://localhost:1309', $profile->origin());
        self::assertSame('localhost:1309', $profile->authority());
        self::assertTrue($profile->isDevelopmentLoopbackHttp());
    }

    /** @return iterable<string, array{mixed}> */
    public static function developmentModeProvider(): iterable
    {
        yield 'numeric string' => ['1'];
        yield 'true string' => ['true'];
        yield 'uppercase true string' => ['TRUE'];
        yield 'on string' => ['on'];
        yield 'yes string' => ['yes'];
        yield 'boolean true' => [true];
    }

    #[DataProvider('loopbackOriginProvider')]
    public function testOnlyExactLoopbackHostsAreEligibleForDevelopmentHttp(
        string $origin,
        string $authority
    ): void {
        $profile = ProjectRuntimeProfile::fromEnvironment([
            'RAIZ' => $origin,
            'DEV_MODE' => '1',
        ]);

        self::assertSame($origin, $profile->origin());
        self::assertSame($authority, $profile->authority());
        self::assertTrue($profile->isDevelopmentLoopbackHttp());
    }

    /** @return iterable<string, array{string, string}> */
    public static function loopbackOriginProvider(): iterable
    {
        yield 'localhost' => [
            'http://localhost:1309',
            'localhost:1309',
        ];
        yield 'IPv4 loopback' => [
            'http://127.0.0.1:1309',
            '127.0.0.1:1309',
        ];
        yield 'IPv6 loopback' => [
            'http://[::1]:1309',
            '[::1]:1309',
        ];
    }

    #[DataProvider('disabledDevelopmentModeProvider')]
    public function testHttpOriginFailsClosedForOtherDevelopmentModeValues(
        mixed $developmentMode
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        $environment = ['RAIZ' => 'http://localhost:1309'];
        if ($developmentMode !== null) {
            $environment['DEV_MODE'] = $developmentMode;
        }

        ProjectRuntimeProfile::fromEnvironment($environment);
    }

    /** @return iterable<string, array{mixed}> */
    public static function disabledDevelopmentModeProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'boolean false' => [false];
        yield 'integer one is not an env string' => [1];
        yield 'zero string' => ['0'];
        yield 'false string' => ['false'];
        yield 'numeric alternative' => ['2'];
        yield 'surrounding whitespace' => [' true '];
    }

    #[DataProvider('invalidOriginProvider')]
    public function testInvalidOriginsFailWithoutLeakingTheirValue(mixed $origin): void
    {
        try {
            ProjectRuntimeProfile::fromEnvironment([
                'RAIZ' => $origin,
                'DEV_MODE' => '1',
            ]);
            self::fail('An invalid project origin must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'Project runtime profile contains an invalid origin.',
                $exception->getMessage()
            );
            self::assertStringNotContainsString(
                'must-not-leak',
                $exception->getMessage()
            );
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidOriginProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'non string' => [['https://example.test']];
        yield 'unsupported scheme' => ['ftp://localhost:1309'];
        yield 'uppercase scheme' => ['HTTPS://example.test'];
        yield 'uppercase host is noncanonical' => ['https://Example.test'];
        yield 'credentials' => ['https://must-not-leak@example.test'];
        yield 'password' => ['https://user:must-not-leak@example.test'];
        yield 'nested path' => ['https://example.test/path'];
        yield 'query' => ['https://example.test?must-not-leak=1'];
        yield 'fragment' => ['https://example.test#must-not-leak'];
        yield 'leading whitespace' => [' https://example.test'];
        yield 'control character' => ["https://example.test\n"];
        yield 'invalid host' => ['https://bad_host.invalid'];
        yield 'zero port' => ['https://example.test:0'];
        yield 'out of range port' => ['https://example.test:65536'];
        yield 'noncanonical port' => ['https://example.test:00443'];
        yield 'nonloopback HTTP' => ['http://example.test:1309'];
        yield 'loopback alias' => ['http://127.0.0.2:1309'];
        yield 'expanded IPv6 loopback' => [
            'http://[0:0:0:0:0:0:0:1]:1309',
        ];
    }

    public function testRequestLikeEnvironmentKeysCannotInfluenceTheProfile(): void
    {
        $profile = ProjectRuntimeProfile::fromEnvironment([
            'RAIZ' => 'https://www.example.test',
            'DEV_MODE' => '0',
            'HTTP_HOST' => 'must-not-leak.invalid',
            'HTTP_FORWARDED' => 'host=must-not-leak.invalid;proto=http',
            'HTTP_X_FORWARDED_HOST' => 'must-not-leak.invalid',
        ]);

        self::assertSame('https://www.example.test', $profile->origin());
        self::assertSame('www.example.test', $profile->authority());
        self::assertFalse($profile->isDevelopmentLoopbackHttp());
    }
}
