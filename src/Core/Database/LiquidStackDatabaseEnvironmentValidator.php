<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Validates the dedicated, per-project LiquidStack module database profile.
 * Diagnostic output contains variable names only; credential values cross
 * the boundary solely through connectionParameters().
 */
final class LiquidStackDatabaseEnvironmentValidator implements
    DatabaseEnvironmentValidatorInterface
{
    public const REQUIRED_NAMES =
        DatabaseConnectionProfile::LIQUIDSTACK_ENVIRONMENT_NAMES;

    /**
     * @param array<string, mixed> $environment
     * @return array{missing: list<string>, invalid: list<string>, ready: bool}
     */
    public function inspect(#[\SensitiveParameter] array $environment): array
    {
        $missing = [];
        $invalid = [];

        foreach (self::REQUIRED_NAMES as $name) {
            if (
                !array_key_exists($name, $environment)
                || $environment[$name] === null
                || (is_string($environment[$name])
                    && trim($environment[$name]) === '')
            ) {
                $missing[] = $name;
                continue;
            }

            if (!$this->isValidValue($name, $environment[$name])) {
                $invalid[] = $name;
            }
        }

        return [
            'missing' => $missing,
            'invalid' => $invalid,
            'ready' => $missing === [] && $invalid === [],
        ];
    }

    /**
     * @param array<string, mixed> $environment
     * @return array{0: string, 1: string, 2: string}
     */
    public function connectionParameters(
        #[\SensitiveParameter] array $environment
    ): array {
        $inspection = $this->inspect($environment);
        if ($inspection['missing'] !== []) {
            throw new DatabaseConnectionException(
                'database.environment_missing'
            );
        }
        if ($inspection['invalid'] !== []) {
            throw new DatabaseConnectionException(
                'database.environment_invalid'
            );
        }

        $host = trim((string) $environment['LIQUIDSTACK_DB_HOST']);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $port = (int) $environment['LIQUIDSTACK_DB_PORT'];
        $database = (string) $environment['LIQUIDSTACK_DB_NAME'];
        $charset = (string) $environment['LIQUIDSTACK_DB_CHARSET'];

        return [
            'mysql:host=' . $host
                . ';port=' . $port
                . ';dbname=' . $database
                . ';charset=' . $charset,
            (string) $environment['LIQUIDSTACK_DB_USER'],
            (string) $environment['LIQUIDSTACK_DB_PASSWORD'],
        ];
    }

    private function isValidValue(string $name, mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return match ($name) {
            'LIQUIDSTACK_DB_HOST' => $this->isValidHost($value),
            'LIQUIDSTACK_DB_PORT' => preg_match(
                '/\A[0-9]{1,5}\z/',
                $value
            ) === 1 && (int) $value >= 1 && (int) $value <= 65535,
            'LIQUIDSTACK_DB_NAME' => preg_match(
                '/\A[A-Za-z0-9_$-]{1,64}\z/',
                $value
            ) === 1,
            'LIQUIDSTACK_DB_USER' => strlen($value) >= 1
                && strlen($value) <= 128
                && trim($value) !== ''
                && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1,
            'LIQUIDSTACK_DB_PASSWORD' => strlen($value) >= 1
                && strlen($value) <= 4096
                && !str_contains($value, "\0"),
            'LIQUIDSTACK_DB_CHARSET' => $value === 'utf8mb4',
            default => false,
        };
    }

    private function isValidHost(string $value): bool
    {
        $host = trim($value);
        if (
            $host === ''
            || strlen($host) > 255
            || preg_match('/[;\x00-\x1F\x7F]/', $host) === 1
        ) {
            return false;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(
                substr($host, 1, -1),
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6
            ) !== false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match(
            '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,252}[A-Za-z0-9])?\z/',
            $host
        ) === 1;
    }
}
