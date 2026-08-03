<?php

declare(strict_types=1);

use App\Core\Database\SharedPdoConnectionFactory;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationException;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\WebAdmin\WebAdminCanonicalSeedVerifier;
use App\Core\Modules\WebAdmin\WebAdminInitialSchemaContract;
use App\Core\Modules\WebAdmin\WebAdminHttpSchemaGate;
use App\Core\Modules\WebAdmin\WebAdminMigrationPostconditionVerifier;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Bootstrap\BootstrapResult;
use App\Core\WebAdmin\Bootstrap\WebAdminBootstrapService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\CredentialAction\CredentialActionRepository;
use App\Core\WebAdmin\CredentialAction\CredentialActionService;
use App\Core\WebAdmin\CredentialAction\PasswordResetDelivery;
use App\Core\WebAdmin\Mail\PasswordResetMailSenderInterface;
use App\Core\WebAdmin\Outbox\WebAdminOutboxRepository;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use App\Core\WebAdmin\UserManagement\ActiveModuleSet;
use App\Core\WebAdmin\UserManagement\EditorInviteResult;
use App\Core\WebAdmin\UserManagement\EditorMutationResult;
use App\Core\WebAdmin\UserManagement\UserManagementRepository;
use App\Core\WebAdmin\UserManagement\UserManagementService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Opt-in contract test against a disposable, pre-created MySQL/MariaDB DB.
 *
 * This file deliberately does not load .env. All process inputs use the
 * LIQUIDSTACK_TEST_MYSQL_* namespace and the database name guard runs before
 * PDO is constructed.
 */
#[Group('mysql-integration')]
final class WebAdminMySqlIntegrationTest extends TestCase
{
    private const OPT_IN_ENV = 'LIQUIDSTACK_TEST_MYSQL_INTEGRATION';
    private const TABLE_PREFIX = 'ls_webadmin_';
    private const SYSTEM_EMAIL = 'system-admin@example.test';
    private const SITE_EMAIL = 'site-admin@example.test';
    private const LOGIN_PASSWORD = 'LiquidStack integration password 2026!';
    private const RESET_PASSWORD =
        'LiquidStack integration replacement password 2026!';
    private const EDITOR_EMAIL = 'editor-integration@example.test';
    private const EDITOR_PASSWORD =
        'LiquidStack integration editor password 2026!';

    public function testRealMySqlWebAdminLifecycle(): void
    {
        if (getenv(self::OPT_IN_ENV) !== '1') {
            self::markTestSkipped(sprintf(
                'Opt-in MySQL/MariaDB test; set %s=1 explicitly.',
                self::OPT_IN_ENV
            ));
        }

        $configuration = WebAdminMySqlTestConfiguration::fromProcess();
        $previousTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $guardConnection = null;
        $connection = null;
        $guardLockName = null;
        $databaseAcceptedAsEmpty = false;
        $projectRoot = null;

        try {
            $guardConnection = $this->connect($configuration);
            $this->assertSelectedDatabase(
                $guardConnection,
                $configuration->database()
            );
            $guardLockName = $this->acquireGuardLock(
                $guardConnection,
                $configuration->database()
            );

            $connection = $this->connect($configuration);
            $this->assertSelectedDatabase(
                $connection,
                $configuration->database()
            );
            $this->assertDatabaseIsEmpty(
                $connection,
                $configuration->database()
            );
            $databaseAcceptedAsEmpty = true;

            $projectRoot = $this->createModuleProject();
            $registry = ModuleRegistry::forProject(
                $projectRoot,
                dirname(__DIR__, 2)
            );
            $catalog = MigrationCatalog::fromRegistry($registry);
            $scopes = MigrationScopeCollection::fromTablePrefixes([
                'webadmin' => self::TABLE_PREFIX,
            ]);
            $entries = $catalog->entries();
            self::assertNotEmpty($entries);

            $runner = new MigrationRunner();
            $this->assertForeignNamespaceCollisionIsNonMutating(
                $connection,
                $configuration->database(),
                $runner,
                $catalog,
                $scopes
            );
            try {
                $firstRun = $runner->apply(
                    $connection,
                    $catalog,
                    $scopes
                );
            } catch (MigrationException $exception) {
                if (
                    $exception->issueCode()
                    === 'migration.postcondition_failed'
                ) {
                    self::fail(sprintf(
                        'MySQL postcondition stage failed: %s.',
                        $this->firstFailedPostconditionStage(
                            $connection,
                            $entries,
                            $scopes,
                            $exception->moduleId(),
                            $exception->migrationId()
                        )
                    ));
                }
                throw $exception;
            }
            self::assertTrue($firstRun->changed());
            self::assertNotEmpty($firstRun->applied());

            self::assertSame(
                $this->expectedTables(),
                $this->tableNames(
                    $connection,
                    $configuration->database()
                )
            );
            $this->assertMigrationRegistry($connection, $entries);
            $this->assertSchemaPostcondition($connection, $entries, $scopes);
            $this->assertCanonicalSeeds($connection);

            $secondRun = $runner->apply($connection, $catalog, $scopes);
            self::assertFalse($secondRun->changed());
            self::assertSame([], $secondRun->applied());

            $bootstrap = new WebAdminBootstrapService(
                $connection,
                WebAdminConfig::defaults()
            );
            $firstBootstrap = $bootstrap->bootstrap([
                WebAdminConfig::BOOTSTRAP_EMAIL_ENV['system_superadmin'] =>
                    self::SYSTEM_EMAIL,
                WebAdminConfig::BOOTSTRAP_EMAIL_ENV['site_admin'] =>
                    self::SITE_EMAIL,
            ]);
            self::assertSame(
                BootstrapResult::COMPLETED,
                $firstBootstrap->status()
            );
            self::assertTrue($firstBootstrap->changed());
            self::assertSame(2, $firstBootstrap->createdAccounts());

            // Completed DB state is authoritative: no environment values are
            // consulted on an idempotent replay.
            $secondBootstrap = $bootstrap->bootstrap([]);
            self::assertSame(
                BootstrapResult::ALREADY_COMPLETED,
                $secondBootstrap->status()
            );
            self::assertFalse($secondBootstrap->changed());
            self::assertSame(2, $this->tableCount($connection, 'users'));
            self::assertSame(2, $this->tableCount($connection, 'outbox'));

            $siteInvitation = $this->deliverBootstrapInvitations($connection);
            $this->completeSiteInvitation($connection, $siteInvitation);
            [$authentication, $authenticatedToken] =
                $this->assertBasicLoginAndSession($connection);
            $replacementToken = $this->assertPasswordResetLifecycle(
                $connection,
                $authentication,
                $authenticatedToken
            );
            $this->assertEditorManagementLifecycle(
                $connection,
                $authentication,
                $replacementToken
            );
            $this->assertConcurrentEditorManagement(
                $connection,
                $configuration,
                $authentication,
                $replacementToken
            );
            $this->assertConcurrentSessionLockOrdering(
                $connection,
                $configuration,
                $replacementToken
            );
            $resend = $bootstrap->resendInvitations();
            self::assertTrue($resend->changed());
            self::assertSame(1, $resend->queuedInvites());
            self::assertSame(1, $resend->skippedIdentities());
            $webAdminScope = $scopes->get('webadmin');
            self::assertNotNull($webAdminScope);
            self::assertTrue(
                (new WebAdminMigrationPostconditionVerifier())->verify(
                    $connection,
                    $webAdminScope
                )
            );
            self::assertTrue(
                (new WebAdminHttpSchemaGate())->isReady(
                    $connection,
                    $registry,
                    $webAdminScope
                )
            );
        } finally {
            try {
                if (
                    $databaseAcceptedAsEmpty
                    && $connection instanceof PDO
                ) {
                    $this->dropOnlyExpectedTables(
                        $connection,
                        $configuration->database()
                    );
                }
            } finally {
                try {
                    if (
                        $guardConnection instanceof PDO
                        && is_string($guardLockName)
                    ) {
                        $this->releaseGuardLock(
                            $guardConnection,
                            $guardLockName
                        );
                    }
                } finally {
                    if (is_string($projectRoot)) {
                        $this->removeModuleProject($projectRoot);
                    }
                    ini_set(
                        'zend.exception_ignore_args',
                        $previousTraceSetting
                    );
                }
            }
        }
    }

