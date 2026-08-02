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
    /** @var array<string, mixed> */
    private array $parameters = [];

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->parameters = $params ?? [];

        return true;
    }

    public function fetchAll(
        int $mode = PDO::FETCH_DEFAULT,
        mixed ...$args
    ): array {
        $table = $this->parameters['table'] ?? null;
        if (!is_string($table)) {
            return $this->rows;
        }

        return array_values(array_filter(
            $this->rows,
            static fn (array $row): bool =>
                ($row['TABLE_NAME'] ?? null) === $table
        ));
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

final class WebAdminMediaReadOnlyStatement extends PDOStatement
{
    public function fetchColumn(int $column = 0): mixed
    {
        return false;
    }
}

final class WebAdminMediaReadOnlyPdo extends PDO
{
    /** @var list<string> */
    public array $queries = [];

    public function __construct()
    {
    }

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        $this->queries[] = $query;

        return new WebAdminMediaReadOnlyStatement();
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
                'ENFORCED' => 'YES',
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

        $notEnforced = $metadata;
        $notEnforced[0]['ENFORCED'] = 'NO';
        self::assertFalse($method->invoke(
            $this->mediaVerifier(),
            $scope,
            $notEnforced
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

    public function testMySqlColumnsRequireExactPortableTypesAndTimestamp(): void
    {
        $method = new ReflectionMethod(
            WebAdminMediaMigrationPostconditionVerifier::class,
            'validMySqlColumns'
        );
        $verifier = $this->mediaVerifier();
        $mySql = $this->mysqlAssetColumnRows(false);
        $mariaDb = $this->mysqlAssetColumnRows(true);

        self::assertTrue($method->invoke(
            $verifier,
            'media_assets',
            $mySql
        ));
        self::assertTrue($method->invoke(
            $verifier,
            'media_assets',
            $mariaDb
        ));

        foreach ([
            [0, 'COLUMN_TYPE', 'bigint unsigned zerofill'],
            [4, 'COLUMN_TYPE', 'int(11) unsigned'],
            [4, 'COLUMN_TYPE', 'int unsigned zerofill'],
            [9, 'COLUMN_TYPE', 'datetime'],
            [9, 'COLUMN_DEFAULT', 'CURRENT_TIMESTAMP'],
            [9, 'COLUMN_DEFAULT', 'CURRENT_TIMESTAMP(3)'],
            [9, 'COLUMN_DEFAULT', 'CURRENT_TIMESTAMP(6) + INTERVAL 0 SECOND'],
            [9, 'DATETIME_PRECISION', 3],
        ] as [$rowNumber, $field, $value]) {
            $drifted = $mySql;
            $drifted[$rowNumber][$field] = $value;
            self::assertFalse($method->invoke(
                $verifier,
                'media_assets',
                $drifted
            ), $field . '=' . (string) $value);
        }

        $mySql[9]['COLUMN_DEFAULT'] = 'current_timestamp(6)';
        self::assertTrue($method->invoke(
            $verifier,
            'media_assets',
            $mySql
        ));
    }

    public function testMySqlStoredLabelLengthCountsCharactersNotBytes(): void
    {
        $pdo = new WebAdminMediaReadOnlyPdo();
        $method = new ReflectionMethod(
            WebAdminMediaMigrationPostconditionVerifier::class,
            'storedRowsAreValid'
        );

        self::assertTrue($method->invoke(
            $this->mediaVerifier(),
            $pdo,
            $this->scope(),
            'mysql'
        ));
        $queries = implode("\n", $pdo->queries);
        self::assertStringContainsString(
            'CHAR_LENGTH(TRIM(label)) < 1',
            $queries
        );
        self::assertStringContainsString(
            'CHAR_LENGTH(label) > 120',
            $queries
        );
    }

    public function testSqliteChecksAreExactAndCommentsCannotSpoofThem(): void
    {
        $pdo = $this->sqliteWithSchema();
        $sql = $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' "
            . "AND name = 'ls_webadmin_media_assets'"
        )->fetchColumn();
        self::assertIsString($sql);
        $method = new ReflectionMethod(
            WebAdminMediaMigrationPostconditionVerifier::class,
            'validSqliteTableSql'
        );
        $verifier = $this->mediaVerifier();

        self::assertTrue($method->invoke(
            $verifier,
            'media_assets',
            $sql
        ));

        $extraCheck = preg_replace(
            '/\)\s*\z/',
            ', CHECK ("source_width" > 0))',
            $sql,
            1
        );
        self::assertIsString($extraCheck);
        self::assertFalse($method->invoke(
            $verifier,
            'media_assets',
            $extraCheck
        ));
        self::assertFalse($method->invoke(
            $verifier,
            'media_assets',
            $sql . ' /* CHECK ("source_width" > 0) */'
        ));
        self::assertFalse($method->invoke(
            $verifier,
            'media_assets',
            str_replace("'image/webp'", "'image/WEBP'", $sql)
        ));

        $extract = new ReflectionMethod(
            WebAdminMediaMigrationPostconditionVerifier::class,
            'sqliteCheckExpressions'
        );
        self::assertSame(
            ["value='literal/*not-a-comment*/'"],
            $extract->invoke(
                $verifier,
                "CREATE TABLE sample (value TEXT CHECK (value = "
                    . "'literal/*not-a-comment*/'))"
            )
        );
    }

    public function testMySqlForeignKeyMetadataQueryQualifiesJoinedColumns(): void
    {
        $scope = $this->scope();
        $rows = [
            [
                'TABLE_NAME' => $scope->tableName('media_assets'),
                'CONSTRAINT_NAME' => 'fk_media_author_original',
                'COLUMN_NAME' => 'created_by_user_id',
                'REFERENCED_TABLE_NAME' => $scope->tableName('users'),
                'REFERENCED_COLUMN_NAME' => 'id',
                'ORDINAL_POSITION' => 1,
                'SAME_SCHEMA' => 1,
                'UPDATE_RULE' => 'RESTRICT',
                'DELETE_RULE' => 'RESTRICT',
            ],
            [
                'TABLE_NAME' => $scope->tableName('media_variants'),
                'CONSTRAINT_NAME' => 'fk_media_variant_original',
                'COLUMN_NAME' => 'asset_id',
                'REFERENCED_TABLE_NAME' => $scope->tableName('media_assets'),
                'REFERENCED_COLUMN_NAME' => 'id',
                'ORDINAL_POSITION' => 1,
                'SAME_SCHEMA' => 1,
                'UPDATE_RULE' => 'RESTRICT',
                'DELETE_RULE' => 'CASCADE',
            ],
        ];
        $pdo = new WebAdminMediaForeignKeyPdo($rows);
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
            'k.REFERENCED_TABLE_SCHEMA = DATABASE() AS SAME_SCHEMA',
            $pdo->preparedSql
        );
        self::assertStringContainsString(
            'r.UPDATE_RULE, r.DELETE_RULE',
            $pdo->preparedSql
        );

        $renamed = $rows;
        $renamed[0]['CONSTRAINT_NAME'] = 'fk_structurally_equivalent_author';
        $renamed[1]['CONSTRAINT_NAME'] = 'fk_structurally_equivalent_asset';
        self::assertTrue($method->invoke(
            $this->mediaVerifier(),
            new WebAdminMediaForeignKeyPdo($renamed),
            $scope,
            'mysql'
        ), 'Foreign-key names are not part of the published contract.');

        foreach ([
            ['SAME_SCHEMA', 0],
            ['UPDATE_RULE', 'CASCADE'],
            ['DELETE_RULE', 'SET NULL'],
            ['ORDINAL_POSITION', 2],
        ] as [$field, $value]) {
            $drifted = $rows;
            $drifted[0][$field] = $value;
            self::assertFalse($method->invoke(
                $this->mediaVerifier(),
                new WebAdminMediaForeignKeyPdo($drifted),
                $scope,
                'mysql'
            ), (string) $field);
        }
    }

    public function testMySqlIndexesRequireExactBtreeMetadata(): void
    {
        $method = new ReflectionMethod(
            WebAdminMediaMigrationPostconditionVerifier::class,
            'mysqlIndexSignaturesFromRows'
        );
        $rows = [
            $this->mysqlIndexRow('PRIMARY', 0, 1, 'id'),
            $this->mysqlIndexRow(
                'uq_wa_media_assets_public',
                0,
                1,
                'public_id'
            ),
            $this->mysqlIndexRow(
                'idx_wa_media_assets_created',
                1,
                1,
                'created_at'
            ),
            $this->mysqlIndexRow(
                'idx_wa_media_assets_created',
                1,
                2,
                'id'
            ),
            $this->mysqlIndexRow(
                'idx_wa_media_assets_author',
                1,
                1,
                'created_by_user_id'
            ),
        ];
        $expected = [
            'P:id',
            'U:public_id',
            'N:created_at,id',
            'N:created_by_user_id',
        ];

        self::assertSame($expected, $method->invoke(
            $this->mediaVerifier(),
            $rows
        ));
        $renamed = $rows;
        $renamed[1]['INDEX_NAME'] = 'uq_equivalent_public_id';
        $renamed[2]['INDEX_NAME'] = 'ix_equivalent_created';
        $renamed[3]['INDEX_NAME'] = 'ix_equivalent_created';
        $renamed[4]['INDEX_NAME'] = 'ix_equivalent_author';
        self::assertSame($expected, $method->invoke(
            $this->mediaVerifier(),
            $renamed
        ), 'Index names are not part of the published contract.');
        self::assertSame([], $method->invoke(
            $this->mediaVerifier(),
            []
        ));

        foreach ([
            [0, 'INDEX_TYPE', 'HASH'],
            [1, 'SUB_PART', 12],
            [2, 'COLLATION', 'D'],
            [3, 'IGNORED', 'YES'],
            [4, 'SEQ_IN_INDEX', 2],
        ] as [$rowNumber, $field, $value]) {
            $drifted = $rows;
            $drifted[$rowNumber][$field] = $value;
            self::assertSame(['invalid'], $method->invoke(
                $this->mediaVerifier(),
                $drifted
            ), (string) $field);
        }

        $wrongPrimary = $rows;
        $wrongPrimary[0]['COLUMN_NAME'] = 'public_id';
        self::assertNotSame($expected, $method->invoke(
            $this->mediaVerifier(),
            $wrongPrimary
        ));
    }

    public function testCombinedPostconditionRejectsDescendingSqliteIndex(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec('DROP INDEX "ls_webadmin_ix_ma_author"');
        $pdo->exec(
            'CREATE INDEX "ls_webadmin_ix_ma_author" '
            . 'ON "ls_webadmin_media_assets" ("created_by_user_id" DESC)'
        );

        self::assertContains(
            'webadmin.media.indexes_invalid',
            $this->mediaVerifier()->issueCodes($pdo, $this->scope())
        );
    }

    public function testCombinedPostconditionAcceptsRenamedStructuralSqliteIndex(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec('DROP INDEX "ls_webadmin_ix_ma_author"');
        $pdo->exec(
            'CREATE INDEX "project_owned_equivalent_author" '
            . 'ON "ls_webadmin_media_assets" ("created_by_user_id")'
        );

        self::assertNotContains(
            'webadmin.media.indexes_invalid',
            $this->mediaVerifier()->issueCodes($pdo, $this->scope())
        );
    }

    public function testCombinedPostconditionRejectsOrphanMediaRows(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec(
            "INSERT INTO ls_webadmin_media_assets ("
            . 'public_id, label, source_mime, source_width, source_height, '
            . 'source_bytes, source_sha256, created_by_user_id) VALUES ('
            . "'01234567-89ab-4cde-8f01-23456789abcd', 'Orphan', "
            . "'image/png', 800, 600, 1024, '"
            . str_repeat('a', 64) . "', 999999)"
        );
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::assertContains(
            'webadmin.media.data_integrity_invalid',
            $this->mediaVerifier()->issueCodes($pdo, $this->scope())
        );

        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec(
            "INSERT INTO ls_webadmin_media_variants ("
            . 'asset_id, width, height, bytes, sha256, storage_key, mime) '
            . "VALUES (999999, 800, 600, 512, '"
            . str_repeat('b', 64)
            . "', '01/01234567-89ab-4cde-8f01-23456789abcd/800.avif', "
            . "'image/avif')"
        );
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::assertContains(
            'webadmin.media.data_integrity_invalid',
            $this->mediaVerifier()->issueCodes($pdo, $this->scope())
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

    /** @return list<array<string, int|string|null>> */
    private function mysqlAssetColumnRows(bool $mariaDbDisplayWidths): array
    {
        $bigint = $mariaDbDisplayWidths
            ? 'bigint(20) unsigned'
            : 'bigint unsigned';
        $int = $mariaDbDisplayWidths ? 'int(10) unsigned' : 'int unsigned';

        return [
            $this->mysqlColumnRow(
                'id',
                'bigint',
                $bigint,
                null,
                null,
                null,
                'PRI',
                'auto_increment'
            ),
            $this->mysqlColumnRow(
                'public_id',
                'char',
                'char(36)',
                36,
                'ascii',
                'ascii_bin',
                'UNI'
            ),
            $this->mysqlColumnRow(
                'label',
                'varchar',
                'varchar(120)',
                120,
                'utf8mb4',
                'utf8mb4_unicode_ci'
            ),
            $this->mysqlColumnRow(
                'source_mime',
                'varchar',
                'varchar(32)',
                32,
                'ascii',
                'ascii_bin'
            ),
            $this->mysqlColumnRow('source_width', 'int', $int),
            $this->mysqlColumnRow('source_height', 'int', $int),
            $this->mysqlColumnRow('source_bytes', 'bigint', $bigint),
            $this->mysqlColumnRow(
                'source_sha256',
                'char',
                'char(64)',
                64,
                'ascii',
                'ascii_bin'
            ),
            $this->mysqlColumnRow(
                'created_by_user_id',
                'bigint',
                $bigint,
                null,
                null,
                null,
                'MUL'
            ),
            $this->mysqlColumnRow(
                'created_at',
                'datetime',
                'datetime(6)',
                null,
                null,
                null,
                'MUL',
                $mariaDbDisplayWidths ? '' : 'DEFAULT_GENERATED',
                $mariaDbDisplayWidths
                    ? 'current_timestamp(6)'
                    : 'CURRENT_TIMESTAMP(6)',
                6
            ),
        ];
    }

    /** @return array<string, int|string|null> */
    private function mysqlColumnRow(
        string $name,
        string $dataType,
        string $columnType,
        ?int $length = null,
        ?string $charset = null,
        ?string $collation = null,
        string $key = '',
        string $extra = '',
        ?string $default = null,
        ?int $datetimePrecision = null
    ): array {
        return [
            'COLUMN_NAME' => $name,
            'DATA_TYPE' => $dataType,
            'COLUMN_TYPE' => $columnType,
            'IS_NULLABLE' => 'NO',
            'COLUMN_DEFAULT' => $default,
            'CHARACTER_MAXIMUM_LENGTH' => $length,
            'DATETIME_PRECISION' => $datetimePrecision,
            'CHARACTER_SET_NAME' => $charset,
            'COLLATION_NAME' => $collation,
            'COLUMN_KEY' => $key,
            'EXTRA' => $extra,
        ];
    }

    /** @return array<string, int|string|null> */
    private function mysqlIndexRow(
        string $name,
        int $nonUnique,
        int $sequence,
        string $column
    ): array {
        return [
            'INDEX_NAME' => $name,
            'NON_UNIQUE' => $nonUnique,
            'SEQ_IN_INDEX' => $sequence,
            'COLUMN_NAME' => $column,
            'INDEX_TYPE' => 'BTREE',
            'SUB_PART' => null,
            'COLLATION' => 'A',
            'IGNORED' => 'NO',
        ];
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
