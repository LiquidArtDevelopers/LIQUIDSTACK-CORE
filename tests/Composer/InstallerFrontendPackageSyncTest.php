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
    private const SUPERVISED_LAD =
        'node scripts/swap-env.mjs development '
        . '&& node App/tools/liquidstack-dev.mjs';

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
            $this->projectRoot . '/App/includes',
        ]);
        $this->filesystem->copy(
            dirname(__DIR__, 2) . '/stubs/App/tools/php-dev-router.php',
            $this->projectRoot . '/App/tools/php-dev-router.php'
        );
        $this->filesystem->copy(
            dirname(__DIR__, 2) . '/stubs/App/tools/liquidstack-dev.mjs',
            $this->projectRoot . '/App/tools/liquidstack-dev.mjs'
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/includes/_globalHead.php',
            $this->canonicalDevelopmentHead()
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
            'scripts' => [
                'dev' => 'vite',
                'lad' => self::LEGACY_LAD,
            ],
        ]);

        $first = $this->sync();
        self::assertSame(
            self::SUPERVISED_LAD,
            $this->readPackage()['scripts']['lad'] ?? null
        );
        self::assertStringContainsString(
            'Updated canonical frontend scripts in package.json: lad',
            $first
        );
        self::assertStringContainsString(
            'liquidstack_dev_vite_origin()',
            (string) file_get_contents(
                $this->projectRoot . '/App/includes/_globalHead.php'
            )
        );

        $second = $this->sync();
        self::assertSame(
            self::SUPERVISED_LAD,
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

    public function testCanonicalRoutedLadScriptMigratesToSupervisor(): void
    {
        $this->writePackage([
            'scripts' => [
                'dev' => 'vite',
                'lad' => self::ROUTED_LAD,
            ],
        ]);

        $output = $this->sync();

        self::assertSame(
            self::SUPERVISED_LAD,
            $this->readPackage()['scripts']['lad'] ?? null
        );
        self::assertStringContainsString(
            'Updated canonical frontend scripts in package.json: lad',
            $output
        );
    }

    public function testCanonicalLadMigratesWithEscapedNoncedHead(): void
    {
        $headPath = $this->projectRoot . '/App/includes/_globalHead.php';
        $head = $this->escapedNoncedDevelopmentHead();
        $this->filesystem->dumpFile($headPath, $head);
        $this->writePackage([
            'scripts' => [
                'dev' => 'vite',
                'lad' => self::ROUTED_LAD,
            ],
        ]);

        $output = $this->sync();

        self::assertSame(
            self::SUPERVISED_LAD,
            $this->readPackage()['scripts']['lad'] ?? null
        );
        self::assertSame($head, file_get_contents($headPath));
        self::assertStringContainsString(
            'Updated canonical frontend scripts in package.json: lad',
            $output
        );
        self::assertStringNotContainsString('Preserved custom', $output);
        self::assertStringNotContainsString(
            'Deferred canonical frontend script migration',
            $output
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
            'scripts' => [
                'dev' => 'vite',
                'lad' => self::LEGACY_LAD,
            ],
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
            'scripts' => [
                'dev' => 'vite',
                'lad' => self::LEGACY_LAD,
            ],
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

    public function testCanonicalLadScriptWaitsForItsManagedSupervisor(): void
    {
        $this->filesystem->remove(
            $this->projectRoot . '/App/tools/liquidstack-dev.mjs'
        );
        $this->writePackage([
            'scripts' => [
                'dev' => 'vite',
                'lad' => self::ROUTED_LAD,
            ],
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
    }

    public function testCanonicalLadScriptWaitsWhenSupervisorIsCustomized(): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/tools/liquidstack-dev.mjs',
            "console.log('custom supervisor');\n"
        );
        $this->writePackage([
            'scripts' => [
                'dev' => 'vite',
                'lad' => self::ROUTED_LAD,
            ],
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
    }

    public function testAlreadyRoutedLadReportsAMissingManagedRouter(): void
    {
        $this->filesystem->remove(
            $this->projectRoot . '/App/tools/php-dev-router.php'
        );
        $this->writePackage([
            'scripts' => [
                'dev' => 'vite',
                'lad' => self::ROUTED_LAD,
            ],
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

    public function testCanonicalLadScriptWaitsWhenDevScriptIsMissing(): void
    {
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
    }

    public function testCanonicalLadScriptWaitsWhenDevScriptIsCustomized(): void
    {
        $this->writePackage([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'lad' => self::ROUTED_LAD,
            ],
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
    }

    public function testCanonicalLadScriptWaitsForCanonicalHeadIntegration(): void
    {
        $customHead = str_replace(
            'localhost:5173',
            'localhost:9000',
            $this->canonicalDevelopmentHead()
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/includes/_globalHead.php',
            $customHead
        );
        $this->writePackage([
            'scripts' => [
                'dev' => 'vite',
                'lad' => self::ROUTED_LAD,
            ],
        ]);

        $output = $this->sync();

        self::assertSame(
            self::ROUTED_LAD,
            $this->readPackage()['scripts']['lad'] ?? null
        );
        self::assertSame(
            $customHead,
            file_get_contents(
                $this->projectRoot . '/App/includes/_globalHead.php'
            )
        );
        self::assertStringContainsString('Preserved custom', $output);
        self::assertStringContainsString(
            'Deferred canonical frontend script migration',
            $output
        );
    }

    public function testSupervisedLadCompletesADeferredHeadIntegration(): void
    {
        $this->writePackage([
            'scripts' => [
                'dev' => 'vite',
                'lad' => self::SUPERVISED_LAD,
            ],
        ]);

        $output = $this->sync();

        self::assertSame(
            self::SUPERVISED_LAD,
            $this->readPackage()['scripts']['lad'] ?? null
        );
        self::assertStringContainsString(
            'liquidstack_dev_vite_origin()',
            (string) file_get_contents(
                $this->projectRoot . '/App/includes/_globalHead.php'
            )
        );
        self::assertStringContainsString(
            'Integrated the dynamic Vite origin',
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

    private function canonicalDevelopmentHead(): string
    {
        return <<<'PHP'
<?php if ($devMode): ?>
<script type="module" src="http://localhost:5173/@vite/client"></script>
<script defer src="http://localhost:5173/src/js/<?= $resources ?>.js" type="module"></script>
<?php endif; ?>
PHP;
    }

    private function escapedNoncedDevelopmentHead(): string
    {
        return <<<'PHP'
<?php
$escapeMeta = static fn (mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$headCspNonce = isset($cspNonce) && is_string($cspNonce)
    ? $cspNonce
    : null;
$headScriptNonceAttribute = $headCspNonce === null
    ? ''
    : ' nonce="' . $escapeMeta($headCspNonce) . '"';
?>
<?php if ($devMode): ?>
<script<?= $headScriptNonceAttribute ?> type="module" src="<?= $escapeMeta(liquidstack_dev_vite_origin()) ?>/@vite/client"></script>
<script<?= $headScriptNonceAttribute ?> defer type="module" src="<?= $escapeMeta(liquidstack_dev_vite_origin()) ?>/src/js/<?= $escapeMeta($resources ?? '') ?>.js"></script>
<?php endif; ?>
PHP;
    }
}
