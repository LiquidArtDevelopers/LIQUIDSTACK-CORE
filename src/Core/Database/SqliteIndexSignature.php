<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;

/** Builds an exact portable signature from SQLite PRAGMA index metadata. */
final class SqliteIndexSignature
{
    /**
     * @param array<string, mixed> $index
     * @param list<string> $allowedOrigins
     */
    public static function fromPragmaRow(
        PDO $pdo,
        array $index,
        array $allowedOrigins
    ): ?string {
        $name = (string) ($index['name'] ?? '');
        $origin = (string) ($index['origin'] ?? '');
        $unique = $index['unique'] ?? null;
        if (
            $name === ''
            || !in_array($origin, $allowedOrigins, true)
            || !in_array($unique, [0, '0', 1, '1'], true)
            || !in_array($index['partial'] ?? null, [0, '0'], true)
            || ($origin === 'pk' && (int) $unique !== 1)
        ) {
            return null;
        }

        $quoted = '"' . str_replace('"', '""', $name) . '"';
        $rows = $pdo->query(
            'PRAGMA index_xinfo(' . $quoted . ')'
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }

        $columns = [];
        foreach ($rows as $row) {
            $key = $row['key'] ?? null;
            if (!in_array($key, [0, '0', 1, '1'], true)) {
                return null;
            }
            if ((int) $key === 0) {
                continue;
            }
            $sequence = (int) ($row['seqno'] ?? -1);
            $column = (string) ($row['name'] ?? '');
            if (
                $sequence < 0
                || (int) ($row['cid'] ?? -1) < 0
                || $column === ''
                || (int) ($row['desc'] ?? 1) !== 0
                || strtoupper((string) ($row['coll'] ?? '')) !== 'BINARY'
                || isset($columns[$sequence])
            ) {
                return null;
            }
            $columns[$sequence] = strtolower($column);
        }
        ksort($columns, SORT_NUMERIC);
        if (
            $columns === []
            || array_keys($columns) !== range(0, count($columns) - 1)
        ) {
            return null;
        }

        $kind = $origin === 'pk'
            ? 'p'
            : ((int) $unique === 1 ? '1' : '0');

        return $kind . ':' . implode(',', $columns);
    }
}
