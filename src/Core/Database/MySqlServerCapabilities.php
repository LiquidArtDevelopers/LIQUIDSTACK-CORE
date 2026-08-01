<?php

declare(strict_types=1);

namespace App\Core\Database;

final class MySqlServerCapabilities
{
    public static function isMariaDb(string $rawVersion): bool
    {
        return stripos($rawVersion, 'mariadb') !== false;
    }

    public static function normalizedVersion(string $rawVersion): ?string
    {
        $pattern = self::isMariaDb($rawVersion)
            ? '/(\d+)\.(\d+)\.(\d+)(?=-MariaDB\b)/i'
            : '/(\d+)\.(\d+)\.(\d+)/';
        if (preg_match($pattern, $rawVersion, $match) !== 1) {
            return null;
        }

        return sprintf('%d.%d.%d', $match[1], $match[2], $match[3]);
    }

    public static function supportsReliableCheckMetadata(
        string $rawVersion
    ): bool {
        $version = self::normalizedVersion($rawVersion);
        if ($version === null) {
            return false;
        }
        if (!self::isMariaDb($rawVersion)) {
            return version_compare($version, '8.0.16', '>=');
        }

        // MariaDB fixed truncated CHECK_CLAUSE metadata in the maintained
        // patch lines below (MDEV-24139). Older patch releases cannot prove
        // this schema contract safely.
        [$major, $minor] = array_map('intval', explode('.', $version));
        if ($major > 10 || ($major === 10 && $minor >= 6)) {
            return true;
        }

        $minimum = match ($major . '.' . $minor) {
            '10.2' => '10.2.37',
            '10.3' => '10.3.28',
            '10.4' => '10.4.18',
            '10.5' => '10.5.9',
            default => null,
        };

        return $minimum !== null
            && version_compare($version, $minimum, '>=');
    }

    public static function supportsIgnoredIndexes(string $rawVersion): bool
    {
        $version = self::normalizedVersion($rawVersion);

        return self::isMariaDb($rawVersion)
            && $version !== null
            && version_compare($version, '10.6.0', '>=');
    }
}
