<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use PDO;

enum MigrationDatabaseDriver: string
{
    case MYSQL = 'mysql';
    case SQLITE = 'sqlite';

    public static function fromPdo(PDO $pdo): self
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($driver)) {
            throw new MigrationException('database.driver_unsupported');
        }

        return match ($driver) {
            self::MYSQL->value => self::MYSQL,
            self::SQLITE->value => self::SQLITE,
            default => throw new MigrationException(
                'database.driver_unsupported'
            ),
        };
    }
}
