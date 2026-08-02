<?php

declare(strict_types=1);

use App\Core\Routing\UrlContext;
use App\Core\Routing\UrlResolver;
use App\Core\Support\Paths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class UrlResolverTest extends TestCase
{
    private string $fixtureRoot;
    private string $previousProjectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . '/liquidstack-url-resolver-'
            . bin2hex(random_bytes(8));
        $this->previousProjectRoot = Paths::projectRoot();

        $this->filesystem->mkdir($this->fixtureRoot . '/App/config');
        $this->filesystem->dumpFile(
            $this->fixtureRoot . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en', 'fr', 'eu'];\n"
        );

        Paths::setProjectRoot($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        Paths::setProjectRoot($this->previousProjectRoot);
        $this->filesystem->remove($this->fixtureRoot);

        parent::tearDown();
    }

    public function testUsesStoredLanguageWhenCustomConsentIsExactlyTrue(): void
    {
        $context = $this->resolve(
            '/services',
            [
                'cookie_custom' => 'true',
                'cookie_custom_lang' => ' EU ',
            ],
            'fr-FR,fr;q=0.9'
        );

        self::assertSame('eu', $context->lang);
        self::assertNull($context->urlLang);
    }

    /**
     * @param array<string, mixed> $cookies
     */
    #[DataProvider('cookiesWithoutCustomConsentProvider')]
    public function testIgnoresStoredLanguageWithoutExactCustomConsent(
        array $cookies
    ): void {
        $context = $this->resolve(
            '/services',
            $cookies,
            'fr-FR,fr;q=0.9'
        );

        self::assertSame('fr', $context->lang);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function cookiesWithoutCustomConsentProvider(): iterable
    {
        yield 'stale language cookie without consent cookie' => [[
            'cookie_custom_lang' => 'eu',
        ]];
        yield 'custom cookies explicitly denied' => [[
            'cookie_custom' => 'false',
            'cookie_custom_lang' => 'eu',
        ]];
        yield 'empty custom consent' => [[
            'cookie_custom' => '',
            'cookie_custom_lang' => 'eu',
        ]];
        yield 'boolean true is not the consent cookie value' => [[
            'cookie_custom' => true,
            'cookie_custom_lang' => 'eu',
        ]];
        yield 'numeric truthy value is not consent' => [[
            'cookie_custom' => '1',
            'cookie_custom_lang' => 'eu',
        ]];
        yield 'case variant is not exact consent' => [[
            'cookie_custom' => 'TRUE',
            'cookie_custom_lang' => 'eu',
        ]];
        yield 'whitespace padded value is not exact consent' => [[
            'cookie_custom' => ' true ',
            'cookie_custom_lang' => 'eu',
        ]];
    }

    public function testStaleLanguageCookieFallsBackToDefaultWithoutBrowserMatch(): void
    {
        $context = $this->resolve(
            '/services',
            ['cookie_custom_lang' => 'eu'],
            'de-DE,de;q=0.9'
        );

        self::assertSame('es', $context->lang);
    }

    public function testInvalidAllowedLanguageCookieFallsBackToBrowserLanguage(): void
    {
        $context = $this->resolve(
            '/services',
            [
                'cookie_custom' => 'true',
                'cookie_custom_lang' => 'de',
            ],
            'fr-FR,fr;q=0.9'
        );

        self::assertSame('fr', $context->lang);
    }

    /**
     * @param array<string, mixed> $cookies
     */
    #[DataProvider('explicitPublicUrlCookiesProvider')]
    public function testExplicitPublicUrlLanguageAlwaysWins(
        array $cookies
    ): void {
        $context = $this->resolve(
            '/fr/services/',
            $cookies,
            'en-US,en;q=0.9',
            [
                'MULTILANG' => '1',
                'ES_SIMPLIFICADO' => '0',
            ]
        );

        self::assertSame('fr', $context->lang);
        self::assertSame('fr', $context->urlLang);
        self::assertSame('/fr/services', $context->url);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function explicitPublicUrlCookiesProvider(): iterable
    {
        yield 'allowed conflicting stored preference' => [[
            'cookie_custom' => 'true',
            'cookie_custom_lang' => 'eu',
        ]];
        yield 'denied conflicting stored preference' => [[
            'cookie_custom' => 'false',
            'cookie_custom_lang' => 'eu',
        ]];
        yield 'stale conflicting preference without consent' => [[
            'cookie_custom_lang' => 'eu',
        ]];
        yield 'no preference cookies' => [[]];
    }

    /**
     * @param array<string, mixed> $cookies
     * @param array<string, string> $env
     */
    private function resolve(
        string $requestUri,
        array $cookies,
        string $acceptLanguage,
        array $env = []
    ): UrlContext {
        return UrlResolver::resolve(
            [
                'REQUEST_URI' => $requestUri,
                'HTTP_ACCEPT_LANGUAGE' => $acceptLanguage,
            ],
            $cookies,
            $env + [
                'LANG_DEFAULT' => 'es',
                'MULTILANG' => '0',
                'ES_SIMPLIFICADO' => '1',
            ]
        );
    }
}