    private function assertForeignNamespaceCollisionIsNonMutating(
        PDO $connection,
        string $database,
        MigrationRunner $runner,
        MigrationCatalog $catalog,
        MigrationScopeCollection $scopes
    ): void {
        $foreignTable = self::TABLE_PREFIX . 'users';
        $qualified = $this->quoteIdentifier($database)
            . '.' . $this->quoteIdentifier($foreignTable);
        self::assertNotFalse($connection->exec(
            'CREATE TABLE ' . $qualified . ' ('
                . '`marker` VARBINARY(32) NOT NULL, '
                . '`payload` VARBINARY(32) NOT NULL, '
                . 'PRIMARY KEY (`marker`)'
                . ') ENGINE=InnoDB'
        ));
        $insert = $connection->prepare(
            'INSERT INTO ' . $qualified
                . ' (`marker`, `payload`) VALUES (:marker, :payload)'
        );
        self::assertNotFalse($insert);
        self::assertTrue($insert->execute([
            'marker' => "foreign\0marker",
            'payload' => "\x00\x01\xFE\xFF",
        ]));
        $beforeTables = $this->tableNames($connection, $database);
        $beforeDefinition = $connection->query(
            'SHOW CREATE TABLE ' . $qualified
        )->fetch(PDO::FETCH_ASSOC);
        $beforeRows = $connection->query(
            'SELECT HEX(`marker`) AS marker, HEX(`payload`) AS payload '
                . 'FROM ' . $qualified . ' ORDER BY `marker`'
        )->fetchAll(PDO::FETCH_ASSOC);

        try {
            $runner->apply($connection, $catalog, $scopes);
            self::fail(
                'A foreign WebAdmin namespace must block before any write.'
            );
        } catch (MigrationException $exception) {
            self::assertSame(
                'migration.precondition_failed',
                $exception->issueCode()
            );
            self::assertSame('webadmin', $exception->moduleId());
            self::assertSame(
                '0001_webadmin_identity_and_access',
                $exception->migrationId()
            );
        }

        self::assertSame(
            $beforeTables,
            $this->tableNames($connection, $database)
        );
        self::assertSame(
            $beforeDefinition,
            $connection->query('SHOW CREATE TABLE ' . $qualified)
                ->fetch(PDO::FETCH_ASSOC)
        );
        self::assertSame(
            $beforeRows,
            $connection->query(
                'SELECT HEX(`marker`) AS marker, HEX(`payload`) AS payload '
                    . 'FROM ' . $qualified . ' ORDER BY `marker`'
            )->fetchAll(PDO::FETCH_ASSOC)
        );
        self::assertSame([$foreignTable], $beforeTables);

        self::assertNotFalse($connection->exec('DROP TABLE ' . $qualified));
        self::assertSame([], $this->tableNames($connection, $database));
    }

    private function connect(
        #[\SensitiveParameter] WebAdminMySqlTestConfiguration $configuration
    ): PDO {
        try {
            $contractNames = WebAdminConfig::SHARED_DATABASE_ENV;
            $connection = (new SharedPdoConnectionFactory([
                $contractNames[0] => $configuration->host()
                    . ':' . $configuration->port(),
                $contractNames[1] => $configuration->username(),
                $contractNames[2] => $configuration->password(),
                $contractNames[3] => $configuration->database(),
            ]))->connect();
            self::assertSame(
                'mysql',
                $connection->getAttribute(PDO::ATTR_DRIVER_NAME)
            );

            return $connection;
        } catch (Throwable) {
            self::fail(
                'Could not connect to the isolated MySQL/MariaDB test DB.'
            );
        }
    }

    private function assertSelectedDatabase(
        PDO $connection,
        string $expectedDatabase
    ): void {
        self::assertSame(
            $expectedDatabase,
            $connection->query('SELECT DATABASE()')->fetchColumn(),
            'The connection must remain scoped to the guarded test DB.'
        );
    }

    private function acquireGuardLock(
        PDO $connection,
        string $database
    ): string {
        $lockName = 'liquidstack:test:mysql:'
            . substr(hash('sha256', $database), 0, 40);
        $statement = $connection->prepare(
            'SELECT GET_LOCK(:lock_name, 0)'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute(['lock_name' => $lockName]));
        self::assertSame(
            '1',
            (string) $statement->fetchColumn(),
            'Another integration run already owns this test DB.'
        );

        return $lockName;
    }

    private function releaseGuardLock(
        PDO $connection,
        string $lockName
    ): void {
        $statement = $connection->prepare(
            'SELECT RELEASE_LOCK(:lock_name)'
        );
        if (
            $statement === false
            || !$statement->execute(['lock_name' => $lockName])
            || (string) $statement->fetchColumn() !== '1'
        ) {
            throw new RuntimeException(
                'The MySQL integration guard lock could not be released.'
            );
        }
    }

    private function assertDatabaseIsEmpty(
        PDO $connection,
        string $database
    ): void {
        $queries = [
            'tables' => [
                'SELECT COUNT(*) FROM information_schema.tables '
                    . 'WHERE table_schema = :schema',
                'schema',
            ],
            'routines' => [
                'SELECT COUNT(*) FROM information_schema.routines '
                    . 'WHERE routine_schema = :schema',
                'schema',
            ],
            'triggers' => [
                'SELECT COUNT(*) FROM information_schema.triggers '
                    . 'WHERE trigger_schema = :schema',
                'schema',
            ],
            'events' => [
                'SELECT COUNT(*) FROM information_schema.events '
                    . 'WHERE event_schema = :schema',
                'schema',
            ],
        ];
        foreach ($queries as $kind => [$sql, $parameter]) {
            $statement = $connection->prepare($sql);
            self::assertNotFalse($statement);
            self::assertTrue($statement->execute([$parameter => $database]));
            self::assertSame(
                0,
                (int) $statement->fetchColumn(),
                sprintf(
                    'The isolated test DB must contain zero %s before use.',
                    $kind
                )
            );
        }
    }

    /**
     * @param list<array{
     *     module: string,
     *     provider: class-string,
     *     migration: object
     * }> $entries
     */
    private function assertMigrationRegistry(
        PDO $connection,
        array $entries
    ): void {
        $actual = $connection->query(
            'SELECT module_id, migration_id, checksum FROM '
            . MigrationRegistry::TABLE
            . ' ORDER BY module_id, migration_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $expected = array_map(
            static fn (array $entry): array => [
                'module_id' => $entry['module'],
                'migration_id' => $entry['migration']->id(),
                'checksum' => $entry['migration']->checksum(),
            ],
            $entries
        );

        self::assertSame($expected, $actual);
    }

    /**
     * @param list<array{
     *     module: string,
     *     provider: class-string,
     *     migration: object
     * }> $entries
     */
    private function assertSchemaPostcondition(
        PDO $connection,
        array $entries,
        MigrationScopeCollection $scopes
    ): void {
        foreach ($entries as $entry) {
            $verifier = $entry['migration']->postconditionVerifier();
            if ($verifier === null) {
                continue;
            }
            $scope = $scopes->get($entry['module']);
            self::assertNotNull($scope);
            self::assertTrue($verifier->verify($connection, $scope));
        }
    }

    private function assertCanonicalSeeds(PDO $connection): void
    {
        $expectedRoles = array_keys(WebAdminInitialSchemaContract::roles());
        sort($expectedRoles, SORT_STRING);
        self::assertSame(
            $expectedRoles,
            $connection->query(
                'SELECT code FROM ls_webadmin_roles ORDER BY code'
            )->fetchAll(PDO::FETCH_COLUMN)
        );

        $expectedCapabilities = array_keys(
            WebAdminInitialSchemaContract::capabilities()
        );
        sort($expectedCapabilities, SORT_STRING);
        self::assertSame(
            $expectedCapabilities,
            $connection->query(
                'SELECT code FROM ls_webadmin_capabilities ORDER BY code'
            )->fetchAll(PDO::FETCH_COLUMN)
        );

        $expectedGrants = WebAdminInitialSchemaContract::roleCapabilities();
        ksort($expectedGrants, SORT_STRING);
        foreach ($expectedGrants as &$capabilities) {
            sort($capabilities, SORT_STRING);
        }
        unset($capabilities);

        $actualGrants = [];
        $rows = $connection->query(
            'SELECT r.code AS role_code, c.code AS capability_code '
            . 'FROM ls_webadmin_role_capabilities rc '
            . 'JOIN ls_webadmin_roles r ON r.id = rc.role_id '
            . 'JOIN ls_webadmin_capabilities c ON c.id = rc.capability_id '
            . 'ORDER BY r.code, c.code'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $actualGrants[(string) $row['role_code']][] =
                (string) $row['capability_code'];
        }
        ksort($actualGrants, SORT_STRING);
        self::assertSame($expectedGrants, $actualGrants);

        $state = $connection->query(
            "SELECT value_text FROM ls_webadmin_state "
            . "WHERE state_key = 'bootstrap.initial_accounts'"
        )->fetchColumn();
        self::assertSame('pending', $state);
    }

