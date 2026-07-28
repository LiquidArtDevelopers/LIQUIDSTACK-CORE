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
            $this->projectRoot . '/src/scss/resources/_art02little.scss',
            'obsolete managed stylesheet'
        );
        $this->writeFile(
            $this->projectRoot . '/App/config/routes/get.php',
            '<?php return ["project-route" => true];'
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
            'check-OK.svg',
            'compass-outline.svg',
            'people.svg',
            'shield-checkmark-outline.svg',
        ] as $asset) {
            self::assertFileEquals(
                $coreRoot . "/resources/img/system/{$asset}",
                $this->projectRoot . "/public/assets/img/system/{$asset}"
            );
        }

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
            'export default { projectRoute: true };',
            file_get_contents($this->projectRoot . '/App/config/rutas.js')
        );

        $showroom = file_get_contents($this->projectRoot . '/App/views/_showroom.php');
        $templates = file_get_contents($this->projectRoot . '/App/views/_templates.php');
        $entrypoint = file_get_contents($this->projectRoot . '/src/scss/templates.scss');

        self::assertIsString($showroom);
        self::assertIsString($templates);
        self::assertIsString($entrypoint);
        self::assertStringContainsString("controller('art02little'", $showroom);
        self::assertStringContainsString("controller('moduleList01'", $showroom);
        self::assertStringContainsString("controller('moduleParrafo01'", $showroom);
        self::assertStringContainsString("require __DIR__ . '/_showroom.php';", $templates);
        self::assertStringContainsString("@use './resources/art02little';", $entrypoint);
        self::assertStringContainsString("@use './resources/moduleList01';", $entrypoint);
        self::assertStringContainsString("@use './resources/moduleParrafo01';", $entrypoint);

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
        }
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

        return new Event('test-resource-sync', $composer, new BufferIO());
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, $contents);
    }
}
