<?php

declare(strict_types=1);

use App\Core\Composer\Installer;
use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/src/Core/Composer/Installer.php';

final class InstallerResourceSyncTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;
    private BufferIO $io;

    protected function setUp(): void
    {
        $this->filesystem  = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-resource-sync-'
            . bin2hex(random_bytes(8));

        $this->filesystem->mkdir($this->projectRoot . DIRECTORY_SEPARATOR . 'vendor');
        $this->writeFile(
            $this->projectRoot . '/src/scss/_config.scss',
            (string) file_get_contents(
                dirname(__DIR__, 2) . '/src/scss/_config.scss'
            )
        );
        $this->writeFile(
            $this->projectRoot . '/App/views/project-only.php',
            '<?php echo "local";'
        );
        $this->writeFile(
            $this->projectRoot . '/App/config/routes/get.php',
            '<?php return ["project-route" => true];'
        );
        $this->writeFile(
            $this->projectRoot . '/App/config/routes/post.php',
            '<?php return ["/languages/update" => "updateLanguage.php", "project-post-route" => true];'
        );
        $this->writeFile(
            $this->projectRoot . '/App/config/rutas.js',
            'export default { projectRoute: true };'
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testComposerUpdatePromotesResourcesAndBothCatalogViews(): void
    {
        Installer::postUpdate($this->createEvent());

        $coreRoot = dirname(__DIR__, 2);

        foreach (['art02little', 'moduleList01', 'moduleParrafo01'] as $resource) {
            self::assertFileEquals(
                $coreRoot . "/stubs/App/controllers/{$resource}.php",
                $this->projectRoot . "/App/controllers/{$resource}.php"
            );
            self::assertFileEquals(
                $coreRoot . "/stubs/App/templates/_{$resource}.html",
                $this->projectRoot . "/App/templates/_{$resource}.html"
            );
            self::assertFileEquals(
                $coreRoot . "/resources/scss/_{$resource}.scss",
                $this->projectRoot . "/src/scss/resources/_{$resource}.scss"
            );
        }

        foreach ([
            'hero06',
            'hero07',
            'moduleH1Type03',
            'moduleH1Type04',
            'moduleH2Type02',
            'moduleButtonType02',
            'moduleButtonType03',
            'moduleButtonType04',
            'moduleTable01',
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
            'art32',
            'art33',
            'art34',
            'artAccordion02',
        ] as $resource) {
            self::assertFileEquals(
                $coreRoot . "/stubs/App/controllers/{$resource}.php",
                $this->projectRoot . "/App/controllers/{$resource}.php"
            );
            self::assertFileEquals(
                $coreRoot . "/stubs/App/templates/_{$resource}.html",
                $this->projectRoot . "/App/templates/_{$resource}.html"
            );
            self::assertFileEquals(
                $coreRoot . "/resources/scss/_{$resource}.scss",
                $this->projectRoot . "/src/scss/resources/_{$resource}.scss"
            );
        }

        self::assertFileEquals(
            $coreRoot . '/resources/js/_artAccordion02.js',
            $this->projectRoot . '/src/js/resources/_artAccordion02.js'
        );

        foreach ([
            'moduleFormContact01',
            'moduleFormContact02',
            'moduleFormContact03',
        ] as $resource) {
            self::assertFileEquals(
                $coreRoot . "/stubs/App/controllers/{$resource}.php",
                $this->projectRoot . "/App/controllers/{$resource}.php"
            );
            self::assertFileEquals(
                $coreRoot . "/stubs/App/templates/_{$resource}.html",
                $this->projectRoot . "/App/templates/_{$resource}.html"
            );
            self::assertFileEquals(
                $coreRoot . "/resources/scss/_{$resource}.scss",
                $this->projectRoot . "/src/scss/resources/_{$resource}.scss"
            );
        }

        self::assertFileEquals(
            $coreRoot . '/stubs/App/controllers/_moduleFormContact.php',
            $this->projectRoot . '/App/controllers/_moduleFormContact.php'
        );
        self::assertFileEquals(
            $coreRoot . '/stubs/App/templates/_moduleFormContact.html',
            $this->projectRoot . '/App/templates/_moduleFormContact.html'
        );
        self::assertFileEquals(
            $coreRoot . '/resources/scss/_moduleFormContact.scss',
            $this->projectRoot . '/src/scss/resources/_moduleFormContact.scss'
        );
        self::assertFileEquals(
            $coreRoot . '/resources/js/_moduleFormContact.js',
            $this->projectRoot . '/src/js/resources/_moduleFormContact.js'
        );

        self::assertFileEquals(
            $coreRoot . '/resources/scss/_art15.scss',
            $this->projectRoot . '/src/scss/resources/_art15.scss'
        );
        self::assertFileEquals(
            $coreRoot . '/resources/scss/_art16.scss',
            $this->projectRoot . '/src/scss/resources/_art16.scss'
        );

        foreach (['art16', 'hero00'] as $resource) {
            self::assertFileEquals(
                $coreRoot . "/stubs/App/controllers/{$resource}.php",
                $this->projectRoot . "/App/controllers/{$resource}.php"
            );
            self::assertFileEquals(
                $coreRoot . "/stubs/App/templates/_{$resource}.html",
                $this->projectRoot . "/App/templates/_{$resource}.html"
            );
        }

        foreach ([
            'art17',
            'art18',
            'artPricingGlass01',
            'sectionParallax01',
            'artHeroScroll01',
            'artZipper',
        ] as $resource) {
            self::assertFileEquals(
                $coreRoot . "/stubs/App/controllers/{$resource}.php",
                $this->projectRoot . "/App/controllers/{$resource}.php"
            );
        }
        self::assertFileEquals(
            $coreRoot . '/stubs/App/templates/_artZipper.html',
            $this->projectRoot . '/App/templates/_artZipper.html'
        );

        self::assertFileEquals(
            $coreRoot . '/resources/js/_inlineEditor.js',
            $this->projectRoot . '/src/js/resources/_inlineEditor.js'
        );
        self::assertFileEquals(
            $coreRoot . '/stubs/App/app/updateLanguage.php',
            $this->projectRoot . '/App/app/updateLanguage.php'
        );
        self::assertFileEquals(
            $coreRoot . '/stubs/App/tools/update-languages.php',
            $this->projectRoot . '/App/tools/update-languages.php'
        );
        self::assertFileEquals(
            $coreRoot . '/stubs/App/app/formContact.php',
            $this->projectRoot . '/App/app/formContact.php'
        );
        self::assertFileEquals(
            $coreRoot . '/stubs/App/app/_phpmailer.php',
            $this->projectRoot . '/App/app/_phpmailer.php'
        );
        self::assertFileEquals(
            $coreRoot . '/stubs/App/class/_comprobaciones.php',
            $this->projectRoot . '/App/class/_comprobaciones.php'
        );
        foreach (['es', 'en', 'eu'] as $language) {
            self::assertFileEquals(
                $coreRoot . "/stubs/App/config/languages/_email/{$language}.json",
                $this->projectRoot
                    . "/App/config/languages/_email/{$language}.json"
            );
        }
        self::assertStringContainsString(
            'CORE sync seguro:',
            $this->io->getOutput()
        );
        self::assertFileExists(
            $this->projectRoot
                . '/.liquidstack/core/managed-files.json'
        );

        foreach ([
            'check-OK.svg',
            'compass-outline.svg',
            'people.svg',
            'shield-checkmark-outline.svg',
            'arrow-forward-outline.svg',
            'book-outline.svg',
            'code-slash-outline.svg',
            'cube-outline.svg',
            'ribbon-outline.svg',
            'school-outline.svg',
            'settings-outline.svg',
            'sparkles-outline.svg',
            'speedometer-outline.svg',
            'star-outline.svg',
            'stats-chart-outline.svg',
            'time.svg',
        ] as $asset) {
            self::assertFileEquals(
                $coreRoot . "/resources/img/system/{$asset}",
                $this->projectRoot . "/public/assets/img/system/{$asset}"
            );
        }

        self::assertFileEquals(
            $coreRoot . '/resources/img/logos/logo-black.svg',
            $this->projectRoot . '/public/assets/img/logos/logo-black.svg'
        );

        self::assertFileEquals(
            $coreRoot . '/stubs/App/views/_showroom.php',
            $this->projectRoot . '/App/views/_showroom.php'
        );
        self::assertFileEquals(
            $coreRoot . '/stubs/App/views/_templates.php',
            $this->projectRoot . '/App/views/_templates.php'
        );
        self::assertFileExists($this->projectRoot . '/App/views/project-only.php');
        self::assertSame(
            '<?php return ["project-route" => true];',
            file_get_contents($this->projectRoot . '/App/config/routes/get.php')
        );
        self::assertSame(
            '<?php return ["/languages/update" => "updateLanguage.php", "project-post-route" => true];',
            file_get_contents($this->projectRoot . '/App/config/routes/post.php')
        );
        self::assertSame(
            'export default { projectRoute: true };',
            file_get_contents($this->projectRoot . '/App/config/rutas.js')
        );

        $showroom = file_get_contents($this->projectRoot . '/App/views/_showroom.php');
        $templates = file_get_contents($this->projectRoot . '/App/views/_templates.php');
        $entrypoint = file_get_contents($this->projectRoot . '/src/scss/templates.scss');
        $scriptEntrypoint = file_get_contents(
            $this->projectRoot . '/src/js/templates.js'
        );

        self::assertIsString($showroom);
        self::assertIsString($templates);
        self::assertIsString($entrypoint);
        self::assertIsString($scriptEntrypoint);
        self::assertStringContainsString("controller('art02little'", $showroom);
        self::assertStringContainsString("controller('moduleList01'", $showroom);
        self::assertStringContainsString("controller('moduleParrafo01'", $showroom);
        self::assertStringContainsString("controller('art33'", $showroom);
        self::assertStringContainsString("controller('art34'", $showroom);
        self::assertStringContainsString(
            "controller('moduleFormContact01'",
            $showroom
        );
        self::assertStringContainsString(
            "controller('moduleFormContact02'",
            $showroom
        );
        self::assertStringContainsString(
            "controller('moduleFormContact03'",
            $showroom
        );
        self::assertStringContainsString("require __DIR__ . '/_showroom.php';", $templates);
        self::assertStringContainsString("@use './resources/art02little';", $entrypoint);
        self::assertStringContainsString("@use './resources/moduleList01';", $entrypoint);
        self::assertStringContainsString("@use './resources/moduleParrafo01';", $entrypoint);
        self::assertStringContainsString("@use './resources/moduleTable01';", $entrypoint);
        self::assertStringContainsString("@use './resources/art33';", $entrypoint);
        self::assertStringContainsString("@use './resources/art34';", $entrypoint);
        self::assertStringContainsString(
            "@use './resources/moduleFormContact03';",
            $entrypoint
        );
        self::assertStringContainsString(
            "import initArtAccordion02 from \"./resources/_artAccordion02.js\";",
            $scriptEntrypoint
        );
        self::assertStringContainsString('initArtAccordion02()', $scriptEntrypoint);
        self::assertStringContainsString(
            "from './resources/_moduleFormContact.js'",
            $scriptEntrypoint
        );
        self::assertStringContainsString(
            'initModuleFormContact()',
            $scriptEntrypoint
        );

        foreach ([
            'hero06',
            'hero07',
            'moduleH1Type03',
            'moduleH1Type04',
            'moduleH2Type02',
            'moduleButtonType02',
            'moduleButtonType03',
            'moduleButtonType04',
            'moduleTable01',
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
            'art32',
            'art33',
            'art34',
            'artAccordion02',
        ] as $resource) {
            self::assertStringContainsString(
                "controller('{$resource}', 0",
                $showroom
            );
            self::assertStringContainsString(
                "@use './resources/{$resource}';",
                $entrypoint
            );
        }

        $inlineEditor = file_get_contents(
            $this->projectRoot . '/src/js/resources/_inlineEditor.js'
        );
        $languageEndpoint = file_get_contents(
            $this->projectRoot . '/App/app/updateLanguage.php'
        );

        self::assertIsString($inlineEditor);
        self::assertIsString($languageEndpoint);
        self::assertStringContainsString('const saveBatchValues = async', $inlineEditor);
        self::assertStringContainsString('[data-inline-collection="lines"]', $inlineEditor);
        self::assertStringContainsString('[data-inline-background]', $inlineEditor);
        self::assertStringContainsString('[data-inline-group]', $inlineEditor);
        self::assertStringContainsString(
            'INLINE_EDITOR_HANDLER_KEY',
            $inlineEditor
        );
        self::assertStringContainsString(
            'removeDoubleClickHandler(previousHandlers.doubleClick);',
            $inlineEditor
        );
        self::assertStringContainsString(
            'document.removeEventListener("click", previousHandlers.anchorClick, true);',
            $inlineEditor
        );
        self::assertStringContainsString(
            'previousHandlers.languageChange,',
            $inlineEditor
        );
        self::assertStringContainsString(
            'event.stopImmediatePropagation()',
            $inlineEditor
        );
        self::assertStringContainsString(
            'document.addEventListener("dblclick", handleInlineEditorDoubleClick, true);',
            $inlineEditor
        );
        self::assertStringContainsString(
            'import.meta.hot.dispose(cleanupHandlers);',
            $inlineEditor
        );
        self::assertStringContainsString("\$batchInput = \$data['updates'] ?? null;", $languageEndpoint);

        foreach (['es', 'en', 'eu'] as $lang) {
            $languagePath = $this->projectRoot
                . "/App/config/languages/templates/{$lang}.json";
            $language = json_decode(
                (string) file_get_contents($languagePath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertArrayHasKey('art02little_00_headerPrimary', $language);
            self::assertArrayHasKey('art02little_01_headerSecondary_c', $language);
            self::assertArrayHasKey('moduleParrafo01_01_p_text', $language);
            self::assertArrayHasKey('moduleList01_01_f_li_text', $language);
            self::assertArrayHasKey('moduleH2Type01_05_h2_text', $language);
            self::assertArrayHasKey(
                'moduleButtonType02_00_cta_img',
                $language
            );
            self::assertArrayHasKey(
                'moduleButtonType03_00_cta_link',
                $language
            );
            self::assertArrayHasKey(
                'moduleButtonType04_00_cta_link',
                $language
            );
            self::assertArrayHasKey(
                'moduleTable01_00_caption',
                $language
            );
            self::assertArrayHasKey(
                'moduleTable01_00_c_list_c',
                $language
            );
            self::assertArrayHasKey('art30_00_d_img', $language);
            self::assertArrayHasKey('art32_00_h_img', $language);
            self::assertArrayHasKey('art33_00_headerPrimary', $language);
            self::assertArrayHasKey('art34_00_headerPrimary', $language);
            self::assertArrayHasKey(
                'moduleFormContact01_00_legend',
                $language
            );
            self::assertArrayHasKey(
                'moduleFormContact03_00_server_error',
                $language
            );
            self::assertArrayHasKey('moduleList01_05_e_li_text', $language);
            self::assertArrayHasKey('moduleParrafo01_06_p_text', $language);
            self::assertArrayHasKey(
                'art30_00_benefit_f_img',
                $language
            );
        }
    }

    public function testComposerUpdatePreservesExistingProjectLogos(): void
    {
        $logoPath = $this->projectRoot . '/public/assets/img/logos/logo-black.svg';
        $this->writeFile($logoPath, '<svg data-project-logo="true"></svg>');

        Installer::postUpdate($this->createEvent());

        self::assertSame(
            '<svg data-project-logo="true"></svg>',
            file_get_contents($logoPath)
        );
    }

    public function testResourceSyncStopsSafelyWhenScssConfigIsMissing(): void
    {
        $this->filesystem->remove(
            $this->projectRoot . '/src/scss/_config.scss'
        );

        Installer::syncResources($this->createEvent());

        self::assertFileDoesNotExist(
            $this->projectRoot . '/src/scss/resources/_art02.scss'
        );
        self::assertStringContainsString(
            'Se omite la sincronización de recursos',
            $this->io->getOutput()
        );
    }

    public function testResourceSyncAddsOnlyMissingScssColorVariables(): void
    {
        $configPath = $this->projectRoot . '/src/scss/_config.scss';
        $original = "\$color00: #project-white;\r\n"
            . "\$color02: #project-primary;\r\n"
            . "\$filterColorSepia: project-dark-filter;\r\n"
            . "\$filterColor02: project-primary-filter;\r\n";
        $this->writeFile($configPath, $original);

        Installer::syncResources($this->createEvent());

        $updated = (string) file_get_contents($configPath);

        self::assertStringStartsWith($original . "\r\n", $updated);
        self::assertSame(1, substr_count($updated, '$color00:'));
        self::assertSame(1, substr_count($updated, '$color02:'));
        self::assertStringContainsString(
            '$color01SVG: $filterColorSepia !default;',
            $updated
        );
        self::assertStringContainsString(
            '$color02SVG: $filterColor02 !default;',
            $updated
        );
        self::assertStringContainsString(
            '$color03SVG: invert(17%)',
            $updated
        );
        self::assertStringContainsString(
            '$color04bis: #e9f5ff45 !default;',
            $updated
        );
        self::assertStringContainsString(
            'Contrato SCSS de CORE:',
            $this->io->getOutput()
        );

        Installer::syncResources($this->createEvent());

        self::assertSame($updated, file_get_contents($configPath));
    }

    public function testComposerUpdatePreservesProjectFormAndLegalOverrides(): void
    {
        $localFiles = [
            '/App/app/formContact.php' => '<?php // local contact backend',
            '/App/app/_phpmailer.php' => '<?php // local mail transport',
            '/App/class/_comprobaciones.php' => '<?php // local checks',
            '/App/config/languages/_email/es.json' => '{"local":"email copy"}',
            '/App/controllers/footerInfo01.php' => '<?php // local footer',
            '/App/templates/_footerInfo01.html' => '<div>local footer</div>',
            '/src/js/resources/_terminos.js' => 'export const localLegal = true;',
            '/src/scss/resources/_moduleTerminos.scss' => '.local-legal{}',
        ];

        foreach ($localFiles as $relativePath => $contents) {
            $this->writeFile($this->projectRoot . $relativePath, $contents);
        }

        $postRoutes = (string) file_get_contents(
            $this->projectRoot . '/App/config/routes/post.php'
        );

        Installer::postUpdate($this->createEvent());

        foreach ($localFiles as $relativePath => $contents) {
            self::assertSame(
                $contents,
                file_get_contents($this->projectRoot . $relativePath),
                "Composer no debe sobrescribir {$relativePath}"
            );
        }

        self::assertSame(
            $postRoutes,
            file_get_contents($this->projectRoot . '/App/config/routes/post.php')
        );
        self::assertFileEquals(
            dirname(__DIR__, 2)
                . '/stubs/App/config/languages/_email/en.json',
            $this->projectRoot . '/App/config/languages/_email/en.json'
        );
        self::assertFileEquals(
            dirname(__DIR__, 2)
                . '/stubs/App/config/languages/_email/eu.json',
            $this->projectRoot . '/App/config/languages/_email/eu.json'
        );
    }

    private function createEvent(): Event
    {
        $config = new Config(false, $this->projectRoot);
        $config->merge([
            'config' => [
                'vendor-dir' => $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor',
            ],
        ]);

        $composer = new Composer();
        $composer->setConfig($config);

        $this->io = new BufferIO();

        return new Event('test-resource-sync', $composer, $this->io);
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, $contents);
    }
}