    /**
     * Produces only a stable stage name; it never returns metadata, SQL,
     * database messages or credential values.
     *
     * @param list<array{
     *     module: string,
     *     provider: class-string,
     *     migration: object
     * }> $entries
     */
    private function firstFailedPostconditionStage(
        PDO $connection,
        array $entries,
        MigrationScopeCollection $scopes,
        ?string $moduleId,
        ?string $migrationId
    ): string {
        try {
            $entry = null;
            foreach ($entries as $candidate) {
                if (
                    $candidate['module'] === $moduleId
                    && $candidate['migration']->id() === $migrationId
                ) {
                    $entry = $candidate;
                    break;
                }
            }
            if (!is_array($entry)) {
                return 'catalog_entry';
            }
            $scope = $scopes->get((string) $entry['module']);
            $verifier = $entry['migration']->postconditionVerifier();
            if ($scope === null || $verifier === null) {
                return 'verifier_contract';
            }

            $reflection = new ReflectionClass($verifier);
            $collector = $reflection->getMethod('collectMySql');
            $collector->setAccessible(true);
            $metadata = $collector->invoke(
                $verifier,
                $connection,
                $scope
            );
            if (!is_array($metadata)) {
                return 'metadata_collection';
            }

            $stages = [
                'tables' => ['validateTables', ['mysql', $metadata]],
                'columns' => ['validateColumns', ['mysql', $metadata]],
                'primary_keys' => ['validatePrimaryKeys', [$metadata]],
                'indexes' => ['validateIndexes', ['mysql', $metadata]],
                'foreign_keys' => [
                    'validateForeignKeys',
                    ['mysql', $metadata],
                ],
                'checks' => ['validateChecks', ['mysql', $metadata]],
                'triggers' => ['validateNoTriggers', [$metadata]],
            ];
            foreach ($stages as $stage => [$methodName, $arguments]) {
                $method = $reflection->getMethod($methodName);
                $method->setAccessible(true);
                if (!$method->invokeArgs($verifier, $arguments)) {
                    if ($stage === 'columns') {
                        return $this->firstFailedMySqlColumn(
                            $reflection,
                            $verifier,
                            $metadata
                        );
                    }
                    if ($stage === 'checks') {
                        return $this->firstFailedMySqlCheck(
                            $reflection,
                            $verifier,
                            $metadata
                        );
                    }

                    return $stage;
                }
            }
            if (($metadata['data_integrity'] ?? null) !== true) {
                return 'data_integrity';
            }
            if (
                !is_array($metadata['seeds'] ?? null)
                || !(new WebAdminCanonicalSeedVerifier())->validateMetadata(
                    $metadata['seeds']
                )
            ) {
                return 'seeds';
            }

            return 'overall';
        } catch (Throwable) {
            return 'metadata_collection';
        }
    }

    /** @param array<string, mixed> $metadata */
    private function firstFailedMySqlColumn(
        ReflectionClass $reflection,
        object $verifier,
        array $metadata
    ): string {
        $actualTables = $metadata['columns'] ?? null;
        if (!is_array($actualTables)) {
            return 'columns:metadata';
        }
        $validator = $reflection->getMethod('validateMySqlColumn');
        $validator->setAccessible(true);

        foreach (
            WebAdminInitialSchemaContract::mysqlColumns()
            as $suffix => $expectedColumns
        ) {
            $actualColumns = $actualTables[$suffix] ?? null;
            if (!is_array($actualColumns)) {
                return 'columns:' . $suffix;
            }
            $actualByName = [];
            foreach ($actualColumns as $actualColumn) {
                if (!is_array($actualColumn)) {
                    return 'columns:' . $suffix . '.metadata';
                }
                $name = (string) ($actualColumn['name'] ?? '');
                if ($name === '' || isset($actualByName[$name])) {
                    return 'columns:' . $suffix . '.metadata';
                }
                $actualByName[$name] = $actualColumn;
            }
            foreach ($expectedColumns as $expected) {
                $name = (string) ($expected['name'] ?? 'metadata');
                $actual = $actualByName[$name] ?? null;
                if (
                    !is_array($actual)
                    || !$validator->invoke($verifier, $expected, $actual)
                ) {
                    $keys = is_array($actual)
                        ? $this->mysqlColumnDivergentKeys(
                            $reflection,
                            $verifier,
                            $expected,
                            $actual
                        )
                        : ['metadata'];

                    return 'columns:' . $suffix . '.' . $name
                        . '[' . implode(',', $keys) . ']';
                }
            }
        }

        return 'columns:overall';
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @return list<string>
     */
    private function mysqlColumnDivergentKeys(
        ReflectionClass $reflection,
        object $verifier,
        array $expected,
        array $actual
    ): array {
        $keys = [];
        if (strtolower((string) ($actual['type'] ?? '')) !== $expected['type']) {
            $keys[] = 'type';
        }
        if (($actual['nullable'] ?? null) !== $expected['nullable']) {
            $keys[] = 'nullable';
        }
        if (
            (bool) ($actual['unsigned'] ?? false)
            !== (bool) ($expected['unsigned'] ?? false)
        ) {
            $keys[] = 'unsigned';
        }
        $normalizeDefault = $reflection->getMethod(
            'normalizeMySqlDefault'
        );
        $normalizeDefault->setAccessible(true);
        if (
            ($actual['default'] ?? null)
            !== $normalizeDefault->invoke(
                $verifier,
                $expected['default'] ?? null
            )
        ) {
            $keys[] = 'default';
        }
        foreach (
            ['length', 'datetime_precision', 'charset', 'collation']
            as $key
        ) {
            if (
                array_key_exists($key, $expected)
                && ($actual[$key] ?? null) !== $expected[$key]
            ) {
                $keys[] = $key;
            }
        }

        $extra = strtolower(trim((string) ($actual['extra'] ?? '')));
        $tokens = $extra === ''
            ? []
            : (preg_split('/\s+/', $extra) ?: []);
        $expectsAutoIncrement = ($expected['extra'] ?? null)
            === 'auto_increment';
        $allowed = $expectsAutoIncrement ? ['auto_increment'] : [];
        if (($expected['default'] ?? null) === 'current_timestamp(6)') {
            $allowed[] = 'default_generated';
        }
        if (
            in_array('auto_increment', $tokens, true)
                !== $expectsAutoIncrement
            || array_diff($tokens, $allowed) !== []
        ) {
            $keys[] = 'extra';
        }

        return $keys === [] ? ['metadata'] : $keys;
    }

    /** @param array<string, mixed> $metadata */
    private function firstFailedMySqlCheck(
        ReflectionClass $reflection,
        object $verifier,
        array $metadata
    ): string {
        $equivalent = $reflection->getMethod('expressionsEquivalent');
        $equivalent->setAccessible(true);

        foreach (
            WebAdminInitialSchemaContract::mysqlChecks()
            as $suffix => $expectedChecks
        ) {
            $actualChecks = $metadata['checks'][$suffix] ?? null;
            if (!is_array($actualChecks)) {
                return 'checks:' . $suffix . '[metadata]';
            }
            $matched = [];
            foreach ($expectedChecks as $expectedName => $expression) {
                $expressionMatched = false;
                $found = false;
                foreach ($actualChecks as $actualName => $actual) {
                    if (
                        isset($matched[(string) $actualName])
                        || !is_string($actual)
                        || !$equivalent->invoke(
                            $verifier,
                            $actual,
                            $expression
                        )
                    ) {
                        continue;
                    }
                    $expressionMatched = true;
                    if (
                        ($metadata['check_enforcement'][$suffix][$actualName]
                            ?? null) !== true
                    ) {
                        continue;
                    }
                    $matched[(string) $actualName] = true;
                    $found = true;
                    break;
                }
                if (!$found) {
                    return 'checks:' . $suffix . '.' . $expectedName
                        . ($expressionMatched
                            ? '[enforcement]'
                            : '[' . $this->classifyCheckExpressionMismatch(
                                $reflection,
                                $verifier,
                                $expression,
                                $actualChecks
                            ) . ']');
                }
            }
            if (count($actualChecks) !== count($expectedChecks)) {
                return 'checks:' . $suffix . '[count]';
            }
        }

        return 'checks:overall';
    }

    /** @param array<string, mixed> $actualChecks */
    private function classifyCheckExpressionMismatch(
        ReflectionClass $reflection,
        object $verifier,
        string $expected,
        array $actualChecks
    ): string {
        $normalize = $reflection->getMethod('normalizeSqlStructure');
        $normalize->setAccessible(true);
        $expectedNormalized = (string) $normalize->invoke(
            $verifier,
            $expected
        );

        foreach ($actualChecks as $actual) {
            if (!is_string($actual)) {
                continue;
            }
            $actualNormalized = (string) $normalize->invoke(
                $verifier,
                $actual
            );
            if (
                str_replace(['(', ')'], '', $actualNormalized)
                === str_replace(['(', ')'], '', $expectedNormalized)
            ) {
                return 'redundant_parentheses';
            }

            $operators = static fn (string $value): string => str_replace(
                ['<>', '==', '&&', '||'],
                ['!=', '=', 'and', 'or'],
                $value
            );
            if ($operators($actualNormalized) === $operators($expectedNormalized)) {
                return 'operator_spelling';
            }

            $functions = static fn (string $value): string => str_replace(
                ['character_length(', 'length('],
                'char_length(',
                $value
            );
            if ($functions($actualNormalized) === $functions($expectedNormalized)) {
                return 'function_alias';
            }

            $literals = static fn (string $value): string => preg_replace(
                "/'(?:(null|true|false)|([0-9]+))'/i",
                '$1$2',
                $value
            ) ?? $value;
            if ($literals($actualNormalized) === $literals($expectedNormalized)) {
                return 'literal_representation';
            }
            if (
                substr_count($actualNormalized, '(')
                    !== substr_count($expectedNormalized, '(')
                || substr_count($actualNormalized, ')')
                    !== substr_count($expectedNormalized, ')')
            ) {
                return 'parentheses';
            }
        }

        return 'other';
    }

    private function deliverBootstrapInvitations(PDO $connection): string
    {
        $repository = new WebAdminOutboxRepository(
            $connection,
            WebAdminTableNames::fromPdo($connection, self::TABLE_PREFIX)
        );
        $delivered = [];
        for ($index = 0; $index < 2; $index++) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $claim = $repository->claimNext($now);
            self::assertFalse($claim->isNone());
            $lease = $claim->lease();
            self::assertTrue($repository->acknowledge($lease, $now));
            $delivered[$lease->recipientEmail()] =
                $lease->revealActionToken();
        }

        self::assertSame(2, count($delivered));
        self::assertArrayHasKey(self::SITE_EMAIL, $delivered);
        self::assertSame(2, (int) $connection->query(
            "SELECT COUNT(*) FROM ls_webadmin_outbox WHERE status = 'sent'"
        )->fetchColumn());

        return $delivered[self::SITE_EMAIL];
    }

