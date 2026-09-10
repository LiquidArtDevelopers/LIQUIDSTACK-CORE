<?php

declare(strict_types=1);

use App\Core\Composer\DevelopmentViteHeadIntegrator;
use Composer\IO\BufferIO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DevelopmentViteHeadIntegratorTest extends TestCase
{
    private Filesystem $filesystem;
    private string $projectRoot;
    private ?string $externalRoot = null;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-development-head-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir(
            $this->projectRoot . '/App/includes'
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
        if ($this->externalRoot !== null) {
            $this->filesystem->remove($this->externalRoot);
        }
    }

    public function testMigratesCanonicalHeadAndIsIdempotent(): void
    {
        $path = $this->headPath();
        $legacy = "\xEF\xBB\xBF" . str_replace(
            "\n",
            "\r\n",
            $this->canonicalLegacyHead()
        );
        $this->filesystem->dumpFile($path, $legacy);
        $io = new BufferIO();

        self::assertTrue(DevelopmentViteHeadIntegrator::integrate(
            $this->projectRoot,
            $this->filesystem,
            $io
        ));

        $integrated = (string) file_get_contents($path);
        self::assertStringStartsWith("\xEF\xBB\xBF", $integrated);
        self::assertStringContainsString("\r\n", $integrated);
        self::assertSame(
            2,
            substr_count($integrated, 'liquidstack_dev_vite_origin()')
        );
        self::assertStringNotContainsString(
            'http://localhost:5173',
            $integrated
        );
        self::assertTrue(
            DevelopmentViteHeadIntegrator::isIntegrated($this->projectRoot)
        );

        $secondIo = new BufferIO();
        self::assertTrue(DevelopmentViteHeadIntegrator::integrate(
            $this->projectRoot,
            $this->filesystem,
            $secondIo
        ));
        self::assertSame($integrated, file_get_contents($path));
        self::assertStringContainsString(
            'already integrated',
            $secondIo->getOutput()
        );
    }

    public function testRecognizesEscapedNoncedHeadWithoutRewriting(): void
    {
        $path = $this->headPath();
        $contents = $this->canonicalEscapedNoncedHead();
        $this->filesystem->dumpFile($path, $contents);

        self::assertTrue(
            DevelopmentViteHeadIntegrator::isIntegrated($this->projectRoot)
        );

        $io = new BufferIO();
        self::assertTrue(DevelopmentViteHeadIntegrator::integrate(
            $this->projectRoot,
            $this->filesystem,
            $io
        ));
        self::assertSame($contents, file_get_contents($path));
        self::assertSame(
            2,
            substr_count($contents, 'liquidstack_dev_vite_origin()')
        );
        self::assertStringContainsString(
            'already integrated',
            $io->getOutput()
        );
    }

    #[DataProvider('unsafeHeadProvider')]
    public function testPreservesCustomOrAmbiguousHead(string $contents): void
    {
        $path = $this->headPath();
        $this->filesystem->dumpFile($path, $contents);
        $io = new BufferIO();

        self::assertFalse(DevelopmentViteHeadIntegrator::integrate(
            $this->projectRoot,
            $this->filesystem,
            $io
        ));
        self::assertSame($contents, file_get_contents($path));
        self::assertFalse(
            DevelopmentViteHeadIntegrator::isIntegrated($this->projectRoot)
        );
        self::assertStringContainsString(
            'Preserved custom',
            $io->getOutput()
        );
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeHeadProvider(): iterable
    {
        yield 'custom port' => [str_replace(
            'localhost:5173',
            'localhost:9000',
            self::canonicalLegacyHeadStatic()
        )];
        yield 'only client is canonical' => [
            '<script src="http://localhost:5173/@vite/client"></script>'
                . "\n"
                . '<script src="/src/js/home.js"></script>'
                . "\n",
        ];
        yield 'duplicate client' => [
            '<script src="http://localhost:5173/@vite/client"></script>'
                . "\n"
                . self::canonicalLegacyHeadStatic(),
        ];
        yield 'partial helper integration' => [
            '<script src="<?= liquidstack_dev_vite_origin() ?>'
                . '/@vite/client"></script>'
                . "\n"
                . '<script src="http://localhost:5173/src/js/home.js"></script>'
                . "\n",
        ];
        yield 'legacy origins in PHP string' => [
            '<?php $head = \'<script src="http://localhost:5173'
                . '/@vite/client"></script>\' . "\n"; ?>'
                . "\n"
                . '<?php $entry = \'<script src="http://localhost:5173'
                . '/src/js/home.js"></script>\'; ?>'
                . "\n",
        ];
        yield 'legacy origins in multiline HTML comment' => [
            "<!--\n"
                . '<script src="http://localhost:5173/@vite/client"></script>'
                . "\n"
                . '<script src="http://localhost:5173/src/js/home.js"></script>'
                . "\n-->\n",
        ];
        yield 'legacy origins in script bodies' => [
            '<script>const client = \'src="http://localhost:5173'
                . '/@vite/client"\';</script>'
                . "\n"
                . '<script>const entry = \'src="http://localhost:5173'
                . '/src/js/home.js"\';</script>'
                . "\n",
        ];
        yield 'legacy origins nested in another attribute' => [
            '<script data-code=\'src="http://localhost:5173'
                . '/@vite/client"\'></script>'
                . "\n"
                . '<script data-code=\'src="http://localhost:5173'
                . '/src/js/home.js"\'></script>'
                . "\n",
        ];
        yield 'escaped origins without the canonical HTML escaper' => [
            str_replace(
                'ENT_QUOTES | ENT_SUBSTITUTE',
                'ENT_SUBSTITUTE',
                self::canonicalEscapedNoncedHeadStatic()
            ),
        ];
        yield 'escaped origins without the CSP nonce attribute' => [
            str_replace(
                '<script<?= $headScriptNonceAttribute ?>',
                '<script',
                self::canonicalEscapedNoncedHeadStatic()
            ),
        ];
        yield 'escaped origins with nonce sourced from request input' => [
            str_replace(
                '$headCspNonce = isset($cspNonce)',
                "\$headCspNonce = isset(\$_GET['nonce'])",
                self::canonicalEscapedNoncedHeadStatic()
            ),
        ];
        yield 'escaped origins with a second nonce attribute assignment' => [
            self::canonicalEscapedNoncedHeadStatic()
                . "\n<?php \$headScriptNonceAttribute = "
                . "\$_GET['nonce'] ?? ''; ?>\n",
        ];
        yield 'escaped origins plus an arbitrary third helper occurrence' => [
            self::canonicalEscapedNoncedHeadStatic()
                . "\n<!-- liquidstack_dev_vite_origin() -->\n",
        ];
        yield 'escaped origins nested in another attribute' => [
            str_replace(
                ' src="<?= $escapeMeta(liquidstack_dev_vite_origin()) ?>',
                ' data-code=\'src="<?= '
                    . '$escapeMeta(liquidstack_dev_vite_origin()) ?>',
                self::canonicalEscapedNoncedHeadStatic()
            ),
        ];
    }

    public function testMissingHeadDefersIntegration(): void
    {
        $io = new BufferIO();

        self::assertFalse(DevelopmentViteHeadIntegrator::integrate(
            $this->projectRoot,
            $this->filesystem,
            $io
        ));
        self::assertStringContainsString('missing', $io->getOutput());
    }

    public function testLinkedAncestorDefersWithoutWritingOutsideProject(): void
    {
        $this->filesystem->remove($this->projectRoot . '/App');
        $this->externalRoot = sys_get_temp_dir()
            . '/liquidstack-development-head-external-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->externalRoot . '/includes');
        $externalHead = $this->externalRoot . '/includes/_globalHead.php';
        $contents = $this->canonicalLegacyHead();
        $this->filesystem->dumpFile($externalHead, $contents);

        if (!@symlink($this->externalRoot, $this->projectRoot . '/App')) {
            self::markTestSkipped(
                'The platform cannot create the linked-directory fixture.'
            );
        }

        $io = new BufferIO();
        self::assertFalse(DevelopmentViteHeadIntegrator::integrate(
            $this->projectRoot,
            $this->filesystem,
            $io
        ));
        self::assertSame($contents, file_get_contents($externalHead));
        self::assertFalse(
            DevelopmentViteHeadIntegrator::isIntegrated($this->projectRoot)
        );
        self::assertStringContainsString('linked', $io->getOutput());
    }

    private function headPath(): string
    {
        return $this->projectRoot . '/App/includes/_globalHead.php';
    }

    private function canonicalLegacyHead(): string
    {
        return self::canonicalLegacyHeadStatic();
    }

    private function canonicalEscapedNoncedHead(): string
    {
        return self::canonicalEscapedNoncedHeadStatic();
    }

    private static function canonicalLegacyHeadStatic(): string
    {
        return <<<'PHP'
<?php if ($devMode): ?>
<script type="module" src="http://localhost:5173/@vite/client"></script>
<script defer src="http://localhost:5173/src/js/<?= $resources ?>.js" type="module"></script>
<?php endif; ?>
PHP;
    }

    private static function canonicalEscapedNoncedHeadStatic(): string
    {
        return <<<'PHP'
<?php
$escapeMeta = static fn (mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$headCspNonce = isset($cspNonce)
    && is_string($cspNonce)
    && preg_match('/\A[A-Za-z0-9+\/_=-]+\z/D', $cspNonce) === 1
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
