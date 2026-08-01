<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Pure validator for the shared LiquidStack database environment.
 *
 * It never opens a connection and its public diagnostic result contains only
 * variable names. Credential values remain caller-owned and are returned only
 * by connectionParameters(), which is consumed by the PDO boundary.
 */
final class SharedDatabaseEnvironmentValidator
{
    public const REQUIRED_NAMES = [
        'BBDD_SERVER',
        'BBDD_USER',
        'BBDD_PASS',
        'BBDD_NAME',
    ];

    /**
     * @param array<string, mixed> $environment
     * @return array{missing: list<string>, invalid: list<string>, ready: bool}
     */
    public function inspect(array $environment): array
    {
        $missing = [];
        $invalid = [];

        foreach (self::REQUIRED_NAMES as $name) {
            if (
                !array_key_exists($name, $environment)
                || $this->isMissingValue($name, $environment[$name])
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

    private function isMissingValue(string $name, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        /*
         * An empty database password is valid in existing LiquidStack
         * installations. For every other field, an empty/blank value means
         * that configuration is still missing rather than malformed.
         */
        return $name !== 'BBDD_PASS' && trim($value) === '';
    }

    /**
     * @param array<string, mixed> $environment
     * @return array{0: string, 1: string, 2: string}
     */
    public function connectionParameters(array $environment): array
    {
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

        $server = trim((string) $environment['BBDD_SERVER']);
        [$host, $port] = $this->parseServer($server);
        $dsn = 'mysql:host=' . $host;
        if ($port !== null) {
            $dsn .= ';port=' . $port;
        }
        $dsn .= ';dbname=' . $environment['BBDD_NAME'] . ';charset=utf8mb4';

        return [
            $dsn,
            (string) $environment['BBDD_USER'],
            (string) $environment['BBDD_PASS'],
        ];
    }

    private function isValidValue(string $name, mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return match ($name) {
            'BBDD_SERVER' => $this->isValidServer($value),
            'BBDD_USER' => strlen($value) >= 1
                && strlen($value) <= 128
                && trim($value) !== ''
                && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1,
            'BBDD_PASS' => strlen($value) <= 4096
                && !str_contains($value, "\0"),
            'BBDD_NAME' => preg_match(
                '/\A[A-Za-z0-9_$-]{1,64}\z/',
                $value
            ) === 1,
            default => false,
        };
    }

    private function isValidServer(string $server): bool
    {
        $server = trim($server);
        if (
            $server === ''
            || strlen($server) > 255
            || preg_match('/[;\x00-\x1F\x7F]/', $server) === 1
        ) {
            return false;
        }

        try {
            $this->parseServer($server);
        } catch (DatabaseConnectionException) {
            return false;
        }

        return true;
    }

    /** @return array{0: string, 1: ?int} */
    private function parseServer(string $server): array
    {
        if (preg_match('/\A\[([^]]+)](?::([0-9]{1,5}))?\z/', $server, $match) === 1) {
            if (filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new DatabaseConnectionException(
                    'database.environment_invalid'
                );
            }

            return [$match[1], $this->validatedPort($match[2] ?? null)];
        }

        if (substr_count($server, ':') > 1) {
            throw new DatabaseConnectionException(
                'database.environment_invalid'
            );
        }

        $parts = explode(':', $server, 2);
        $host = $parts[0];
        if (
            preg_match(
                '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,252}[A-Za-z0-9])?\z/',
                $host
            ) !== 1
        ) {
            throw new DatabaseConnectionException(
                'database.environment_invalid'
            );
        }

        return [$host, $this->validatedPort($parts[1] ?? null)];
    }

    private function validatedPort(?string $port): ?int
    {
        if ($port === null) {
            return null;
        }
        if (
            preg_match('/\A[0-9]{1,5}\z/', $port) !== 1
            || (int) $port < 1
            || (int) $port > 65535
        ) {
            throw new DatabaseConnectionException(
                'database.environment_invalid'
            );
        }

        return (int) $port;
    }
}
