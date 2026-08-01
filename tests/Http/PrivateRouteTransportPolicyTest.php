<?php

declare(strict_types=1);

use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrivateRouteTransportPolicyTest extends TestCase
{
    private PrivateRouteTransportPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PrivateRouteTransportPolicy();
    }

    #[DataProvider('secureRequestProvider')]
    public function testServerAssertedHttpsIsAlwaysAccepted(
        array $server,
        array $environment
    ): void {
        self::assertTrue($this->policy->accepts(
            Request::fromInput($server),
            $environment
        ));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function secureRequestProvider(): iterable
    {
        yield 'HTTPS flag without project environment' => [
            self::server(['HTTPS' => 'on']),
            [],
        ];
        yield 'request scheme with invalid project origin' => [
            self::server([
                'REQUEST_SCHEME' => 'https',
                'HTTP_HOST' => "invalid\n",
                'REMOTE_ADDR' => '203.0.113.7',
            ]),
            ['RAIZ' => 'must-not-be-required'],
        ];
        yield 'server port 443 in production' => [
            self::server([
                'SERVER_PORT' => '443',
                'HTTP_HOST' => 'www.example.test',
                'REMOTE_ADDR' => '203.0.113.7',
            ]),
            [
                'RAIZ' => 'https://www.example.test',
                'DEV_MODE' => '0',
            ],
        ];
    }

    #[DataProvider('trustedDevelopmentRequestProvider')]
    public function testExactDevelopmentLoopbackRequestIsAccepted(
        string $origin,
        string $host,
        string $clientIp
    ): void {
        self::assertTrue($this->policy->accepts(
            Request::fromInput(self::server([
                'HTTP_HOST' => $host,
                'REMOTE_ADDR' => $clientIp,
            ])),
            [
                'RAIZ' => $origin,
                'DEV_MODE' => '1',
            ]
        ));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function trustedDevelopmentRequestProvider(): iterable
    {
        yield 'localhost over IPv4 peer' => [
            'http://localhost:1309',
            'localhost:1309',
            '127.0.0.1',
        ];
        yield 'localhost over IPv6 peer' => [
            'http://localhost:1309',
            'localhost:1309',
            '::1',
        ];
        yield 'localhost over expanded IPv6 peer' => [
            'http://localhost:1309',
            'localhost:1309',
            '0:0:0:0:0:0:0:1',
        ];
        yield 'localhost over IPv4-mapped IPv6 peer' => [
            'http://localhost:1309',
            'localhost:1309',
            '::ffff:127.0.0.1',
        ];
        yield 'IPv4 loopback origin' => [
            'http://127.0.0.1:1309',
            '127.0.0.1:1309',
            '127.0.0.1',
        ];
        yield 'IPv6 loopback origin' => [
            'http://[::1]:1309',
            '[::1]:1309',
            '::1',
        ];
        yield 'origin without explicit port' => [
            'http://localhost',
            'localhost',
            '127.0.0.1',
        ];
    }

    #[DataProvider('untrustedDevelopmentRequestProvider')]
    public function testHttpRequestFailsClosed(
        array $serverOverrides,
        array $environment,
        array $explicitHeaders = []
    ): void {
        self::assertFalse($this->policy->accepts(
            Request::fromInput(
                self::server($serverOverrides),
                [],
                [],
                [],
                $explicitHeaders
            ),
            $environment
        ));
    }

    /**
     * @return iterable<string, array{
     *     array<string, mixed>,
     *     array<string, mixed>,
     *     2?: array<string, mixed>
     * }>
     */
    public static function untrustedDevelopmentRequestProvider(): iterable
    {
        $validServer = [
            'HTTP_HOST' => 'localhost:1309',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $validEnvironment = [
            'RAIZ' => 'http://localhost:1309',
            'DEV_MODE' => '1',
        ];

        yield 'missing project environment' => [$validServer, []];
        yield 'missing origin' => [
            $validServer,
            ['DEV_MODE' => '1'],
        ];
        yield 'development mode disabled' => [
            $validServer,
            ['RAIZ' => 'http://localhost:1309', 'DEV_MODE' => '0'],
        ];
        yield 'noncanonical development mode value' => [
            $validServer,
            ['RAIZ' => 'http://localhost:1309', 'DEV_MODE' => 1],
        ];
        yield 'HTTPS origin does not authorize an HTTP request' => [
            $validServer,
            ['RAIZ' => 'https://localhost:1309', 'DEV_MODE' => '1'],
        ];
        yield 'non-loopback HTTP origin' => [
            $validServer,
            ['RAIZ' => 'http://example.test:1309', 'DEV_MODE' => '1'],
        ];

        yield 'Host is missing' => [
            ['REMOTE_ADDR' => '127.0.0.1'],
            $validEnvironment,
        ];
        yield 'Host uses a different name' => [
            [
                'HTTP_HOST' => '127.0.0.1:1309',
                'REMOTE_ADDR' => '127.0.0.1',
            ],
            $validEnvironment,
        ];
        yield 'Host is missing the configured port' => [
            [
                'HTTP_HOST' => 'localhost',
                'REMOTE_ADDR' => '127.0.0.1',
            ],
            $validEnvironment,
        ];
        yield 'Host has another port' => [
            [
                'HTTP_HOST' => 'localhost:1310',
                'REMOTE_ADDR' => '127.0.0.1',
            ],
            $validEnvironment,
        ];
        yield 'Host DNS spelling is not canonical lowercase' => [
            [
                'HTTP_HOST' => 'LOCALHOST:1309',
                'REMOTE_ADDR' => '127.0.0.1',
            ],
            $validEnvironment,
        ];
        yield 'Host has a trailing DNS dot' => [
            [
                'HTTP_HOST' => 'localhost.:1309',
                'REMOTE_ADDR' => '127.0.0.1',
            ],
            $validEnvironment,
        ];
        yield 'multiple Host values are rejected' => [
            [
                'REMOTE_ADDR' => '127.0.0.1',
            ],
            $validEnvironment,
            ['Host' => ['localhost:1309', 'localhost:1309']],
        ];
        yield 'comma-separated Host values are rejected' => [
            [
                'HTTP_HOST' => 'localhost:1309, localhost:1309',
                'REMOTE_ADDR' => '127.0.0.1',
            ],
            $validEnvironment,
        ];
        yield 'invalid Host header fails closed' => [
            [
                'HTTP_HOST' => "localhost:1309\ninvalid",
                'REMOTE_ADDR' => '127.0.0.1',
            ],
            $validEnvironment,
        ];

        yield 'client address is missing' => [
            ['HTTP_HOST' => 'localhost:1309'],
            $validEnvironment,
        ];
        yield 'client is remote' => [
            [
                'HTTP_HOST' => 'localhost:1309',
                'REMOTE_ADDR' => '203.0.113.7',
            ],
            $validEnvironment,
        ];
        yield 'other IPv4 loopback aliases are not accepted' => [
            [
                'HTTP_HOST' => 'localhost:1309',
                'REMOTE_ADDR' => '127.0.0.2',
            ],
            $validEnvironment,
        ];
        yield 'Forwarded cannot replace Host or REMOTE_ADDR' => [
            [
                'HTTP_HOST' => 'attacker.example:1309',
                'HTTP_FORWARDED' => 'for=127.0.0.1;host=localhost:1309;proto=https',
                'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
                'HTTP_X_FORWARDED_HOST' => 'localhost:1309',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'REMOTE_ADDR' => '203.0.113.7',
            ],
            $validEnvironment,
        ];
        yield 'X-Forwarded-Proto cannot manufacture HTTPS' => [
            [
                'HTTP_HOST' => 'www.example.test',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'REMOTE_ADDR' => '203.0.113.7',
            ],
            ['RAIZ' => 'https://www.example.test', 'DEV_MODE' => '0'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function server(array $overrides = []): array
    {
        return array_replace([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin',
        ], $overrides);
    }
}
