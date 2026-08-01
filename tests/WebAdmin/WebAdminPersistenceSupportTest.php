<?php

declare(strict_types=1);

namespace Tests\WebAdmin;

use App\Core\WebAdmin\Bootstrap\BootstrapException;
use App\Core\WebAdmin\Bootstrap\PdoBootstrapRepository;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class WebAdminPersistenceSupportTest extends TestCase
{
    public function testRandomGeneratorProducesUniqueCanonicalUuidV4Values(): void
    {
        $generator = new RandomUuidV4Generator();
        $values = [];

        for ($index = 0; $index < 128; ++$index) {
            $value = $generator->generateV4();
            self::assertMatchesRegularExpression(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $value
            );
            $values[] = $value;
        }

        self::assertCount(128, array_unique($values));
    }

    public function testSystemClockIsExplicitlyUtc(): void
    {
        $now = (new SystemClock())->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertLessThanOrEqual(
            2,
            abs(time() - $now->getTimestamp())
        );
    }

    public function testTableNamesQuoteOnlyValidatedScopedIdentifiers(): void
    {
        $sqlite = new PDO('sqlite::memory:');
        $sqliteNames = WebAdminTableNames::fromPdo(
            $sqlite,
            'client_admin_'
        );
        self::assertSame('sqlite', $sqliteNames->driver());
        self::assertSame(
            '"client_admin_users"',
            $sqliteNames->table('users')
        );

        $mysqlNames = WebAdminTableNames::fromPdo(
            new BootstrapDriverPdo('mysql'),
            'ls_webadmin_'
        );
        self::assertSame('mysql', $mysqlNames->driver());
        self::assertSame(
            '`ls_webadmin_role_capabilities`',
            $mysqlNames->table('role_capabilities')
        );

        $maximumPrefix = 'a' . str_repeat('b', 45) . '_';
        self::assertSame(47, strlen($maximumPrefix));
        self::assertSame(
            66,
            strlen(WebAdminTableNames::fromPdo(
                new BootstrapDriverPdo('mysql'),
                $maximumPrefix
            )->table('role_capabilities')),
            'The quoted identifier contains two quote characters.'
        );
    }

    public function testTableNamesRejectUnsupportedDriversAndUnsafeNames(): void
    {
        foreach ([
            fn (): WebAdminTableNames => WebAdminTableNames::fromPdo(
                new BootstrapDriverPdo('pgsql'),
                'ls_webadmin_'
            ),
            fn (): WebAdminTableNames => WebAdminTableNames::fromPdo(
                new BootstrapDriverPdo('mysql'),
                'Invalid-prefix_'
            ),
            fn (): WebAdminTableNames => WebAdminTableNames::fromPdo(
                new BootstrapDriverPdo('mysql'),
                'a' . str_repeat('b', 46) . '_'
            ),
        ] as $factory) {
            try {
                $factory();
                self::fail('The table namespace should be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $names = WebAdminTableNames::fromPdo(
            new BootstrapDriverPdo('mysql'),
            'ls_webadmin_'
        );
        foreach (['Users', 'users;drop', str_repeat('a', 60)] as $suffix) {
            try {
                $names->table($suffix);
                self::fail('The table suffix should be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testBootstrapExceptionExposesOnlyAStableIssueCode(): void
    {
        $exception = new BootstrapException('bootstrap.environment_invalid');

        self::assertSame(
            'bootstrap.environment_invalid',
            $exception->issueCode()
        );
        self::assertSame(
            'No se pudo completar el bootstrap de WebAdmin de forma segura.',
            $exception->getMessage()
        );
        self::assertStringNotContainsString(
            'environment',
            $exception->getMessage()
        );
    }

    public function testMysqlFalseTransactionReturnsFailClosedAndRollback(): void
    {
        $beginFailure = new BootstrapTransactionPdo(false, true);
        $repository = new PdoBootstrapRepository(
            $beginFailure,
            WebAdminTableNames::fromPdo($beginFailure, 'ls_webadmin_')
        );
        try {
            $repository->transaction(static fn (): string => 'unreachable');
            self::fail('A false beginTransaction() must fail.');
        } catch (BootstrapException $exception) {
            self::assertSame(
                'bootstrap.persistence_failed',
                $exception->issueCode()
            );
        }
        self::assertSame(0, $beginFailure->rollbackCalls);

        $commitFailure = new BootstrapTransactionPdo(true, false);
        $repository = new PdoBootstrapRepository(
            $commitFailure,
            WebAdminTableNames::fromPdo($commitFailure, 'ls_webadmin_')
        );
        try {
            $repository->transaction(static fn (): string => 'value');
            self::fail('A false commit() must fail.');
        } catch (BootstrapException $exception) {
            self::assertSame(
                'bootstrap.persistence_failed',
                $exception->issueCode()
            );
        }
        self::assertSame(1, $commitFailure->rollbackCalls);
        self::assertFalse($commitFailure->inTransaction());
    }

    public function testMysqlLocksTheBootstrapStateRowBeforeWork(): void
    {
        $pdo = new BootstrapLockingPdo();
        $repository = new PdoBootstrapRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        );

        $state = $repository->transaction(
            static fn (PdoBootstrapRepository $repository): string =>
                $repository->lockInitialAccountsState()
        );

        self::assertSame('pending', $state);
        self::assertCount(1, $pdo->preparedSql);
        self::assertSame(
            'SELECT value_text FROM `ls_webadmin_state` '
            . 'WHERE state_key = :state_key FOR UPDATE',
            $pdo->preparedSql[0]
        );
        self::assertSame([
            'state_key' => PdoBootstrapRepository::STATE_KEY,
        ], $pdo->lastParameters);
        self::assertFalse($pdo->inTransaction());
    }

    public function testMysqlRequiresNativePreparesAndChecksExecuteReturn(): void
    {
        $emulated = new BootstrapLockingPdo(true, true);
        try {
            new PdoBootstrapRepository(
                $emulated,
                WebAdminTableNames::fromPdo($emulated, 'ls_webadmin_')
            );
            self::fail('Emulated prepares must be rejected.');
        } catch (BootstrapException $exception) {
            self::assertSame(
                'bootstrap.pdo_configuration_invalid',
                $exception->issueCode()
            );
        }

        $executeFailure = new BootstrapLockingPdo(false, false);
        $repository = new PdoBootstrapRepository(
            $executeFailure,
            WebAdminTableNames::fromPdo(
                $executeFailure,
                'ls_webadmin_'
            )
        );
        try {
            $repository->transaction(
                static fn (PdoBootstrapRepository $repository): string =>
                    $repository->lockInitialAccountsState()
            );
            self::fail('A false statement execute() must fail.');
        } catch (BootstrapException $exception) {
            self::assertSame(
                'bootstrap.persistence_failed',
                $exception->issueCode()
            );
        }
        self::assertSame(1, $executeFailure->rollbackCalls);
        self::assertFalse($executeFailure->inTransaction());
    }

    public function testNestedSqliteTransactionFailsClosedAndRepositoryRecovers(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $repository = new PdoBootstrapRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        );

        try {
            $repository->transaction(
                static fn (PdoBootstrapRepository $repository): mixed =>
                    $repository->transaction(static fn (): string => 'nested')
            );
            self::fail('A nested transaction must fail.');
        } catch (BootstrapException $exception) {
            self::assertSame(
                'bootstrap.transaction_already_active',
                $exception->issueCode()
            );
        }

        self::assertSame(
            'recovered',
            $repository->transaction(static fn (): string => 'recovered')
        );
    }

    public function testBootstrapRepositoryRequiresSqliteForeignKeys(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            new PdoBootstrapRepository(
                $pdo,
                WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
            );
            self::fail('SQLite foreign-key enforcement must be required.');
        } catch (BootstrapException $exception) {
            self::assertSame(
                'bootstrap.pdo_configuration_invalid',
                $exception->issueCode()
            );
        }
    }

    public function testCaughtNestedAttemptDoesNotLoseOuterTransactionOwnership(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $repository = new PdoBootstrapRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        );

        $result = $repository->transaction(
            static function (PdoBootstrapRepository $repository): string {
                try {
                    $repository->transaction(
                        static fn (): string => 'unreachable'
                    );
                } catch (BootstrapException $exception) {
                    self::assertSame(
                        'bootstrap.transaction_already_active',
                        $exception->issueCode()
                    );
                }

                try {
                    $repository->transaction(
                        static fn (): string => 'still-unreachable'
                    );
                    self::fail('The outer transaction must remain owned.');
                } catch (BootstrapException $exception) {
                    self::assertSame(
                        'bootstrap.transaction_already_active',
                        $exception->issueCode()
                    );
                }

                return 'outer-completed';
            }
        );

        self::assertSame('outer-completed', $result);
    }
}

final class BootstrapDriverPdo extends PDO
{
    public function __construct(private readonly string $driver)
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute !== PDO::ATTR_DRIVER_NAME) {
            throw new InvalidArgumentException('Unsupported test attribute.');
        }

        return $this->driver;
    }
}

