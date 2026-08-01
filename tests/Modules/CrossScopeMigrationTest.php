<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationException;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationPreconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationProviderInterface;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CrossScopeMigrationFactory
{
    public static function definition(
        string $id,
        string $table,
        ?string $targetScopeModuleId = null,
        ?MigrationPreconditionVerifierInterface $precondition = null,
        ?MigrationPostconditionVerifierInterface $postcondition = null
    ): MigrationDefinition {
        return MigrationDefinition::sql(
            id: $id,
            description: 'Cross-scope fixture ' . $id . '.',
            statementsByDriver: [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {{table:' . $table
                        . '}} (id BIGINT PRIMARY KEY)',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {{table:' . $table
                        . '}} (id INTEGER PRIMARY KEY)',
                ],
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: $postcondition,
            preconditionVerifier: $precondition,
            targetScopeModuleId: $targetScopeModuleId
        );
    }
}

final class CrossScopeWebadminMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield CrossScopeMigrationFactory::definition(
            '0001_foundation',
            'foundation'
        );
    }
}

final class CrossScopePreconditionFixture implements
    MigrationPreconditionVerifierInterface
{
    /** @var list<string> */
    public static array $scopeModules = [];

    public function contractVersion(): string
    {
        return 'cross-scope-pre-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        self::$scopeModules[] = $scope->moduleId();
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $statement->execute(['name' => $scope->tableName('foundation')]);

        return (int) $statement->fetchColumn() === 1;
    }
}

final class CrossScopePostconditionFixture implements
    MigrationPostconditionVerifierInterface
{
    /** @var list<string> */
    public static array $scopeModules = [];

    public function contractVersion(): string
    {
        return 'cross-scope-post-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        self::$scopeModules[] = $scope->moduleId();
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $statement->execute(['name' => $scope->tableName('blog_access')]);

        return (int) $statement->fetchColumn() === 1;
    }
}

final class CrossScopeVerifiedBlogMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'blog';
    }

    public static function migrations(): iterable
    {
        yield CrossScopeMigrationFactory::definition(
            '0001_extend_webadmin',
            'blog_access',
            'webadmin',
            new CrossScopePreconditionFixture(),
            new CrossScopePostconditionFixture()
        );
    }
}

final class CrossScopeSimpleBlogMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static string $target = 'webadmin';

    public static function moduleId(): string
    {
        return 'blog';
    }

    public static function migrations(): iterable
    {
        yield CrossScopeMigrationFactory::definition(
            '0002_shared_blog',
            'shared_blog',
            self::$target
        );
    }
}

final class CrossScopeTargetPolicyWebadminProviderFixture implements
    MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield CrossScopeMigrationFactory::definition(
            '0001_invalid_later_target',
            'invalid_later_target',
            'blog'
        );
    }
}

final class CrossScopeOutOfOrderBlogMigrationProviderFixture implements
    MigrationProviderInterface
{
    public static bool $includeEarlier = false;

    public static function moduleId(): string
    {
        return 'blog';
    }

    public static function migrations(): iterable
    {
        if (self::$includeEarlier) {
            yield CrossScopeMigrationFactory::definition(
                '0001_inserted_late',
                'inserted_late',
                'webadmin'
            );
        }

        yield CrossScopeMigrationFactory::definition(
            '0002_already_applied',
            'already_applied',
            'webadmin'
        );
    }
}

