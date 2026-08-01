<?php

declare(strict_types=1);

use App\Core\Database\DatabaseConnectionException;
use App\Core\Database\SharedPdoConnectionFactory;
use PHPUnit\Framework\TestCase;

final class MysqlSessionStatementFixture extends PDOStatement
{
    /** @param array<string, mixed> $row */
    public function __construct(private readonly array $row)
    {
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return $this->row;
    }
}

final class MysqlUtcPdoFixture extends PDO
{
    /** @var list<string> */
    public array $executedSql = [];
    /** @var list<string> */
    public array $queriedSql = [];
    public bool $failExecution = false;
    public string $serverVersion = '8.0.36';
    /** @var array<string, mixed> */
    public array $sessionRow = [
        'time_zone' => '+00:00',
        'foreign_key_checks' => 1,
        'unique_checks' => 1,
        'sql_mode' => 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION',
    ];

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_SERVER_VERSION => $this->serverVersion,
            default => parent::getAttribute($attribute),
        };
    }

    public function exec(string $statement): int|false
    {
        $this->executedSql[] = $statement;

        return $this->failExecution ? false : 0;
    }

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        $this->queriedSql[] = $query;

        return new MysqlSessionStatementFixture($this->sessionRow);
    }
}

final class SharedPdoConnectionFactoryTest extends TestCase
{
    public function testBuildsAConstrainedMysqlConnectionWithoutReadingFiles(): void
    {
        $captured = [];
        $factory = new SharedPdoConnectionFactory(
            [
                'BBDD_SERVER' => 'db.internal:3307',
                'BBDD_USER' => 'liquidstack',
                'BBDD_PASS' => '',
                'BBDD_NAME' => 'liquidstack_test',
            ],
            static function (
                string $dsn,
                string $username,
                string $password,
                array $options
            ) use (&$captured): \PDO {
                $captured = compact(
                    'dsn',
                    'username',
                    'password',
                    'options'
                );

                return new \PDO('sqlite::memory:');
            }
        );

        self::assertInstanceOf(\PDO::class, $factory->connect());
        self::assertSame(
            'mysql:host=db.internal;port=3307;dbname=liquidstack_test;charset=utf8mb4',
            $captured['dsn']
        );
        self::assertSame('liquidstack', $captured['username']);
        self::assertSame('', $captured['password']);
        self::assertSame(
            \PDO::ERRMODE_EXCEPTION,
            $captured['options'][\PDO::ATTR_ERRMODE]
        );
        self::assertFalse(
            $captured['options'][\PDO::ATTR_EMULATE_PREPARES]
        );
        self::assertFalse(
            $captured['options'][\PDO::ATTR_STRINGIFY_FETCHES]
        );
        self::assertSame(
            \PDO::FETCH_ASSOC,
            $captured['options'][\PDO::ATTR_DEFAULT_FETCH_MODE]
        );
        self::assertFalse($captured['options'][\PDO::ATTR_PERSISTENT]);
        self::assertSame(5, $captured['options'][\PDO::ATTR_TIMEOUT]);
    }

    public function testAcceptsBracketedIpv6WithoutDsnInjection(): void
    {
        $capturedDsn = null;
        $factory = new SharedPdoConnectionFactory(
            $this->environment('[2001:db8::1]:3308'),
            static function (string $dsn) use (&$capturedDsn): \PDO {
                $capturedDsn = $dsn;

                return new \PDO('sqlite::memory:');
            }
        );

        $factory->connect();

        self::assertSame(
            'mysql:host=2001:db8::1;port=3308;dbname=liquidstack_test;charset=utf8mb4',
            $capturedDsn
        );
    }

    public function testFixesEveryMysqlSessionToUtcBeforeReturningIt(): void
    {
        $pdo = new MysqlUtcPdoFixture();
        $factory = new SharedPdoConnectionFactory(
            $this->environment('localhost'),
            static fn (): PDO => $pdo
        );

        self::assertSame($pdo, $factory->connect());
        self::assertCount(1, $pdo->executedSql);
        self::assertStringContainsString(
            "time_zone = '+00:00'",
            $pdo->executedSql[0]
        );
        self::assertStringContainsString(
            'foreign_key_checks = 1',
            $pdo->executedSql[0]
        );
        self::assertStringContainsString(
            'unique_checks = 1',
            $pdo->executedSql[0]
        );
        self::assertStringContainsString('sql_mode = CASE', $pdo->executedSql[0]);
        self::assertCount(1, $pdo->queriedSql);
        self::assertStringContainsString(
            '@@SESSION.sql_mode',
            $pdo->queriedSql[0]
        );
    }

