<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Database\MySqlServerCapabilities;
use App\Core\Modules\Migrations\MigrationConditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Exact, read-only schema/data contract for Blog copy idempotency. */
final class BlogCopyOperationMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    private const COLUMNS = [
        'request_public_id', 'payload_sha256', 'actor_public_id',
        'operation', 'source_post_public_id', 'source_locale',
        'destination_locale', 'expected_lock_version',
        'result_post_public_id', 'result_locale', 'created_at',
        'completed_at',
    ];

    private readonly MigrationConditionVerifierInterface $baseVerifier;

    public function __construct(
        ?MigrationConditionVerifierInterface $baseVerifier = null
    ) {
        $this->baseVerifier = $baseVerifier ?? self::priorFrontier();
    }

    private static function priorFrontier():
        BlogDummyCategoryNormalizationPostcondition {
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
            expectUrlHistoryExtension: true,
            expectCopyOperationExtension: true
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
        $privateDraft =
            new BlogPrivateDraftPublicationMigrationPostconditionVerifier(
                $preferences
            );
        $robots = new BlogRobotsPreferencesMigrationPostconditionVerifier(
            $privateDraft
        );

        return new BlogDummyCategoryNormalizationPostcondition(
            new BlogUrlHistoryMigrationPostconditionVerifier($robots)
        );
    }

    public function contractVersion(): string
    {
        return 'blog-copy-operation-idempotency-schema-v1';
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

            return match (MigrationDatabaseDriver::fromPdo($pdo)->value) {
                'sqlite' => $this->verifySqlite($pdo, $scope),
                'mysql' => $this->verifyMySql($pdo, $scope),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    private function verifySqlite(PDO $pdo, MigrationScope $scope): bool
    {
        $name = $scope->tableName('copy_operations');
        $quoted = $scope->quotedTable('copy_operations', 'sqlite');
        $definition = $pdo->prepare(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $definition->execute(['name' => $name]);
        $sql = $definition->fetchColumn();
        if (!is_string($sql) || stripos($sql, 'WITHOUT ROWID') === false) {
            return false;
        }
        $rows = $pdo->query('PRAGMA table_info(' . $quoted . ')')
            ->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(
            static fn (array $row): string =>
                strtolower((string) ($row['name'] ?? '')),
            $rows
        );
        if ($names !== self::COLUMNS || count($rows) !== 12) {
            return false;
        }
        foreach ($rows as $index => $row) {
            $nullable = in_array($index, [8, 9, 11], true);
            if (
                strtoupper((string) ($row['type'] ?? ''))
                    !== ($index === 7 ? 'INTEGER' : 'TEXT')
                || (int) ($row['notnull'] ?? -1) !== ($nullable ? 0 : 1)
                || (int) ($row['pk'] ?? -1) !== ($index === 0 ? 1 : 0)
            ) {
                return false;
            }
        }
        $indexes = $pdo->query('PRAGMA index_list(' . $quoted . ')')
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $index) {
            if ((string) ($index['origin'] ?? '') !== 'pk') {
                return false;
            }
        }
        $triggers = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' "
                . 'AND tbl_name = :name'
        );
        $triggers->execute(['name' => $name]);

        return (int) $triggers->fetchColumn() === 0
            && $this->dataIsValid($pdo, $quoted);
    }

    private function verifyMySql(PDO $pdo, MigrationScope $scope): bool
    {
        $name = $scope->tableName('copy_operations');
        $quoted = $scope->quotedTable('copy_operations', 'mysql');
        $table = $pdo->prepare(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                . "AND TABLE_TYPE = 'BASE TABLE'"
        );
        $table->execute(['name' => $name]);
        $metadata = $table->fetch(PDO::FETCH_ASSOC);
        if (
            !is_array($metadata)
            || strtoupper((string) ($metadata['ENGINE'] ?? '')) !== 'INNODB'
            || strtolower((string) ($metadata['TABLE_COLLATION'] ?? ''))
                !== 'utf8mb4_unicode_ci'
        ) {
            return false;
        }
        $query = $pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, '
                . 'CHARACTER_MAXIMUM_LENGTH, DATETIME_PRECISION, '
                . 'CHARACTER_SET_NAME, COLLATION_NAME FROM '
                . 'information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
                . 'AND TABLE_NAME = :name ORDER BY ORDINAL_POSITION'
        );
        $query->execute(['name' => $name]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (array_map(
            static fn (array $row): string =>
                strtolower((string) ($row['COLUMN_NAME'] ?? '')),
            $rows
        ) !== self::COLUMNS) {
            return false;
        }
        $contracts = [
            ['char', false, 36, 'ascii', 'ascii_bin'],
            ['char', false, 64, 'ascii', 'ascii_bin'],
            ['char', false, 36, 'ascii', 'ascii_bin'],
            ['varchar', false, 16, 'ascii', 'ascii_bin'],
            ['char', false, 36, 'ascii', 'ascii_bin'],
            ['varchar', false, 16, 'ascii', 'ascii_bin'],
            ['varchar', false, 16, 'ascii', 'ascii_bin'],
            ['bigint', false, null, null, null],
            ['char', true, 36, 'ascii', 'ascii_bin'],
            ['varchar', true, 16, 'ascii', 'ascii_bin'],
            ['datetime', false, null, null, null],
            ['datetime', true, null, null, null],
        ];
        foreach ($rows as $index => $row) {
            [$type, $nullable, $length, $charset, $collation] =
                $contracts[$index];
            if (
                strtolower((string) ($row['DATA_TYPE'] ?? '')) !== $type
                || (strtoupper((string) ($row['IS_NULLABLE'] ?? '')) === 'YES')
                    !== $nullable
                || ($row['CHARACTER_MAXIMUM_LENGTH'] === null
                    ? null : (int) $row['CHARACTER_MAXIMUM_LENGTH']) !== $length
                || ($row['CHARACTER_SET_NAME'] === null
                    ? null : strtolower((string) $row['CHARACTER_SET_NAME']))
                    !== $charset
                || ($row['COLLATION_NAME'] === null
                    ? null : strtolower((string) $row['COLLATION_NAME']))
                    !== $collation
                || ($index === 7 && !str_contains(
                    strtolower((string) ($row['COLUMN_TYPE'] ?? '')),
                    'unsigned'
                ))
                || (in_array($index, [10, 11], true)
                    && (int) ($row['DATETIME_PRECISION'] ?? -1) !== 6)
            ) {
                return false;
            }
        }
        $indexes = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM '
                . 'information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() '
                . 'AND TABLE_NAME = :name ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $indexes->execute(['name' => $name]);
        $indexRows = $indexes->fetchAll(PDO::FETCH_ASSOC);
        if (
            count($indexRows) !== 1
            || strtoupper((string) (
                $indexRows[0]['INDEX_NAME']
                    ?? $indexRows[0]['index_name']
                    ?? ''
            ))
                !== 'PRIMARY'
            || (int) (
                $indexRows[0]['NON_UNIQUE']
                    ?? $indexRows[0]['non_unique']
                    ?? -1
            ) !== 0
            || (
                $indexRows[0]['COLUMN_NAME']
                    ?? $indexRows[0]['column_name']
                    ?? null
            ) !== 'request_public_id'
        ) {
            return false;
        }
        $foreignKeys = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE '
                . 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name AND '
                . "CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $foreignKeys->execute(['name' => $name]);
        $triggers = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE '
                . 'TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = :name'
        );
        $triggers->execute(['name' => $name]);

        return (int) $foreignKeys->fetchColumn() === 0
            && (int) $triggers->fetchColumn() === 0
            && $this->mysqlChecksAreEnforced($pdo, $name)
            && $this->dataIsValid($pdo, $quoted);
    }

    private function mysqlChecksAreEnforced(PDO $pdo, string $table): bool
    {
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (!is_string($version)) {
            return false;
        }
        $mariaDb = MySqlServerCapabilities::isMariaDb($version);
        $query = $pdo->prepare(
            'SELECT ' . ($mariaDb ? "'YES'" : 'tc.ENFORCED')
                . ' AS ENFORCED FROM information_schema.TABLE_CONSTRAINTS tc '
                . 'JOIN information_schema.CHECK_CONSTRAINTS cc ON '
                . 'cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND '
                . 'cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
                . ($mariaDb ? 'AND cc.TABLE_NAME = tc.TABLE_NAME ' : '')
                . 'WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = '
                . ":name AND tc.CONSTRAINT_TYPE = 'CHECK'"
        );
        $query->execute(['name' => $table]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== 8) {
            return false;
        }
        foreach ($rows as $row) {
            if (strtoupper((string) ($row['ENFORCED'] ?? '')) !== 'YES') {
                return false;
            }
        }

        return true;
    }

    private function dataIsValid(PDO $pdo, string $table): bool
    {
        $invalid = $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE '
                . "operation NOT IN ('duplicate_post', 'add_locale') OR "
                . 'expected_lock_version < 1 OR '
                . '(result_post_public_id IS NULL) <> (result_locale IS NULL) OR '
                . '(result_locale IS NULL) <> (completed_at IS NULL) OR '
                . '(result_locale IS NOT NULL AND result_locale <> destination_locale) '
                . 'OR (completed_at IS NOT NULL AND completed_at < created_at)'
        );

        return (int) $invalid->fetchColumn() === 0;
    }
}
