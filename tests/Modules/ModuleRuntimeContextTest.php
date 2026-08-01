<?php

declare(strict_types=1);

use App\Core\Modules\ModuleRuntimeContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ModuleRuntimeContextTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-runtime-context-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot . '/App/config');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testMissingLanguageCatalogMeansNoReservedLocales(): void
    {
        self::assertSame(
            [],
            (new ModuleRuntimeContext($this->projectRoot))->languages()
        );
    }

    public function testEnvironmentUsabilityIsExplicitAndDefaultsToTrue(): void
    {
        self::assertTrue(
            (new ModuleRuntimeContext($this->projectRoot))
                ->environmentIsUsable()
        );
        self::assertFalse(
            (new ModuleRuntimeContext($this->projectRoot, [], false))
                ->environmentIsUsable()
        );
    }

    public function testLanguageCatalogIsNormalizedAndDeduplicated(): void
    {
        $this->writeLanguages("<?php\nreturn ['ES', 'en', 'es'];\n");

        self::assertSame(
            ['es', 'en'],
            (new ModuleRuntimeContext($this->projectRoot))->languages()
        );
    }

    public function testLanguageCatalogCannotEmitOrLeakContent(): void
    {
        $this->writeLanguages(<<<'PHP'
<?php

echo 'must-not-leak';
return ['es'];
PHP);

        ob_start();
        try {
            (new ModuleRuntimeContext($this->projectRoot))->languages();
            self::fail('A language catalog that emits output must fail.');
        } catch (RuntimeException $exception) {
            $outerOutput = (string) ob_get_clean();
            self::assertSame('', $outerOutput);
            self::assertStringNotContainsString(
                'must-not-leak',
                $exception->getMessage()
            );
            self::assertStringContainsString(
                'no puede emitir',
                $exception->getMessage()
            );
        }
    }

    public function testLanguageCatalogExceptionsAreRedacted(): void
    {
        $this->writeLanguages(<<<'PHP'
<?php

throw new RuntimeException('must-not-leak');
PHP);

        try {
            (new ModuleRuntimeContext($this->projectRoot))->languages();
            self::fail('A failing language catalog must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString(
                'must-not-leak',
                $exception->getMessage()
            );
            self::assertStringContainsString(
                'no se pudo cargar',
                $exception->getMessage()
            );
        }
    }

    private function writeLanguages(string $contents): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/langs.php',
            $contents
        );
    }
}
