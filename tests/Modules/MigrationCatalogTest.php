<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationProviderInterface;
use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WebadminMigrationProviderFixture implements MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield new MigrationDefinition(
            '0001_webadmin_fixture',
            'Prepara el fixture WebAdmin.',
            hash('sha256', 'webadmin:0001')
        );
    }
}

final class BlogMigrationProviderFixture implements MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'blog';
    }

    public static function migrations(): iterable
    {
        yield new MigrationDefinition(
            '0001_blog_fixture',
            'Prepara el fixture Blog.',
            hash('sha256', 'blog:0001'),
            true
        );
    }
}

final class DuplicateMigrationProviderFixture implements MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'blog';
    }

    public static function migrations(): iterable
    {
        $migration = new MigrationDefinition(
            '0001_duplicate',
            'Duplicada.',
            hash('sha256', 'duplicate')
        );

        yield $migration;
        yield $migration;
    }
}

final class UnsortedMigrationProviderFixture implements MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield new MigrationDefinition(
            '0002_second',
            'Segunda.',
            hash('sha256', 'second')
        );
        yield new MigrationDefinition(
            '0001_first',
            'Primera.',
            hash('sha256', 'first')
        );
    }
}

final class MigrationCatalogTest extends TestCase
{
    private string $root;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-migration-catalog-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->root . '/project';
        $this->filesystem->mkdir([
            $this->projectRoot,
            $this->root . '/modules/webadmin',
            $this->root . '/modules/blog',
        ]);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testCatalogKeepsModuleDependencyAndProviderOrder(): void
    {
        $this->writeManifest(
            'webadmin',
            [],
            WebadminMigrationProviderFixture::class
        );
        $this->writeManifest(
            'blog',
            ['webadmin'],
            BlogMigrationProviderFixture::class
        );
        $this->writeComposer(['liquidstack/blog' => '*']);

        $plan = MigrationCatalog::fromRegistry($this->registry())->plan();

        self::assertSame([
            'webadmin:0001_webadmin_fixture',
            'blog:0001_blog_fixture',
        ], array_map(
            static fn (array $entry): string =>
                $entry['module'] . ':' . $entry['id'],
            $plan->entries()
        ));
        self::assertFalse($plan->entries()[0]['destructive']);
        self::assertTrue($plan->entries()[1]['destructive']);
        self::assertSame('catalog-only', $plan->toArray()['mode']);
        self::assertTrue($plan->toArray()['read_only']);
        self::assertSame(
            'not_evaluated',
            $plan->toArray()['database_state']
        );
    }

    public function testDuplicateMigrationWithinModuleIsRejected(): void
    {
        $this->writeManifest(
            'webadmin',
            [],
            WebadminMigrationProviderFixture::class
        );
        $this->writeManifest(
            'blog',
            ['webadmin'],
            DuplicateMigrationProviderFixture::class
        );
        $this->writeComposer(['liquidstack/blog' => '*']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicada');

        MigrationCatalog::fromRegistry($this->registry());
    }

    public function testMigrationsAreSortedByIdInsideDependencyOrder(): void
    {
        $this->writeManifest(
            'webadmin',
            [],
            UnsortedMigrationProviderFixture::class
        );
        $this->writeManifest(
            'blog',
            ['webadmin'],
            BlogMigrationProviderFixture::class
        );
        $this->writeComposer(['liquidstack/blog' => '*']);

        $entries = MigrationCatalog::fromRegistry($this->registry())
            ->plan()
            ->entries();

        self::assertSame([
            'webadmin:0001_first',
            'webadmin:0002_second',
            'blog:0001_blog_fixture',
        ], array_map(
            static fn (array $entry): string =>
                $entry['module'] . ':' . $entry['id'],
            $entries
        ));
    }

    public function testSqlDefinitionCalculatesStableChecksumFromCanonicalContract(): void
    {
        $arguments = [
            'id' => '0001_create_users',
            'description' => 'Crea usuarios.',
            'statementsByDriver' => [
                'sqlite' => ['CREATE TABLE {{table:users}} (id INTEGER)'],
                'mysql' => ['CREATE TABLE IF NOT EXISTS {{table:users}} (id BIGINT)'],
            ],
            'destructive' => false,
            'transactionalDrivers' => ['sqlite'],
            'retrySafe' => true,
        ];

        $first = MigrationDefinition::sql(...$arguments);
        $second = MigrationDefinition::sql(...$arguments);
        $changed = MigrationDefinition::sql(
            ...array_replace($arguments, [
                'statementsByDriver' => [
                    'mysql' => ['CREATE TABLE IF NOT EXISTS {{table:users}} (id BIGINT, name TEXT)'],
                    'sqlite' => ['CREATE TABLE {{table:users}} (id INTEGER)'],
                ],
            ])
        );

        self::assertSame($first->checksum(), $second->checksum());
        self::assertNotSame($first->checksum(), $changed->checksum());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first->checksum());
    }

