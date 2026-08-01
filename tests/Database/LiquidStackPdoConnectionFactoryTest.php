<?php

declare(strict_types=1);

use App\Core\Database\DatabaseConnectionException;
use App\Core\Database\LiquidStackPdoConnectionFactory;
use PHPUnit\Framework\TestCase;

final class LiquidStackPdoConnectionFactoryTest extends TestCase
{
    public function testBuildsTheDedicatedConnectionAndIgnoresLegacyCredentials(): void
    {
        $captured = [];
        $environment = $this->environment() + [
            'BBDD_SERVER' => 'legacy;must-not-win',
            'BBDD_USER' => 'legacy-user',
            'BBDD_PASS' => 'legacy-secret',
            'BBDD_NAME' => 'legacy_database',
        ];
        $factory = new LiquidStackPdoConnectionFactory(
            $environment,
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
        self::assertSame(
            'mysql:host=dedicated.internal;port=3308;dbname=liquidstack_modules;charset=utf8mb4',
            $captured['dsn']
        );
        self::assertSame('dedicated-user', $captured['username']);
        self::assertSame('dedicated-secret', $captured['password']);
        self::assertSame(PDO::ERRMODE_EXCEPTION, $captured['options'][PDO::ATTR_ERRMODE]);
        self::assertFalse($captured['options'][PDO::ATTR_EMULATE_PREPARES]);
        self::assertFalse($captured['options'][PDO::ATTR_STRINGIFY_FETCHES]);
        self::assertFalse($captured['options'][PDO::ATTR_PERSISTENT]);
    }

    public function testLegacyCredentialsAreNeverAFallbackForDedicatedProfile(): void
    {
        $connectorCalled = false;
        $factory = new LiquidStackPdoConnectionFactory(
            [
                'BBDD_SERVER' => 'localhost',
                'BBDD_USER' => 'legacy-user',
                'BBDD_PASS' => 'legacy-secret',
                'BBDD_NAME' => 'legacy_database',
            ],
            static function () use (&$connectorCalled): PDO {
                $connectorCalled = true;

                return new PDO('sqlite::memory:');
            }
        );

        try {
            $factory->connect();
            self::fail('El perfil dedicado no debía reutilizar BBDD_*.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame(
                'database.environment_missing',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'legacy-secret',
                $exception->getMessage()
            );
        }

        self::assertFalse($connectorCalled);
    }

    public function testInvalidDedicatedProfileStopsBeforeTheConnector(): void
    {
        $connectorCalled = false;
        $environment = $this->environment();
        $environment['LIQUIDSTACK_DB_CHARSET'] =
            'utf8mb4;password=must-not-leak';
        $factory = new LiquidStackPdoConnectionFactory(
            $environment,
            static function () use (&$connectorCalled): PDO {
                $connectorCalled = true;

                return new PDO('sqlite::memory:');
            }
        );

        try {
            $factory->connect();
            self::fail('El charset inseguro debía impedir la conexión.');
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

    public function testConnectorFailureNeverLeaksDedicatedCredentials(): void
    {
        $factory = new LiquidStackPdoConnectionFactory(
            $this->environment(),
            static function (): PDO {
                throw new RuntimeException(
                    'dedicated.internal dedicated-user dedicated-secret'
                );
            }
        );

        try {
            $factory->connect();
            self::fail('El error interno del driver debía ocultarse.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame(
                'database.connection_failed',
                $exception->issueCode()
            );
            foreach ([
                'dedicated.internal',
                'dedicated-user',
                'dedicated-secret',
            ] as $secret) {
                self::assertStringNotContainsString(
                    $secret,
                    $exception->getMessage()
                );
            }
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return [
            'LIQUIDSTACK_DB_HOST' => 'dedicated.internal',
            'LIQUIDSTACK_DB_PORT' => '3308',
            'LIQUIDSTACK_DB_NAME' => 'liquidstack_modules',
            'LIQUIDSTACK_DB_USER' => 'dedicated-user',
            'LIQUIDSTACK_DB_PASSWORD' => 'dedicated-secret',
            'LIQUIDSTACK_DB_CHARSET' => 'utf8mb4',
        ];
    }
}
