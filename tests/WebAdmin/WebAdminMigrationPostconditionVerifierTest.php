<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationException;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\WebAdmin\WebAdminInitialSchemaContract;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\Modules\WebAdmin\WebAdminMigrationPostconditionVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WebAdminMigrationPostconditionVerifierTest extends TestCase
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
            . '/liquidstack-webadmin-verifier-' . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '^1.9',
                    'liquidstack/webadmin' => '*',
                ],
            ], JSON_THROW_ON_ERROR)
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testCanonicalSQLiteSchemaAndCustomScopeVerify(): void
    {
        foreach (['ls_webadmin_', 'customer_admin_'] as $prefix) {
            $pdo = $this->sqlite();
            $scope = MigrationScope::forTablePrefix('webadmin', $prefix);
            $scopes = MigrationScopeCollection::fromTablePrefixes([
                'webadmin' => $prefix,
            ]);
            (new MigrationRunner())->apply($pdo, $this->catalog(), $scopes);
            $changesBeforeVerification = (int) $pdo->query(
                'SELECT total_changes()'
            )->fetchColumn();

            self::assertTrue(
                (new WebAdminMigrationPostconditionVerifier())->verify(
                    $pdo,
                    $scope
                ),
                'El contrato debe ser independiente del prefijo físico.'
            );
            self::assertTrue(
                (new MigrationDatabasePlanner())->plan(
                    $pdo,
                    $this->catalog(),
                    $scopes
                )->isApplicable()
            );
            self::assertSame(
                $changesBeforeVerification,
                (int) $pdo->query('SELECT total_changes()')->fetchColumn(),
                'La verificación y el planner deben ser estrictamente read-only.'
            );
        }
    }

    public function testViewCollisionFailsAndRollsBackWithoutRegistry(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE VIEW ls_webadmin_credentials AS SELECT 1 AS user_id');

        $this->assertFailedApplyRollsBack($pdo);
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master "
            . "WHERE type = 'view' AND name = 'ls_webadmin_credentials'"
        )->fetchColumn());
    }

    public function testWeakPreexistingTableFailsAndRollsBackWithoutRegistry(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec(
            'CREATE TABLE ls_webadmin_credentials ('
            . 'user_id INTEGER, password_hash TEXT)'
        );

        $this->assertFailedApplyRollsBack($pdo);
        self::assertSame(
            ['user_id', 'password_hash'],
            $pdo->query(
                'PRAGMA table_info("ls_webadmin_credentials")'
            )->fetchAll(PDO::FETCH_COLUMN, 1)
        );
    }

    public function testSQLiteCheckLiteralCaseCannotMasqueradeAsCanonical(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec(<<<'SQL'
CREATE TABLE ls_webadmin_users (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("public_id") = 36),
    "email_canonical" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK ("email_canonical" = lower("email_canonical")),
    "display_name" TEXT NULL,
    "status" TEXT COLLATE BINARY NOT NULL CHECK ("status" IN ('INVITED', 'ACTIVE', 'SUSPENDED')),
    "auth_version" INTEGER NOT NULL DEFAULT 1 CHECK ("auth_version" > 0),
    "created_by_user_id" INTEGER NULL REFERENCES ls_webadmin_users ("id") ON DELETE SET NULL,
    "invited_at" TEXT NULL,
    "activated_at" TEXT NULL,
    "suspended_at" TEXT NULL,
    "last_login_at" TEXT NULL,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL);

        $this->assertFailedApplyRollsBack($pdo, 1);
        self::assertSame(1, $this->tableCount($pdo, 'ls_webadmin_users'));
    }

    public function testGlobalSQLiteIndexNameCollisionCannotProduceFalseSuccess(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE collision_owner (status TEXT)');
        $pdo->exec(
            'CREATE INDEX ls_webadmin_ix_us_status ON collision_owner (status)'
        );

        $this->assertFailedApplyRollsBack($pdo);
        self::assertSame('collision_owner', $pdo->query(
            "SELECT tbl_name FROM sqlite_master "
            . "WHERE type = 'index' AND name = 'ls_webadmin_ix_us_status'"
        )->fetchColumn());
    }

    public function testAppliedSQLiteDriftDetectsColumnTypePrimaryForeignKeyCheckAndIndex(): void
    {
        $mutations = [
            'missing column' => static fn (PDO $pdo): int => $pdo->exec(
                'CREATE TABLE ls_webadmin_credentials_replacement ('
                . 'user_id INTEGER PRIMARY KEY REFERENCES ls_webadmin_users(id) '
                . 'ON DELETE CASCADE, password_hash TEXT COLLATE BINARY NULL, '
                . "created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')), "
                . "updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')), "
                . 'CHECK (password_hash IS NULL))'
            ),
            'wrong type' => static fn (PDO $pdo): int => $pdo->exec(
                self::credentialsReplacement('user_id TEXT PRIMARY KEY')
            ),
            'missing primary key' => static fn (PDO $pdo): int => $pdo->exec(
                self::credentialsReplacement('user_id INTEGER')
            ),
            'missing foreign key' => static fn (PDO $pdo): int => $pdo->exec(
                self::credentialsReplacement('user_id INTEGER PRIMARY KEY', false)
            ),
            'missing check' => static fn (PDO $pdo): int => $pdo->exec(
                self::credentialsReplacement(
                    'user_id INTEGER PRIMARY KEY',
                    true,
                    false
                )
            ),
        ];

        foreach ($mutations as $label => $createReplacement) {
            $pdo = $this->sqliteWithSchema();
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec('DROP TABLE ls_webadmin_credentials');
            $createReplacement($pdo);
            $pdo->exec(
                'ALTER TABLE ls_webadmin_credentials_replacement '
                . 'RENAME TO ls_webadmin_credentials'
            );
            $pdo->exec('PRAGMA foreign_keys = ON');
            $this->assertDrift($pdo, $label);
        }

        $pdo = $this->sqliteWithSchema();
        $pdo->exec('DROP INDEX ls_webadmin_ix_se_expiry');
        $this->assertDrift($pdo, 'missing index');

        $pdo = $this->sqliteWithSchema();
        $pdo->exec('DROP INDEX ls_webadmin_ix_us_status');
        $pdo->exec(
            'CREATE INDEX ls_webadmin_ix_us_status ON ls_webadmin_users '
            . '(status COLLATE NOCASE DESC)'
        );
        $this->assertDrift($pdo, 'index collation and direction');
    }

    public function testAppliedSQLiteDriftDetectsEveryCanonicalSeedFamily(): void
    {
        $mutations = [
            'role' => "UPDATE ls_webadmin_roles SET label_key = 'changed' "
                . "WHERE code = 'site_admin'",
            'capability' => "UPDATE ls_webadmin_capabilities "
                . "SET is_delegable = 0 WHERE code = 'webadmin.users.view'",
            'relationship' => 'DELETE FROM ls_webadmin_role_capabilities '
                . 'WHERE role_id = (SELECT id FROM ls_webadmin_roles '
                . "WHERE code = 'editor') AND capability_id = (SELECT id "
                . 'FROM ls_webadmin_capabilities '
                . "WHERE code = 'webadmin.access')",
            'unauthorized canonical relationship' =>
                'INSERT INTO ls_webadmin_role_capabilities '
                . '(role_id, capability_id) SELECT r.id, c.id '
                . 'FROM ls_webadmin_roles r CROSS JOIN '
                . 'ls_webadmin_capabilities c '
                . "WHERE r.code = 'editor' "
                . "AND c.code = 'webadmin.system.diagnose'",
            'state' => "UPDATE ls_webadmin_state SET value_text = 'invalid' "
                . "WHERE state_key = 'bootstrap.initial_accounts'",
        ];

        foreach ($mutations as $label => $sql) {
            $pdo = $this->sqliteWithSchema();
            $pdo->exec($sql);
            $this->assertDrift($pdo, $label);
        }
    }

    public function testSQLiteLiteralsCannotImpersonateChecksOrCollations(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('DROP TABLE ls_webadmin_credentials');
        $pdo->exec(
            'CREATE TABLE ls_webadmin_credentials ('
            . 'user_id INTEGER PRIMARY KEY REFERENCES ls_webadmin_users(id) '
            . 'ON DELETE CASCADE, password_hash TEXT COLLATE BINARY NULL, '
            . 'password_set_at TEXT NULL, '
            . "created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')), "
            . "updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')), "
            . 'spoof TEXT NULL CHECK ('
            . "'check((password_hash is null and password_set_at is null)"
            . "or(password_hash is not null and password_set_at is not null))' <> ''"
            . '))'
        );
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->assertDrift($pdo, 'literal CHECK spoof');

        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('DROP TABLE ls_webadmin_credentials');
        $pdo->exec(str_replace(
            'password_hash TEXT COLLATE BINARY',
            "password_hash TEXT CHECK ('password_hash text collate binary' <> '')",
            self::credentialsReplacement('user_id INTEGER PRIMARY KEY')
        ));
        $pdo->exec(
            'ALTER TABLE ls_webadmin_credentials_replacement '
            . 'RENAME TO ls_webadmin_credentials'
        );
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->assertDrift($pdo, 'literal COLLATE spoof');
    }

    public function testSQLiteIdentifiersCannotImpersonateColumnOrCheckClauses(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('DROP TABLE ls_webadmin_credentials');
        $pdo->exec(str_replace(
            'password_hash TEXT COLLATE BINARY NULL',
            'password_hash TEXT COLLATE NOCASE NULL '
                . 'CONSTRAINT "password_hashtextcollatebinary" CHECK (1)',
            self::credentialsReplacement('user_id INTEGER PRIMARY KEY')
        ));
        $pdo->exec(
            'ALTER TABLE ls_webadmin_credentials_replacement '
            . 'RENAME TO ls_webadmin_credentials'
        );
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->assertDrift($pdo, 'quoted COLLATE identifier spoof');

        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('DROP TABLE ls_webadmin_credentials');
        $withoutCheck = self::credentialsReplacement(
            'user_id INTEGER PRIMARY KEY',
            true,
            false
        );
        $pdo->exec(substr($withoutCheck, 0, -1)
            . ', [check((password_hash is null and password_set_at is null)'
            . 'or(password_hash is not null and password_set_at is not null))] '
            . 'TEXT NULL)');
        $pdo->exec(
            'ALTER TABLE ls_webadmin_credentials_replacement '
            . 'RENAME TO ls_webadmin_credentials'
        );
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->assertDrift($pdo, 'bracket CHECK identifier spoof');
    }

    public function testSQLiteConnectionCannotDisableCheckEnforcement(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA ignore_check_constraints = ON');

        $this->assertDrift($pdo, 'disabled CHECK enforcement');
    }

    public function testDirectSQLiteVerifierRequiresForeignKeyEnforcement(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA foreign_keys = OFF');

        self::assertFalse(
            (new WebAdminMigrationPostconditionVerifier())->verify(
                $pdo,
                $this->scope()
            )
        );
    }

    public function testSQLiteRejectsExtraWriteChangingSchemaObjects(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec(
            'CREATE UNIQUE INDEX extra_unique_status '
            . 'ON ls_webadmin_users (status)'
        );
        $this->assertDrift($pdo, 'extra unique index');

        $pdo = $this->sqliteWithSchema();
        $pdo->exec(
            'CREATE TRIGGER block_webadmin_user BEFORE INSERT '
            . 'ON ls_webadmin_users BEGIN '
            . "SELECT RAISE(ABORT, 'blocked'); END"
        );
        $this->assertDrift($pdo, 'extra trigger');

        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('DROP TABLE ls_webadmin_credentials');
        $replacement = self::credentialsReplacement(
            'user_id INTEGER PRIMARY KEY'
        );
        $pdo->exec(substr($replacement, 0, -1)
            . ', CHECK (password_hash IS NULL))');
        $pdo->exec(
            'ALTER TABLE ls_webadmin_credentials_replacement '
            . 'RENAME TO ls_webadmin_credentials'
        );
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->assertDrift($pdo, 'extra check');

        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('DROP TABLE ls_webadmin_credentials');
        $replacement = self::credentialsReplacement(
            'user_id INTEGER PRIMARY KEY'
        );
        $pdo->exec(str_replace(
            ', CHECK ((',
            ', future_user_id INTEGER NULL REFERENCES '
                . 'ls_webadmin_users(id) ON DELETE SET NULL, CHECK ((',
            $replacement
        ));
        $pdo->exec(
            'ALTER TABLE ls_webadmin_credentials_replacement '
            . 'RENAME TO ls_webadmin_credentials'
        );
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->assertDrift($pdo, 'extra foreign key');
    }

    public function testSQLiteAllowsFutureNonUniqueIndexes(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec(
            'CREATE INDEX future_user_lookup ON ls_webadmin_users '
            . '(status, display_name)'
        );

        self::assertTrue(
            (new WebAdminMigrationPostconditionVerifier())->verify(
                $pdo,
                $this->scope()
            )
        );
    }

    public function testSQLiteRejectsRowsInsertedWithEnforcementDisabled(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA ignore_check_constraints = ON');
        $pdo->exec(
            "INSERT INTO ls_webadmin_users "
            . '(public_id, email_canonical, status) VALUES '
            . "('11111111-1111-4111-8111-111111111111', "
            . "'invalid@example.test', 'invalid')"
        );
        $pdo->exec('PRAGMA ignore_check_constraints = OFF');
        $this->assertDrift($pdo, 'stored CHECK violation');

        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at) '
            . 'VALUES (999999, NULL, NULL)'
        );
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->assertDrift($pdo, 'stored foreign-key violation');
    }

    public function testSQLiteRejectsASecondEffectiveColumnCollation(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('DROP TABLE ls_webadmin_credentials');
        $pdo->exec(str_replace(
            'password_hash TEXT COLLATE BINARY NULL',
            'password_hash TEXT COLLATE BINARY COLLATE NOCASE NULL',
            self::credentialsReplacement('user_id INTEGER PRIMARY KEY')
        ));
        $pdo->exec(
            'ALTER TABLE ls_webadmin_credentials_replacement '
            . 'RENAME TO ls_webadmin_credentials'
        );
        $pdo->exec('PRAGMA foreign_keys = ON');

        $this->assertDrift($pdo, 'second effective collation');
    }

    public function testSQLiteQuotedIdentifiersCannotSpoofTableOptions(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec('DROP TABLE ls_webadmin_state');
        $pdo->exec(
            'CREATE TABLE ls_webadmin_state ('
            . 'state_key TEXT COLLATE BINARY NOT NULL PRIMARY KEY, '
            . 'value_text TEXT NOT NULL, '
            . "updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')), "
            . '")withoutrowid" TEXT NULL)'
        );

        $this->assertDrift($pdo, 'quoted WITHOUT ROWID spoof');
    }

    public function testTwoSQLiteScopesCanCoexistWithoutAuxiliaryNameCollision(): void
    {
        $pdo = $this->sqlite();
        $migration = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        $first = MigrationScope::forTablePrefix('webadmin', 'tenant_a_');
        $second = MigrationScope::forTablePrefix('webadmin', 'tenant_b_');
        foreach ([$first, $second] as $scope) {
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                $pdo->exec($sql);
            }
        }
        $verifier = new WebAdminMigrationPostconditionVerifier();

        self::assertTrue($verifier->verify($pdo, $first));
        self::assertTrue($verifier->verify($pdo, $second));
    }

    public function testFutureUsersCapabilitiesEdgesAndStateRowsAreAllowed(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec(
            'ALTER TABLE ls_webadmin_users ADD COLUMN future_note TEXT NULL'
        );
        $pdo->exec(
            "INSERT INTO ls_webadmin_users "
            . '(public_id, email_canonical, status) VALUES '
            . "('11111111-1111-4111-8111-111111111111', "
            . "'future@example.test', 'invited')"
        );
        $pdo->exec(
            "INSERT INTO ls_webadmin_capabilities "
            . '(module_id, code, label_key, is_delegable) VALUES '
            . "('blog', 'blog.posts.publish', 'blog.capabilities.publish', 1)"
        );
        $pdo->exec(
            'INSERT INTO ls_webadmin_role_capabilities '
            . '(role_id, capability_id) SELECT r.id, c.id '
            . 'FROM ls_webadmin_roles r CROSS JOIN ls_webadmin_capabilities c '
            . "WHERE r.code = 'editor' AND c.code = 'blog.posts.publish'"
        );
        $pdo->exec(
            "UPDATE ls_webadmin_state SET value_text = 'completed' "
            . "WHERE state_key = 'bootstrap.initial_accounts'"
        );
        $pdo->exec(
            "INSERT INTO ls_webadmin_state (state_key, value_text) "
            . "VALUES ('future.setting', 'enabled')"
        );

        self::assertTrue(
            (new WebAdminMigrationPostconditionVerifier())->verify(
                $pdo,
                $this->scope()
            )
        );
        self::assertTrue((new MigrationDatabasePlanner())->plan(
            $pdo,
            $this->catalog(),
            $this->scopes()
        )->isApplicable());
    }

    public function testCanonicalMySqlAndMariaDbMetadataFixturesValidate(): void
    {
        $verifier = new WebAdminMigrationPostconditionVerifier();
        $mysql = $this->mysqlFixture('8.0.36');
        $mariaDb = $this->mysqlFixture('10.11.8-MariaDB');

        self::assertTrue($verifier->validateMetadata('mysql', $mysql));
        self::assertTrue($verifier->validateMetadata('mysql', $mariaDb));
        self::assertTrue($verifier->validateMetadata(
            'mysql',
            $this->mysqlFixture('5.5.5-10.11.8-MariaDB')
        ));
        self::assertFalse($verifier->validateMetadata(
            'mysql',
            $this->mysqlFixture('5.7.44')
        ));
        self::assertFalse($verifier->validateMetadata(
            'mysql',
            $this->mysqlFixture('10.1.48-MariaDB')
        ));
        self::assertTrue($verifier->validateMetadata(
            'mysql',
            $this->mysqlFixture('10.4.32-MariaDB')
        ));
        $mariaDbParentheses = $this->mysqlFixture('10.4.32-MariaDB');
        $mariaDbParentheses['checks']['credentials'][
            'chk_wa_credentials_password'
        ] = 'password_hash is null and password_set_at is null '
            . 'or password_hash is not null and password_set_at is not null';
        self::assertTrue($verifier->validateMetadata(
            'mysql',
            $mariaDbParentheses
        ));
        self::assertFalse($verifier->validateMetadata(
            'mysql',
            $this->mysqlFixture(
                '10.3.27-MariaDB-0ubuntu0.20.04.2'
            )
        ));

        $extended = $mysql;
        $extended['columns']['users'][] = [
            'name' => 'future_note',
            'type' => 'varchar',
            'nullable' => true,
            'unsigned' => false,
            'length' => 255,
            'datetime_precision' => null,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'default' => null,
            'extra' => '',
        ];
        $extended['indexes']['users']['future_note_lookup'] = [
            'unique' => false,
            'columns' => ['future_note'],
            'directions' => ['A'],
            'collations' => [null],
            'partial' => false,
            'type' => 'BTREE',
            'visible' => true,
        ];
        self::assertTrue($verifier->validateMetadata('mysql', $extended));
    }

    public function testMySqlMetadataFixtureRejectsStructuralAndSeedDrift(): void
    {
        $verifier = new WebAdminMigrationPostconditionVerifier();
        $fixture = $this->mysqlFixture('8.0.36');
        $variants = [];

        $variant = $fixture;
        $variant['tables']['credentials']['kind'] = 'VIEW';
        $variants['view collision'] = $variant;
        $variant = $fixture;
        $variant['tables']['users']['engine'] = 'MYISAM';
        $variants['engine'] = $variant;
        $variant = $fixture;
        $variant['columns']['users'][1]['length'] = 35;
        $variants['length'] = $variant;
        $variant = $fixture;
        $variant['columns']['users'][1]['type'] = 'varchar';
        $variants['type'] = $variant;
        $variant = $fixture;
        $variant['columns']['users'][1]['nullable'] = true;
        $variants['nullability'] = $variant;
        $variant = $fixture;
        $variant['columns']['users'][2]['collation'] = 'ascii_general_ci';
        $variants['ascii collation'] = $variant;
        $variant = $fixture;
        $variant['columns']['users'][7]['datetime_precision'] = 0;
        $variants['datetime precision'] = $variant;
        $variant = $fixture;
        unset($variant['indexes']['credentials']['PRIMARY']);
        $variants['primary key'] = $variant;
        $variant = $fixture;
        unset($variant['indexes']['sessions']['idx_wa_sessions_expiry']);
        $variants['index'] = $variant;
        $variant = $fixture;
        $variant['indexes']['sessions']['idx_wa_sessions_expiry']['visible'] =
            false;
        $variants['invisible index'] = $variant;
        $variant = $fixture;
        $variant['indexes']['sessions']['idx_wa_sessions_expiry'][
            'directions'
        ] = ['D'];
        $variants['descending index'] = $variant;
        $variant = $fixture;
        $variant['indexes']['users']['extra_unique_status'] = [
            'unique' => true,
            'columns' => ['status'],
            'directions' => ['A'],
            'collations' => [null],
            'partial' => false,
            'type' => 'BTREE',
            'visible' => true,
        ];
        $variants['extra unique index'] = $variant;
        $variant = $fixture;
        array_shift($variant['foreign_keys']['credentials']);
        $variants['foreign key'] = $variant;
        $variant = $fixture;
        $variant['foreign_keys']['credentials'][0]['match'] = 'FULL';
        $variants['foreign key match'] = $variant;
        $variant = $fixture;
        $variant['foreign_keys']['credentials'][0][
            'target_schema_local'
        ] = false;
        $variants['cross-schema foreign key'] = $variant;
        $variant = $fixture;
        $variant['foreign_keys']['credentials'][] =
            $variant['foreign_keys']['credentials'][0];
        $variants['extra foreign key'] = $variant;
        $variant = $fixture;
        unset($variant['checks']['sessions']['chk_wa_sessions_identity']);
        $variants['check missing'] = $variant;
        $variant = $fixture;
        $variant['checks']['sessions']['chk_wa_sessions_identity'] = '1=1';
        $variants['check altered'] = $variant;
        $variant = $fixture;
        $variant['checks']['credentials']['chk_wa_credentials_password'] =
            'password_hash is null and ('
            . 'password_set_at is null or password_hash is not null) '
            . 'and password_set_at is not null';
        $variants['check boolean precedence altered'] = $variant;
        $variant = $fixture;
        $variant['checks']['users']['chk_wa_users_status'] =
            "status in ('INVITED','ACTIVE','SUSPENDED')";
        $variants['case-sensitive check literal'] = $variant;
        $variant = $fixture;
        $variant['check_enforcement']['sessions'][
            'chk_wa_sessions_identity'
        ] = false;
        $variants['check not enforced'] = $variant;
        $variant = $fixture;
        $variant['checks']['users']['extra_check'] = "status='invited'";
        $variant['check_enforcement']['users']['extra_check'] = true;
        $variants['extra check'] = $variant;
        $variant = $fixture;
        $variant['check_runtime_enabled'] = false;
        $variants['check runtime disabled'] = $variant;
        $variant = $fixture;
        $variant['foreign_keys_enforced'] = false;
        $variants['foreign keys disabled'] = $variant;
        $variant = $fixture;
        $variant['unique_checks_enforced'] = false;
        $variants['unique checks disabled'] = $variant;
        $variant = $fixture;
        $variant['strict_sql_mode'] = false;
        $variants['non-strict SQL mode'] = $variant;
        $variant = $fixture;
        $variant['data_integrity'] = false;
        $variants['stored data drift'] = $variant;
        $variant = $fixture;
        $variant['triggers']['users'][] = 'dangerous_trigger';
        $variants['trigger'] = $variant;
        $variant = $fixture;
        $variant['columns']['users'][0]['extra'] =
            'auto_increment invisible';
        $variants['invisible column'] = $variant;
        $variant = $fixture;
        $variant['columns']['users'][12]['extra'] =
            'default_generated on update CURRENT_TIMESTAMP(6)';
        $variants['on-update column'] = $variant;
        $variant = $fixture;
        $variant['columns']['users'][3]['default'] = "'NULL'";
        $variants['quoted null string default'] = $variant;
        $variant = $fixture;
        $variant['columns']['users'][3]['default'] = 'CURRENT_TIMESTAMP(6)';
        $variants['unexpected expression default'] = $variant;
        $variant = $fixture;
        $variant['columns']['users'][11]['default'] =
            "'current_timestamp(6)'";
        $variants['timestamp literal cannot impersonate expression'] =
            $variant;
        $variant = $fixture;
        $variant['seeds']['roles'][0]['label_key'] = 'changed';
        $variants['seed'] = $variant;
        $variant = $fixture;
        $variant['seeds']['roles'][] = $variant['seeds']['roles'][0];
        $variants['duplicate canonical seed'] = $variant;
        $variant = $fixture;
        $variant['seeds']['roles'][0]['is_protected'] = '1junk';
        $variants['malformed protected flag'] = $variant;
        $variant = $fixture;
        $variant['seeds']['capabilities'][0]['is_delegable'] = 'junk';
        $variants['malformed delegable flag'] = $variant;
        $variant = $fixture;
        $variant['seeds']['role_capabilities'][] =
            $variant['seeds']['role_capabilities'][0];
        $variants['duplicate canonical relationship'] = $variant;
        $variant = $fixture;
        unset($variant['seeds']['bootstrap_state']);
        $variant['seeds']['bootstrap_state_values'] = [
            'pending',
            'completed',
        ];
        $variants['duplicate bootstrap state'] = $variant;

        foreach ($variants as $label => $variant) {
            self::assertFalse(
                $verifier->validateMetadata('mysql', $variant),
                $label
            );
        }
    }

    public function testMySqlAuxiliaryNamesAreValidatedSemantically(): void
    {
        $fixture = $this->mysqlFixture('8.0.36');
        $fixture['indexes']['users']['ls_webadmin_ix_us_status'] =
            $fixture['indexes']['users']['idx_wa_users_status'];
        unset($fixture['indexes']['users']['idx_wa_users_status']);
        $expression = $fixture['checks']['users']['chk_wa_users_status'];
        $enforced = $fixture['check_enforcement']['users'][
            'chk_wa_users_status'
        ];
        unset(
            $fixture['checks']['users']['chk_wa_users_status'],
            $fixture['check_enforcement']['users']['chk_wa_users_status']
        );
        $fixture['checks']['users']['ls_webadmin_c_us_status'] = $expression;
        $fixture['check_enforcement']['users'][
            'ls_webadmin_c_us_status'
        ] = $enforced;
        $fixture['foreign_keys']['users'][0]['name'] =
            'ls_webadmin_f_us_creator';

        self::assertTrue(
            (new WebAdminMigrationPostconditionVerifier())->validateMetadata(
                'mysql',
                $fixture
            )
        );
    }

    private function assertFailedApplyRollsBack(
        PDO $pdo,
        int $expectedUsers = 0
    ): void
    {
        try {
            (new MigrationRunner())->apply(
                $pdo,
                $this->catalog(),
                $this->scopes()
            );
            self::fail('El objeto incompatible debía romper la precondición.');
        } catch (MigrationException $exception) {
            self::assertSame(
                'migration.precondition_failed',
                $exception->issueCode()
            );
            self::assertSame(
                'La operación de migraciones no pudo completarse de forma segura.',
                $exception->getMessage()
            );
        }

        self::assertSame(0, $this->tableCount($pdo, MigrationRegistry::TABLE));
        self::assertSame(
            $expectedUsers,
            $this->tableCount($pdo, 'ls_webadmin_users')
        );
    }

    private function assertDrift(PDO $pdo, string $label): void
    {
        $before = $this->schemaSnapshot($pdo);
        $plan = (new MigrationDatabasePlanner())->plan(
            $pdo,
            $this->catalog(),
            $this->scopes()
        );

        self::assertContains(
            'migration.postcondition_drift',
            array_column($plan->blockers(), 'code'),
            $label
        );
        self::assertSame($before, $this->schemaSnapshot($pdo), $label);
    }

    private static function credentialsReplacement(
        string $userIdDefinition,
        bool $foreignKey = true,
        bool $check = true
    ): string {
        $userId = $userIdDefinition;
        if ($foreignKey) {
            $userId .= ' REFERENCES ls_webadmin_users(id) ON DELETE CASCADE';
        }
        $constraint = $check
            ? ', CHECK ((password_hash IS NULL AND password_set_at IS NULL) '
                . 'OR (password_hash IS NOT NULL AND password_set_at IS NOT NULL))'
            : '';

        return 'CREATE TABLE ls_webadmin_credentials_replacement ('
            . $userId . ', password_hash TEXT COLLATE BINARY NULL, '
            . 'password_set_at TEXT NULL, '
            . "created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')), "
            . "updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))"
            . $constraint . ')';
    }

    /** @return array<string, mixed> */
    private function mysqlFixture(string $serverVersion): array
    {
        $metadata = [
            'server_version' => $serverVersion,
            'check_runtime_enabled' => true,
            'foreign_keys_enforced' => true,
            'unique_checks_enforced' => true,
            'strict_sql_mode' => true,
            'data_integrity' => true,
            'tables' => [],
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => [],
            'checks' => WebAdminInitialSchemaContract::mysqlChecks(),
            'check_enforcement' => [],
            'triggers' => [],
            'seeds' => [
                'roles' => [],
                'capabilities' => [],
                'role_capabilities' => [],
                'bootstrap_state' => 'pending',
            ],
        ];
        foreach (WebAdminInitialSchemaContract::tableSuffixes() as $suffix) {
            $metadata['tables'][$suffix] = [
                'kind' => 'BASE TABLE',
                'engine' => 'INNODB',
                'collation' => 'utf8mb4_unicode_ci',
                'sql' => null,
            ];
            $metadata['columns'][$suffix] = [];
            foreach (WebAdminInitialSchemaContract::mysqlColumns()[$suffix] as $column) {
                $metadata['columns'][$suffix][] = [
                    'name' => $column['name'],
                    'type' => $column['type'],
                    'nullable' => $column['nullable'],
                    'unsigned' => $column['unsigned'] ?? false,
                    'length' => $column['length'] ?? null,
                    'datetime_precision' => $column['datetime_precision'] ?? null,
                    'charset' => $column['charset'] ?? null,
                    'collation' => $column['collation'] ?? null,
                    'default' => $column['default'] ?? null,
                    'extra' => $column['extra'] ?? '',
                ];
            }
            $metadata['indexes'][$suffix] = [
                'PRIMARY' => [
                    'unique' => true,
                    'columns' => WebAdminInitialSchemaContract::primaryKeys()[$suffix],
                    'directions' => array_fill(
                        0,
                        count(WebAdminInitialSchemaContract::primaryKeys()[$suffix]),
                        'A'
                    ),
                    'collations' => array_fill(
                        0,
                        count(WebAdminInitialSchemaContract::primaryKeys()[$suffix]),
                        null
                    ),
                    'partial' => false,
                    'type' => 'BTREE',
                    'visible' => true,
                ],
            ];
            foreach (WebAdminInitialSchemaContract::indexes()[$suffix] as $name => $index) {
                $metadata['indexes'][$suffix][$name] = $index + [
                    'directions' => array_fill(
                        0,
                        count($index['columns']),
                        'A'
                    ),
                    'collations' => array_fill(
                        0,
                        count($index['columns']),
                        null
                    ),
                    'partial' => false,
                    'type' => 'BTREE',
                    'visible' => true,
                ];
            }
            $metadata['foreign_keys'][$suffix] = array_map(
                static fn (array $foreignKey): array => $foreignKey + [
                    'target_schema_local' => true,
                ],
                WebAdminInitialSchemaContract::foreignKeys()[$suffix]
            );
            $metadata['triggers'][$suffix] = [];
            $metadata['check_enforcement'][$suffix] = array_fill_keys(
                array_keys(
                    WebAdminInitialSchemaContract::mysqlChecks()[$suffix]
                ),
                true
            );
        }
        foreach (WebAdminInitialSchemaContract::roles() as $code => $role) {
            $metadata['seeds']['roles'][] = ['code' => $code] + $role;
        }
        foreach (WebAdminInitialSchemaContract::capabilities() as $code => $capability) {
            $metadata['seeds']['capabilities'][] = ['code' => $code] + $capability;
        }
        foreach (WebAdminInitialSchemaContract::roleCapabilities() as $role => $codes) {
            foreach ($codes as $code) {
                $metadata['seeds']['role_capabilities'][] = [
                    'role_code' => $role,
                    'capability_code' => $code,
                ];
            }
        }

        return $metadata;
    }

    private function sqliteWithSchema(): PDO
    {
        $pdo = $this->sqlite();
        (new MigrationRunner())->apply(
            $pdo,
            $this->catalog(),
            $this->scopes()
        );

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

    private function catalog(): MigrationCatalog
    {
        return MigrationCatalog::fromRegistry(ModuleRegistry::forProject(
            $this->projectRoot,
            dirname(__DIR__, 2)
        ));
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

    private function tableCount(PDO $pdo, string $name): int
    {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $statement->execute(['name' => $name]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, ?string> */
    private function schemaSnapshot(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT type || ':' || name, sql FROM sqlite_master "
            . "WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