    public function testSqlDefinitionRejectsTransactionalMysql(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MySQL como transaccional');

        MigrationDefinition::sql(
            id: '0001_mysql_transaction',
            description: 'Contrato no soportado.',
            statementsByDriver: [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {{table:users}} (id BIGINT)',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {{table:users}} (id INTEGER)',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['mysql', 'sqlite'],
            retrySafe: true
        );
    }

    /** @dataProvider unsafeMysqlContractProvider */
    public function testSqlDefinitionRejectsUnsafeMysqlRetryContract(
        string $mysql,
        bool $retrySafe
    ): void {
        $this->expectException(InvalidArgumentException::class);

        MigrationDefinition::sql(
            id: '0001_unsafe_mysql',
            description: 'Contrato no reintentable.',
            statementsByDriver: [
                'mysql' => [$mysql],
                'sqlite' => ['SELECT 1'],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: $retrySafe
        );
    }

    public static function unsafeMysqlContractProvider(): iterable
    {
        yield 'retry flag disabled' => [
            'CREATE TABLE IF NOT EXISTS {{table:users}} (id BIGINT)',
            false,
        ];
        yield 'plain create' => [
            'CREATE TABLE {{table:users}} (id BIGINT)',
            true,
        ];
        yield 'plain update' => [
            'UPDATE {{table:users}} SET id = id + 1',
            true,
        ];
        yield 'multiple statements' => [
            'DROP TABLE IF EXISTS {{table:users}}; DROP TABLE IF EXISTS {{table:roles}}',
            true,
        ];
    }

    /** @dataProvider retrySafeMysqlContractProvider */
    public function testSqlDefinitionAcceptsConservativeMysqlRetryContract(
        string $id,
        string $mysql
    ): void {
        $definition = MigrationDefinition::sql(
            id: $id,
            description: 'Contrato reintentable.',
            statementsByDriver: [
                'mysql' => [$mysql],
                'sqlite' => ['SELECT 1'],
            ],
            destructive: str_starts_with($mysql, 'DROP'),
            transactionalDrivers: ['sqlite'],
            retrySafe: true
        );

        self::assertTrue($definition->isRetrySafe());
    }

    public static function retrySafeMysqlContractProvider(): iterable
    {
        yield 'create table' => [
            '0001_create',
            'CREATE TABLE IF NOT EXISTS {{table:users}} (id BIGINT)',
        ];
        yield 'insert ignore select' => [
            '0002_insert_ignore',
            'INSERT IGNORE INTO {{table:users}} (id) SELECT 1',
        ];
        yield 'upsert' => [
            '0003_upsert',
            'INSERT INTO {{table:users}} (id) VALUES (1) ON DUPLICATE KEY UPDATE id = VALUES(id)',
        ];
        yield 'drop if exists' => [
            '0004_drop',
            'DROP TABLE IF EXISTS {{table:users}}',
        ];
    }

    public function testDefinitionRejectsNonSha256Checksum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SHA-256');

        new MigrationDefinition('0001_invalid', 'Inválida.', 'abc');
    }

    public function testDefinitionRejectsInvalidUtf8Description(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('descripción válida');

        new MigrationDefinition(
            '0001_invalid_utf8',
            "\xC3\x28",
            hash('sha256', 'invalid-utf8')
        );
    }

    private function registry(): ModuleRegistry
    {
        return ModuleRegistry::forProject(
            $this->projectRoot,
            $this->root
        );
    }

    /**
     * @param list<string> $requires
     */
    private function writeManifest(
        string $id,
        array $requires,
        string $provider
    ): void {
        $this->filesystem->dumpFile(
            $this->root . '/modules/' . $id . '/module.json',
            json_encode([
                'schema' => 1,
                'id' => $id,
                'package' => 'liquidstack/' . $id,
                'requires' => $requires,
                'providers' => ['migrations' => [$provider]],
                'project_files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }

    /**
     * @param array<string, string> $require
     */
    private function writeComposer(array $require): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode(
                ['require' => $require],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }
}