final class BootstrapTransactionPdo extends PDO
{
    private bool $active = false;
    public int $rollbackCalls = 0;

    public function __construct(
        private readonly bool $beginResult,
        private readonly bool $commitResult
    ) {
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            default => null,
        };
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function beginTransaction(): bool
    {
        if ($this->beginResult) {
            $this->active = true;
        }

        return $this->beginResult;
    }

    public function commit(): bool
    {
        if ($this->commitResult) {
            $this->active = false;
        }

        return $this->commitResult;
    }

    public function rollBack(): bool
    {
        ++$this->rollbackCalls;
        $this->active = false;

        return true;
    }
}

final class BootstrapLockingPdo extends PDO
{
    private bool $active = false;

    /** @var list<string> */
    public array $preparedSql = [];

    /** @var array<string, mixed> */
    public array $lastParameters = [];

    public int $rollbackCalls = 0;

    public function __construct(
        public readonly bool $executeResult = true,
        private readonly bool $emulatedPrepares = false
    )
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => $this->emulatedPrepares,
            default => null,
        };
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function beginTransaction(): bool
    {
        $this->active = true;

        return true;
    }

    public function commit(): bool
    {
        $this->active = false;

        return true;
    }

    public function rollBack(): bool
    {
        ++$this->rollbackCalls;
        $this->active = false;

        return true;
    }

    public function prepare(
        string $query,
        array $options = []
    ): \PDOStatement|false {
        $this->preparedSql[] = $query;

        return new BootstrapStateStatement($this);
    }
}

final class BootstrapStateStatement extends \PDOStatement
{
    public function __construct(private readonly BootstrapLockingPdo $pdo)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->lastParameters = $params ?? [];

        return $this->pdo->executeResult;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return PdoBootstrapRepository::STATE_PENDING;
    }
}
