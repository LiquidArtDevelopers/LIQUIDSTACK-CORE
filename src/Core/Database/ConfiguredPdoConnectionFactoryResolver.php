<?php

declare(strict_types=1);

namespace App\Core\Database;

final class ConfiguredPdoConnectionFactoryResolver
{
    /** @param array<string, mixed> $environment */
    public function resolve(
        string $connection,
        #[\SensitiveParameter] array $environment
    ): PdoConnectionFactoryInterface {
        return match ($connection) {
            DatabaseConnectionProfile::SHARED =>
                new SharedPdoConnectionFactory($environment),
            DatabaseConnectionProfile::LIQUIDSTACK =>
                new LiquidStackPdoConnectionFactory($environment),
            default => throw new DatabaseConnectionException(
                'database.connection_unsupported'
            ),
        };
    }

    public function environmentValidator(
        string $connection
    ): DatabaseEnvironmentValidatorInterface {
        return match ($connection) {
            DatabaseConnectionProfile::SHARED =>
                new SharedDatabaseEnvironmentValidator(),
            DatabaseConnectionProfile::LIQUIDSTACK =>
                new LiquidStackDatabaseEnvironmentValidator(),
            default => throw new DatabaseConnectionException(
                'database.connection_unsupported'
            ),
        };
    }
}
