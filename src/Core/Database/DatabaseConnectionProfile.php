<?php

declare(strict_types=1);

namespace App\Core\Database;

final class DatabaseConnectionProfile
{
    public const SHARED = 'shared';
    public const LIQUIDSTACK = 'liquidstack';

    public const SHARED_ENVIRONMENT_NAMES = [
        'BBDD_SERVER',
        'BBDD_USER',
        'BBDD_PASS',
        'BBDD_NAME',
    ];

    public const LIQUIDSTACK_ENVIRONMENT_NAMES = [
        'LIQUIDSTACK_DB_HOST',
        'LIQUIDSTACK_DB_PORT',
        'LIQUIDSTACK_DB_NAME',
        'LIQUIDSTACK_DB_USER',
        'LIQUIDSTACK_DB_PASSWORD',
        'LIQUIDSTACK_DB_CHARSET',
    ];

    public static function isSupported(mixed $connection): bool
    {
        return is_string($connection) && in_array($connection, [
            self::SHARED,
            self::LIQUIDSTACK,
        ], true);
    }

    /** @return list<string> */
    public static function environmentNames(string $connection): array
    {
        return match ($connection) {
            self::SHARED => self::SHARED_ENVIRONMENT_NAMES,
            self::LIQUIDSTACK => self::LIQUIDSTACK_ENVIRONMENT_NAMES,
            default => throw new DatabaseConnectionException(
                'database.connection_unsupported'
            ),
        };
    }
}
