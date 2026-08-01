<?php

declare(strict_types=1);

use App\Core\Database\MySqlServerCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MySqlServerCapabilitiesTest extends TestCase
{
    #[DataProvider('versionProvider')]
    public function testParsesServerVersionAndCheckMetadataSupport(
        string $raw,
        ?string $normalized,
        bool $reliableChecks,
        bool $ignoredIndexes
    ): void {
        self::assertSame(
            $normalized,
            MySqlServerCapabilities::normalizedVersion($raw)
        );
        self::assertSame(
            $reliableChecks,
            MySqlServerCapabilities::supportsReliableCheckMetadata($raw)
        );
        self::assertSame(
            $ignoredIndexes,
            MySqlServerCapabilities::supportsIgnoredIndexes($raw)
        );
    }

    public static function versionProvider(): iterable
    {
        yield 'mysql before enforced checks' => [
            '5.7.44-log',
            '5.7.44',
            false,
            false,
        ];
        yield 'mysql first enforced checks' => [
            '8.0.16-0ubuntu0.18.04.1',
            '8.0.16',
            true,
            false,
        ];
        yield 'MariaDB compatibility prefix' => [
            '5.5.5-10.11.8-MariaDB',
            '10.11.8',
            true,
            true,
        ];
        yield 'MariaDB distro suffix is not its version' => [
            '10.3.39-MariaDB-0ubuntu0.20.04.2',
            '10.3.39',
            true,
            false,
        ];
        yield 'MariaDB truncated check metadata patch' => [
            '10.3.27-MariaDB',
            '10.3.27',
            false,
            false,
        ];
        yield 'MariaDB local supported line before fix' => [
            '10.4.17-MariaDB',
            '10.4.17',
            false,
            false,
        ];
        yield 'MariaDB local supported version' => [
            '10.4.32-MariaDB',
            '10.4.32',
            true,
            false,
        ];
        yield 'unparseable' => ['MariaDB development', null, false, false];
    }
}
