<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;
use RuntimeException;
use Throwable;

final class MySqlSessionContract
{
    public function enforce(PDO $pdo): void
    {
        $rawVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $isMariaDb = MySqlServerCapabilities::isMariaDb($rawVersion);
        $assignments = [
            "time_zone = '+00:00'",
            'foreign_key_checks = 1',
            'unique_checks = 1',
            "sql_mode = CASE WHEN FIND_IN_SET('STRICT_TRANS_TABLES', "
                . "@@SESSION.sql_mode) > 0 OR FIND_IN_SET('STRICT_ALL_TABLES', "
                . "@@SESSION.sql_mode) > 0 THEN @@SESSION.sql_mode ELSE "
                . "CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), "
                . "'STRICT_TRANS_TABLES') END",
        ];
        if ($isMariaDb) {
            $assignments[] = 'check_constraint_checks = 1';
        }
        if ($pdo->exec(
            'SET SESSION ' . implode(', ', $assignments)
        ) === false || $this->issueCodes($pdo) !== []) {
            throw new RuntimeException(
                'The MySQL session contract could not be enforced.'
            );
        }
    }

    /** @return list<string> */
    public function issueCodes(PDO $pdo): array
    {
        try {
            $rawVersion = (string) $pdo->getAttribute(
                PDO::ATTR_SERVER_VERSION
            );
            $isMariaDb = MySqlServerCapabilities::isMariaDb($rawVersion);
            $fields = [
                '@@SESSION.time_zone AS time_zone',
                '@@SESSION.foreign_key_checks AS foreign_key_checks',
                '@@SESSION.unique_checks AS unique_checks',
                '@@SESSION.sql_mode AS sql_mode',
            ];
            if ($isMariaDb) {
                $fields[] = '@@SESSION.check_constraint_checks '
                    . 'AS check_constraint_checks';
            }
            $row = $pdo->query('SELECT ' . implode(', ', $fields))
                ->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return ['database.mysql_session_contract_unavailable'];
            }
        } catch (Throwable) {
            return ['database.mysql_session_contract_unavailable'];
        }

        $issues = [];
        if ((string) ($row['time_zone'] ?? '') !== '+00:00') {
            $issues[] = 'database.mysql_utc_required';
        }
        if (!$this->enabled($row['foreign_key_checks'] ?? null)) {
            $issues[] = 'database.mysql_foreign_keys_disabled';
        }
        if (!$this->enabled($row['unique_checks'] ?? null)) {
            $issues[] = 'database.mysql_unique_checks_disabled';
        }
        if (
            $isMariaDb
            && !$this->enabled($row['check_constraint_checks'] ?? null)
        ) {
            $issues[] = 'database.mysql_check_constraints_disabled';
        }
        $sqlModes = array_map(
            'strtoupper',
            array_filter(array_map(
                'trim',
                explode(',', (string) ($row['sql_mode'] ?? ''))
            ))
        );
        if (
            !in_array('STRICT_TRANS_TABLES', $sqlModes, true)
            && !in_array('STRICT_ALL_TABLES', $sqlModes, true)
        ) {
            $issues[] = 'database.mysql_strict_mode_required';
        }

        return $issues;
    }

    private function enabled(mixed $value): bool
    {
        return in_array(strtoupper((string) $value), ['1', 'ON'], true);
    }
}