final class CrossScopeMigrationTest extends TestCase
{
    private string $root;
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for these tests.');
        }

        CrossScopePreconditionFixture::$scopeModules = [];
        CrossScopePostconditionFixture::$scopeModules = [];
        CrossScopeSimpleBlogMigrationProviderFixture::$target = 'webadmin';
        CrossScopeOutOfOrderBlogMigrationProviderFixture::$includeEarlier = false;

        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-cross-scope-' . bin2hex(random_bytes(8));
        $this->projectRoot = $this->root . '/project';
        $this->filesystem->mkdir($this->projectRoot);
    }

    protected function tearDown(): void
    {
        CrossScopeSimpleBlogMigrationProviderFixture::$target = 'webadmin';
        CrossScopeOutOfOrderBlogMigrationProviderFixture::$includeEarlier = false;
        $this->filesystem->remove($this->root);
    }

    public function testLegacyChecksumIsUnchangedAndOptInTargetIsCanonical(): void
    {
        $statements = [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS {{table:users}} (id BIGINT PRIMARY KEY)',
            ],
            'sqlite' => [
                'CREATE TABLE IF NOT EXISTS {{table:users}} (id INTEGER PRIMARY KEY)',
            ],
        ];
        $arguments = [
            'id' => '0001_checksum_contract',
            'description' => 'Operator-facing description.',
            'statementsByDriver' => $statements,
            'destructive' => false,
            'transactionalDrivers' => ['sqlite'],
            'retrySafe' => true,
        ];

        $legacy = MigrationDefinition::sql(...$arguments);
        $legacyCanonical = json_encode([
            'schema' => 1,
            'id' => '0001_checksum_contract',
            'description' => 'Operator-facing description.',
            'destructive' => false,
            'retry_safe' => true,
            'transactional_drivers' => ['sqlite'],
            'statements' => $statements,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256', $legacyCanonical), $legacy->checksum());
        self::assertNull($legacy->targetScopeModuleId());

        $targeted = MigrationDefinition::sql(
            ...$arguments,
            targetScopeModuleId: 'webadmin'
        );
        $targetCanonical = json_encode([
            'schema' => 5,
            'id' => '0001_checksum_contract',
            'target_scope_module_id' => 'webadmin',
            'destructive' => false,
            'retry_safe' => true,
            'transactional_drivers' => ['sqlite'],
            'statements' => $statements,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        self::assertSame(hash('sha256', $targetCanonical), $targeted->checksum());
        self::assertSame('webadmin', $targeted->targetScopeModuleId());
        self::assertNotSame($legacy->checksum(), $targeted->checksum());
        self::assertNotSame(
            $targeted->checksum(),
            MigrationDefinition::sql(
                ...$arguments,
                targetScopeModuleId: 'content'
            )->checksum()
        );
        self::assertSame(
            $targeted->checksum(),
            MigrationDefinition::sql(
                ...array_replace($arguments, [
                    'description' => 'Changed non-contract description.',
                ]),
                targetScopeModuleId: 'webadmin'
            )->checksum()
        );
    }

    public function testInvalidTargetScopeIdentifierIsRejectedAtDefinitionTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scope');

        CrossScopeMigrationFactory::definition(
            '0001_invalid_target',
            'invalid_target',
            'WebAdmin'
        );
    }

    public function testCatalogAcceptsOwnAndTransitiveDependencyTargetsAndPlansThem(): void
    {
        $this->writeManifest(
            'webadmin',
            [],
            [CrossScopeWebadminMigrationProviderFixture::class]
        );
        $this->writeManifest('content', ['webadmin']);
        $this->writeManifest(
            'blog',
            ['content'],
            [CrossScopeVerifiedBlogMigrationProviderFixture::class]
        );
        $this->writeComposer(['blog']);

        $catalog = $this->catalog();
        $entries = $catalog->plan()->entries();

        self::assertSame(
            ['webadmin', 'webadmin'],
            array_column($entries, 'target_scope_module')
        );
        self::assertSame(
            ['webadmin:0001_foundation', 'blog:0001_extend_webadmin'],
            array_map(
                static fn (array $entry): string =>
                    $entry['module'] . ':' . $entry['id'],
                $entries
            )
        );
        self::assertSame(
            ['webadmin', 'content', 'blog'],
            $catalog->activeModuleIds()
        );

        $scopes = MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'ls_shared_',
        ]);
        $definition = $catalog->entries()[1]['migration'];
        self::assertSame(
            'webadmin',
            $definition->targetScope('blog', $scopes)?->moduleId()
        );
    }

    public function testCatalogAcceptsAnExplicitOwningModuleTarget(): void
    {
        CrossScopeSimpleBlogMigrationProviderFixture::$target = 'blog';
        $this->writeManifest('webadmin');
        $this->writeManifest(
            'blog',
            ['webadmin'],
            [CrossScopeSimpleBlogMigrationProviderFixture::class]
        );
        $this->writeComposer(['blog']);

        $catalog = $this->catalog();
        $entry = $catalog->plan()->entries()[0];
        $scope = $catalog->entries()[0]['migration']->targetScope(
            'blog',
            MigrationScopeCollection::fromTablePrefixes([
                'blog' => 'ls_blog_',
            ])
        );

        self::assertSame('blog', $entry['module']);
        self::assertSame('blog', $entry['target_scope_module']);
        self::assertSame('blog', $scope?->moduleId());
    }

    /**
     * @dataProvider rejectedTargetProvider
     */
    public function testCatalogRejectsInactiveUnrelatedUnknownAndLaterTargets(
        string $scenario
    ): void {
        $this->writeManifest('webadmin');
        $this->writeManifest('search');

        if ($scenario === 'later') {
            $this->writeManifest(
                'webadmin',
                [],
                [CrossScopeTargetPolicyWebadminProviderFixture::class]
            );
            $this->writeManifest('blog', ['webadmin']);
            $this->writeComposer(['blog']);
        } else {
            CrossScopeSimpleBlogMigrationProviderFixture::$target = match ($scenario) {
                'inactive', 'unrelated' => 'search',
                default => 'ghost',
            };
            $this->writeManifest(
                'blog',
                ['webadmin'],
                [CrossScopeSimpleBlogMigrationProviderFixture::class]
            );
            $this->writeComposer(
                $scenario === 'unrelated'
                    ? ['blog', 'search']
                    : ['blog']
            );
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dependencia transitiva activa');

        $this->catalog();
    }

    public static function rejectedTargetProvider(): iterable
    {
        yield 'inactive catalog module' => ['inactive'];
        yield 'active but unrelated module' => ['unrelated'];
        yield 'unknown module' => ['unknown'];
        yield 'later dependent module' => ['later'];
    }

    public function testSQLiteApplyUsesEffectiveScopeForSqlConditionsAndRegistry(): void
    {
        $this->writeManifest(
            'webadmin',
            [],
            [CrossScopeWebadminMigrationProviderFixture::class]
        );
        $this->writeManifest('content', ['webadmin']);
        $this->writeManifest(
            'blog',
            ['content'],
            [CrossScopeVerifiedBlogMigrationProviderFixture::class]
        );
        $scopes = MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'ls_shared_',
        ]);
        $pdo = $this->sqlite();
        $runner = new MigrationRunner();

        // Preconditions are intentionally state guards, not dependencies on a
        // migration pending in the same plan. Enable WebAdmin first, then Blog.
        $this->writeComposer(['webadmin']);
        $runner->apply($pdo, $this->catalog(), $scopes);
        $this->writeComposer(['blog']);

        $catalog = $this->catalog();
        $preflight = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $catalog,
            $scopes
        );
        self::assertTrue($preflight->isApplicable());
        self::assertSame(
            ['webadmin', 'webadmin'],
            array_column($preflight->entries(), 'target_scope_module')
        );

        $result = $runner->apply($pdo, $catalog, $scopes);

        self::assertSame(
            ['blog:0001_extend_webadmin'],
            array_map(
                static fn (array $entry): string =>
                    $entry['module'] . ':' . $entry['id'],
                $result->applied()
            )
        );
        self::assertSame(1, $this->tableCount($pdo, 'ls_shared_blog_access'));
        self::assertSame(0, $this->tableCount($pdo, 'ls_blog_blog_access'));
        self::assertNotEmpty(CrossScopePreconditionFixture::$scopeModules);
        self::assertNotEmpty(CrossScopePostconditionFixture::$scopeModules);
        self::assertSame(
            ['webadmin'],
            array_values(array_unique(CrossScopePreconditionFixture::$scopeModules))
        );
        self::assertSame(
            ['webadmin'],
            array_values(array_unique(CrossScopePostconditionFixture::$scopeModules))
        );

        $row = $pdo->query(
            "SELECT module_id, migration_id, scope_hash FROM ls_module_migrations "
                . "WHERE module_id = 'blog'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('blog', $row['module_id'] ?? null);
        self::assertSame('0001_extend_webadmin', $row['migration_id'] ?? null);
        self::assertSame(
            $scopes->get('webadmin')?->hash(),
            $row['scope_hash'] ?? null
        );

        $postflight = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $catalog,
            $scopes
        );
        self::assertTrue($postflight->isApplicable());
        self::assertSame(['applied', 'applied'], array_column(
            $postflight->entries(),
            'status'
        ));

        $pdo->exec('DROP TABLE "ls_shared_blog_access"');
        $drift = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $catalog,
            $scopes
        );
        self::assertSame(
            ['migration.postcondition_drift'],
            array_column($drift->blockers(), 'code')
        );
        self::assertSame(
            'postcondition_drift',
            $drift->entries()[1]['status']
        );
    }

    public function testMissingEffectiveTargetScopeBlocksWithoutMutation(): void
    {
        $this->writeManifest('webadmin');
        $this->writeManifest(
            'blog',
            ['webadmin'],
            [CrossScopeSimpleBlogMigrationProviderFixture::class]
        );
        $this->writeComposer(['blog']);
        $pdo = $this->sqlite();
        $catalog = $this->catalog();
        $scopes = MigrationScopeCollection::fromTablePrefixes([
            'blog' => 'ls_blog_',
        ]);

        $plan = (new MigrationDatabasePlanner())->plan($pdo, $catalog, $scopes);

        self::assertFalse($plan->isApplicable());
        self::assertSame('scope_missing', $plan->entries()[0]['status']);
        self::assertSame('webadmin', $plan->entries()[0]['target_scope_module']);
        self::assertNull($plan->entries()[0]['scope_hash']);
        self::assertSame(
            ['migration.scope_missing'],
            array_column($plan->blockers(), 'code')
        );

        try {
            (new MigrationRunner())->apply($pdo, $catalog, $scopes);
            self::fail('A missing target scope must block apply.');
        } catch (MigrationException $exception) {
            self::assertSame('migration.scope_missing', $exception->issueCode());
            self::assertSame('blog', $exception->moduleId());
            self::assertSame('0002_shared_blog', $exception->migrationId());
        }

        self::assertSame(0, $this->tableCount($pdo, MigrationRegistry::TABLE));
        self::assertSame(0, $this->tableCount($pdo, 'ls_blog_shared_blog'));
    }

    public function testEffectiveScopePrefixAndTargetDeclarationBothDetectDrift(): void
    {
        $this->writeManifest('webadmin');
        $this->writeManifest('content', ['webadmin']);
        $this->writeManifest(
            'blog',
            ['content'],
            [CrossScopeSimpleBlogMigrationProviderFixture::class]
        );
        $this->writeComposer(['blog']);
        $pdo = $this->sqlite();
        $catalog = $this->catalog();
        $originalScopes = MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'ls_shared_',
            'content' => 'ls_content_',
        ]);

        (new MigrationRunner())->apply($pdo, $catalog, $originalScopes);

        $prefixDrift = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $catalog,
            MigrationScopeCollection::fromTablePrefixes([
                'webadmin' => 'ls_changed_',
                'content' => 'ls_content_',
            ])
        );
        self::assertSame('scope_mismatch', $prefixDrift->entries()[0]['status']);
        self::assertSame(
            ['migration.scope_mismatch'],
            array_column($prefixDrift->blockers(), 'code')
        );

        CrossScopeSimpleBlogMigrationProviderFixture::$target = 'content';
        $targetDrift = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $this->catalog(),
            $originalScopes
        );
        self::assertSame(
            'content',
            $targetDrift->entries()[0]['target_scope_module']
        );
        self::assertSame(
            'checksum_mismatch',
            $targetDrift->entries()[0]['status']
        );
        self::assertSame(
            ['migration.checksum_mismatch'],
            array_column($targetDrift->blockers(), 'code')
        );
    }

    public function testOutOfOrderRemainsBoundToOwningModuleNotSharedTarget(): void
    {
        $this->writeManifest('webadmin');
        $this->writeManifest(
            'blog',
            ['webadmin'],
            [CrossScopeOutOfOrderBlogMigrationProviderFixture::class]
        );
        $this->writeComposer(['blog']);
        $pdo = $this->sqlite();
        $scopes = MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'ls_shared_',
        ]);

        (new MigrationRunner())->apply($pdo, $this->catalog(), $scopes);
        CrossScopeOutOfOrderBlogMigrationProviderFixture::$includeEarlier = true;

        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $this->catalog(),
            $scopes
        );

        self::assertSame(
            ['0001_inserted_late', '0002_already_applied'],
            array_column($plan->entries(), 'id')
        );
        self::assertSame(
            ['blog', 'blog'],
            array_column($plan->entries(), 'module')
        );
        self::assertSame(
            ['webadmin', 'webadmin'],
            array_column($plan->entries(), 'target_scope_module')
        );
        self::assertSame(
            ['out_of_order', 'applied'],
            array_column($plan->entries(), 'status')
        );
        self::assertSame(
            ['migration.out_of_order'],
            array_column($plan->blockers(), 'code')
        );

        try {
            (new MigrationRunner())->apply($pdo, $this->catalog(), $scopes);
            self::fail('An out-of-order cross-scope migration must be rejected.');
        } catch (MigrationException $exception) {
            self::assertSame('migration.out_of_order', $exception->issueCode());
            self::assertSame('blog', $exception->moduleId());
            self::assertSame('0001_inserted_late', $exception->migrationId());
        }

        self::assertSame(0, $this->tableCount($pdo, 'ls_shared_inserted_late'));
    }

    private function catalog(): MigrationCatalog
    {
        return MigrationCatalog::fromRegistry(ModuleRegistry::forProject(
            $this->projectRoot,
            $this->root
        ));
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    /** @param list<string> $requires */
    private function writeManifest(
        string $id,
        array $requires = [],
        array $migrationProviders = []
    ): void {
        $directory = $this->root . '/modules/' . $id;
        $this->filesystem->mkdir($directory);
        $this->filesystem->dumpFile(
            $directory . '/module.json',
            json_encode([
                'schema' => 1,
                'id' => $id,
                'package' => 'liquidstack/' . $id,
                'requires' => $requires,
                'providers' => $migrationProviders === []
                    ? []
                    : ['migrations' => $migrationProviders],
                'project_files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }

    /** @param list<string> $moduleIds */
    private function writeComposer(array $moduleIds): void
    {
        $requirements = [];
        foreach ($moduleIds as $moduleId) {
            $requirements['liquidstack/' . $moduleId] = '*';
        }
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode(
                ['require' => $requirements],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }

    private function tableCount(PDO $pdo, string $table): int
    {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $statement->execute(['name' => $table]);

        return (int) $statement->fetchColumn();
    }
}
