<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use App\Core\Database\MySqlSessionContract;
use PDO;
use Throwable;

/** Lightweight, read-only PDO/session contract shared by CLI and HTTP gates. */
final class MigrationDatabaseConnectionContract
{
    /** @return list<string> Stable issue codes without connection details. */
    public function issueCodes(PDO $pdo, string $driver): array
    {
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            return ['database.driver_unsupported'];
        }

        try {
            if (
                (int) $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
            ) {
                return ['database.pdo_exception_mode_required'];
            }
            if (
                $driver === 'mysql'
                && (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)
            ) {
                return ['database.mysql_native_prepares_required'];
            }
        } catch (Throwable) {
            return ['database.pdo_contract_unavailable'];
        }

        if ($driver === 'mysql') {
            return (new MySqlSessionContract())->issueCodes($pdo);
        }

        try {
            $foreignKeys = $pdo->query('PRAGMA foreign_keys')->fetchColumn();
        } catch (Throwable) {
            $foreignKeys = false;
        }

        return (string) $foreignKeys === '1'
            ? []
            : ['database.sqlite_foreign_keys_disabled'];
    }
}
