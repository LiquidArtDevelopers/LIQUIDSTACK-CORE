<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use function App\Core\Support\getMatchRouteByLang;
use function App\Core\Support\homePath;
use function App\Core\Support\resolve_localized_href;
use function App\Core\Support\schemaWebPageAccessibility;
use function App\Core\Support\shouldExcludeFromSitemap;

final class HelpersTest extends TestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
        unset($GLOBALS['lang']);
        unset($GLOBALS['arrayRutasGet']);
    }

    public function testHomePathUsesSimplifiedDefaultLanguage(): void
    {
        $_ENV['LANG_DEFAULT']    = 'es';
        $_ENV['ES_SIMPLIFICADO'] = '1';

        self::assertSame('/', homePath('es'));
        self::assertSame('/eu', homePath('eu'));
    }

    public function testResolveLocalizedHrefBuildsAbsolutePathWithLanguage(): void
    {
        $_ENV['RAIZ'] = 'https://example.test';
        $GLOBALS['lang'] = 'eu';

        $href = resolve_localized_href('contacto');

        self::assertSame('https://example.test/eu/contacto', $href);
    }

    public function testResolveLocalizedHrefRespectsAnchorsAndRelativeOutput(): void
    {
        $empty = resolve_localized_href('', [
            'absolute'      => false,
            'include_lang'  => false,
            'leading_slash' => false,
        ]);

        self::assertSame('', $empty);
        self::assertSame('#hero', resolve_localized_href('#hero'));
    }

    public function testSitemapCanExplicitlyExcludePrivateProjectRoutes(): void
    {
        self::assertTrue(shouldExcludeFromSitemap(
            '/es/area-privada',
            [
                'content' => 'area-privada',
                'sitemap' => false,
            ]
        ));
        self::assertFalse(shouldExcludeFromSitemap(
            '/es/blog',
            [
                'content' => 'blog',
                'sitemap' => true,
            ]
        ));
    }

    public function testWebPageSchemaDoesNotClaimUnauditedAccessibility(): void
    {
        $_ENV['RAIZ'] = 'https://example.test';
        $GLOBALS['arrayRutasGet'] = [
            'es' => ['/es/noticias' => []],
            'en' => ['/en/news' => []],
        ];

        $html = schemaWebPageAccessibility(
            'en',
            '/en/news',
            'News & insights',
            'Independent description.'
        );
        self::assertSame(1, preg_match(
            '~<script type="application/ld\+json">\s*(.*?)\s*</script>~s',
            $html,
            $matches
        ));
        $schema = json_decode(
            $matches[1],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('WebPage', $schema['@type']);
        self::assertSame('en', $schema['inLanguage']);
        self::assertSame('News & insights', $schema['name']);
        self::assertCount(2, $schema['hasPart']);
        foreach ([
            'accessibilityAPI',
            'accessibilityControl',
            'accessibilityFeature',
            'accessibilityHazard',
            'accessibilitySummary',
        ] as $unsupportedClaim) {
            self::assertArrayNotHasKey($unsupportedClaim, $schema);
        }
        self::assertStringNotContainsString('WCAG', $html);
    }

    public function testWebPageSchemaCannotCloseItsJsonLdScript(): void
    {
        $_ENV['RAIZ'] = 'https://example.test';
        $GLOBALS['arrayRutasGet'] = [
            'es' => ['/es/noticias' => []],
        ];
        $payload = '</script><script>alert("matrix")</script>&\'seo\'';

        $html = schemaWebPageAccessibility(
            'es',
            '/es/noticias',
            $payload,
            $payload
        );

        self::assertStringNotContainsString(
            '</script><script',
            $html
        );
        self::assertSame(1, substr_count($html, '</script>'));
        self::assertStringContainsString('\\u003C/script\\u003E', $html);
        self::assertSame(1, preg_match(
            '~<script type="application/ld\+json">\s*(.*?)\s*</script>~s',
            $html,
            $matches
        ));
        $schema = json_decode(
            $matches[1],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame($payload, $schema['name']);
        self::assertSame($payload, $schema['description']);
    }

    public function testWebPageSchemaSubstitutesInvalidUtf8InsteadOfFailing(): void
    {
        $_ENV['RAIZ'] = 'https://example.test';
        $GLOBALS['arrayRutasGet'] = [
            'es' => ['/es/noticias' => []],
        ];

        $html = schemaWebPageAccessibility(
            'es',
            '/es/noticias',
            "SEO\xB1",
            'Description'
        );

        self::assertSame(1, preg_match(
            '~<script type="application/ld\+json">\s*(.*?)\s*</script>~s',
            $html,
            $matches
        ));
        $schema = json_decode(
            $matches[1],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame("SEO\u{FFFD}", $schema['name']);
    }

    public function testWebPageSchemaSupportsAnOptionalValidatedCspNonce(): void
    {
        $_ENV['RAIZ'] = 'https://example.test';
        $GLOBALS['arrayRutasGet'] = [
            'es' => ['/es/noticias' => []],
        ];

        $html = schemaWebPageAccessibility(
            'es',
            '/es/noticias',
            'Noticias',
            'Actualidad',
            'valid+nonce/2026=='
        );

        self::assertStringStartsWith(
            '<script nonce="valid+nonce/2026==" '
                . 'type="application/ld+json">',
            $html
        );
    }

    public function testWebPageSchemaRejectsAnInvalidCspNonce(): void
    {
        $_ENV['RAIZ'] = 'https://example.test';
        $GLOBALS['arrayRutasGet'] = [
            'es' => ['/es/noticias' => []],
        ];

        $this->expectException(InvalidArgumentException::class);
        schemaWebPageAccessibility(
            'es',
            '/es/noticias',
            'Noticias',
            'Actualidad',
            '" onload="alert(1)'
        );
    }

    public function testShowroomCategoryHasServerRenderedLanguageLinks(): void
    {
        $filesystem = new Filesystem();
        $fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-showroom-route-'
            . bin2hex(random_bytes(8));
        $previousRoot = Paths::projectRoot();

        $routes = [
            'es' => [
                '/es/showroom' => [
                    'resources' => 'templates',
                    'content' => 'templates',
                    'view' => '../App/views/_showroom.php',
                ],
                '/es/templates' => [
                    'resources' => 'templates',
                    'content' => 'templates',
                    'view' => '../App/views/_templates.php',
                ],
                '/es/servicios' => [
                    'resources' => 'servicios',
                    'content' => 'servicios',
                    'view' => '../App/views/servicios.php',
                ],
            ],
            'en' => [
                '/en/showroom' => [
                    'resources' => 'templates',
                    'content' => 'templates',
                    'view' => '../App/views/_showroom.php',
                ],
                '/en/templates' => [
                    'resources' => 'templates',
                    'content' => 'templates',
                    'view' => '../App/views/_templates.php',
                ],
                '/en/services' => [
                    'resources' => 'servicios',
                    'content' => 'servicios',
                    'view' => '../App/views/servicios.php',
                ],
            ],
            'eu' => [
                '/eu/showroom' => [
                    'resources' => 'templates',
                    'content' => 'templates',
                    'view' => '../App/views/_showroom.php',
                ],
                '/eu/templates' => [
                    'resources' => 'templates',
                    'content' => 'templates',
                    'view' => '../App/views/_templates.php',
                ],
                '/eu/zerbitzuak' => [
                    'resources' => 'servicios',
                    'content' => 'servicios',
                    'view' => '../App/views/servicios.php',
                ],
            ],
        ];

        try {
            $filesystem->mkdir($fixtureRoot . '/App/config/routes');
            $filesystem->dumpFile(
                $fixtureRoot . '/App/config/routes/get.php',
                "<?php\nreturn " . var_export($routes, true) . ";\n"
            );
            Paths::setProjectRoot($fixtureRoot);

            self::assertSame(
                '/en/showroom/media',
                getMatchRouteByLang('/es/showroom/media', 'en')
            );
            self::assertSame(
                '/eu/templates/particles',
                getMatchRouteByLang('/en/templates/particles', 'eu')
            );
            self::assertSame(
                '/en/services',
                getMatchRouteByLang('/es/servicios', 'en')
            );
            self::assertNull(
                getMatchRouteByLang('/es/showroom/private', 'en')
            );
        } finally {
            Paths::setProjectRoot($previousRoot);
            $filesystem->remove($fixtureRoot);
        }
    }
}