    private function completeSiteInvitation(
        PDO $connection,
        #[\SensitiveParameter] string $rawInvitation
    ): void {
        $actions = $this->credentialActions($connection);
        $bound = $actions->bindActionToken(
            $rawInvitation,
            CredentialActionService::INVITATION
        );
        self::assertNotNull($bound);
        self::assertTrue($actions->completeInvitation(
            $bound->sessionToken(),
            $bound->csrfToken(),
            self::LOGIN_PASSWORD,
            '127.0.0.1',
            'LiquidStack MySQL integration test'
        )->isCompleted());

        $status = $connection->prepare(
            'SELECT status FROM ls_webadmin_users '
            . 'WHERE email_canonical = :email'
        );
        self::assertNotFalse($status);
        self::assertTrue($status->execute(['email' => self::SITE_EMAIL]));
        self::assertSame('active', $status->fetchColumn());
    }

    /**
     * @return array{WebAdminAuthenticationService, string}
     */
    private function assertBasicLoginAndSession(PDO $connection): array
    {
        $tables = WebAdminTableNames::fromPdo(
            $connection,
            self::TABLE_PREFIX
        );
        $authentication = new WebAdminAuthenticationService(
            new WebAdminAuthenticationRepository($connection, $tables),
            WebAdminConfig::defaults(),
            SecurityKey::fromRawBytes(str_repeat('I', 32)),
            new SystemClock(),
            new RandomUuidV4Generator(),
            PasswordHasher::productive(),
            new SecureTokenGenerator()
        );

        $preAuthentication = $authentication->openPreAuthenticationSession(
            null,
            '127.0.0.1'
        );
        $attempt = $authentication->authenticate(
            $preAuthentication->sessionToken(),
            $preAuthentication->csrfToken(),
            self::SITE_EMAIL,
            self::LOGIN_PASSWORD,
            '127.0.0.1',
            'LiquidStack MySQL integration test'
        );
        self::assertTrue($attempt->isSuccessful());
        self::assertTrue($attempt->nextSession()->isAuthenticated());
        self::assertSame(
            self::SITE_EMAIL,
            $attempt->authenticatedSession()->emailCanonical()
        );

        $resolved = $authentication->resolveAuthenticatedSession(
            $attempt->nextSession()->sessionToken()
        );
        self::assertNotNull($resolved);
        self::assertSame(
            $attempt->authenticatedSession()->userId(),
            $resolved->userId()
        );
        $authorization = new WebAdminAuthorizationService(
            $connection,
            $tables
        );
        self::assertTrue($authorization->mayAccessWebAdmin(
            $attempt->nextSession()->sessionToken()
        ));
        self::assertFalse($authorization->mayAccessWebAdmin(
            (new SecureTokenGenerator())->generate()
        ));

        $statement = $connection->prepare(
            'SELECT session_type, token_hash, csrf_token_hash, revoked_at '
            . 'FROM ls_webadmin_sessions WHERE token_hash = :token_hash'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'token_hash' => hash(
                'sha256',
                $attempt->nextSession()->sessionToken()
            ),
        ]));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('authenticated', $row['session_type']);
        self::assertSame(
            hash('sha256', $attempt->nextSession()->sessionToken()),
            $row['token_hash']
        );
        self::assertSame(
            hash('sha256', $attempt->nextSession()->csrfToken()),
            $row['csrf_token_hash']
        );
        self::assertNotSame(
            $attempt->nextSession()->sessionToken(),
            $row['token_hash']
        );
        self::assertNull($row['revoked_at']);

        return [
            $authentication,
            $attempt->nextSession()->sessionToken(),
        ];
    }

    private function assertPasswordResetLifecycle(
        PDO $connection,
        WebAdminAuthenticationService $authentication,
        #[\SensitiveParameter] string $authenticatedToken
    ): string {
        $mailSender = new WebAdminMySqlPasswordResetMailSender($connection);
        $actions = $this->credentialActions($connection, $mailSender);
        $result = $actions->requestPasswordReset(
            self::SITE_EMAIL,
            '127.0.0.1',
            'LiquidStack MySQL integration test',
            'und'
        );
        self::assertFalse($result->deliveryFailed());
        self::assertCount(1, $mailSender->deliveries);
        self::assertFalse($mailSender->transactionObserved);
        $delivery = $mailSender->deliveries[0];
        self::assertSame(self::SITE_EMAIL, $delivery->recipientEmail());
        self::assertSame(0, (int) $connection->query(
            "SELECT COUNT(*) FROM ls_webadmin_outbox "
            . "WHERE kind = 'password_reset'"
        )->fetchColumn());

        $bound = $actions->bindActionToken(
            $delivery->rawToken(),
            CredentialActionService::PASSWORD_RESET
        );
        self::assertNotNull($bound);
        self::assertTrue($actions->completePasswordReset(
            $bound->sessionToken(),
            $bound->csrfToken(),
            self::RESET_PASSWORD,
            '127.0.0.1',
            'LiquidStack MySQL integration test'
        )->isCompleted());
        self::assertNull($authentication->resolveAuthenticatedSession(
            $authenticatedToken
        ));

        $preAuthentication = $authentication->openPreAuthenticationSession(
            null,
            '127.0.0.1'
        );
        $replacementLogin = $authentication->authenticate(
            $preAuthentication->sessionToken(),
            $preAuthentication->csrfToken(),
            self::SITE_EMAIL,
            self::RESET_PASSWORD,
            '127.0.0.1',
            'LiquidStack MySQL integration test'
        );
        self::assertTrue($replacementLogin->isSuccessful());

        return $replacementLogin->nextSession()->sessionToken();
    }

    private function assertEditorManagementLifecycle(
        PDO $connection,
        WebAdminAuthenticationService $authentication,
        #[\SensitiveParameter] string $actorSessionToken
    ): void {
        $tables = WebAdminTableNames::fromPdo(
            $connection,
            self::TABLE_PREFIX
        );
        $service = new UserManagementService(
            new UserManagementRepository($connection, $tables),
            new ActiveModuleSet(['webadmin']),
            WebAdminConfig::defaults(),
            SecurityKey::fromRawBytes(str_repeat('I', 32)),
            new SystemClock(),
            new RandomUuidV4Generator(),
            PasswordHasher::productive(),
            new SecureTokenGenerator()
        );
        $csrf = $authentication->authenticatedCsrfToken(
            $actorSessionToken
        );
        self::assertNotNull($csrf);
        $csrfToken = $csrf->csrfToken();

        $initialPage = $service->listEditors($actorSessionToken, 25);
        self::assertNotNull($initialPage);
        self::assertSame([], $initialPage->editors());
        $catalog = $service->delegableCapabilities($actorSessionToken);
        self::assertNotNull($catalog);
        self::assertSame(
            ['webadmin.users.view'],
            $catalog->codes()
        );

        $invitation = $service->inviteEditor(
            $actorSessionToken,
            $csrfToken,
            'Editor de integración',
            self::EDITOR_EMAIL,
            ['webadmin.users.view'],
            'es'
        );
        self::assertSame(EditorInviteResult::INVITED, $invitation->status());
        $editor = $invitation->editor();
        self::assertNotNull($editor);
        self::assertSame(self::EDITOR_EMAIL, $editor->emailCanonical());
        self::assertSame('invited', $editor->status());
        self::assertSame(
            ['webadmin.users.view'],
            $editor->directCapabilities()
        );
        $editorPublicId = $editor->publicId();
        self::assertSame(1, (int) $connection->query(
            "SELECT COUNT(*) FROM ls_webadmin_outbox WHERE kind = 'invite' "
            . "AND status = 'pending' AND user_id = (SELECT id FROM "
            . "ls_webadmin_users WHERE email_canonical = '"
            . self::EDITOR_EMAIL . "')"
        )->fetchColumn());

        $alreadyQueued = $service->resendInvitation(
            $actorSessionToken,
            $csrfToken,
            $editorPublicId,
            'es'
        );
        self::assertSame(
            EditorMutationResult::ALREADY_QUEUED,
            $alreadyQueued->status()
        );

        $suspendedInvite = $service->suspendEditor(
            $actorSessionToken,
            $csrfToken,
            $editorPublicId
        );
        self::assertSame(
            EditorMutationResult::APPLIED,
            $suspendedInvite->status()
        );
        self::assertSame(1, (int) $connection->query(
            "SELECT COUNT(*) FROM ls_webadmin_outbox WHERE kind = 'invite' "
            . "AND status = 'failed' AND last_error_code = "
            . "'outbox.recipient_unavailable' AND user_id = (SELECT id "
            . "FROM ls_webadmin_users WHERE email_canonical = '"
            . self::EDITOR_EMAIL . "')"
        )->fetchColumn());

        $resumedInvite = $service->resumeEditor(
            $actorSessionToken,
            $csrfToken,
            $editorPublicId,
            'es'
        );
        self::assertSame(
            EditorMutationResult::APPLIED,
            $resumedInvite->status()
        );
        $outbox = new WebAdminOutboxRepository($connection, $tables);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $claim = $outbox->claimNext($now);
        self::assertFalse($claim->isNone());
        $lease = $claim->lease();
        self::assertSame('invite', $lease->kind());
        self::assertSame(self::EDITOR_EMAIL, $lease->recipientEmail());
        self::assertTrue($outbox->acknowledge($lease, $now));

        $actions = $this->credentialActions($connection);
        $bound = $actions->bindActionToken(
            $lease->revealActionToken(),
            CredentialActionService::INVITATION
        );
        self::assertNotNull($bound);
        self::assertTrue($actions->completeInvitation(
            $bound->sessionToken(),
            $bound->csrfToken(),
            self::EDITOR_PASSWORD,
            '127.0.0.2',
            'LiquidStack MySQL editor integration test'
        )->isCompleted());

        $active = $service->editorDetail(
            $actorSessionToken,
            $editorPublicId
        );
        self::assertNotNull($active);
        self::assertSame('active', $active->status());
        $removed = $service->replaceCapabilities(
            $actorSessionToken,
            $csrfToken,
            $editorPublicId,
            []
        );
        self::assertSame(EditorMutationResult::APPLIED, $removed->status());
        $restored = $service->replaceCapabilities(
            $actorSessionToken,
            $csrfToken,
            $editorPublicId,
            ['webadmin.users.view']
        );
        self::assertSame(EditorMutationResult::APPLIED, $restored->status());

        $editorPreAuthentication = $authentication
            ->openPreAuthenticationSession(null, '127.0.0.3');
        $editorLogin = $authentication->authenticate(
            $editorPreAuthentication->sessionToken(),
            $editorPreAuthentication->csrfToken(),
            self::EDITOR_EMAIL,
            self::EDITOR_PASSWORD,
            '127.0.0.3',
            'LiquidStack MySQL editor integration test'
        );
        self::assertTrue($editorLogin->isSuccessful());
        $editorSession = $editorLogin->nextSession()->sessionToken();
        self::assertNotNull($authentication->resolveAuthenticatedSession(
            $editorSession
        ));

        $suspended = $service->suspendEditor(
            $actorSessionToken,
            $csrfToken,
            $editorPublicId
        );
        self::assertSame(EditorMutationResult::APPLIED, $suspended->status());
        self::assertNull($authentication->resolveAuthenticatedSession(
            $editorSession
        ));
        $resumed = $service->resumeEditor(
            $actorSessionToken,
            $csrfToken,
            $editorPublicId,
            'es'
        );
        self::assertSame(EditorMutationResult::APPLIED, $resumed->status());
        self::assertSame(0, (int) $connection->query(
            "SELECT COUNT(*) FROM ls_webadmin_outbox WHERE status IN "
            . "('pending', 'processing') AND user_id = (SELECT id FROM "
            . "ls_webadmin_users WHERE email_canonical = '"
            . self::EDITOR_EMAIL . "')"
        )->fetchColumn());

        $actorPublicId = (string) $connection->query(
            "SELECT public_id FROM ls_webadmin_users WHERE "
            . "email_canonical = '" . self::SITE_EMAIL . "'"
        )->fetchColumn();
        self::assertSame(
            EditorMutationResult::DENIED,
            $service->suspendEditor(
                $actorSessionToken,
                $csrfToken,
                $actorPublicId
            )->status()
        );
        $systemPublicId = (string) $connection->query(
            "SELECT public_id FROM ls_webadmin_users WHERE "
            . "email_canonical = '" . self::SYSTEM_EMAIL . "'"
        )->fetchColumn();
        self::assertSame(
            EditorMutationResult::DENIED,
            $service->suspendEditor(
                $actorSessionToken,
                $csrfToken,
                $systemPublicId
            )->status()
        );

        $finalPage = $service->listEditors($actorSessionToken, 25);
        self::assertNotNull($finalPage);
        self::assertCount(1, $finalPage->editors());
        self::assertSame(self::EDITOR_EMAIL, $finalPage->editors()[0]
            ->emailCanonical());
        self::assertSame(0, (int) $connection->query(
            "SELECT COUNT(*) FROM ls_webadmin_audit_log WHERE event_code "
            . "LIKE 'webadmin.editor.%' AND (metadata_json IS NOT NULL OR "
            . "ip_hash IS NOT NULL OR user_agent_hash IS NOT NULL)"
        )->fetchColumn());
        $audit = json_encode(
            $connection->query(
                "SELECT event_code, outcome, reason_code, target_public_id "
                . "FROM ls_webadmin_audit_log WHERE event_code LIKE "
                . "'webadmin.editor.%' ORDER BY id"
            )->fetchAll(PDO::FETCH_ASSOC),
            JSON_THROW_ON_ERROR
        );
        self::assertStringNotContainsString(self::EDITOR_EMAIL, $audit);
        self::assertStringNotContainsString(
            'Editor de integración',
            $audit
        );
        self::assertStringNotContainsString($csrfToken, $audit);
        self::assertStringNotContainsString($actorSessionToken, $audit);
    }

    private function assertConcurrentEditorManagement(
        PDO $connection,
        WebAdminMySqlTestConfiguration $configuration,
        WebAdminAuthenticationService $authentication,
        #[\SensitiveParameter] string $siteSessionToken
    ): void {
        $siteCsrf = $authentication->authenticatedCsrfToken(
            $siteSessionToken
        );
        self::assertNotNull($siteCsrf);
        [$secondSessionToken, $secondCsrf] =
            $this->createConcurrentManagementActor(
                $connection,
                $authentication
            );

        $worker = dirname(__DIR__)
            . DIRECTORY_SEPARATOR . 'Integration'
            . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'webadmin_mysql_lock_worker.php';
        self::assertFileExists($worker);
        $markers = [
            $this->unusedTemporaryPath('ls-wa-invite-a-'),
            $this->unusedTemporaryPath('ls-wa-invite-b-'),
        ];
        $start = $this->unusedTemporaryPath('ls-wa-invite-go-');
        $email = 'concurrent-editor@example.test';
        $processes = [
            $this->managementWorker(
                $worker,
                $configuration,
                'invite_editor',
                $siteSessionToken,
                $siteCsrf->csrfToken(),
                $markers[0],
                ['LIQUIDSTACK_TEST_WORKER_EMAIL' => $email,
                    'LIQUIDSTACK_TEST_WORKER_START' => $start]
            ),
            $this->managementWorker(
                $worker,
                $configuration,
                'invite_editor',
                $secondSessionToken,
                $secondCsrf,
                $markers[1],
                ['LIQUIDSTACK_TEST_WORKER_EMAIL' => $email,
                    'LIQUIDSTACK_TEST_WORKER_START' => $start]
            ),
        ];

        try {
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($markers as $marker) {
                $this->waitForWorkerMarker($marker, $processes);
            }
            self::assertSame(2, file_put_contents($start, 'go', LOCK_EX));
            $exitCodes = array_map(
                static fn (Process $process): int => $process->wait(),
                $processes
            );
            sort($exitCodes, SORT_NUMERIC);
            self::assertSame(
                [0, 4],
                $exitCodes,
                'Exactly one concurrent invite must win; the other conflicts.'
            );
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1.0);
                }
            }
            foreach (array_merge($markers, [$start]) as $path) {
                $this->removeTemporaryMarker($path);
            }
        }

        $target = $connection->prepare(
            'SELECT id, public_id, status FROM ls_webadmin_users '
            . 'WHERE email_canonical = :email'
        );
        self::assertNotFalse($target);
        self::assertTrue($target->execute(['email' => $email]));
        $targetRow = $target->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($targetRow);
        self::assertSame('invited', $targetRow['status']);
        self::assertFalse($target->fetch(PDO::FETCH_ASSOC));
        $targetId = (int) $targetRow['id'];
        $targetPublicId = (string) $targetRow['public_id'];
        self::assertGreaterThan(0, $targetId);
        self::assertSame(1, (int) $connection->query(
            "SELECT COUNT(*) FROM ls_webadmin_outbox WHERE user_id = "
            . $targetId . " AND kind = 'invite' AND status = 'pending'"
        )->fetchColumn());

        $suspendMarker = $this->unusedTemporaryPath('ls-wa-suspend-');
        $suspend = $this->managementWorker(
            $worker,
            $configuration,
            'suspend_editor',
            $siteSessionToken,
            $siteCsrf->csrfToken(),
            $suspendMarker,
            ['LIQUIDSTACK_TEST_WORKER_TARGET' => $targetPublicId]
        );
        $connection->exec('SET SESSION innodb_lock_wait_timeout = 2');
        try {
            self::assertTrue($connection->beginTransaction());
            $outboxLock = $connection->prepare(
                'SELECT id FROM ls_webadmin_outbox WHERE user_id = :user_id '
                . "AND status = 'pending' ORDER BY id FOR UPDATE"
            );
            self::assertNotFalse($outboxLock);
            self::assertTrue($outboxLock->execute(['user_id' => $targetId]));
            self::assertGreaterThan(0, (int) $outboxLock->fetchColumn());

            $suspend->start();
            $this->waitForWorkerMarker($suspendMarker, [$suspend]);
            usleep(250_000);
            self::assertTrue(
                $suspend->isRunning(),
                'Suspend must wait at the target outbox lock.'
            );

            $userLock = $connection->prepare(
                'SELECT id FROM ls_webadmin_users WHERE id = :id FOR UPDATE'
            );
            self::assertNotFalse($userLock);
            self::assertTrue($userLock->execute(['id' => $targetId]));
            self::assertSame($targetId, (int) $userLock->fetchColumn());
            self::assertTrue(
                $suspend->isRunning(),
                'The worker must not hold the user before the outbox.'
            );
            self::assertTrue($connection->commit());
            self::assertSame(0, $suspend->wait());
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            if ($suspend->isRunning()) {
                $suspend->stop(1.0);
            }
            $this->removeTemporaryMarker($suspendMarker);
        }

        self::assertSame('suspended', $connection->query(
            'SELECT status FROM ls_webadmin_users WHERE id = ' . $targetId
        )->fetchColumn());
        self::assertSame(1, (int) $connection->query(
            "SELECT COUNT(*) FROM ls_webadmin_outbox WHERE user_id = "
            . $targetId . " AND kind = 'invite' AND status = 'failed' "
            . "AND last_error_code = 'outbox.recipient_unavailable'"
        )->fetchColumn());
    }

    /** @return array{string, string} */
    private function createConcurrentManagementActor(
        PDO $connection,
        WebAdminAuthenticationService $authentication
    ): array {
        $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s.u');
        $email = 'concurrent-manager@example.test';
        $insert = $connection->prepare(
            'INSERT INTO ls_webadmin_users (public_id, email_canonical, '
            . 'display_name, status, auth_version, activated_at, created_at, '
            . 'updated_at) VALUES (:public_id, :email, :display_name, '
            . "'active', 1, :activated_at, :created_at, :updated_at)"
        );
        self::assertNotFalse($insert);
        self::assertTrue($insert->execute([
            'public_id' => (new RandomUuidV4Generator())->generateV4(),
            'email' => $email,
            'display_name' => 'Concurrent manager',
            'activated_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]));
        $userId = (int) $connection->lastInsertId();
        self::assertGreaterThan(0, $userId);
        $credential = $connection->prepare(
            'INSERT INTO ls_webadmin_credentials (user_id, password_hash, '
            . 'password_set_at, created_at, updated_at) VALUES (:user_id, '
            . ':password_hash, :password_set_at, :created_at, :updated_at)'
        );
        self::assertNotFalse($credential);
        self::assertTrue($credential->execute([
            'user_id' => $userId,
            'password_hash' => PasswordHasher::productive()->hash(
                self::EDITOR_PASSWORD
            ),
            'password_set_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]));
        $siteId = (int) $connection->query(
            "SELECT id FROM ls_webadmin_users WHERE email_canonical = '"
            . self::SITE_EMAIL . "'"
        )->fetchColumn();
        self::assertGreaterThan(0, $siteId);
        self::assertSame(1, $connection->exec(
            'INSERT INTO ls_webadmin_user_roles '
            . '(user_id, role_id, assigned_by_user_id, source) SELECT '
            . $userId . ', id, ' . $siteId . ", 'system' FROM "
            . "ls_webadmin_roles WHERE code = 'editor'"
        ));
        self::assertSame(4, $connection->exec(
            'INSERT INTO ls_webadmin_user_capabilities '
            . '(user_id, capability_id, assigned_by_user_id) SELECT '
            . $userId . ', id, ' . $siteId . ' FROM '
            . "ls_webadmin_capabilities WHERE code IN ('webadmin.users.view', "
            . "'webadmin.users.invite', 'webadmin.users.suspend', "
            . "'webadmin.users.capabilities.manage')"
        ));

        $preAuthentication = $authentication->openPreAuthenticationSession(
            null,
            '127.0.0.4'
        );
        $login = $authentication->authenticate(
            $preAuthentication->sessionToken(),
            $preAuthentication->csrfToken(),
            $email,
            self::EDITOR_PASSWORD,
            '127.0.0.4',
            'LiquidStack concurrent manager integration test'
        );
        self::assertTrue($login->isSuccessful());

        return [
            $login->nextSession()->sessionToken(),
            $login->nextSession()->csrfToken(),
        ];
    }

    /** @param array<string, string> $extraEnvironment */
    private function managementWorker(
        string $worker,
        WebAdminMySqlTestConfiguration $configuration,
        string $operation,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $marker,
        array $extraEnvironment
    ): Process {
        $process = new Process(
            [PHP_BINARY, $worker],
            dirname(__DIR__, 2),
            $extraEnvironment + [
                'LIQUIDSTACK_TEST_WORKER_AUTOLOAD' => dirname(__DIR__, 2)
                    . DIRECTORY_SEPARATOR . 'vendor'
                    . DIRECTORY_SEPARATOR . 'autoload.php',
                'LIQUIDSTACK_TEST_WORKER_MARKER' => $marker,
                'LIQUIDSTACK_TEST_WORKER_OPERATION' => $operation,
                'LIQUIDSTACK_TEST_WORKER_TOKEN' => $sessionToken,
                'LIQUIDSTACK_TEST_WORKER_CSRF' => $csrfToken,
                'LIQUIDSTACK_TEST_WORKER_HOST' => $configuration->host()
                    . ':' . $configuration->port(),
                'LIQUIDSTACK_TEST_WORKER_USERNAME' =>
                    $configuration->username(),
                'LIQUIDSTACK_TEST_WORKER_PASSWORD' =>
                    $configuration->password(),
                'LIQUIDSTACK_TEST_WORKER_DATABASE' =>
                    $configuration->database(),
            ]
        );
        $process->setTimeout(12.0);
        $process->disableOutput();

        return $process;
    }

    /** @param list<Process> $processes */
    private function waitForWorkerMarker(
        string $marker,
        array $processes
    ): void {
        $deadline = microtime(true) + 3.0;
        while (!is_file($marker) && microtime(true) < $deadline) {
            foreach ($processes as $process) {
                if (!$process->isRunning()) {
                    break 2;
                }
            }
            usleep(10_000);
        }
        self::assertFileExists(
            $marker,
            'An isolated management worker did not reach its probe.'
        );
    }

    private function unusedTemporaryPath(string $prefix): string
    {
        if (preg_match('/\A[a-z0-9-]{3,32}\z/', $prefix) !== 1) {
            throw new RuntimeException('Unsafe temporary marker prefix.');
        }
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false || !unlink($path)) {
            throw new RuntimeException('Could not prepare a worker marker.');
        }

        return $path;
    }

    private function removeTemporaryMarker(string $path): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($path));
        if (
            $temporaryRoot === false
            || $parent !== $temporaryRoot
            || (
                !str_starts_with(basename($path), 'ls-wa-')
                && preg_match(
                    '/\Als-[a-f0-9]{4}\.tmp\z/i',
                    basename($path)
                ) !== 1
            )
        ) {
            throw new RuntimeException('Unsafe temporary marker path.');
        }
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not remove a worker marker.');
        }
    }

    private function assertConcurrentSessionLockOrdering(
        PDO $connection,
        WebAdminMySqlTestConfiguration $configuration,
        #[\SensitiveParameter] string $authenticatedToken
    ): void {
        $userId = (int) $connection->query(
            "SELECT id FROM ls_webadmin_users "
            . "WHERE email_canonical = 'site-admin@example.test'"
        )->fetchColumn();
        self::assertGreaterThan(0, $userId);

        $this->assertWorkerWaitsForUserBeforeSession(
            $connection,
            $configuration,
            'resolve_auth',
            $authenticatedToken,
            $userId
        );

        $mailSender = new WebAdminMySqlPasswordResetMailSender($connection);
        $actions = $this->credentialActions($connection, $mailSender);
        $result = $actions->requestPasswordReset(
            self::SITE_EMAIL,
            '127.0.0.1',
            'LiquidStack MySQL lock-order integration test',
            'und'
        );
        self::assertFalse($result->deliveryFailed());
        self::assertCount(1, $mailSender->deliveries);
        $bound = $actions->bindActionToken(
            $mailSender->deliveries[0]->rawToken(),
            CredentialActionService::PASSWORD_RESET
        );
        self::assertNotNull($bound);

        $this->assertWorkerWaitsForUserBeforeSession(
            $connection,
            $configuration,
            'resolve_action',
            $bound->sessionToken(),
            $userId
        );
    }

    private function assertWorkerWaitsForUserBeforeSession(
        PDO $connection,
        WebAdminMySqlTestConfiguration $configuration,
        string $operation,
        #[\SensitiveParameter] string $sessionToken,
        int $userId
    ): void {
        self::assertContains($operation, ['resolve_auth', 'resolve_action']);
        $worker = dirname(__DIR__)
            . DIRECTORY_SEPARATOR . 'Integration'
            . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'webadmin_mysql_lock_worker.php';
        self::assertFileExists($worker);

        $marker = tempnam(sys_get_temp_dir(), 'ls-wa-lock-');
        if ($marker === false || !unlink($marker)) {
            throw new RuntimeException(
                'Could not prepare the MySQL lock-order marker.'
            );
        }

        $process = new Process(
            [PHP_BINARY, $worker],
            dirname(__DIR__, 2),
            [
                'LIQUIDSTACK_TEST_WORKER_AUTOLOAD' => dirname(__DIR__, 2)
                    . DIRECTORY_SEPARATOR . 'vendor'
                    . DIRECTORY_SEPARATOR . 'autoload.php',
                'LIQUIDSTACK_TEST_WORKER_MARKER' => $marker,
                'LIQUIDSTACK_TEST_WORKER_OPERATION' => $operation,
                'LIQUIDSTACK_TEST_WORKER_TOKEN' => $sessionToken,
                'LIQUIDSTACK_TEST_WORKER_HOST' => $configuration->host()
                    . ':' . $configuration->port(),
                'LIQUIDSTACK_TEST_WORKER_USERNAME' =>
                    $configuration->username(),
                'LIQUIDSTACK_TEST_WORKER_PASSWORD' =>
                    $configuration->password(),
                'LIQUIDSTACK_TEST_WORKER_DATABASE' =>
                    $configuration->database(),
            ]
        );
        $process->setTimeout(10.0);
        $process->disableOutput();

        try {
            self::assertTrue($connection->beginTransaction());
            $userLock = $connection->prepare(
                'SELECT id FROM ls_webadmin_users WHERE id = :id FOR UPDATE'
            );
            self::assertNotFalse($userLock);
            self::assertTrue($userLock->execute(['id' => $userId]));
            self::assertSame($userId, (int) $userLock->fetchColumn());

            $process->start();
            $deadline = microtime(true) + 2.0;
            while (!is_file($marker) && microtime(true) < $deadline) {
                if (!$process->isRunning()) {
                    break;
                }
                usleep(10_000);
            }
            self::assertFileExists(
                $marker,
                'The isolated worker did not reach its lock-order probe.'
            );
            self::assertTrue(
                $process->isRunning(),
                'The isolated worker exited before exercising row locks.'
            );

            // The marker is emitted immediately before the service call. Give
            // the local worker enough time to reach the user row held above.
            usleep(250_000);
            self::assertTrue(
                $process->isRunning(),
                'The worker did not wait on the user-first lock boundary.'
            );

            $sessionLock = $connection->prepare(
                'SELECT id FROM ls_webadmin_sessions '
                . 'WHERE token_hash = :token_hash FOR UPDATE'
            );
            self::assertNotFalse($sessionLock);
            self::assertTrue($sessionLock->execute([
                'token_hash' => hash('sha256', $sessionToken),
            ]));
            self::assertGreaterThan(0, (int) $sessionLock->fetchColumn());
            self::assertTrue(
                $process->isRunning(),
                'The worker must still wait for the user before the session.'
            );

            self::assertTrue($connection->commit());
            self::assertSame(
                0,
                $process->wait(),
                'The isolated lock-order worker did not complete cleanly.'
            );
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(1.0);
            }
            if (is_file($marker) && !unlink($marker)) {
                throw new RuntimeException(
                    'Could not remove the MySQL lock-order marker.'
                );
            }
        }
    }

    private function credentialActions(
        PDO $connection,
        ?PasswordResetMailSenderInterface $mailSender = null
    ): CredentialActionService {
        $tables = WebAdminTableNames::fromPdo(
            $connection,
            self::TABLE_PREFIX
        );

        return new CredentialActionService(
            new CredentialActionRepository(
                $connection,
                $tables
            ),
            WebAdminConfig::defaults(),
            SecurityKey::fromRawBytes(str_repeat('I', 32)),
            new SystemClock(),
            new RandomUuidV4Generator(),
            PasswordHasher::productive(),
            new SecureTokenGenerator(),
            passwordResetMailSender: $mailSender
        );
    }

    private function tableCount(PDO $connection, string $suffix): int
    {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $suffix) !== 1) {
            throw new RuntimeException('Invalid integration table suffix.');
        }

        return (int) $connection->query(
            'SELECT COUNT(*) FROM `'
            . self::TABLE_PREFIX . $suffix
            . '`'
        )->fetchColumn();
    }

    /** @return list<string> */
    private function expectedTables(): array
    {
        $tables = [MigrationRegistry::TABLE];
        foreach (WebAdminInitialSchemaContract::tableSuffixes() as $suffix) {
            $tables[] = self::TABLE_PREFIX . $suffix;
        }
        sort($tables, SORT_STRING);

        return $tables;
    }

    /** @return list<string> */
    private function tableNames(PDO $connection, string $database): array
    {
        $statement = $connection->prepare(
            'SELECT table_name FROM information_schema.tables '
            . 'WHERE table_schema = :schema ORDER BY table_name'
        );
        if ($statement === false || !$statement->execute([
            'schema' => $database,
        ])) {
            throw new RuntimeException(
                'The isolated test DB could not be inspected.'
            );
        }

        $tables = array_values(array_map(
            'strval',
            $statement->fetchAll(PDO::FETCH_COLUMN)
        ));
        sort($tables, SORT_STRING);

        return $tables;
    }

    private function dropOnlyExpectedTables(
        PDO $connection,
        string $database
    ): void {
        $this->assertSelectedDatabase($connection, $database);
        $expected = $this->expectedTables();
        $present = $this->tableNames($connection, $database);
        $targets = array_values(array_intersect($expected, $present));

        if ($targets !== []) {
            if ($connection->exec(
                'SET SESSION FOREIGN_KEY_CHECKS = 0'
            ) === false) {
                throw new RuntimeException(
                    'Could not prepare isolated-table cleanup.'
                );
            }
            try {
                foreach ($targets as $table) {
                    $sql = 'DROP TABLE IF EXISTS '
                        . $this->quoteIdentifier($database)
                        . '.' . $this->quoteIdentifier($table);
                    if ($connection->exec($sql) === false) {
                        throw new RuntimeException(
                            'An isolated integration table was not removed.'
                        );
                    }
                }
            } finally {
                if ($connection->exec(
                    'SET SESSION FOREIGN_KEY_CHECKS = 1'
                ) === false) {
                    throw new RuntimeException(
                        'Could not restore foreign-key checks after cleanup.'
                    );
                }
            }
        }

        if (array_intersect(
            $expected,
            $this->tableNames($connection, $database)
        ) !== []) {
            throw new RuntimeException(
                'The isolated integration tables were not fully removed.'
            );
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/\A[a-z0-9_]+\z/', $identifier) !== 1) {
            throw new RuntimeException(
                'Unsafe MySQL integration identifier rejected.'
            );
        }

        return '`' . $identifier . '`';
    }

    private function createModuleProject(): string
    {
        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-core-mysql-project-'
            . bin2hex(random_bytes(12));
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException(
                'Could not create the temporary module project.'
            );
        }
        $composerJson = json_encode([
            'require' => [
                'liquidstack/core' => '^1.9',
                'liquidstack/webadmin' => '*',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents(
            $path . DIRECTORY_SEPARATOR . 'composer.json',
            $composerJson . PHP_EOL
        ) === false) {
            rmdir($path);
            throw new RuntimeException(
                'Could not prepare the temporary module project.'
            );
        }

        return $path;
    }

    private function removeModuleProject(string $path): void
    {
        $expectedParent = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($path));
        $leaf = basename($path);
        if (
            $expectedParent === false
            || $parent !== $expectedParent
            || preg_match(
                '/\Aliquidstack-core-mysql-project-[a-f0-9]{24}\z/',
                $leaf
            ) !== 1
        ) {
            throw new RuntimeException(
                'Refusing to clean an unvalidated temporary path.'
            );
        }

        $composerJson = $path . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composerJson) && !unlink($composerJson)) {
            throw new RuntimeException(
                'Could not remove the temporary composer.json.'
            );
        }
        if (is_dir($path) && !rmdir($path)) {
            throw new RuntimeException(
                'Could not remove the temporary module project.'
            );
        }
    }
}

