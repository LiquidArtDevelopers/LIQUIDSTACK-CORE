<?php

declare(strict_types=1);

use App\Core\Composer\Installer;
use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InstallerFrontendPackageSyncTest extends TestCase
{
    private const LEGACY_LAD =
        'node scripts/swap-env.mjs development && concurrently '
        . '"php -S localhost:1309 -t public" "npm run dev"';
    private const ROUTED_LAD =
        'node scripts/swap-env.mjs development && concurrently '
        . '"php -S localhost:1309 -t public App/tools/php-dev-router.php" '
        . '"npm run dev"';

    private Filesystem $filesystem;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-frontend-package-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir([
            $this->projectRoot . '/vendor',
            $this->projectRoot . '/App/tools',
        ]);
        $this->filesystem->copy(
            dirname(__DIR__, 2) . '/stubs/App/tools/php-dev-router.php',
            $this->projectRoot . '/App/tools/php-dev-router.php'
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testCanonicalLegacyLadScriptMigratesAndIsIdempotent(): void
    {
        $this->writePackage([
            'private' => true,
            'scripts' => ['lad' => self::LEGACY_LAD],
        ]);

        $first = $this->sync();
        self::assertSame(
            self::ROUTED_LAD,
            $this->readPackage()['scripts']['lad'] ?? null
        );
        self::assertStringContainsString(
            'Updated canonical frontend scripts in package.json: lad',
            $first
        );

        $second = $this->sync();
        self::assertSame(
            self::ROUTED_LAD,
            $this->readPackage()['scripts']['lad'] ?? null
        );
        self::assertStringContainsString(
            'Frontend dependencies already up to date',
            $second
        );
        self::assertStringNotContainsString(
            'Updated canonical frontend scripts',
            $second
        );
    }

    public function testCustomizedLadScriptIsPreserved(): void
    {
        $custom = 'node custom-development-server.mjs';
        $this->writePackage([
            'scripts' => ['lad' => $custom],
        ]);

        $output = $this->sync();

        self::assertSame(
            $custom,
            $this->readPackage()['scripts']['lad'] ?? null
        );
        self::assertStringContainsString(
            'Preserved customized frontend script in package.json: lad',
            $output
        );
    }

    public function testCanonicalLadScriptWaitsForItsManagedRouter(): void
    {
        $this->filesystem->remove(
            $this->projectRoot . '/App/tools/php-dev-router.php'
        );
        $this->writePackage([
            'scripts' => ['lad' => self::LEGACY_LAD],
        ]);

        $output = $this->sync();

        self::assertSame(
            self::LEGACY_LAD,
            $this->readPackage()['scripts']['lad'] ?? null
        );
        self::assertStringContainsString(
            'Deferred canonical frontend script migration',
            $output
        );
        self::assertStringNotContainsString(
            'already up to date',
            $output
        );
    }

    public function testCanonicalLadScriptWaitsWhenRouterIsCustomized(): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/tools/php-dev-router.php',
            "<?php echo 'custom router';\n"
        );
        $this->writePackage([
            'scripts' => ['lad' => self::LEGACY_LAD],
        ]);

        $output = $this->sync();

        self::assertSame(
            self::LEGACY_LAD,
            $this->readPackage()['scripts']['lad'] ?? null
        );
        self::assertStringContainsString(
            'Deferred canonical frontend script migration',
            $output
        );
        self::assertStringNotContainsString(
            'already up to date',
            $output
        );
    }

    public function testAlreadyRoutedLadReportsAMissingManagedRouter(): void
    {
        $this->filesystem->remove(
            $this->projectRoot . '/App/tools/php-dev-router.php'
        );
        $this->writePackage([
            'scripts' => ['lad' => self::ROUTED_LAD],
        ]);

        $output = $this->sync();

        self::assertSame(
            self::ROUTED_LAD,
            $this->readPackage()['scripts']['lad'] ?? null
        );
        self::assertStringContainsString(
            'Deferred canonical frontend script migration',
            $output
        );
        self::assertStringNotContainsString(
            'already up to date',
            $output
        );
    }

    public function testMissingLadScriptIsNotInvented(): void
    {
        $this->writePackage([
            'scripts' => ['dev' => 'vite'],
        ]);

        $this->sync();

        self::assertArrayNotHasKey(
            'lad',
            $this->readPackage()['scripts'] ?? []
        );
    }

    /** @param array<string, mixed> $package */
    private function writePackage(array $package): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/package.json',
            json_encode(
                $package,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }

    /** @return array<string, mixed> */
    private function readPackage(): array
    {
        return json_decode(
            (string) file_get_contents($this->projectRoot . '/package.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function sync(): string
    {
        $config = new Config(false, $this->projectRoot);
        $config->merge(['config' => [
            'vendor-dir' => $this->projectRoot . '/vendor',
        ]]);
        $composer = new Composer();
        $composer->setConfig($config);
        $io = new BufferIO();

        Installer::syncFrontendDependencies(new Event(
            'post-update-cmd',
            $composer,
            $io
        ));

        return $io->getOutput();
    }
}