    public function testEnablesAndVerifiesMariaDbCheckConstraints(): void
    {
        $pdo = new MysqlUtcPdoFixture();
        $pdo->serverVersion = '10.4.32-MariaDB';
        $pdo->sessionRow['check_constraint_checks'] = 1;

        self::assertSame($pdo, (new SharedPdoConnectionFactory(
            $this->environment('localhost'),
            static fn (): PDO => $pdo
        ))->connect());
        self::assertStringContainsString(
            'check_constraint_checks = 1',
            $pdo->executedSql[0]
        );
        self::assertStringContainsString(
            '@@SESSION.check_constraint_checks',
            $pdo->queriedSql[0]
        );
    }

    public function testRejectsAConnectionThatCannotProveStrictMode(): void
    {
        $pdo = new MysqlUtcPdoFixture();
        $pdo->sessionRow['sql_mode'] = 'NO_ENGINE_SUBSTITUTION';

        $this->expectException(DatabaseConnectionException::class);
        (new SharedPdoConnectionFactory(
            $this->environment('localhost'),
            static fn (): PDO => $pdo
        ))->connect();
    }

    public function testTimezoneInitializationFailureIsSanitized(): void
    {
        $pdo = new MysqlUtcPdoFixture();
        $pdo->failExecution = true;
        $factory = new SharedPdoConnectionFactory(
            $this->environment('localhost'),
            static fn (): PDO => $pdo
        );

        try {
            $factory->connect();
            self::fail('Una sesión sin UTC debía rechazarse.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame(
                'database.connection_failed',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'timezone',
                strtolower($exception->getMessage())
            );
        }
    }

    /** @dataProvider invalidEnvironmentProvider */
    public function testRejectsMissingOrUnsafeEnvironment(
        array $environment,
        string $expectedCode
    ): void {
        try {
            (new SharedPdoConnectionFactory($environment))->connect();
            self::fail('El entorno inseguro debía rechazarse antes de conectar.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame($expectedCode, $exception->issueCode());
            self::assertStringNotContainsString(
                'must-not-leak',
                $exception->getMessage()
            );
        }
    }

    public static function invalidEnvironmentProvider(): iterable
    {
        yield 'missing' => [
            ['BBDD_SERVER' => 'localhost'],
            'database.environment_missing',
        ];
        yield 'dsn injection' => [
            [
                'BBDD_SERVER' => 'localhost;dbname=must-not-leak',
                'BBDD_USER' => 'user',
                'BBDD_PASS' => 'must-not-leak',
                'BBDD_NAME' => 'database',
            ],
            'database.environment_invalid',
        ];
        yield 'invalid database' => [
            [
                'BBDD_SERVER' => 'localhost',
                'BBDD_USER' => 'user',
                'BBDD_PASS' => 'must-not-leak',
                'BBDD_NAME' => 'database;must-not-leak',
            ],
            'database.environment_invalid',
        ];
        yield 'invalid port' => [
            [
                'BBDD_SERVER' => 'localhost:99999',
                'BBDD_USER' => 'user',
                'BBDD_PASS' => 'must-not-leak',
                'BBDD_NAME' => 'database',
            ],
            'database.environment_invalid',
        ];
    }

    public function testConnectorFailureDoesNotLeakItsMessage(): void
    {
        $factory = new SharedPdoConnectionFactory(
            $this->environment('localhost'),
            static function (): \PDO {
                throw new RuntimeException('must-not-leak');
            }
        );

        try {
            $factory->connect();
            self::fail('El fallo del conector debía sanearse.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame(
                'database.connection_failed',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'must-not-leak',
                $exception->getMessage()
            );
            self::assertNull($exception->getPrevious());
        }
    }

    public function testInvalidEnvironmentIsRejectedBeforeCallingConnector(): void
    {
        $connectorCalled = false;
        $factory = new SharedPdoConnectionFactory(
            [
                'BBDD_SERVER' => 'localhost;must-not-leak',
                'BBDD_USER' => 'user',
                'BBDD_PASS' => 'must-not-leak',
                'BBDD_NAME' => 'database',
            ],
            static function () use (&$connectorCalled): PDO {
                $connectorCalled = true;

                return new PDO('sqlite::memory:');
            }
        );

        try {
            $factory->connect();
            self::fail('El conector no debía recibir un entorno inválido.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame(
                'database.environment_invalid',
                $exception->issueCode()
            );
        }

        self::assertFalse($connectorCalled);
    }

    /** @return array<string, string> */
    private function environment(string $server): array
    {
        return [
            'BBDD_SERVER' => $server,
            'BBDD_USER' => 'liquidstack',
            'BBDD_PASS' => 'must-not-leak',
            'BBDD_NAME' => 'liquidstack_test',
        ];
    }
}
