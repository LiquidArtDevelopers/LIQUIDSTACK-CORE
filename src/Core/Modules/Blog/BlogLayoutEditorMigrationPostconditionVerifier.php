<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV2CompatibilityCanonicalizer;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredSnapshotHasher;
use App\Core\Database\MySqlColumnDefaultNormalizer;
use App\Core\Database\MySqlServerCapabilities;
use App\Core\Database\SqlCheckExpressionCanonicalizer;
use App\Core\Database\SqliteIndexSignature;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Exact read-only postcondition for the optional layout editor v2 schema. */
final class BlogLayoutEditorMigrationPostconditionVerifier implements
    MigrationPostconditionVerifierInterface
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'content_layout_docs' => [
            'document_id', 'schema_version', 'template_key', 'document_json',
            'document_bytes', 'document_sha256', 'snapshot_sha256',
        ],
        'content_layout_revisions' => [
            'revision_id', 'schema_version', 'template_key', 'document_json',
            'document_bytes', 'document_sha256', 'snapshot_sha256',
        ],
    ];

    private readonly BlogAnalyticsMigrationPostconditionVerifier $baseVerifier;

    public function __construct(
        ?BlogAnalyticsMigrationPostconditionVerifier $baseVerifier = null,
        private readonly MySqlColumnDefaultNormalizer $defaultNormalizer =
            new MySqlColumnDefaultNormalizer(),
        private readonly BlogDocumentCodec $codec = new BlogDocumentCodec(),
        bool $expectEditorPreferencesExtension = false,
        bool $expectPrivateDraftPublicationExtension = false
    ) {
        $this->baseVerifier = $baseVerifier
            ?? new BlogAnalyticsMigrationPostconditionVerifier(
                expectLayoutEditorExtension: true,
                expectEditorPreferencesExtension:
                    $expectEditorPreferencesExtension,
                expectPrivateDraftPublicationExtension:
                    $expectPrivateDraftPublicationExtension
            );
    }

    public function contractVersion(): string
    {
        return 'blog-layout-editor-schema-v2';
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

            return $this->tablesAndColumnsAreExact(
                $pdo,
                $scope,
                $driver
            )
                && $this->indexesAreExact($pdo, $scope, $driver)
                && $this->foreignKeysAreExact($pdo, $scope, $driver)
                && $this->checksAreExact($pdo, $scope, $driver)
                && $this->hasNoTriggers($pdo, $scope, $driver)
                && $this->dataIsValid($pdo, $scope, $driver);
        } catch (Throwable) {
            return false;
        }
    }

    private function tablesAndColumnsAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $isMariaDb = false;
        if ($driver === 'mysql') {
            $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
            if (!is_string($serverVersion)) {
                return false;
            }
            $isMariaDb = MySqlServerCapabilities::isMariaDb($serverVersion);
        }
        foreach (self::COLUMNS as $suffix => $names) {
            if ($driver === 'sqlite') {
                $table = $pdo->prepare(
                    "SELECT type, sql FROM sqlite_master WHERE name = :name"
                );
                $table->execute(['name' => $scope->tableName($suffix)]);
                $metadata = $table->fetch(PDO::FETCH_ASSOC);
                if (
                    !is_array($metadata)
                    || ($metadata['type'] ?? null) !== 'table'
                    || !is_string($metadata['sql'] ?? null)
                    || stripos((string) $metadata['sql'], 'WITHOUT ROWID')
                        === false
                ) {
                    return false;
                }
                foreach (
                    ['template_key', 'document_sha256', 'snapshot_sha256']
                    as $binary
                ) {
                    if (preg_match(
                        '/"' . preg_quote($binary, '/')
                            . '"\s+TEXT\s+COLLATE\s+BINARY\b/i',
                        (string) $metadata['sql']
                    ) !== 1) {
                        return false;
                    }
                }
                $rows = $pdo->query(
                    'PRAGMA table_info('
                        . $scope->quotedTable($suffix, 'sqlite') . ')'
                )->fetchAll(PDO::FETCH_ASSOC);
                $actualNames = array_map(
                    static fn (array $row): string =>
                        strtolower((string) ($row['name'] ?? '')),
                    $rows
                );
                if (
                    $actualNames !== $names
                    || !$this->sqliteColumnsAreExact($rows)
                ) {
                    return false;
                }
                continue;
            }

            $table = $pdo->prepare(
                'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES '
                    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                    . "AND TABLE_TYPE = 'BASE TABLE'"
            );
            $table->execute(['name' => $scope->tableName($suffix)]);
            $metadata = $table->fetch(PDO::FETCH_ASSOC);
            if (
                !is_array($metadata)
                || strtoupper((string) ($metadata['ENGINE'] ?? ''))
                    !== 'INNODB'
                || strtolower((string) ($metadata['TABLE_COLLATION'] ?? ''))
                    !== 'utf8mb4_unicode_ci'
            ) {
                return false;
            }
            $query = $pdo->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, '
                    . 'COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, '
                    . 'DATETIME_PRECISION, CHARACTER_SET_NAME, '
                    . 'COLLATION_NAME, EXTRA FROM information_schema.COLUMNS '
                    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                    . 'ORDER BY ORDINAL_POSITION'
            );
            $query->execute(['name' => $scope->tableName($suffix)]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $actualNames = array_map(
                static fn (array $row): string =>
                    strtolower((string) ($row['COLUMN_NAME'] ?? '')),
                $rows
            );
            if (
                $actualNames !== $names
                || !$this->mysqlColumnsAreExact($rows, $isMariaDb)
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $rows */
    private function sqliteColumnsAreExact(array $rows): bool
    {
        $expected = [
            ['INTEGER', 1, 1, null],
            ['INTEGER', 1, 0, '2'],
            ['TEXT', 1, 0, null],
            ['TEXT', 1, 0, null],
            ['INTEGER', 1, 0, null],
            ['TEXT', 1, 0, null],
            ['TEXT', 1, 0, null],
        ];
        $actual = array_map(
            static fn (array $row): array => [
                strtoupper((string) ($row['type'] ?? '')),
                (int) ($row['notnull'] ?? -1),
                (int) ($row['pk'] ?? -1),
                $row['dflt_value'] === null
                    ? null : trim((string) $row['dflt_value'], "()'\" "),
            ],
            $rows
        );

        return $actual === $expected;
    }

    /** @param list<array<string, mixed>> $rows */
    private function mysqlColumnsAreExact(
        array $rows,
        bool $isMariaDb
    ): bool
    {
        $types = [
            'bigint', 'smallint', 'varchar', 'longtext', 'int', 'char', 'char',
        ];
        foreach ($rows as $index => $row) {
            $type = strtolower((string) ($row['DATA_TYPE'] ?? ''));
            $name = strtolower((string) ($row['COLUMN_NAME'] ?? ''));
            if (
                $type !== ($types[$index] ?? null)
                || strtoupper((string) ($row['IS_NULLABLE'] ?? '')) !== 'NO'
                || trim(strtolower((string) ($row['EXTRA'] ?? ''))) !== ''
            ) {
                return false;
            }
            if (
                in_array($type, ['bigint', 'smallint', 'int'], true)
                && !str_contains(
                    strtolower((string) ($row['COLUMN_TYPE'] ?? '')),
                    'unsigned'
                )
            ) {
                return false;
            }
            $default = $this->defaultNormalizer->normalizeMetadata(
                isset($row['COLUMN_DEFAULT'])
                    ? (string) $row['COLUMN_DEFAULT'] : null,
                $type,
                (string) ($row['EXTRA'] ?? ''),
                $isMariaDb
            );
            if (
                $name === 'schema_version'
                    ? trim((string) $default, "'\"") !== '2'
                    : $default !== null
            ) {
                return false;
            }
            if ($type === 'varchar' && (
                (int) ($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0) !== 64
                || strtolower((string) ($row['CHARACTER_SET_NAME'] ?? ''))
                    !== 'ascii'
                || strtolower((string) ($row['COLLATION_NAME'] ?? ''))
                    !== 'ascii_bin'
            )) {
                return false;
            }
            if ($type === 'char' && (
                (int) ($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0) !== 64
                || strtolower((string) ($row['CHARACTER_SET_NAME'] ?? ''))
                    !== 'ascii'
                || strtolower((string) ($row['COLLATION_NAME'] ?? ''))
                    !== 'ascii_bin'
            )) {
                return false;
            }
            if ($type === 'longtext' && (
                strtolower((string) ($row['CHARACTER_SET_NAME'] ?? ''))
                    !== 'utf8mb4'
                || strtolower((string) ($row['COLLATION_NAME'] ?? ''))
                    !== 'utf8mb4_unicode_ci'
            )) {
                return false;
            }
        }

        return true;
    }

    private function indexesAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        foreach (array_keys(self::COLUMNS) as $suffix) {
            if ($driver === 'sqlite') {
                $actual = [];
                foreach ($pdo->query(
                    'PRAGMA index_list('
                        . $scope->quotedTable($suffix, 'sqlite') . ')'
                )->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $signature = SqliteIndexSignature::fromPragmaRow(
                        $pdo,
                        $row,
                        ['pk']
                    );
                    if (!is_string($signature)) {
                        return false;
                    }
                    $actual[] = $signature;
                }
                sort($actual, SORT_STRING);
                $primary = $suffix === 'content_layout_docs'
                    ? 'p:document_id' : 'p:revision_id';
                if ($actual !== [$primary]) {
                    return false;
                }
                continue;
            }
            $query = $pdo->prepare(
                'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, '
                    . 'SUB_PART FROM information_schema.STATISTICS WHERE '
                    . 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name '
                    . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
            );
            $query->execute(['name' => $scope->tableName($suffix)]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $key = $suffix === 'content_layout_docs'
                ? 'document_id' : 'revision_id';
            if (
                count($rows) !== 1
                || strtoupper((string) ($rows[0]['INDEX_NAME'] ?? ''))
                    !== 'PRIMARY'
                || (int) ($rows[0]['NON_UNIQUE'] ?? -1) !== 0
                || (int) ($rows[0]['SEQ_IN_INDEX'] ?? -1) !== 1
                || strtolower((string) ($rows[0]['COLUMN_NAME'] ?? ''))
                    !== $key
                || ($rows[0]['SUB_PART'] ?? null) !== null
            ) {
                return false;
            }
        }

        return true;
    }

    private function foreignKeysAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $expected = [
            'content_layout_docs' => ['document_id', 'content_docs'],
            'content_layout_revisions' => [
                'revision_id', 'content_revisions',
            ],
        ];
        foreach ($expected as $suffix => [$column, $target]) {
            if ($driver === 'sqlite') {
                $rows = $pdo->query(
                    'PRAGMA foreign_key_list('
                        . $scope->quotedTable($suffix, 'sqlite') . ')'
                )->fetchAll(PDO::FETCH_ASSOC);
                $row = count($rows) === 1 ? $rows[0] : [];
                $actual = [
                    strtolower((string) ($row['from'] ?? '')),
                    strtolower((string) ($row['table'] ?? '')),
                    strtolower((string) ($row['to'] ?? '')),
                    strtoupper((string) ($row['on_update'] ?? '')),
                    strtoupper((string) ($row['on_delete'] ?? '')),
                ];
            } else {
                $query = $pdo->prepare(
                    'SELECT k.COLUMN_NAME AS `from`, '
                        . 'k.REFERENCED_TABLE_NAME AS `table`, '
                        . 'k.REFERENCED_COLUMN_NAME AS `to`, '
                        . 'r.UPDATE_RULE AS on_update, '
                        . 'r.DELETE_RULE AS on_delete FROM '
                        . 'information_schema.KEY_COLUMN_USAGE k JOIN '
                        . 'information_schema.REFERENTIAL_CONSTRAINTS r ON '
                        . 'r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND '
                        . 'r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE '
                        . 'k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = '
                        . ':name AND k.REFERENCED_TABLE_NAME IS NOT NULL'
                );
                $query->execute(['name' => $scope->tableName($suffix)]);
                $rows = $query->fetchAll(PDO::FETCH_ASSOC);
                $row = count($rows) === 1 ? $rows[0] : [];
                $update = strtoupper((string) ($row['on_update'] ?? ''));
                $actual = [
                    strtolower((string) ($row['from'] ?? '')),
                    strtolower((string) ($row['table'] ?? '')),
                    strtolower((string) ($row['to'] ?? '')),
                    $update === 'RESTRICT' ? 'NO ACTION' : $update,
                    strtoupper((string) ($row['on_delete'] ?? '')),
                ];
            }
            if ($actual !== [
                $column,
                strtolower($scope->tableName($target)),
                'id',
                'NO ACTION',
                'CASCADE',
            ]) {
                return false;
            }
        }

        return true;
    }

    private function checksAreExact(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $expected = [
            'schema_version=2',
            'char_length(template_key) BETWEEN 1 AND 64 '
                . 'AND template_key = lower(template_key) '
                . 'AND template_key = trim(template_key) '
                . "AND template_key REGEXP '^[a-z][a-z0-9_-]{0,63}$'",
            'document_bytes BETWEEN 1 AND 300000',
            "document_sha256 REGEXP '^[0-9a-f]{64}$'",
            "snapshot_sha256 REGEXP '^[0-9a-f]{64}$'",
        ];
        foreach (array_keys(self::COLUMNS) as $suffix) {
            if ($driver === 'sqlite') {
                $query = $pdo->prepare(
                    "SELECT sql FROM sqlite_master WHERE type = 'table' "
                        . 'AND name = :name'
                );
                $query->execute(['name' => $scope->tableName($suffix)]);
                $sql = $query->fetchColumn();
                if (!is_string($sql)) {
                    return false;
                }
                $actual = $this->checkExpressions($sql);
                $wanted = array_map(
                    [SqlCheckExpressionCanonicalizer::class, 'canonicalize'],
                    [
                        'schema_version=2',
                        'length(template_key) BETWEEN 1 AND 64 '
                            . 'AND template_key = lower(template_key) '
                            . 'AND template_key = trim(template_key) '
                            . "AND substr(template_key, 1, 1) GLOB '[a-z]' "
                            . "AND template_key NOT GLOB '*[^a-z0-9_-]*'",
                        'document_bytes BETWEEN 1 AND 300000',
                        'length(document_sha256)=64 AND document_sha256 '
                            . "NOT GLOB '*[^0-9a-f]*'",
                        'length(snapshot_sha256)=64 AND snapshot_sha256 '
                            . "NOT GLOB '*[^0-9a-f]*'",
                    ]
                );
            } else {
                $version = $pdo->query('SELECT VERSION()')->fetchColumn();
                if (!is_string($version)) {
                    return false;
                }
                $mariaDb = MySqlServerCapabilities::isMariaDb($version);
                $query = $pdo->prepare(
                    'SELECT cc.CHECK_CLAUSE, '
                        . ($mariaDb ? "'YES'" : 'tc.ENFORCED')
                        . ' AS ENFORCED FROM '
                        . 'information_schema.TABLE_CONSTRAINTS tc JOIN '
                        . 'information_schema.CHECK_CONSTRAINTS cc ON '
                        . 'cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND '
                        . 'cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
                        . ($mariaDb
                            ? 'AND cc.TABLE_NAME = tc.TABLE_NAME ' : '')
                        . 'WHERE tc.TABLE_SCHEMA = DATABASE() AND '
                        . 'tc.TABLE_NAME = :table AND '
                        . "tc.CONSTRAINT_TYPE = 'CHECK'"
                );
                $query->execute(['table' => $scope->tableName($suffix)]);
                $actual = [];
                foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (strtoupper((string) ($row['ENFORCED'] ?? ''))
                        !== 'YES') {
                        return false;
                    }
                    $actual[] = SqlCheckExpressionCanonicalizer::canonicalize(
                        (string) ($row['CHECK_CLAUSE'] ?? '')
                    );
                }
                $wanted = array_map(
                    [SqlCheckExpressionCanonicalizer::class, 'canonicalize'],
                    $expected
                );
            }
            sort($actual, SORT_STRING);
            sort($wanted, SORT_STRING);
            if ($actual !== $wanted) {
                return false;
            }
        }

        return true;
    }

    private function hasNoTriggers(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        foreach (array_keys(self::COLUMNS) as $suffix) {
            $query = $driver === 'sqlite'
                ? $pdo->prepare(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' "
                        . 'AND tbl_name = :name'
                )
                : $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE '
                        . 'TRIGGER_SCHEMA = DATABASE() AND '
                        . 'EVENT_OBJECT_TABLE = :name'
                );
            $query->execute(['name' => $scope->tableName($suffix)]);
            if ((int) $query->fetchColumn() !== 0) {
                return false;
            }
        }

        return true;
    }

    private function dataIsValid(
        PDO $pdo,
        MigrationScope $scope,
        string $driver
    ): bool {
        $documents = $scope->quotedTable('content_docs', $driver);
        $revisions = $scope->quotedTable('content_revisions', $driver);
        $localizations = $scope->quotedTable(
            'post_localizations',
            $driver
        );
        $layoutDocuments = $scope->quotedTable(
            'content_layout_docs',
            $driver
        );
        $layoutRevisions = $scope->quotedTable(
            'content_layout_revisions',
            $driver
        );

        $current = $pdo->query(
            'SELECT ld.schema_version AS layout_schema_version, '
                . 'ld.template_key AS layout_template_key, '
                . 'ld.document_json AS layout_document_json, '
                . 'ld.document_bytes AS layout_document_bytes, '
                . 'ld.document_sha256 AS layout_document_sha256, '
                . 'ld.snapshot_sha256 AS layout_snapshot_sha256, '
                . 'd.id AS base_id, d.schema_version AS base_schema_version, '
                . 'd.template_key AS base_template_key, '
                . 'd.document_json AS base_document_json, '
                . 'd.document_bytes AS base_document_bytes, '
                . 'd.document_sha256 AS base_document_sha256, '
                . 'd.body_text_sha256 AS base_body_text_sha256, '
                . 'd.snapshot_sha256 AS base_snapshot_sha256, '
                . 'l.h1, l.slug, l.seo_title, l.meta_description, '
                . 'l.excerpt, l.body_text FROM ' . $layoutDocuments . ' ld '
                . 'LEFT JOIN ' . $documents . ' d ON d.id = ld.document_id '
                . 'LEFT JOIN ' . $localizations
                . ' l ON l.id = d.localization_id'
        );
        if (!$this->companionRowsAreValid($current)) {
            return false;
        }

        $history = $pdo->query(
            'SELECT lr.schema_version AS layout_schema_version, '
                . 'lr.template_key AS layout_template_key, '
                . 'lr.document_json AS layout_document_json, '
                . 'lr.document_bytes AS layout_document_bytes, '
                . 'lr.document_sha256 AS layout_document_sha256, '
                . 'lr.snapshot_sha256 AS layout_snapshot_sha256, '
                . 'r.id AS base_id, r.schema_version AS base_schema_version, '
                . 'r.template_key AS base_template_key, '
                . 'r.document_json AS base_document_json, '
                . 'r.document_bytes AS base_document_bytes, '
                . 'r.document_sha256 AS base_document_sha256, '
                . 'r.body_text_sha256 AS base_body_text_sha256, '
                . 'r.snapshot_sha256 AS base_snapshot_sha256, '
                . 'r.h1, r.slug, r.seo_title, r.meta_description, '
                . 'r.excerpt, r.body_text FROM ' . $layoutRevisions . ' lr '
                . 'LEFT JOIN ' . $revisions . ' r ON r.id = lr.revision_id'
        );

        return $this->companionRowsAreValid($history);
    }

    private function companionRowsAreValid(\PDOStatement|false $statement): bool
    {
        if ($statement === false) {
            return false;
        }
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row) || !$this->companionRowIsValid($row)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function companionRowIsValid(array $row): bool
    {
        try {
            if (
                !$this->isPositiveInteger($row['base_id'] ?? null)
                || !is_string($row['layout_document_json'] ?? null)
                || !is_string($row['h1'] ?? null)
                || !is_string($row['body_text'] ?? null)
            ) {
                return false;
            }
            foreach (
                ['slug', 'seo_title', 'meta_description', 'excerpt']
                as $nullable
            ) {
                if (
                    ($row[$nullable] ?? null) !== null
                    && !is_string($row[$nullable])
                ) {
                    return false;
                }
            }

            $json = $row['layout_document_json'];
            $document = $this->codec->decodeDraft($json);
            if ($document->version() !== BlogDocument::LAYOUT_VERSION) {
                return false;
            }
            $draft = new BlogStructuredDraft(
                $row['h1'],
                $document,
                $row['slug'] ?? null,
                $row['seo_title'] ?? null,
                $row['meta_description'] ?? null,
                $row['excerpt'] ?? null,
                $this->codec
            );
            $compatibility = $draft->compatibilityDraft();

            return $this->integerEquals(
                $row['layout_schema_version'] ?? null,
                BlogDocument::LAYOUT_VERSION
            )
                && $this->sameString(
                    $row['layout_template_key'] ?? null,
                    $draft->templateKey()
                )
                && $this->layoutMetadataIsValid($row, $draft, $json)
                && $this->integerEquals(
                    $row['base_schema_version'] ?? null,
                    $draft->compatibilitySchemaVersion()
                )
                && $this->sameString(
                    $row['base_template_key'] ?? null,
                    $draft->compatibilityTemplateKey()
                )
                && $this->sameString(
                    $row['base_document_json'] ?? null,
                    $draft->compatibilityCanonicalJson()
                )
                && $this->integerEquals(
                    $row['base_document_bytes'] ?? null,
                    $draft->compatibilityDocumentBytes()
                )
                && $this->sameString(
                    $row['base_document_sha256'] ?? null,
                    $draft->compatibilityDocumentSha256()
                )
                && $this->sameString(
                    $row['body_text'],
                    $compatibility->bodyText()
                )
                && $this->sameString(
                    $row['base_body_text_sha256'] ?? null,
                    $draft->bodyTextSha256()
                )
                && $this->sameString(
                    $row['base_snapshot_sha256'] ?? null,
                    $draft->compatibilitySnapshotSha256()
                );
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $row */
    private function layoutMetadataIsValid(
        array $row,
        BlogStructuredDraft $draft,
        string $persistedJson
    ): bool {
        if (
            hash_equals($draft->canonicalJson(), $persistedJson)
            && $this->integerEquals(
                $row['layout_document_bytes'] ?? null,
                $draft->documentBytes()
            )
            && $this->sameString(
                $row['layout_document_sha256'] ?? null,
                $draft->documentSha256()
            )
            && $this->sameString(
                $row['layout_snapshot_sha256'] ?? null,
                $draft->snapshotSha256()
            )
        ) {
            return true;
        }

        $legacyJson = null;
        foreach ((new BlogDocumentV2CompatibilityCanonicalizer())
            ->candidates($draft->document()) as $candidate) {
            if (hash_equals($candidate, $persistedJson)) {
                $legacyJson = $candidate;
                break;
            }
        }
        if ($legacyJson === null) {
            return false;
        }
        $legacySha256 = hash('sha256', $legacyJson);
        $legacySnapshotSha256 = (new BlogStructuredSnapshotHasher())->hash(
            $draft->compatibilityDraft(),
            $legacySha256
        );

        return $this->integerEquals(
            $row['layout_document_bytes'] ?? null,
            strlen($legacyJson)
        )
            && $this->sameString(
                $row['layout_document_sha256'] ?? null,
                $legacySha256
            )
            && $this->sameString(
                $row['layout_snapshot_sha256'] ?? null,
                $legacySnapshotSha256
            );
    }

    private function sameString(mixed $actual, string $expected): bool
    {
        return is_string($actual) && hash_equals($expected, $actual);
    }

    private function integerEquals(mixed $actual, int $expected): bool
    {
        if (is_int($actual)) {
            return $actual === $expected;
        }

        return is_string($actual)
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $actual) === 1
            && $actual === (string) $expected;
    }

    private function isPositiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1;
    }

    /** @return list<string> */
    private function checkExpressions(string $sql): array
    {
        $expressions = [];
        $length = strlen($sql);
        for ($index = 0; $index < $length;) {
            if (in_array($sql[$index], ["'", '"', '`', '['], true)) {
                $this->skipQuotedToken($sql, $index);
                continue;
            }
            if (
                strncasecmp(substr($sql, $index, 5), 'check', 5) !== 0
                || ($index > 0
                    && preg_match('/[a-z0-9_]/i', $sql[$index - 1]) === 1)
                || preg_match('/[a-z0-9_]/i', $sql[$index + 5] ?? '') === 1
            ) {
                $index++;
                continue;
            }
            $cursor = $index + 5;
            while ($cursor < $length && ctype_space($sql[$cursor])) {
                $cursor++;
            }
            if (($sql[$cursor] ?? '') !== '(') {
                $index += 5;
                continue;
            }
            $start = ++$cursor;
            $depth = 1;
            while ($cursor < $length && $depth > 0) {
                if (in_array($sql[$cursor], ["'", '"', '`', '['], true)) {
                    $this->skipQuotedToken($sql, $cursor);
                    continue;
                }
                if ($sql[$cursor] === '(') {
                    $depth++;
                } elseif ($sql[$cursor] === ')') {
                    $depth--;
                }
                $cursor++;
            }
            if ($depth !== 0) {
                return ['invalid'];
            }
            $expressions[] = SqlCheckExpressionCanonicalizer::canonicalize(
                substr($sql, $start, $cursor - $start - 1)
            );
            $index = $cursor;
        }

        return $expressions;
    }

    private function skipQuotedToken(string $sql, int &$index): void
    {
        $opening = $sql[$index];
        $closing = $opening === '[' ? ']' : $opening;
        $length = strlen($sql);
        $index++;
        while ($index < $length) {
            if ($sql[$index] !== $closing) {
                $index++;
                continue;
            }
            if (($sql[$index + 1] ?? '') === $closing) {
                $index += 2;
                continue;
            }
            $index++;
            return;
        }
    }
}
