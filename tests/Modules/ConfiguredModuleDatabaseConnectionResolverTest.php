<?php

declare(strict_types=1);

use App\Core\Database\DatabaseConnectionException;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ConfiguredModuleDatabaseConnectionResolverTest extends TestCase
{
    private string $coreRoot;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->coreRoot = sys_get_temp_dir()
            . '/liquidstack-module-database-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->coreRoot . '/project';

        $this->filesystem->mkdir([
            $this->coreRoot . '/modules/webadmin',
            $this->coreRoot . '/modules/blog',
            $this->projectRoot . '/App/config/modules',
        ]);
        $this->writeManifest('webadmin', []);
        $this->writeManifest('blog', ['webadmin']);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '*',
                    'liquidstack/blog' => '*',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->coreRoot);
    }

    public function testBothModulesKeepSharedAsTheRetrocompatibleDefault(): void
    {
        self::assertFileDoesNotExist(
            $this->projectRoot . '/App/config/langs.php'
        );

        self::assertSame('shared', $this->resolve());
    }

    public function testBothModulesCanSelectTheDedicatedLiquidStackConnection(): void
    {
        $this->writeModuleConfig('webadmin', <<<'PHP'
<?php

return ['database' => ['connection' => 'liquidstack']];
PHP);
        $this->writeModuleConfig('blog', <<<'PHP'
<?php

return ['database' => ['connection' => 'liquidstack']];
PHP);

        self::assertSame('liquidstack', $this->resolve());
    }

    public function testConnectionMismatchFailsClosedWithoutLeakingSecrets(): void
    {
        $secret = 'module-database-password-must-not-leak';
        $this->writeModuleConfig('webadmin', <<<'PHP'
<?php

return ['database' => ['connection' => 'liquidstack']];
PHP);
        $this->writeModuleConfig(
            'blog',
            "<?php\n\n"
                . '$unusedSecret = ' . var_export($secret, true) . ";\n"
                . "return ['database' => ['connection' => 'shared']];\n"
        );

        try {
            $this->resolve();
            self::fail('Different module connections must fail closed.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame(
                'database.connection_mismatch',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                $secret,
                $exception->getMessage()
            );
            self::assertStringNotContainsString(
                $secret,
                (string) $exception
            );
        }
    }

    private function resolve(): string
    {
        return (new ConfiguredModuleDatabaseConnectionResolver())->resolve(
            ModuleRegistry::forProject($this->projectRoot, $this->coreRoot),
            $this->projectRoot
        );
    }

    private function writeModuleConfig(string $module, string $contents): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/modules/' . $module . '.php',
            $contents
        );
    }

    /** @param list<string> $requires */
    private function writeManifest(string $id, array $requires): void
    {
        $this->filesystem->dumpFile(
            $this->coreRoot . '/modules/' . $id . '/module.json',
            json_encode([
                'schema' => 1,
                'id' => $id,
                'package' => 'liquidstack/' . $id,
                'requires' => $requires,
                'providers' => [],
                'project_files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }
}
