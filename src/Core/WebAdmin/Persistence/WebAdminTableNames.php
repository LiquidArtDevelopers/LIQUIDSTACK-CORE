<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Persistence;

use App\Core\WebAdmin\Configuration\WebAdminConfig;
use InvalidArgumentException;
use PDO;

/**
 * Validates and quotes the project-scoped WebAdmin table namespace.
 *
 * Values returned by table() are identifiers, never user data. Callers must
 * continue to bind every value through prepared statements.
 */
final class WebAdminTableNames
{
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];

    private function __construct(
        private readonly string $driver,
        private readonly string $prefix
    ) {
    }

    public static function fromPdo(PDO $pdo, string $prefix): self
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (
            !is_string($driver)
            || !in_array($driver, self::SUPPORTED_DRIVERS, true)
        ) {
            throw new InvalidArgumentException(
                'Unsupported WebAdmin database driver.'
            );
        }
        if (
            preg_match('/\A[a-z][a-z0-9_]+_\z/', $prefix) !== 1
            || strlen($prefix) > WebAdminConfig::MAX_TABLE_PREFIX_LENGTH
        ) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin table namespace.'
            );
        }

        return new self($driver, $prefix);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function table(string $suffix): string
    {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $suffix) !== 1) {
            throw new InvalidArgumentException('Invalid WebAdmin table name.');
        }

        $name = $this->prefix . $suffix;
        if (strlen($name) > WebAdminConfig::MYSQL_IDENTIFIER_MAX_LENGTH) {
            throw new InvalidArgumentException('WebAdmin table name is too long.');
        }

        return $this->driver === 'mysql'
            ? '`' . $name . '`'
            : '"' . $name . '"';
    }
}
