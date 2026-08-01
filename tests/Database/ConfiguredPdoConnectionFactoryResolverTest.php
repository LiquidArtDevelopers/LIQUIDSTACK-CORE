<?php

declare(strict_types=1);

use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\DatabaseConnectionException;
use App\Core\Database\DatabaseConnectionProfile;
use App\Core\Database\LiquidStackDatabaseEnvironmentValidator;
use App\Core\Database\LiquidStackPdoConnectionFactory;
use App\Core\Database\SharedDatabaseEnvironmentValidator;
use App\Core\Database\SharedPdoConnectionFactory;
use PHPUnit\Framework\TestCase;

final class ConfiguredPdoConnectionFactoryResolverTest extends TestCase
{
    public function testResolvesOnlyTheExplicitConnectionProfile(): void
    {
        $resolver = new ConfiguredPdoConnectionFactoryResolver();
        $environment = $this->sharedEnvironment()
            + $this->liquidStackEnvironment();

        self::assertInstanceOf(
            SharedPdoConnectionFactory::class,
            $resolver->resolve(DatabaseConnectionProfile::SHARED, $environment)
        );
        self::assertInstanceOf(
            LiquidStackPdoConnectionFactory::class,
            $resolver->resolve(
                DatabaseConnectionProfile::LIQUIDSTACK,
                $environment
            )
        );
        self::assertInstanceOf(
            SharedDatabaseEnvironmentValidator::class,
            $resolver->environmentValidator(
                DatabaseConnectionProfile::SHARED
            )
        );
        self::assertInstanceOf(
            LiquidStackDatabaseEnvironmentValidator::class,
            $resolver->environmentValidator(
                DatabaseConnectionProfile::LIQUIDSTACK
            )
        );
    }

    public function testDedicatedSelectionNeverFallsBackToCompleteSharedEnvironment(): void
    {
        $factory = (new ConfiguredPdoConnectionFactoryResolver())->resolve(
            DatabaseConnectionProfile::LIQUIDSTACK,
            $this->sharedEnvironment()
        );

        try {
            $factory->connect();
            self::fail('La selección dedicada no debía usar BBDD_*.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame(
                'database.environment_missing',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'shared-secret',
                $exception->getMessage()
            );
        }
    }

    public function testSharedSelectionNeverFallsBackToCompleteDedicatedEnvironment(): void
    {
        $factory = (new ConfiguredPdoConnectionFactoryResolver())->resolve(
            DatabaseConnectionProfile::SHARED,
            $this->liquidStackEnvironment()
        );

        try {
            $factory->connect();
            self::fail('La selección shared no debía usar LIQUIDSTACK_DB_*.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame(
                'database.environment_missing',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'dedicated-secret',
                $exception->getMessage()
            );
        }
    }

    public function testEnvironmentNameCatalogIsStableForBothProfiles(): void
    {
        self::assertSame([
            'BBDD_SERVER',
            'BBDD_USER',
            'BBDD_PASS',
            'BBDD_NAME',
        ], DatabaseConnectionProfile::environmentNames(
            DatabaseConnectionProfile::SHARED
        ));
        self::assertSame([
            'LIQUIDSTACK_DB_HOST',
            'LIQUIDSTACK_DB_PORT',
            'LIQUIDSTACK_DB_NAME',
            'LIQUIDSTACK_DB_USER',
            'LIQUIDSTACK_DB_PASSWORD',
            'LIQUIDSTACK_DB_CHARSET',
        ], DatabaseConnectionProfile::environmentNames(
            DatabaseConnectionProfile::LIQUIDSTACK
        ));
    }

    /** @dataProvider unsupportedOperationProvider */
    public function testUnsupportedConnectionIsRejectedWithoutReflectingIt(
        string $operation
    ): void {
        $resolver = new ConfiguredPdoConnectionFactoryResolver();
        $unsupported = 'private-secret-profile';

        try {
            if ($operation === 'resolve') {
                $resolver->resolve($unsupported, []);
            } else {
                $resolver->environmentValidator($unsupported);
            }
            self::fail('Un selector desconocido debía rechazarse.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame(
                'database.connection_unsupported',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                $unsupported,
                $exception->getMessage()
            );
            self::assertNull($exception->getPrevious());
        }
    }

    public static function unsupportedOperationProvider(): iterable
    {
        yield 'factory resolver' => ['resolve'];
        yield 'validator resolver' => ['environmentValidator'];
    }

    /** @return array<string, string> */
    private function sharedEnvironment(): array
    {
        return [
            'BBDD_SERVER' => 'localhost',
            'BBDD_USER' => 'shared-user',
            'BBDD_PASS' => 'shared-secret',
            'BBDD_NAME' => 'shared_database',
        ];
    }

    /** @return array<string, string> */
    private function liquidStackEnvironment(): array
    {
        return [
            'LIQUIDSTACK_DB_HOST' => 'dedicated.internal',
            'LIQUIDSTACK_DB_PORT' => '3306',
            'LIQUIDSTACK_DB_NAME' => 'liquidstack_modules',
            'LIQUIDSTACK_DB_USER' => 'dedicated-user',
            'LIQUIDSTACK_DB_PASSWORD' => 'dedicated-secret',
            'LIQUIDSTACK_DB_CHARSET' => 'utf8mb4',
        ];
    }
}