final class WebAdminMySqlPasswordResetMailSender implements
    PasswordResetMailSenderInterface
{
    /** @var list<PasswordResetDelivery> */
    public array $deliveries = [];
    public bool $transactionObserved = false;

    public function __construct(private readonly PDO $connection)
    {
    }

    public function send(PasswordResetDelivery $delivery): void
    {
        $this->transactionObserved = $this->transactionObserved
            || $this->connection->inTransaction();
        $this->deliveries[] = $delivery;
    }
}

final class WebAdminMySqlTestConfiguration
{
    private const HOST_ENV = 'LIQUIDSTACK_TEST_MYSQL_HOST';
    private const PORT_ENV = 'LIQUIDSTACK_TEST_MYSQL_PORT';
    private const DATABASE_ENV = 'LIQUIDSTACK_TEST_MYSQL_DATABASE';
    private const USERNAME_ENV = 'LIQUIDSTACK_TEST_MYSQL_USERNAME';
    private const PASSWORD_ENV = 'LIQUIDSTACK_TEST_MYSQL_PASSWORD';
    private const DATABASE_PATTERN =
        '/\Aliquidstack_core_test_[a-z0-9_]{1,32}\z/';

    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password
    ) {
    }

    public static function fromProcess(): self
    {
        $values = [];
        foreach ([
            self::HOST_ENV,
            self::PORT_ENV,
            self::DATABASE_ENV,
            self::USERNAME_ENV,
            self::PASSWORD_ENV,
        ] as $name) {
            $value = getenv($name);
            if (
                !is_string($value)
                || ($name !== self::PASSWORD_ENV && $value === '')
            ) {
                throw new RuntimeException(sprintf(
                    'Required test-only environment variable %s is missing.',
                    $name
                ));
            }
            $values[$name] = $value;
        }

        $host = $values[self::HOST_ENV];
        if (
            strlen($host) > 253
            || preg_match('/\A[a-zA-Z0-9.-]+\z/', $host) !== 1
            || str_contains($host, '..')
            || trim($host, '.-') !== $host
        ) {
            throw new RuntimeException(
                'The test-only MySQL host is invalid.'
            );
        }

        $portValue = $values[self::PORT_ENV];
        if (preg_match('/\A[0-9]{1,5}\z/', $portValue) !== 1) {
            throw new RuntimeException(
                'The test-only MySQL port is invalid.'
            );
        }
        $port = (int) $portValue;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException(
                'The test-only MySQL port is invalid.'
            );
        }

        $database = $values[self::DATABASE_ENV];
        if (
            preg_match(self::DATABASE_PATTERN, $database) !== 1
            || strlen($database) > 64
        ) {
            throw new RuntimeException(
                'The database must use the strict '
                . 'liquidstack_core_test_* test prefix.'
            );
        }

        $username = $values[self::USERNAME_ENV];
        if (strlen($username) > 128 || str_contains($username, "\0")) {
            throw new RuntimeException(
                'The test-only MySQL username is invalid.'
            );
        }

        return new self(
            $host,
            $port,
            $database,
            $username,
            $values[self::PASSWORD_ENV]
        );
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    /** @return array<string, string|int> */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => '[redacted]',
            'password' => '[redacted]',
        ];
    }
}
