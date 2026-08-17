<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationConditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Exact read-only postcondition for optional migration 0015. */
final class BlogRobotsPreferencesMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    /** @var array<string, string> */
    private const OWNERS = [
        'robots_settings' => 'localization_id',
        'revision_robots' => 'revision_id',
    ];

    private readonly MigrationConditionVerifierInterface $baseVerifier;

    public function __construct(
        ?MigrationConditionVerifierInterface $baseVerifier = null,
        bool $expectUrlHistoryExtension = false
    ) {
        $this->baseVerifier = $baseVerifier
            ?? self::baseVerifier($expectUrlHistoryExtension);
    }

    private static function baseVerifier(bool $expectUrlHistoryExtension):
        BlogPrivateDraftPublicationMigrationPostconditionVerifier
    {
        $initial = new BlogMigrationPostconditionVerifier(
            expectCategoryExtension: true,
            expectStructuredContentExtension: true,
            expectSitemapStateExtension: true,
            expectPostTombstoneExtension: true,
            expectAnalyticsExtension: true,
            expectLayoutEditorExtension: true,
            expectEditorPreferencesExtension: true,
            expectPrivateDraftPublicationExtension: true,
            expectRobotsPreferencesExtension: true,
            expectUrlHistoryExtension: $expectUrlHistoryExtension
        );
        $category = new BlogCategoryMigrationPostconditionVerifier($initial);
        $structured = new BlogStructuredContentMigrationPostconditionVerifier(
            $category
        );
        $sitemap = new BlogSitemapStateMigrationPostconditionVerifier(
            $structured
        );
        $tombstones = new BlogPostTombstoneMigrationPostconditionVerifier(
            $sitemap
        );
        $analytics = new BlogAnalyticsMigrationPostconditionVerifier(
            $tombstones
        );
        $layout = new BlogLayoutEditorMigrationPostconditionVerifier(
            $analytics
        );
        $preferences = new BlogEditorPreferencesMigrationPostconditionVerifier(
            $layout
        );

        return new BlogPrivateDraftPublicationMigrationPostconditionVerifier(
            $preferences
        );
    }

    public function contractVersion(): string
    {
        return 'blog-robots-preferences-schema-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'blog') {
            return false;
        }
        try {
            if (!$this->baseVerifier->verify($pdo, $scope)) {
                return false;
            }
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            if (!in_array($driver, ['mysql', 'sqlite'], true)) {
                return false;
            }
            foreach (self::OWNERS as $suffix => $owner) {
                if (
                    !$this->columnsAreExact($pdo, $scope, $driver, $suffix, $owner)
                    || !$this->primaryKeyIsExact($pdo, $scope, $driver, $suffix, $owner)
                    || !$this->foreignKeyIsExact($pdo, $scope, $driver, $suffix, $owner)
                    || !$this->checksArePresent($pdo, $scope, $driver, $suffix)
                    || !$this->hasNoTriggers($pdo, $scope, $driver, $suffix)
                    || !$this->dataIsValid($pdo, $scope, $driver, $suffix)
                ) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function columnsAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver,
        string $suffix,
        string $owner
    ): bool {
        if ($driver === 'sqlite') {
            $definition = $pdo->prepare(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name"
            );
            $definition->execute(['name' => $scope->tableName($suffix)]);
            $sql = $definition->fetchColumn();
            $rows = $pdo->query(
                'PRAGMA table_info(' . $scope->quotedTable($suffix, 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            $actual = array_map(
                static fn (array $row): array => [
                    strtolower((string) ($row['name'] ?? '')),
                    strtoupper((string) ($row['type'] ?? '')),
                    (int) ($row['notnull'] ?? -1),
                    (int) ($row['pk'] ?? -1),
                    $row['dflt_value'] ?? null,
                ],
                $rows
            );

            return is_string($sql)
                && stripos($sql, 'WITHOUT ROWID') !== false
                && preg_match(
                    '/"settings_sha256"\s+TEXT\s+COLLATE\s+BINARY/i',
                    $sql
                ) === 1
                && $actual === [
                    [$owner, 'INTEGER', 1, 1, null],
                    ['allow_index', 'INTEGER', 1, 0, null],
                    ['allow_follow', 'INTEGER', 1, 0, null],
                    ['settings_sha256', 'TEXT', 1, 0, null],
                ];
        }

        $query = $pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, '
                . 'COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, '
                . 'CHARACTER_SET_NAME, COLLATION_NAME, EXTRA FROM '
                . 'information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
                . 'AND TABLE_NAME = :name ORDER BY ORDINAL_POSITION'
        );
        $query->execute(['name' => $scope->tableName($suffix)]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 4) {
            return false;
        }
        $expected = [
            [$owner, 'bigint', ['bigint unsigned', 'bigint(20) unsigned'], null, null],
            ['allow_index', 'tinyint', ['tinyint unsigned', 'tinyint(3) unsigned'], null, null],
            ['allow_follow', 'tinyint', ['tinyint unsigned', 'tinyint(3) unsigned'], null, null],
            ['settings_sha256', 'char', ['char(64)'], 'ascii', 'ascii_bin'],
        ];
        foreach ($rows as $index => $row) {
            $actual = [
                strtolower((string) ($row['COLUMN_NAME'] ?? '')),
                strtolower((string) ($row['DATA_TYPE'] ?? '')),
                $row['CHARACTER_SET_NAME'] === null ? null
                    : strtolower((string) $row['CHARACTER_SET_NAME']),
                $row['COLLATION_NAME'] === null ? null
                    : strtolower((string) $row['COLLATION_NAME']),
            ];
            $expectedMetadata = [
                $expected[$index][0],
                $expected[$index][1],
                $expected[$index][3],
                $expected[$index][4],
            ];
            $columnType = strtolower(trim((string) (
                preg_replace(
                    '/\s+/',
                    ' ',
                    (string) ($row['COLUMN_TYPE'] ?? '')
                ) ?? ''
            )));
            if (
                $actual !== $expectedMetadata
                || !in_array($columnType, $expected[$index][2], true)
                || strtoupper((string) ($row['IS_NULLABLE'] ?? '')) !== 'NO'
                || ($row['COLUMN_DEFAULT'] ?? null) !== null
                || strtolower((string) ($row['EXTRA'] ?? '')) !== ''
            ) {
                return false;
            }
        }

        return true;
    }

    private function primaryKeyIsExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver,
        string $suffix,
        string $owner
    ): bool {
        if ($driver === 'sqlite') {
            $indexes = $pdo->query(
                'PRAGMA index_list(' . $scope->quotedTable($suffix, 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);

            return count($indexes) === 1
                && (string) ($indexes[0]['origin'] ?? '') === 'pk'
                && (int) ($indexes[0]['unique'] ?? 0) === 1;
        }
        $query = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART '
                . 'FROM information_schema.STATISTICS WHERE '
                . 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $query->execute(['name' => $scope->tableName($suffix)]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        return count($rows) === 1
            && strtoupper((string) ($rows[0]['INDEX_NAME'] ?? '')) === 'PRIMARY'
            && (int) ($rows[0]['NON_UNIQUE'] ?? -1) === 0
            && (int) ($rows[0]['SEQ_IN_INDEX'] ?? -1) === 1
            && strtolower((string) ($rows[0]['COLUMN_NAME'] ?? '')) === $owner
            && ($rows[0]['SUB_PART'] ?? null) === null;
    }

    private function foreignKeyIsExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver,
        string $suffix,
        string $owner
    ): bool {
        $target = $suffix === 'robots_settings'
            ? 'post_localizations' : 'content_revisions';
        if ($driver === 'sqlite') {
            $rows = $pdo->query(
                'PRAGMA foreign_key_list('
                    . $scope->quotedTable($suffix, 'sqlite') . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                return false;
            }
            $row = $rows[0];

            return strtolower((string) ($row['from'] ?? '')) === $owner
                && strtolower((string) ($row['table'] ?? ''))
                    === strtolower($scope->tableName($target))
                && strtolower((string) ($row['to'] ?? '')) === 'id'
                && strtoupper((string) ($row['on_delete'] ?? '')) === 'CASCADE';
        }
        $query = $pdo->prepare(
            'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, '
                . 'k.REFERENCED_COLUMN_NAME, r.DELETE_RULE FROM '
                . 'information_schema.KEY_COLUMN_USAGE k JOIN '
                . 'information_schema.REFERENTIAL_CONSTRAINTS r ON '
                . 'r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND '
                . 'r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE '
                . 'k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = :name '
                . 'AND k.REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $query->execute(['name' => $scope->tableName($suffix)]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        return count($rows) === 1
            && strtolower((string) ($rows[0]['COLUMN_NAME'] ?? '')) === $owner
            && strtolower((string) ($rows[0]['REFERENCED_TABLE_NAME'] ?? ''))
                === strtolower($scope->tableName($target))
            && strtolower((string) ($rows[0]['REFERENCED_COLUMN_NAME'] ?? '')) === 'id'
            && strtoupper((string) ($rows[0]['DELETE_RULE'] ?? '')) === 'CASCADE';
    }

    private function checksArePresent(
        PDO $pdo,
        MigrationScope $scope,
        string $driver,
        string $suffix
    ): bool {
        if ($driver === 'sqlite') {
            $query = $pdo->prepare(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name"
            );
            $query->execute(['name' => $scope->tableName($suffix)]);
            $sql = $query->fetchColumn();
        } else {
            $row = $pdo->query(
                'SHOW CREATE TABLE ' . $scope->quotedTable($suffix, 'mysql')
            )->fetch(PDO::FETCH_NUM);
            $sql = is_array($row) ? ($row[1] ?? null) : null;
        }
        if (!is_string($sql) || preg_match_all('/\bCHECK\s*\(/i', $sql) !== 3) {
            return false;
        }
        $normalized = strtolower(preg_replace('/[\s`"]+/', '', $sql) ?? '');

        return str_contains($normalized, 'allow_indexin(0,1)')
            && str_contains($normalized, 'allow_followin(0,1)')
            && str_contains($normalized, 'settings_sha256');
    }

    private function hasNoTriggers(
        PDO $pdo,
        MigrationScope $scope,
        string $driver,
        string $suffix
    ): bool {
        $query = $driver === 'sqlite'
            ? $pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND tbl_name = :name"
            )
            : $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE '
                    . 'TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = :name'
            );
        $query->execute(['name' => $scope->tableName($suffix)]);

        return (int) $query->fetchColumn() === 0;
    }

    private function dataIsValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver,
        string $suffix
    ): bool {
        $statement = $pdo->query(
            'SELECT allow_index, allow_follow, settings_sha256 FROM '
                . $scope->quotedTable($suffix, $driver)
        );
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $index = $row['allow_index'] ?? null;
            $follow = $row['allow_follow'] ?? null;
            $hash = $row['settings_sha256'] ?? null;
            if (
                !in_array($index, [0, 1, '0', '1'], true)
                || !in_array($follow, [0, 1, '0', '1'], true)
                || !is_string($hash)
            ) {
                return false;
            }
            $preferences = new BlogRobotsPreferences(
                (int) $index === 1,
                (int) $follow === 1
            );
            if (!hash_equals($preferences->integrityHash(), $hash)) {
                return false;
            }
        }

        return true;
    }
}
