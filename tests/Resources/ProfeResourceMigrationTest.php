<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProfeResourceMigrationTest extends TestCase
{
    private const RESOURCES = [
        'hero06',
        'hero07',
        'moduleH1Type03',
        'moduleH1Type04',
        'moduleH2Type02',
        'art20',
        'art21',
        'art22',
        'art23',
        'art24',
        'art25',
        'art26',
        'art27',
        'art28',
        'art29',
        'art30',
        'art31',
        'artAccordion02',
    ];

    private const ARTICLE_RESOURCES = [
        'art20',
        'art21',
        'art22',
        'art23',
        'art24',
        'art25',
        'art26',
        'art27',
        'art28',
        'art29',
        'art30',
        'art31',
        'artAccordion02',
    ];

    private Filesystem $filesystem;
    private string $fixtureRoot;
    private string $previousWorkingDirectory;
    private array $previousEnv;

    public static function setUpBeforeClass(): void
    {
        foreach (self::RESOURCES as $resource) {
            require_once self::coreRoot()
                . "/stubs/App/controllers/{$resource}.php";
        }
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-profe-resources-'
            . bin2hex(random_bytes(8));
        $this->previousWorkingDirectory = (string) getcwd();
        $this->previousEnv = $_ENV;

        foreach (self::RESOURCES as $resource) {
            $target = $this->fixtureRoot
                . "/App/templates/_{$resource}.html";

            $this->filesystem->mkdir(dirname($target));
            $this->filesystem->copy(
                self::coreRoot() . "/stubs/App/templates/_{$resource}.html",
                $target
            );
        }

        chdir($this->fixtureRoot);
        $_ENV['RAIZ'] = 'http://localhost:1309';
        $_ENV['LANG_DEFAULT'] = 'es';
        unset($_ENV['DEV_MODE']);
        $GLOBALS['lang'] = 'es';
    }

    protected function tearDown(): void
    {
        chdir($this->previousWorkingDirectory);
        $_ENV = $this->previousEnv;
        unset($GLOBALS['lang']);
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testEveryMigratedResourceProvidesItsStaticFiles(): void
    {
        foreach (self::RESOURCES as $resource) {
            self::assertFileExists(
                self::coreRoot() . "/stubs/App/controllers/{$resource}.php",
                "{$resource}: missing controller"
            );
            self::assertFileExists(
                self::coreRoot() . "/stubs/App/templates/_{$resource}.html",
                "{$resource}: missing template"
            );
            self::assertFileExists(
                self::coreRoot() . "/resources/scss/_{$resource}.scss",
                "{$resource}: missing SCSS"
            );
        }

        self::assertFileExists(
            self::coreRoot() . '/resources/js/_artAccordion02.js'
        );
    }

    public function testTemplatesKeepTheirSemanticRootContracts(): void
    {
        foreach (['hero06', 'hero07'] as $resource) {
            $template = $this->readTemplate($resource);

            self::assertMatchesRegularExpression('/^\s*<header\b/i', $template);
            self::assertMatchesRegularExpression('/<\/header>\s*$/i', $template);
        }

        foreach (['moduleH1Type03', 'moduleH1Type04'] as $resource) {
            $template = $this->readTemplate($resource);

            self::assertMatchesRegularExpression('/^\s*<div\b/i', $template);
            self::assertMatchesRegularExpression('/<\/div>\s*$/i', $template);
            self::assertDoesNotMatchRegularExpression(
                '/<\s*(?:header|section|article)\b/i',
                $template
            );
        }

        $headingTemplate = $this->readTemplate('moduleH2Type02');
        self::assertStringContainsString('<{header-tag}', $headingTemplate);
        self::assertStringContainsString('</{header-tag}>', $headingTemplate);

        foreach (self::ARTICLE_RESOURCES as $resource) {
            $template = $this->readTemplate($resource);

            self::assertMatchesRegularExpression('/^\s*<article\b/i', $template);
            self::assertMatchesRegularExpression('/<\/article>\s*$/i', $template);
            self::assertDoesNotMatchRegularExpression(
                '/<\s*(?:section|header)\b/i',
                $template,
                "{$resource}: article resources segregate content with divs"
            );
        }
    }

    public function testEveryControllerRendersWithoutUnresolvedPlaceholders(): void
    {
        foreach (self::RESOURCES as $resource) {
            $html = $this->renderResource($resource);

            self::assertDoesNotMatchRegularExpression(
                '/\{[A-Za-z][A-Za-z0-9_-]*\}/',
                $html,
                "{$resource}: unresolved template placeholder"
            );
        }
    }

    public function testStylesheetEntrypointsRegisterAllResources(): void
    {
        $templatesEntry = $this->readFile(
            self::coreRoot() . '/src/scss/templates.scss'
        );

        foreach (self::RESOURCES as $resource) {
            self::assertSame(
                1,
                substr_count(
                    $templatesEntry,
                    "@use './resources/{$resource}';"
                ),
                "{$resource}: templates.scss must register exactly one import"
            );
        }

        $globalEntry = $this->readFile(
            self::coreRoot() . '/src/scss/_global.scss'
        );

        foreach (['moduleH1Type03', 'moduleH1Type04'] as $resource) {
            self::assertSame(
                1,
                substr_count(
                    $globalEntry,
                    "@use '../resources/scss/{$resource}';"
                ),
                "{$resource}: the reusable H1 module must be globally available"
            );
        }
    }

    public function testH1ModulesCanBeSwappedBetweenHeroShells(): void
    {
        $module03 = $this->renderResource('moduleH1Type03', 0, [
            '{a-button-primary}' => '<a href="/matrix">Entrar</a>',
        ]);
        $module04 = $this->renderResource('moduleH1Type04');

        $hero06 = $this->renderResource('hero06', 0, [
            '{hero06-content}' => $module04,
        ]);
        $hero07 = $this->renderResource('hero07', 0, [
            '{hero07-content}' => $module03,
        ]);

        self::assertStringContainsString('class="moduleH1Type04 ', $hero06);
        self::assertStringContainsString('class="moduleH1Type03 ', $hero07);
        self::assertStringContainsString('<a href="/matrix">Entrar</a>', $hero07);
        self::assertStringNotContainsString('{hero06-content}', $hero06);
        self::assertStringNotContainsString('{hero07-content}', $hero07);
        self::assertSame(1, preg_match_all('/<h1\b/', $hero06));
        self::assertSame(1, preg_match_all('/<h1\b/', $hero07));
    }

    public function testHeadingEscalationAndVariableItemCounts(): void
    {
        foreach (['moduleH1Type03', 'moduleH1Type04'] as $resource) {
            $defaultHtml = $this->renderResource($resource);
            $scaledHtml = $this->renderResource($resource, 0, [
                'header_level' => 3,
            ]);

            self::assertMatchesRegularExpression(
                '/<h1\b[^>]*class="[^"]*\b' . $resource . '-title\b/',
                $defaultHtml
            );
            self::assertMatchesRegularExpression(
                '/<h3\b[^>]*class="[^"]*\b' . $resource . '-title\b/',
                $scaledHtml
            );
        }

        $heading = $this->renderResource('moduleH2Type02', 0, [
            'header_level' => 4,
        ]);
        self::assertMatchesRegularExpression('/^\s*<h4\b.*<\/h4>\s*$/s', $heading);

        $art24 = $this->renderResource('art24', 0, [
            'header_level' => 2,
            'items' => 4,
        ]);
        self::assertMatchesRegularExpression(
            '/<h2\b[^>]*class="[^"]*\bart24-title\b/',
            $art24
        );
        self::assertSame(
            4,
            preg_match_all(
                '/<h3\b[^>]*class="[^"]*\bart24-itemTitle\b/',
                $art24
            )
        );

        self::assertSame(
            9,
            substr_count(
                $this->renderResource('art27', 0, ['items' => 9]),
                'class="art27-card"'
            )
        );
        self::assertSame(
            3,
            substr_count(
                $this->renderResource('art28', 0, ['items' => 3]),
                'class="art28-row"'
            )
        );
        self::assertSame(
            4,
            substr_count(
                $this->renderResource('art31', 0, ['items' => 4]),
                'class="art31-row"'
            )
        );
    }

    public function testHeroShellsExposeTheInlineBackgroundContract(): void
    {
        $_ENV['DEV_MODE'] = 'true';

        foreach (['hero06', 'hero07'] as $resource) {
            $html = $this->renderResource($resource);
            $imageKey = "{$resource}_00_img";

            self::assertStringContainsString('data-inline-background', $html);
            self::assertStringContainsString(
                "data-inline-background-target=\".{$resource}-media\"",
                $html
            );
            self::assertStringContainsString(
                "data-inline-background-image-key=\"{$imageKey}\"",
                $html
            );
            self::assertStringContainsString("class=\"{$resource}-media\"", $html);
            self::assertStringContainsString("data-lang=\"{$imageKey}\"", $html);
        }
    }

    public function testAlternatingResourcesUseEvenChildCss(): void
    {
        foreach (['art28', 'art31'] as $resource) {
            $scss = $this->readFile(
                self::coreRoot() . "/resources/scss/_{$resource}.scss"
            );

            self::assertStringContainsString(':nth-child(even)', $scss);
        }
    }

    public function testOptionalAndInteractiveMarkupKeepsItsContracts(): void
    {
        $linkKey = 'art30_00_a_link';
        $hadLink = array_key_exists($linkKey, $GLOBALS);
        $previousLink = $GLOBALS[$linkKey] ?? null;

        try {
            $GLOBALS[$linkKey] = (object) [
                'href' => 'matrix',
                'title' => 'Entrar en Matrix',
            ];
            $linkedCard = $this->renderResource('art30', 0, [
                'items' => 1,
                'benefits' => 0,
            ]);

            self::assertStringContainsString(
                '<div class="art30-card art30-card--interactive"><a class="art30-cardLink"',
                $linkedCard
            );
            self::assertStringNotContainsString(
                '<a class="art30-card"',
                $linkedCard
            );
            self::assertStringContainsString(
                '<div class="art30-benefits"></div>',
                $linkedCard
            );

            $GLOBALS[$linkKey] = (object) [
                'href' => '',
                'title' => '',
            ];
            $plainCard = $this->renderResource('art30', 0, [
                'items' => 1,
                'benefits' => 0,
            ]);

            self::assertStringContainsString(
                '<div class="art30-card">',
                $plainCard
            );
            self::assertStringNotContainsString('art30-cardLink', $plainCard);
        } finally {
            if ($hadLink) {
                $GLOBALS[$linkKey] = $previousLink;
            } else {
                unset($GLOBALS[$linkKey]);
            }
        }

        $art30Scss = $this->readFile(
            self::coreRoot() . '/resources/scss/_art30.scss'
        );
        self::assertStringContainsString(
            '.art30-card--interactive p',
            $art30Scss
        );
        self::assertMatchesRegularExpression(
            '/prefers-reduced-motion:\s*reduce.*?\.art30-cardLink\s*>\s*img/s',
            $art30Scss
        );

        $imageKey = 'artAccordion02_00_img';
        $hadImage = array_key_exists($imageKey, $GLOBALS);
        $previousImage = $GLOBALS[$imageKey] ?? null;

        try {
            $GLOBALS[$imageKey] = (object) [
                'src' => '',
                'alt' => '',
                'title' => '',
            ];
            $accordion = $this->renderResource('artAccordion02');

            self::assertStringContainsString(
                '<div class="artAccordion02-media"></div>',
                $accordion
            );
        } finally {
            if ($hadImage) {
                $GLOBALS[$imageKey] = $previousImage;
            } else {
                unset($GLOBALS[$imageKey]);
            }
        }

        $art26WithoutCta = $this->renderResource('art26');
        self::assertStringNotContainsString('art26-cta', $art26WithoutCta);
        self::assertStringNotContainsString(
            'art26-content--has-cta',
            $art26WithoutCta
        );

        $art26WithCta = $this->renderResource('art26', 0, [
            '{button-primary}' => '<a href="/matrix">Entrar</a>',
        ]);
        self::assertStringContainsString(
            'art26-content art26-content--has-cta',
            $art26WithCta
        );
        self::assertStringContainsString(
            '<div class="art26-cta"><a href="/matrix">Entrar</a></div>',
            $art26WithCta
        );

        $art31Scss = $this->readFile(
            self::coreRoot() . '/resources/scss/_art31.scss'
        );
        self::assertStringContainsString('> h1,', $art31Scss);
    }

    public function testAccordionIsAccessibleAndRegisteredWithGsap(): void
    {
        $items = 4;
        $html = $this->renderResource('artAccordion02', 0, [
            'items' => $items,
        ]);

        self::assertSame(
            $items,
            substr_count($html, '<button class="artAccordion02-trigger"')
        );
        self::assertSame(
            $items,
            preg_match_all('/\saria-expanded="(?:true|false)"/', $html)
        );

        preg_match_all('/\saria-controls="([^"]+)"/', $html, $controls);
        self::assertCount($items, $controls[1]);

        foreach ($controls[1] as $panelId) {
            self::assertStringContainsString('id="' . $panelId . '"', $html);
        }

        self::assertSame($items, substr_count($html, 'role="region"'));
        self::assertStringNotContainsString(
            'role="region"',
            $this->renderResource('artAccordion02', 0, ['items' => 7])
        );

        $resourceScript = $this->readFile(
            self::coreRoot() . '/resources/js/_artAccordion02.js'
        );
        self::assertStringContainsString(
            "import gsap from 'gsap';",
            $resourceScript
        );
        self::assertStringContainsString(
            'prefers-reduced-motion: reduce',
            $resourceScript
        );
        self::assertStringContainsString('gsap.killTweensOf(panel)', $resourceScript);

        $entry = $this->readFile(self::coreRoot() . '/src/js/templates.js');
        self::assertStringContainsString(
            "import gsap from 'gsap';",
            $entry,
            'templates.js debe importar GSAP antes de usar delayedCall al redimensionar'
        );
        self::assertMatchesRegularExpression(
            '/import\s+initArtAccordion02\s+from\s+[\'"]\.\/resources\/_artAccordion02\.js[\'"]\s*;/',
            $entry
        );
        self::assertMatchesRegularExpression(
            '/\binitArtAccordion02\s*\(\s*\)/',
            $entry
        );
    }

    public function testLanguageCatalogsContainTheReusableResourceKeys(): void
    {
        foreach (['es', 'en', 'eu'] as $languageCode) {
            $catalog = json_decode(
                $this->readFile(
                    self::coreRoot()
                    . "/stubs/App/config/languages/templates/{$languageCode}.json"
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            foreach (self::RESOURCES as $resource) {
                self::assertNotEmpty(
                    array_filter(
                        array_keys($catalog),
                        static fn (string $key): bool => str_starts_with(
                            $key,
                            "{$resource}_"
                        )
                    ),
                    "{$languageCode}: missing language keys for {$resource}"
                );
            }

            foreach (['00', '01', '02'] as $index) {
                self::assertArrayHasKey(
                    "moduleList01_{$index}_marker_icon",
                    $catalog
                );
            }
        }
    }

    private function renderResource(
        string $resource,
        int $index = 0,
        array $params = []
    ): string {
        $function = "controller_{$resource}";

        self::assertTrue(
            function_exists($function),
            "Missing controller function {$function}"
        );

        $html = $function($index, $params);
        self::assertIsString($html);

        return $html;
    }

    private function readTemplate(string $resource): string
    {
        return $this->readFile(
            self::coreRoot() . "/stubs/App/templates/_{$resource}.html"
        );
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);

        self::assertIsString($content, "Unable to read {$path}");

        return $content;
    }

    private static function coreRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
