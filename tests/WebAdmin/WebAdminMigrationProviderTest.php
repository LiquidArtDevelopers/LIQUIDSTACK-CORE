<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\Modules\WebAdmin\WebAdminMediaMigrationPostconditionVerifier;
use App\Core\Modules\WebAdmin\WebAdminMigrationPostconditionVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WebAdminMigrationProviderTest extends TestCase
{
    private const TABLE_SUFFIXES = [
        'users',
        'credentials',
        'roles',
        'capabilities',
        'role_capabilities',
        'user_roles',
        'user_capabilities',
        'action_tokens',
        'sessions',
        'rate_limits',
        'audit_log',
        'outbox',
        'state',
    ];

    private string $projectRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-webadmin-migration-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '^1.9',
                    'liquidstack/webadmin' => '*',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testManifestRegistersTheBaseAndMediaMigrations(): void
    {
        $entries = $this->catalog()->entries();

        self::assertCount(2, $entries);
        $migrationsById = [];
        foreach ($entries as $entry) {
            self::assertSame('webadmin', $entry['module']);
            self::assertSame(WebAdminMigrationProvider::class, $entry['provider']);
            $migrationsById[$entry['migration']->id()] = $entry['migration'];
        }
        self::assertSame([
            '0001_webadmin_identity_and_access',
            '0002_webadmin_media_library',
        ], array_keys($migrationsById));

        $migration = $migrationsById['0001_webadmin_identity_and_access'];
        self::assertSame(
            '0001_webadmin_identity_and_access',
            $migration->id()
        );
        self::assertTrue($migration->isExecutableFor('mysql'));
        self::assertTrue($migration->isExecutableFor('sqlite'));
        self::assertFalse($migration->isTransactionalFor('mysql'));
        self::assertTrue($migration->isTransactionalFor('sqlite'));
        self::assertTrue($migration->isRetrySafe());
        self::assertFalse($migration->isDestructive());
        self::assertInstanceOf(
            WebAdminMigrationPostconditionVerifier::class,
            $migration->postconditionVerifier()
        );
        self::assertSame(
            'webadmin-initial-schema-v1',
            $migration->postconditionVerifier()?->contractVersion()
        );

        $media = $migrationsById['0002_webadmin_media_library'];
        self::assertSame('0002_webadmin_media_library', $media->id());
        self::assertTrue($media->isExecutableFor('mysql'));
        self::assertTrue($media->isExecutableFor('sqlite'));
        self::assertFalse($media->isTransactionalFor('mysql'));
        self::assertTrue($media->isTransactionalFor('sqlite'));
        self::assertTrue($media->isRetrySafe());
        self::assertFalse($media->isDestructive());
        self::assertSame(
            ['0001_webadmin_identity_and_access'],
            $media->supersededPostconditionIds()
        );
        self::assertInstanceOf(
            WebAdminMediaMigrationPostconditionVerifier::class,
            $media->postconditionVerifier()
        );
        self::assertSame(
            'webadmin-media-schema-v1',
            $media->postconditionVerifier()?->contractVersion()
        );
    }

    public function testSQLiteApplyCreatesTheCompleteScopedSchemaAndSeeds(): void
    {
        $pdo = $this->sqlite();
        $result = (new MigrationRunner())->apply(
            $pdo,
            $this->catalog(),
            $this->scopes()
        );

        self::assertTrue($result->changed());
        self::assertSame(1, $result->batch());
        self::assertSame(
            [
                'webadmin:0001_webadmin_identity_and_access',
                'webadmin:0002_webadmin_media_library',
            ],
            array_map(
                static fn (array $entry): string =>
                    $entry['module'] . ':' . $entry['id'],
                $result->applied()
            )
        );

        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach (self::TABLE_SUFFIXES as $suffix) {
            self::assertContains('ls_webadmin_' . $suffix, $tables);
        }
        self::assertContains('ls_webadmin_media_assets', $tables);
        self::assertContains('ls_webadmin_media_variants', $tables);
        self::assertContains('ls_module_migrations', $tables);

        self::assertSame([
            [
                'code' => 'editor',
                'is_protected' => 0,
                'is_delegable' => 1,
            ],
            [
                'code' => 'site_admin',
                'is_protected' => 1,
                'is_delegable' => 0,
            ],
            [
                'code' => 'system_superadmin',
                'is_protected' => 1,
                'is_delegable' => 0,
            ],
        ], $pdo->query(
            'SELECT code, is_protected, is_delegable '
            . 'FROM ls_webadmin_roles ORDER BY code'
        )->fetchAll(PDO::FETCH_ASSOC));

        $capabilities = $pdo->query(
            'SELECT code, is_delegable FROM ls_webadmin_capabilities '
            . 'ORDER BY code'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([
            'webadmin.access' => 0,
            'webadmin.audit.view' => 0,
            'webadmin.media.upload' => 1,
            'webadmin.media.view' => 1,
            'webadmin.profile.manage_self' => 0,
            'webadmin.system.diagnose' => 0,
            'webadmin.users.capabilities.manage' => 0,
            'webadmin.users.invite' => 0,
            'webadmin.users.suspend' => 0,
            'webadmin.users.view' => 1,
        ], array_map('intval', $capabilities));

        self::assertSame([
            'editor' => [
                'webadmin.access',
                'webadmin.profile.manage_self',
            ],
            'site_admin' => [
                'webadmin.access',
                'webadmin.audit.view',
                'webadmin.media.upload',
                'webadmin.media.view',
                'webadmin.profile.manage_self',
                'webadmin.users.capabilities.manage',
                'webadmin.users.invite',
                'webadmin.users.suspend',
                'webadmin.users.view',
            ],
            'system_superadmin' => [
                'webadmin.access',
                'webadmin.audit.view',
                'webadmin.media.upload',
                'webadmin.media.view',
                'webadmin.profile.manage_self',
                'webadmin.system.diagnose',
                'webadmin.users.capabilities.manage',
                'webadmin.users.invite',
                'webadmin.users.suspend',
                'webadmin.users.view',
            ],
        ], $this->roleCapabilities($pdo));
        self::assertSame('pending', $pdo->query(
            "SELECT value_text FROM ls_webadmin_state "
            . "WHERE state_key = 'bootstrap.initial_accounts'"
        )->fetchColumn());
        self::assertSame('v1', $pdo->query(
            "SELECT value_text FROM ls_webadmin_state "
            . "WHERE state_key = 'media.quota_lock'"
        )->fetchColumn());

        $createdAt = $pdo->query(
            "SELECT created_at FROM ls_webadmin_roles WHERE code = 'editor'"
        )->fetchColumn();
        self::assertIsString($createdAt);
        self::assertMatchesRegularExpression(
            '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/',
            $createdAt
        );
    }

    public function testSQLiteConstraintsProtectIdentitySessionsAndTokens(): void
    {
        $pdo = $this->sqliteWithSchema();
        $userId = $this->insertUser($pdo);

        $this->expectDatabaseFailure(static function () use ($pdo): void {
            $pdo->exec(
                "INSERT INTO ls_webadmin_users "
                . "(public_id, email_canonical, status) VALUES "
                . "('22222222-2222-4222-8222-222222222222', "
                . "'ADMIN@EXAMPLE.TEST', 'active')"
            );
        });
        $this->expectDatabaseFailure(static function () use ($pdo): void {
            $pdo->exec(
                "INSERT INTO ls_webadmin_users "
                . "(public_id, email_canonical, status) VALUES "
                . "('33333333-3333-4333-8333-333333333333', "
                . "'admin@example.test', 'active')"
            );
        });
        $this->expectDatabaseFailure(static function () use ($pdo): void {
            $pdo->exec(
                "INSERT INTO ls_webadmin_users "
                . "(public_id, email_canonical, status) VALUES "
                . "('44444444-4444-4444-8444-444444444444', "
                . "'other@example.test', 'deleted')"
            );
        });
        $this->expectDatabaseFailure(static function () use ($pdo): void {
            $pdo->exec(
                'INSERT INTO ls_webadmin_credentials (user_id) VALUES (99999)'
            );
        });
        $this->expectDatabaseFailure(static function () use ($pdo, $userId): void {
            $pdo->exec(
                "INSERT INTO ls_webadmin_credentials "
                . "(user_id, password_hash) VALUES ({$userId}, 'hash')"
            );
        });

        $pdo->exec(
            "INSERT INTO ls_webadmin_sessions "
            . "(public_id, session_type, token_hash, csrf_token_hash, "
            . "idle_expires_at, absolute_expires_at) VALUES ("
            . "'55555555-5555-4555-8555-555555555555', 'preauth', '"
            . str_repeat('a', 64) . "', '" . str_repeat('b', 64) . "', "
            . "'2099-01-01 00:00:00.000000', "
            . "'2099-01-01 01:00:00.000000')"
        );
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_sessions "
            . "WHERE session_type = 'preauth' AND user_id IS NULL"
        )->fetchColumn());

        $pdo->exec(
            "INSERT INTO ls_webadmin_sessions "
            . "(public_id, user_id, session_type, token_hash, "
            . "csrf_token_hash, auth_version, idle_expires_at, "
            . "absolute_expires_at) VALUES ("
            . "'77777777-7777-4777-8777-777777777777', {$userId}, "
            . "'authenticated', '" . str_repeat('f', 64) . "', '"
            . str_repeat('0', 64) . "', 1, "
            . "'2099-01-01 00:00:00.000000', "
            . "'2099-01-01 01:00:00.000000')"
        );
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_sessions "
            . "WHERE session_type = 'authenticated' "
            . "AND user_id IS NOT NULL AND auth_version = 1"
        )->fetchColumn());

        $this->expectDatabaseFailure(static function () use ($pdo): void {
            $pdo->exec(
                "INSERT INTO ls_webadmin_sessions "
                . "(public_id, session_type, token_hash, csrf_token_hash, "
                . "idle_expires_at, absolute_expires_at) VALUES ("
                . "'66666666-6666-4666-8666-666666666666', "
                . "'authenticated', '" . str_repeat('c', 64) . "', '"
                . str_repeat('d', 64) . "', "
                . "'2099-01-01 00:00:00.000000', "
                . "'2099-01-01 01:00:00.000000')"
            );
        });

        $pdo->exec(
            "INSERT INTO ls_webadmin_action_tokens "
            . "(user_id, purpose, token_hash, auth_version, expires_at) "
            . "VALUES ({$userId}, 'invite', '" . str_repeat('e', 64)
            . "', 1, '2099-01-01 00:00:00.000000')"
        );
        $this->expectDatabaseFailure(static function () use ($pdo, $userId): void {
            $pdo->exec(
                "INSERT INTO ls_webadmin_action_tokens "
                . "(user_id, purpose, token_hash, auth_version, expires_at) "
                . "VALUES ({$userId}, 'password_reset', '"
                . str_repeat('e', 64)
                . "', 1, '2099-01-01 00:00:00.000000')"
            );
        });
    }

    public function testAuditSchemaHasNoCredentialOrTokenColumns(): void
    {
        $pdo = $this->sqliteWithSchema();
        $columns = array_column(
            $pdo->query(
                'PRAGMA table_info("ls_webadmin_audit_log")'
            )->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );

        self::assertSame([
            'id',
            'request_id',
            'actor_user_id',
            'actor_session_public_id',
            'event_code',
            'outcome',
            'reason_code',
            'target_type',
            'target_public_id',
            'metadata_json',
            'ip_hash',
            'user_agent_hash',
            'occurred_at',
        ], $columns);
        foreach ($columns as $column) {
            self::assertStringNotContainsString('email', $column);
            self::assertStringNotContainsString('password', $column);
            self::assertStringNotContainsString('token', $column);
        }
    }

    public function testRunnerAndDeclarativeSqlAreIdempotent(): void
    {
        $pdo = $this->sqlite();
        $catalog = $this->catalog();
        $runner = new MigrationRunner();

        $first = $runner->apply($pdo, $catalog, $this->scopes());
        $second = $runner->apply($pdo, $catalog, $this->scopes());
        self::assertTrue($first->changed());
        self::assertFalse($second->changed());

        $pdo->exec(
            "UPDATE ls_webadmin_state SET value_text = 'completed' "
            . "WHERE state_key = 'bootstrap.initial_accounts'"
        );
        $pdo->exec(
            "UPDATE ls_webadmin_roles SET label_key = 'corrupt', "
            . "is_protected = 0, is_delegable = 1 "
            . "WHERE code = 'site_admin'"
        );
        $pdo->exec(
            "UPDATE ls_webadmin_capabilities SET module_id = 'other', "
            . "label_key = 'corrupt', is_delegable = 0 "
            . "WHERE code = 'webadmin.users.view'"
        );
        $pdo->exec(
            'DELETE FROM ls_webadmin_role_capabilities '
            . 'WHERE role_id = (SELECT id FROM ls_webadmin_roles '
            . "WHERE code = 'site_admin') "
            . 'AND capability_id = (SELECT id FROM '
            . "ls_webadmin_capabilities WHERE code = 'webadmin.users.view')"
        );

        $migration = $this->migration(
            '0001_webadmin_identity_and_access'
        );
        foreach ($migration->statementsFor(
            'sqlite',
            $this->scopes()->get('webadmin')
        ) as $statement) {
            $pdo->exec($statement);
        }

        self::assertSame(3, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_roles'
        )->fetchColumn());
        self::assertSame(10, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_capabilities'
        )->fetchColumn());
        self::assertSame(21, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_role_capabilities'
        )->fetchColumn());
        self::assertSame([
            'label_key' => 'webadmin.roles.site_admin',
            'is_protected' => 1,
            'is_delegable' => 0,
        ], $pdo->query(
            'SELECT label_key, is_protected, is_delegable '
            . "FROM ls_webadmin_roles WHERE code = 'site_admin'"
        )->fetch(PDO::FETCH_ASSOC));
        self::assertSame([
            'module_id' => 'webadmin',
            'label_key' => 'webadmin.capabilities.users_view',
            'is_delegable' => 1,
        ], $pdo->query(
            'SELECT module_id, label_key, is_delegable '
            . 'FROM ls_webadmin_capabilities '
            . "WHERE code = 'webadmin.users.view'"
        )->fetch(PDO::FETCH_ASSOC));
        self::assertSame(2, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_state'
        )->fetchColumn());
        self::assertSame('completed', $pdo->query(
            "SELECT value_text FROM ls_webadmin_state "
            . "WHERE state_key = 'bootstrap.initial_accounts'"
        )->fetchColumn(), 'Retry-safe seeds must never reset bootstrap state.');
        self::assertSame(2, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_module_migrations'
        )->fetchColumn());
    }

    public function testCustomScopeNeverCreatesDefaultPhysicalTables(): void
    {
        $pdo = $this->sqlite();
        $scopes = MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'client_admin_',
        ]);

        (new MigrationRunner())->apply(
            $pdo,
            $this->catalog(),
            $scopes
        );

        foreach (self::TABLE_SUFFIXES as $suffix) {
            self::assertSame(1, $this->tableCount(
                $pdo,
                'client_admin_' . $suffix
            ));
            self::assertSame(0, $this->tableCount(
                $pdo,
                'ls_webadmin_' . $suffix
            ));
        }
    }

    public function testMySqlContractIsRetrySafeAndMariaDbCompatible(): void
    {
        $migration = $this->migration(
            '0001_webadmin_identity_and_access'
        );
        $scope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        $statements = $migration->statementsFor('mysql', $scope);
        $sql = implode("\n", $statements);

        self::assertFalse($migration->isTransactionalFor('mysql'));
        self::assertTrue($migration->isRetrySafe());
        self::assertStringNotContainsString('{{', $sql);
        self::assertStringContainsString('DATETIME(6)', $sql);
        self::assertStringContainsString('CURRENT_TIMESTAMP(6)', $sql);
        self::assertStringContainsString('ENGINE=InnoDB', $sql);
        self::assertStringContainsString(
            'CHARACTER SET ascii COLLATE ascii_bin',
            $sql
        );
        self::assertStringContainsString(
            'ON DUPLICATE KEY UPDATE',
            $sql
        );
        self::assertStringNotContainsString('INSERT IGNORE INTO', $sql);
        self::assertStringNotContainsString('INSERT OR IGNORE', $sql);
        self::assertStringNotContainsString('ON CONFLICT', $sql);
        self::assertStringNotContainsString('RETURNING ', $sql);
        self::assertStringNotContainsString('DEFERRABLE', $sql);
        self::assertStringNotContainsString('WITHOUT ROWID', $sql);
        self::assertStringNotContainsString('CREATE TYPE', $sql);
        self::assertStringNotContainsString('CONSTRAINT `fk_wa_', $sql);
        self::assertStringNotContainsString('CONSTRAINT `chk_wa_', $sql);
        self::assertStringContainsString(
            'CONSTRAINT `ls_webadmin_f_se_user` FOREIGN KEY (`user_id`)',
            $sql
        );
        self::assertMatchesRegularExpression(
            '/`ls_webadmin_f_se_user`[\s\S]*?ON DELETE RESTRICT/',
            $sql
        );
        self::assertSame(1, preg_match(
            '/CONSTRAINT `ls_webadmin_c_se_identity` CHECK \((.*?)\),\s*'
                . 'CONSTRAINT `ls_webadmin_c_se_expiry`/s',
            $sql,
            $identity
        ));
        self::assertStringContainsString('`user_id` IS NULL', $identity[1]);
        self::assertStringContainsString('`user_id` IS NOT NULL', $identity[1]);
        self::assertStringNotContainsString(
            '`pending_action_token_id`',
            $identity[1]
        );

        foreach ($statements as $statement) {
            self::assertMatchesRegularExpression(
                '/\A(?:CREATE TABLE IF NOT EXISTS|INSERT INTO)\b/',
                ltrim($statement),
                'Every non-transactional statement must be retry-safe.'
            );
            if (str_starts_with(ltrim($statement), 'INSERT INTO')) {
                self::assertStringContainsString(
                    'ON DUPLICATE KEY UPDATE',
                    $statement
                );
            }
        }
        foreach (self::TABLE_SUFFIXES as $suffix) {
            self::assertStringContainsString(
                'CREATE TABLE IF NOT EXISTS `ls_webadmin_' . $suffix . '`',
                $sql
            );
        }


        $sqliteSql = implode("\n", $migration->statementsFor(
            'sqlite',
            $scope
        ));
        self::assertStringNotContainsString('"idx_wa_', $sqliteSql);
        self::assertStringContainsString(
            'CREATE INDEX IF NOT EXISTS "ls_webadmin_ix_us_status"',
            $sqliteSql
        );
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

    private function migration(string $id): MigrationDefinition
    {
        foreach ($this->catalog()->entries() as $entry) {
            if ($entry['migration']->id() === $id) {
                return $entry['migration'];
            }
        }

        self::fail('Missing WebAdmin migration: ' . $id);
    }

    private function scopes(): MigrationScopeCollection
    {
        return MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'ls_webadmin_',
        ]);
    }

    /** @return array<string, list<string>> */
    private function roleCapabilities(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT r.code AS role_code, c.code AS capability_code '
            . 'FROM ls_webadmin_role_capabilities rc '
            . 'JOIN ls_webadmin_roles r ON r.id = rc.role_id '
            . 'JOIN ls_webadmin_capabilities c ON c.id = rc.capability_id '
            . 'ORDER BY r.code, c.code'
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['role_code']][] = $row['capability_code'];
        }

        return $result;
    }

    private function insertUser(PDO $pdo): int
    {
        $pdo->exec(
            "INSERT INTO ls_webadmin_users "
            . "(public_id, email_canonical, status, activated_at) VALUES "
            . "('11111111-1111-4111-8111-111111111111', "
            . "'admin@example.test', 'active', "
            . "'2026-08-01 00:00:00.000000')"
        );

        return (int) $pdo->lastInsertId();
    }

    /** @param callable(): void $operation */
    private function expectDatabaseFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('The database constraint should reject the operation.');
        } catch (PDOException) {
            self::addToAssertionCount(1);
        }
    }

    private function tableCount(PDO $pdo, string $name): int
    {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $statement->execute(['name' => $name]);

        return (int) $statement->fetchColumn();
    }
}
