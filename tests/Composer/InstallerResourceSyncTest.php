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
            $this->projectRoot . '/App/views/project-only.php',
            '<?php echo "local";'
        );
        $this->writeFile(
            $this->projectRoot . '/App/views/_templates.php',
            'obsolete managed view'
        );
        $this->writeFile(
            $this->projectRoot . '/App/app/updateLanguage.php',
            '<?php echo "obsolete language endpoint";'
        );
        $this->writeFile(
            $this->projectRoot . '/App/tools/update-languages.php',
            '<?php echo "obsolete language hydrator";'
        );
        $this->writeFile(
            $this->projectRoot . '/src/scss/resources/_art02little.scss',
            'obsolete managed stylesheet'
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
        self::assertStringContainsString(
            'CORE actualizará App/app/updateLanguage.php',
            $this->io->getOutput()
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
        self::assertStringContainsString("require __DIR__ . '/_showroom.php';", $templates);
        self::assertStringContainsString("@use './resources/art02little';", $entrypoint);
        self::assertStringContainsString("@use './resources/moduleList01';", $entrypoint);
        self::assertStringContainsString("@use './resources/moduleParrafo01';", $entrypoint);
        self::assertStringContainsString(
            "import initArtAccordion02 from \"./resources/_artAccordion02.js\";",
            $scriptEntrypoint
        );
        self::assertStringContainsString('initArtAccordion02()', $scriptEntrypoint);

        foreach ([
            'hero06',
            'hero07',
            'moduleH1Type03',
            'moduleH1Type04',
            'moduleH2Type02',
            'moduleButtonType02',
            'moduleButtonType03',
            'moduleButtonType04',
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
            'document.removeEventListener("dblclick", previousHandlers.doubleClick);',
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
            self::assertArrayHasKey('art30_00_d_img', $language);
            self::assertArrayHasKey('art32_00_h_img', $language);
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
