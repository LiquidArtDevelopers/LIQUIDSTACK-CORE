<?php

declare(strict_types=1);

use App\Core\Database\DatabaseConnectionException;
use App\Core\Database\DatabaseEnvironmentValidatorInterface;
use App\Core\Database\StrictPdoConnectionFactory;
use PHPUnit\Framework\TestCase;

final class StrictPdoValidatorFixture implements
    DatabaseEnvironmentValidatorInterface
{
    /** @var array<string, mixed>|null */
    public ?array $receivedEnvironment = null;

    public function __construct(
        private readonly ?DatabaseConnectionException $failure = null
    ) {
    }

    public function inspect(array $environment): array
    {
        return ['missing' => [], 'invalid' => [], 'ready' => true];
    }

    public function connectionParameters(array $environment): array
    {
        $this->receivedEnvironment = $environment;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return [
            'mysql:host=db.internal;port=3307;dbname=modules;charset=utf8mb4',
            'module_user',
            'strict-secret',
        ];
    }
}

final class StrictPdoConnectionFactoryTest extends TestCase
{
    public function testPassesCallerOwnedEnvironmentAndConstrainedPdoOptions(): void
    {
        $environment = ['PRIVATE_VALUE' => 'strict-secret'];
        $validator = new StrictPdoValidatorFixture();
        $captured = [];
        $factory = new StrictPdoConnectionFactory(
            $environment,
            $validator,
            static function (
                string $dsn,
                string $username,
                string $password,
                array $options
            ) use (&$captured): PDO {
                $captured = compact(
                    'dsn',
                    'username',
                    'password',
                    'options'
                );

                return new PDO('sqlite::memory:');
            }
        );

        self::assertInstanceOf(PDO::class, $factory->connect());
        self::assertSame($environment, $validator->receivedEnvironment);
        self::assertSame(
            'mysql:host=db.internal;port=3307;dbname=modules;charset=utf8mb4',
            $captured['dsn']
        );
        self::assertSame('module_user', $captured['username']);
        self::assertSame('strict-secret', $captured['password']);
        self::assertSame(PDO::ERRMODE_EXCEPTION, $captured['options'][PDO::ATTR_ERRMODE]);
        self::assertSame(PDO::FETCH_ASSOC, $captured['options'][PDO::ATTR_DEFAULT_FETCH_MODE]);
        self::assertFalse($captured['options'][PDO::ATTR_EMULATE_PREPARES]);
        self::assertFalse($captured['options'][PDO::ATTR_STRINGIFY_FETCHES]);
        self::assertFalse($captured['options'][PDO::ATTR_PERSISTENT]);
        self::assertSame(5, $captured['options'][PDO::ATTR_TIMEOUT]);
        self::assertSame($environment, ['PRIVATE_VALUE' => 'strict-secret']);
    }

    public function testValidationFailureIsPreservedBeforeConnectorInvocation(): void
    {
        $connectorCalled = false;
        $factory = new StrictPdoConnectionFactory(
            ['PRIVATE_VALUE' => 'must-not-leak'],
            new StrictPdoValidatorFixture(
                new DatabaseConnectionException(
                    'database.environment_invalid'
                )
            ),
            static function () use (&$connectorCalled): PDO {
                $connectorCalled = true;

                return new PDO('sqlite::memory:');
            }
        );

        try {
            $factory->connect();
            self::fail('El conector no debía ejecutarse con perfil inválido.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame(
                'database.environment_invalid',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'must-not-leak',
                $exception->getMessage()
            );
        }

        self::assertFalse($connectorCalled);
    }

    public function testConnectorFailureIsCollapsedWithoutPreviousOrSecrets(): void
    {
        $factory = new StrictPdoConnectionFactory(
            ['PRIVATE_VALUE' => 'must-not-leak'],
            new StrictPdoValidatorFixture(),
            static function (): PDO {
                throw new RuntimeException(
                    'dsn password strict-secret must-not-leak'
                );
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
                'strict-secret',
                $exception->getMessage()
            );
            self::assertStringNotContainsString(
                'must-not-leak',
                $exception->getMessage()
            );
            self::assertNull($exception->getPrevious());
        }
    }

    public function testTransientDriverConnectionFailureGetsAStableCode(): void
    {
        $factory = new StrictPdoConnectionFactory(
            [],
            new StrictPdoValidatorFixture(),
            static function (): PDO {
                $exception = new PDOException('private host', 2002);
                $exception->errorInfo = ['HY000', 2002, 'private host'];
                throw $exception;
            }
        );

        try {
            $factory->connect();
            self::fail('Transient connection failure was expected.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame(
                'database.connection_unavailable',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'private host',
                $exception->getMessage()
            );
        }
    }
}
