<?php

declare(strict_types=1);

use App\Core\Blog\PublicShell\BlogPublicShellDefaultSecurityPolicy;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityConfig;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityContext;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityPolicyInterface;
use App\Core\Blog\StructuredContent\Document\BlogSafeIframePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogPublicShellSecurityTest extends TestCase
{
    private string $fixtureRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-blog-public-shell-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testProductionContextIsFreshAndCoversPublicArticleNeeds(): void
    {
        $policy = new BlogPublicShellDefaultSecurityPolicy();
        $first = $policy->context();
        $second = $policy->context();

        self::assertNotSame($first->nonce(), $second->nonce());
        self::assertMatchesRegularExpression(
            '/\A[A-Za-z0-9_-]{32}\z/D',
            $first->nonce()
        );
        $headers = $first->headers();
        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame(
            'camera=(), microphone=(), geolocation=()',
            $headers['Permissions-Policy']
        );
        self::assertArrayNotHasKey('Set-Cookie', $headers);
        self::assertSame('[redacted]', $first->__debugInfo()['nonce']);
        self::assertArrayHasKey(
            'Content-Security-Policy',
            $first->__debugInfo()['security_headers']
        );

        $csp = $headers['Content-Security-Policy'];
        self::assertStringContainsString(
            "script-src 'nonce-{$first->nonce()}' 'strict-dynamic' 'self'",
            $csp
        );
        self::assertStringContainsString("connect-src 'self'", $csp);
        self::assertStringContainsString("img-src 'self' data: blob:", $csp);
        self::assertStringContainsString('upgrade-insecure-requests', $csp);
        self::assertStringNotContainsString('localhost:5173', $csp);
        foreach (BlogSafeIframePolicy::cspSources() as $source) {
            self::assertStringContainsString($source, $csp);
        }
    }

    public function testTypedLoopbackDevelopmentAddsViteAndWebSocket(): void
    {
        $context = BlogPublicShellDefaultSecurityPolicy::fromEnvironment([
            'DEV_MODE' => '1',
            'RAIZ' => 'http://localhost:1309',
        ])->context();
        $csp = $context->headers()['Content-Security-Policy'];

        self::assertStringContainsString('http://localhost:5173', $csp);
        self::assertStringContainsString('ws://localhost:5173', $csp);
        self::assertStringNotContainsString('upgrade-insecure-requests', $csp);
    }

    public function testTypedDevelopmentUsesTheIncrementedViteOrigin(): void
    {
        $context = BlogPublicShellDefaultSecurityPolicy::fromEnvironment([
            'DEV_MODE' => '1',
            'RAIZ' => 'http://localhost:1310',
            'LIQUIDSTACK_DEV_VITE_ORIGIN' => 'http://localhost:5174',
        ])->context();
        $csp = $context->headers()['Content-Security-Policy'];

        self::assertStringContainsString('http://localhost:5174', $csp);
        self::assertStringContainsString('ws://localhost:5174', $csp);
        self::assertStringNotContainsString('localhost:5173', $csp);
        self::assertStringNotContainsString('upgrade-insecure-requests', $csp);
    }

    public function testInvalidRuntimeViteOriginFailsClosed(): void
    {
        $context = BlogPublicShellDefaultSecurityPolicy::fromEnvironment([
            'DEV_MODE' => '1',
            'RAIZ' => 'http://localhost:1310',
            'LIQUIDSTACK_DEV_VITE_ORIGIN' => 'http://evil.example:5174',
        ])->context();
        $csp = $context->headers()['Content-Security-Policy'];

        self::assertStringNotContainsString('evil.example', $csp);
        self::assertStringNotContainsString('localhost:5173', $csp);
        self::assertStringContainsString('upgrade-insecure-requests', $csp);
    }

    #[DataProvider('nonDevelopmentEnvironmentProvider')]
    public function testUntrustedOrUnusableDevelopmentInputStaysProduction(
        array $environment,
        bool $environmentUsable
    ): void {
        $csp = BlogPublicShellDefaultSecurityPolicy::fromEnvironment(
            $environment,
            $environmentUsable
        )->context()->headers()['Content-Security-Policy'];

        self::assertStringNotContainsString('localhost:5173', $csp);
        self::assertStringContainsString('upgrade-insecure-requests', $csp);
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function nonDevelopmentEnvironmentProvider(): iterable
    {
        yield 'production flag' => [[
            'DEV_MODE' => '0',
            'RAIZ' => 'http://localhost:1309',
        ], true];
        yield 'remote host' => [[
            'DEV_MODE' => '1',
            'RAIZ' => 'http://example.test',
        ], true];
        yield 'https loopback' => [[
            'DEV_MODE' => '1',
            'RAIZ' => 'https://localhost:1309',
        ], true];
        yield 'unusable environment' => [[
            'DEV_MODE' => '1',
            'RAIZ' => 'http://localhost:1309',
        ], false];
    }

    public function testProjectSourcesExtendDevelopmentWithoutLosingVite(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

return [
    'security_sources' => [
        'script' => ['https://webda.eus'],
        'style' => ['https://webda.eus'],
        'image' => ['https://webda.eus'],
        'font' => ['https://fonts.example.test'],
        'connect' => ['https://webda.eus'],
        'frame' => ['https://player.example.test'],
    ],
];
PHP);
        $default = BlogPublicShellDefaultSecurityPolicy::fromEnvironment([
            'DEV_MODE' => '1',
            'RAIZ' => 'http://localhost:1309',
        ]);

        $config = BlogPublicShellSecurityConfig::fromProject(
            $this->fixtureRoot,
            $default
        );
        $csp = $config->securityPolicy()
            ->context()
            ->headers()['Content-Security-Policy'];

        self::assertTrue($config->isConfigured());
        self::assertStringContainsString('https://webda.eus', $csp);
        self::assertStringContainsString('https://fonts.example.test', $csp);
        self::assertStringContainsString('https://player.example.test', $csp);
        self::assertStringContainsString('http://localhost:5173', $csp);
        self::assertStringContainsString('ws://localhost:5173', $csp);
        self::assertStringNotContainsString('upgrade-insecure-requests', $csp);
    }

    public function testMissingConfigPreservesInjectedPolicy(): void
    {
        $policy = new BlogPublicShellSecurityPolicyFake();
        $config = BlogPublicShellSecurityConfig::fromProject(
            $this->fixtureRoot,
            $policy
        );

        self::assertFalse($config->isConfigured());
        self::assertSame($policy, $config->securityPolicy());
    }

    public function testProjectCanInjectTheSharedPolicyInterface(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

return [
    'security_policy' => new \BlogPublicShellSecurityPolicyFake(),
];
PHP);

        $config = BlogPublicShellSecurityConfig::fromProject(
            $this->fixtureRoot
        );

        self::assertTrue($config->isConfigured());
        self::assertInstanceOf(
            BlogPublicShellSecurityPolicyFake::class,
            $config->securityPolicy()
        );
    }

    #[DataProvider('invalidConfigProvider')]
    public function testInvalidProjectConfigFailsClosed(string $contents): void
    {
        $this->writeConfig($contents);

        $this->expectException(RuntimeException::class);
        BlogPublicShellSecurityConfig::fromProject($this->fixtureRoot);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidConfigProvider(): iterable
    {
        yield 'list' => ["<?php return ['invalid'];\n"];
        yield 'unknown key' => ["<?php return ['secret' => true];\n"];
        yield 'invalid policy' => [
            "<?php return ['security_policy' => new stdClass()];\n",
        ];
        yield 'policy and sources conflict' => [
            "<?php return ['security_policy' => "
                . "new \\BlogPublicShellSecurityPolicyFake(), "
                . "'security_sources' => []];\n",
        ];
        yield 'sources list' => [
            "<?php return ['security_sources' => ['invalid']];\n",
        ];
        yield 'unknown source family' => [
            "<?php return ['security_sources' => ['media' => []]];\n",
        ];
        yield 'source family is not list' => [
            "<?php return ['security_sources' => ['script' => 'x']];\n",
        ];
        yield 'remote HTTP' => [
            "<?php return ['security_sources' => ["
                . "'script' => ['http://evil.example']]];\n",
        ];
        yield 'production loopback HTTP' => [
            "<?php return ['security_sources' => ["
                . "'script' => ['http://localhost:5173']]];\n",
        ];
        yield 'production loopback HTTPS' => [
            "<?php return ['security_sources' => ["
                . "'script' => ['https://localhost:5173']]];\n",
        ];
        yield 'production loopback websocket' => [
            "<?php return ['security_sources' => ["
                . "'connect' => ['wss://localhost:5173']]];\n",
        ];
        yield 'credentials' => [
            "<?php return ['security_sources' => ["
                . "'script' => ['https://user:pass@example.test']]];\n",
        ];
        yield 'path' => [
            "<?php return ['security_sources' => ["
                . "'script' => ['https://example.test/path']]];\n",
        ];
        yield 'query' => [
            "<?php return ['security_sources' => ["
                . "'script' => ['https://example.test?x=1']]];\n",
        ];
        yield 'output' => ["<?php echo 'leak'; return [];\n"];
        yield 'throw' => ["<?php throw new Exception('secret');\n"];
    }

    public function testContextRejectsInvalidNonceAndHeaders(): void
    {
        foreach ([
            ['', [
                'Content-Security-Policy' => "script-src 'nonce-'",
            ]],
            ['abcdefghijklmnopqrstuvwx', []],
            ['abcdefghijklmnopqrstuvwx', [
                'X-Private-Header' => 'secret',
                'Content-Security-Policy' =>
                    "script-src 'nonce-abcdefghijklmnopqrstuvwx'",
            ]],
            ['abcdefghijklmnopqrstuvwx', [
                'Content-Security-Policy' =>
                    "script-src 'nonce-differentnoncevalue0000'",
            ]],
            ['abcdefghijklmnopqrstuvwx', [
                'Content-Security-Policy' =>
                    "script-src 'nonce-abcdefghijklmnopqrstuvwx'\r\nX-Evil: 1",
            ]],
        ] as [$nonce, $headers]) {
            try {
                new BlogPublicShellSecurityContext($nonce, $headers);
                self::fail('Invalid public-shell context must fail closed.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function writeConfig(string $contents): void
    {
        $path = $this->fixtureRoot . '/'
            . BlogPublicShellSecurityConfig::PROJECT_FILE;
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, $contents);
    }
}

final class BlogPublicShellSecurityPolicyFake implements
    BlogPublicShellSecurityPolicyInterface
{
    public const NONCE = 'sharedshellnoncevalue000001';

    public function context(): BlogPublicShellSecurityContext
    {
        return new BlogPublicShellSecurityContext(self::NONCE, [
            'Content-Security-Policy' =>
                "default-src 'self'; script-src 'nonce-"
                    . self::NONCE . "'",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
