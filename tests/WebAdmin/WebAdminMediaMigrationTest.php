<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\WebAdmin\WebAdminHttpSchemaGate;
use App\Core\Modules\WebAdmin\WebAdminMediaHttpSchemaGate;
use App\Core\Modules\WebAdmin\WebAdminMediaMigrationPostconditionVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WebAdminMediaForeignKeyStatement extends PDOStatement
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchAll(
        int $mode = PDO::FETCH_DEFAULT,
        mixed ...$args
    ): array {
        return $this->rows;
    }
}

final class WebAdminMediaForeignKeyPdo extends PDO
{
    public ?string $preparedSql = null;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        $this->preparedSql = $query;

        return new WebAdminMediaForeignKeyStatement($this->rows);
    }
}

final class WebAdminMediaMigrationTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario para estas pruebas.');
        }
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-webadmin-media-migration-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '^1.9',
                    'liquidstack/webadmin' => '*',
                ],
            ], JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testCombinedPostconditionRejectsMissingAndAlteredQuotaMutex(): void
    {
        $pdo = $this->sqliteWithSchema();
        $verifier = $this->mediaVerifier();

        self::assertTrue($verifier->verify($pdo, $this->scope()));

        $pdo->exec(
            "DELETE FROM ls_webadmin_state WHERE state_key = 'media.quota_lock'"
        );
        self::assertContains(
            'webadmin.media.seeds_invalid',
            $verifier->issueCodes($pdo, $this->scope())
        );

        $pdo->exec(
            "INSERT INTO ls_webadmin_state (state_key, value_text) "
            . "VALUES ('media.quota_lock', 'drifted')"
        );
        self::assertContains(
            'webadmin.media.seeds_invalid',
            $verifier->issueCodes($pdo, $this->scope())
        );
    }

    public function testMySqlAndMariaDbCheckClauseCanonicalizationIsAccepted(): void
    {
        $scope = $this->scope();
        $rows = [
            [$scope->tableName('c_ma_bytes'), '`source_bytes` between 1 and 12582912'],
            [$scope->tableName('c_ma_dims'), '`source_width` between 1 and 12000 and `source_height` between 1 and 12000 and `source_width` * `source_height` <= 40000000'],
            [$scope->tableName('c_ma_hash'), 'char_length(`source_sha256`) = 64'],
            [$scope->tableName('c_ma_label'), 'char_length(`label`) between 1 and 120'],
            [$scope->tableName('c_ma_mime'), "`source_mime` in ('image/jpeg','image/png','image/webp')"],
            [$scope->tableName('c_ma_public'), 'char_length(`public_id`) = 36'],
            [$scope->tableName('c_mv_bytes'), '`bytes` > 0'],
            [$scope->tableName('c_mv_dims'), '`width` between 1 and 2560 and `height` between 1 and 2560'],
            [$scope->tableName('c_mv_hash'), 'char_length(`sha256`) = 64'],
            [$scope->tableName('c_mv_mime'), "`mime` = 'image/avif'"],
            [$scope->tableName('c_mv_storage'), 'char_length(`storage_key`) between 1 and 255'],
        ];
        $metadata = array_map(
            static fn (array $row): array => [
                'CONSTRAINT_NAME' => $row[0],
                'CHECK_CLAUSE' => $row[1],
            ],
            $rows
        );
        $method = new ReflectionMethod(
            WebAdminMediaMigrationPostconditionVerifier::class,
            'validateMySqlCheckRows'
        );

        self::assertTrue($method->invoke(
            $this->mediaVerifier(),
            $scope,
            $metadata
        ));

        $mySqlMetadata = $metadata;
        $mySqlMetadata[4]['CHECK_CLAUSE'] = str_replace(
            "'image/",
            "_utf8mb4'image/",
            (string) $mySqlMetadata[4]['CHECK_CLAUSE']
        );
        $mySqlMetadata[9]['CHECK_CLAUSE'] = str_replace(
            "'image/",
            "_utf8mb4'image/",
            (string) $mySqlMetadata[9]['CHECK_CLAUSE']
        );
        self::assertTrue($method->invoke(
            $this->mediaVerifier(),
            $scope,
            $mySqlMetadata
        ));

        $metadata[1]['CHECK_CLAUSE'] = str_replace(
            '40000000',
            '40000001',
            (string) $metadata[1]['CHECK_CLAUSE']
        );
        self::assertFalse($method->invoke(
            $this->mediaVerifier(),
            $scope,
            $metadata
        ));
    }

    public function testMySqlDefaultGeneratedExtraIsTimestampOnly(): void
    {
        $method = new ReflectionMethod(
            WebAdminMediaMigrationPostconditionVerifier::class,
            'validMySqlExtra'
        );
        $verifier = $this->mediaVerifier();

        self::assertTrue($method->invoke(
            $verifier,
            'created_at',
            'DEFAULT_GENERATED',
            ''
        ));
        self::assertTrue($method->invoke(
            $verifier,
            'id',
            'auto_increment',
            'auto_increment'
        ));
        self::assertFalse($method->invoke(
            $verifier,
            'label',
            'DEFAULT_GENERATED',
            ''
        ));
        self::assertFalse($method->invoke(
            $verifier,
            'created_at',
            'DEFAULT_GENERATED on update CURRENT_TIMESTAMP',
            ''
        ));
    }

    public function testMySqlForeignKeyMetadataQueryQualifiesJoinedColumns(): void
    {
        $scope = $this->scope();
        $pdo = new WebAdminMediaForeignKeyPdo([
            [
                'TABLE_NAME' => $scope->tableName('media_assets'),
                'COLUMN_NAME' => 'created_by_user_id',
                'REFERENCED_TABLE_NAME' => $scope->tableName('users'),
                'DELETE_RULE' => 'RESTRICT',
            ],
            [
                'TABLE_NAME' => $scope->tableName('media_variants'),
                'COLUMN_NAME' => 'asset_id',
                'REFERENCED_TABLE_NAME' => $scope->tableName('media_assets'),
                'DELETE_RULE' => 'CASCADE',
            ],
        ]);
        $method = new ReflectionMethod(
            WebAdminMediaMigrationPostconditionVerifier::class,
            'foreignKeysAreValid'
        );

        self::assertTrue($method->invoke(
            $this->mediaVerifier(),
            $pdo,
            $scope,
            'mysql'
        ));
        self::assertNotNull($pdo->preparedSql);
        self::assertStringContainsString(
            'SELECT k.TABLE_NAME, k.COLUMN_NAME, '
            . 'k.REFERENCED_TABLE_NAME, r.DELETE_RULE',
            $pdo->preparedSql
        );
    }

    public function testCombinedPostconditionRejectsPrimaryKeyDrift(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec(
            'ALTER TABLE ls_webadmin_media_assets RENAME TO '
            . 'ls_webadmin_media_assets_original'
        );
        $pdo->exec(
            'CREATE TABLE ls_webadmin_media_assets ('
            . 'id INTEGER NOT NULL,'
            . 'public_id TEXT NOT NULL,'
            . 'label TEXT NOT NULL,'
            . 'source_mime TEXT NOT NULL,'
            . 'source_width INTEGER NOT NULL,'
            . 'source_height INTEGER NOT NULL,'
            . 'source_bytes INTEGER NOT NULL,'
            . 'source_sha256 TEXT NOT NULL,'
            . 'created_by_user_id INTEGER NOT NULL,'
            . "created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f', 'now'))"
            . ')'
        );

        self::assertContains(
            'webadmin.media.schema_invalid',
            $this->mediaVerifier()->issueCodes($pdo, $this->scope())
        );
    }

    public function testBaseWebAdminRemainsReadyUntilMediaMigrationIsApplied(): void
    {
        $pdo = $this->sqlite();
        $catalog = $this->catalog();
        $entries = $catalog->entries();
        $baseMigration = null;
        foreach ($entries as $entry) {
            if (
                $entry['migration']->id()
                === '0001_webadmin_identity_and_access'
            ) {
                $baseMigration = $entry['migration'];
                break;
            }
        }
        self::assertNotNull($baseMigration);
        $scope = $this->scope();
        $registry = new MigrationRegistry();

        foreach ($baseMigration->statementsFor('sqlite', $scope) as $sql) {
            $pdo->exec($sql);
        }
        $registry->ensureExists($pdo);
        $registry->record(
            $pdo,
            'webadmin',
            $baseMigration,
            $scope,
            1,
            new DateTimeImmutable('2026-08-02 00:00:00', new DateTimeZone('UTC'))
        );

        self::assertTrue((new WebAdminHttpSchemaGate())->isReady(
            $pdo,
            $this->registry(),
            $scope
        ));
        self::assertFalse((new WebAdminMediaHttpSchemaGate())->isReady(
            $pdo,
            $this->registry(),
            $scope
        ));

        foreach ($entries[1]['migration']->statementsFor('sqlite', $scope) as $sql) {
            $pdo->exec($sql);
        }
        $registry->record(
            $pdo,
            'webadmin',
            $entries[1]['migration'],
            $scope,
            2,
            new DateTimeImmutable('2026-08-02 00:01:00', new DateTimeZone('UTC'))
        );

        self::assertTrue((new WebAdminMediaHttpSchemaGate())->isReady(
            $pdo,
            $this->registry(),
            $scope
        ));
        $pdo->exec(
            "UPDATE ls_webadmin_state SET value_text = 'drifted' "
            . "WHERE state_key = 'media.quota_lock'"
        );
        self::assertFalse((new WebAdminMediaHttpSchemaGate())->isReady(
            $pdo,
            $this->registry(),
            $scope
        ));
    }

    private function mediaVerifier(): WebAdminMediaMigrationPostconditionVerifier
    {
        $mediaMigration = null;
        foreach ($this->catalog()->entries() as $entry) {
            if ($entry['migration']->id() === '0002_webadmin_media_library') {
                $mediaMigration = $entry['migration'];
                break;
            }
        }
        self::assertNotNull($mediaMigration);
        $verifier = $mediaMigration->postconditionVerifier();
        self::assertInstanceOf(
            WebAdminMediaMigrationPostconditionVerifier::class,
            $verifier
        );

        return $verifier;
    }

    private function sqliteWithSchema(): PDO
    {
        $pdo = $this->sqlite();
        (new MigrationRunner())->apply($pdo, $this->catalog(), $this->scopes());

        return $pdo;
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function registry(): ModuleRegistry
    {
        return ModuleRegistry::forProject($this->projectRoot, dirname(__DIR__, 2));
    }

    private function catalog(): MigrationCatalog
    {
        return MigrationCatalog::fromRegistry($this->registry());
    }

    private function scope(): MigrationScope
    {
        return MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_');
    }

    private function scopes(): MigrationScopeCollection
    {
        return MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'ls_webadmin_',
        ]);
    }
}
