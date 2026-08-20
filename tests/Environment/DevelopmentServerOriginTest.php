<?php

declare(strict_types=1);

use App\Core\Environment\DevelopmentServerOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevelopmentServerOriginTest extends TestCase
{
    public function testMissingRuntimeValuePreservesLegacyVitePort(): void
    {
        $origin = DevelopmentServerOrigin::viteFromEnvironment([]);

        self::assertSame(
            'http://localhost:5173',
            $origin->httpOrigin()
        );
        self::assertSame(
            'ws://localhost:5173',
            $origin->webSocketOrigin()
        );
    }

    #[DataProvider('validOriginProvider')]
    public function testAcceptsExactLoopbackOrigins(
        string $input,
        string $expectedHttp,
        string $expectedWebSocket
    ): void {
        $origin = DevelopmentServerOrigin::viteFromEnvironment([
            DevelopmentServerOrigin::VITE_ORIGIN_ENV => $input,
        ]);

        self::assertSame($expectedHttp, $origin->httpOrigin());
        self::assertSame($expectedWebSocket, $origin->webSocketOrigin());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function validOriginProvider(): iterable
    {
        yield 'incremented localhost' => [
            'http://localhost:5174',
            'http://localhost:5174',
            'ws://localhost:5174',
        ];
        yield 'ipv4 loopback' => [
            'http://127.0.0.1:6200',
            'http://127.0.0.1:6200',
            'ws://127.0.0.1:6200',
        ];
        yield 'ipv6 loopback' => [
            'http://[::1]:6201',
            'http://[::1]:6201',
            'ws://[::1]:6201',
        ];
    }

    #[DataProvider('invalidOriginProvider')]
    public function testRejectsNonCanonicalOrNonLoopbackOrigins(
        mixed $value
    ): void {
        $this->expectException(InvalidArgumentException::class);

        DevelopmentServerOrigin::viteFromEnvironment([
            DevelopmentServerOrigin::VITE_ORIGIN_ENV => $value,
        ]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidOriginProvider(): iterable
    {
        yield 'non string' => [5174];
        yield 'remote host' => ['http://example.test:5174'];
        yield 'https' => ['https://localhost:5174'];
        yield 'missing port' => ['http://localhost'];
        yield 'root path' => ['http://localhost:5174/'];
        yield 'path' => ['http://localhost:5174/vite'];
        yield 'query' => ['http://localhost:5174?x=1'];
        yield 'fragment' => ['http://localhost:5174#x'];
        yield 'credentials' => ['http://user:pass@localhost:5174'];
        yield 'uppercase host' => ['http://LOCALHOST:5174'];
        yield 'whitespace' => [" http://localhost:5174"];
        yield 'control' => ["http://localhost:5174\n"];
        yield 'port zero' => ['http://localhost:0'];
    }

    public function testGlobalHelperUsesRuntimeOriginAndFailsSafe(): void
    {
        $name = DevelopmentServerOrigin::VITE_ORIGIN_ENV;
        $before = getenv($name);

        try {
            putenv($name . '=http://localhost:5199');
            self::assertSame(
                'http://localhost:5199',
                liquidstack_dev_vite_origin()
            );

            putenv($name . '=https://evil.example:5199');
            self::assertSame(
                DevelopmentServerOrigin::DEFAULT_VITE_ORIGIN,
                liquidstack_dev_vite_origin()
            );
        } finally {
            if ($before === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $before);
            }
        }
    }
}
