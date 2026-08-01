<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ConfiguredMigrationScopeFactoryTest extends TestCase
{
    private string $root;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-configured-scopes-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->root . '/project';
        $this->filesystem->mkdir([
            $this->root . '/modules/webadmin',
            $this->root . '/modules/blog',
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
            ], JSON_THROW_ON_ERROR)
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/langs.php',
            "<?php\n\nreturn ['es', 'en'];\n"
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testScopesUseBothProjectOwnedPrefixes(): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/modules/webadmin.php',
            "<?php\nreturn ['database' => "
                . "['table_prefix' => 'tenant_admin_']];\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/modules/blog.php',
            "<?php\nreturn ['database' => "
                . "['table_prefix' => 'tenant_blog_']];\n"
        );

        $scopes = (new ConfiguredMigrationScopeFactory())->create(
            ModuleRegistry::forProject($this->projectRoot, $this->root),
            $this->projectRoot
        );

        self::assertSame(
            'tenant_admin_',
            $scopes->get('webadmin')?->tablePrefix()
        );
        self::assertSame(
            'tenant_blog_',
            $scopes->get('blog')?->tablePrefix()
        );
    }

    /** @param list<string> $requires */
    private function writeManifest(string $id, array $requires): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/modules/' . $id . '/module.json',
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
