<?php

declare(strict_types=1);

namespace App\Core\Database;

/** Canonicalizes INFORMATION_SCHEMA column defaults without losing type. */
final class MySqlColumnDefaultNormalizer
{
    public function normalizeMetadata(
        ?string $sql,
        string $dataType,
        string $extra,
        bool $isMariaDb
    ): ?string {
        $unquotedValueIsLiteral = $isMariaDb
            ? $this->isMariaDbUnquotedNumericLiteral($sql, $dataType)
            : !str_contains(strtolower($extra), 'default_generated');

        return $this->normalize($sql, $unquotedValueIsLiteral);
    }

    public function normalize(
        ?string $sql,
        bool $unquotedValueIsLiteral = false
    ): ?string
    {
        if ($sql === null) {
            return null;
        }

        $normalized = trim($sql);
        if (strcasecmp($normalized, 'null') === 0) {
            // MariaDB represents the absence/DEFAULT NULL semantic with the
            // unquoted SQL token NULL. MySQL returns SQL NULL as PHP null;
            // an unquoted string value NULL is therefore still a literal
            // when its metadata context says so.
            return $unquotedValueIsLiteral ? "'NULL'" : null;
        }

        if (preg_match(
            "/\A_[a-z0-9]+'(.*)'\z/is",
            $normalized,
            $match
        ) === 1) {
            return $this->quotedLiteral((string) $match[1]);
        }
        if (
            strlen($normalized) >= 2
            && $normalized[0] === "'"
            && str_ends_with($normalized, "'")
        ) {
            return $this->quotedLiteral(substr($normalized, 1, -1));
        }

        if ($unquotedValueIsLiteral) {
            return $this->quotedLiteral($normalized);
        }

        return $normalized;
    }

    private function quotedLiteral(string $value): string
    {
        // Preserve literal-vs-expression type for every value, not only NULL.
        // Keeping canonical quotes also makes normalization idempotent and
        // prevents 'current_timestamp(6)' impersonating the SQL expression.
        return "'" . $value . "'";
    }

    private function isMariaDbUnquotedNumericLiteral(
        ?string $sql,
        string $dataType
    ): bool {
        if (
            $sql === null
            || !in_array(strtolower($dataType), [
                'tinyint',
                'smallint',
                'mediumint',
                'int',
                'integer',
                'bigint',
                'decimal',
                'numeric',
                'float',
                'double',
                'real',
            ], true)
        ) {
            return false;
        }

        return preg_match(
            '/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?\z/i',
            trim($sql)
        ) === 1;
    }
}
