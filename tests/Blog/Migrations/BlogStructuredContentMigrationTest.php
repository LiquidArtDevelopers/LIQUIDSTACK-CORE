<?php

declare(strict_types=1);

namespace Tests\Blog\Migrations;

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Modules\Blog\BlogCategoryMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Blog\BlogStructuredContentMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class BlogStructuredMetadataStatement extends PDOStatement
{
    /** @var array<string, mixed> */
    private array $parameters = [];

    /**
     * @param array<string, list<array<string, mixed>>> $rowsByTable
     * @param list<array<string, mixed>>|null $fixedRows
     */
    public function __construct(
        private readonly array $rowsByTable = [],
        private readonly ?array $fixedRows = null
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->parameters = $params ?? [];

        return true;
    }

    public function fetchAll(
        int $mode = PDO::FETCH_DEFAULT,
        mixed ...$args
    ): array {
        if ($this->fixedRows !== null) {
            return $this->fixedRows;
        }

        return $this->rowsByTable[(string) ($this->parameters['table'] ?? '')]
            ?? [];
    }
}

final class BlogStructuredMetadataPdo extends PDO
{
    /** @var list<string> */
    public array $preparedSql = [];

    /**
     * @param array<string, list<array<string, mixed>>> $rowsByTable
     * @param list<array<string, mixed>>|null $fixedRows
     */
    public function __construct(
        private readonly array $rowsByTable = [],
        private readonly ?array $fixedRows = null
    ) {
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        $this->preparedSql[] = $query;

        return new BlogStructuredMetadataStatement(
            $this->rowsByTable,
            $this->fixedRows
        );
    }
}

final class BlogStructuredContentMigrationTest extends TestCase
{
    public function testSchemaIsAdditiveIdempotentAndComposite(): void
    {
        $pdo = $this->pdo();
        $scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $migrations = $this->migrations();

        $this->apply($pdo, $migrations[0], $scope);
        $this->apply($pdo, $migrations[2], $scope);
        $this->apply($pdo, $migrations[4], $scope);
        $this->apply($pdo, $migrations[4], $scope);

        self::assertTrue((new BlogStructuredContentMigrationPostconditionVerifier())
            ->verify($pdo, $scope));
        self::assertFalse((new BlogMigrationPostconditionVerifier())
            ->verify($pdo, $scope));
        self::assertFalse((new BlogCategoryMigrationPostconditionVerifier())
            ->verify($pdo, $scope));
        self::assertTrue((new BlogMigrationPostconditionVerifier(
            expectCategoryExtension: true,
            expectStructuredContentExtension: true
        ))->verify($pdo, $scope));
        self::assertTrue((new BlogCategoryMigrationPostconditionVerifier(
            expectStructuredContentExtension: true
        ))->verify($pdo, $scope));
        self::assertSame(
            ['0001_blog_posts', '0003_blog_categories'],
            $migrations[4]->supersededPostconditionIds()
        );
        self::assertSame(
            [
                '0001_blog_posts',
                '0003_blog_categories',
                '0005_blog_structured_content',
            ],
            BlogMigrationRequirements::structuredContent()->migrationIds()
        );
    }

    public function testExactVerifierAcceptsValidDocumentsAndRevisions(): void
    {
        [$pdo, $scope] = $this->schema('valid_blog_');
        $localizationId = $this->insertLocalization($pdo, $scope);
        $document = $this->document('Structured Matrix content.');
        $this->insertDocumentAndRevision(
            $pdo,
            $scope,
            $localizationId,
            $document
        );

        self::assertTrue((new BlogStructuredContentMigrationPostconditionVerifier())
            ->verify($pdo, $scope));

        $pdo->exec(
            'UPDATE ' . $scope->quotedTable('content_revisions', 'sqlite')
            . " SET snapshot_sha256 = '" . str_repeat('f', 64) . "'"
        );
        self::assertFalse((new BlogStructuredContentMigrationPostconditionVerifier())
            ->verify($pdo, $scope));
    }

    public function testMySqlExtraMetadataUsesAStrictPortableAllowlist(): void
    {
        $verifier = new BlogStructuredContentMigrationPostconditionVerifier();
        $method = new ReflectionMethod($verifier, 'validMySqlExtra');

        self::assertTrue($method->invoke(
            $verifier,
            'id',
            'AUTO_INCREMENT',
            null,
            'auto_increment'
        ));
        self::assertTrue($method->invoke(
            $verifier,
            'created_at',
            'DEFAULT_GENERATED',
            'current_timestamp(6)',
            ''
        ));
        self::assertTrue($method->invoke(
            $verifier,
            'updated_at',
            '',
            'current_timestamp(6)',
            ''
        ));
        self::assertFalse($method->invoke(
            $verifier,
            'document_json',
            'DEFAULT_GENERATED',
            null,
            ''
        ));
        self::assertFalse($method->invoke(
            $verifier,
            'created_at',
            'DEFAULT_GENERATED on update CURRENT_TIMESTAMP',
            'current_timestamp(6)',
            ''
        ));
        self::assertFalse($method->invoke(
            $verifier,
            'id',
            'auto_increment default_generated',
            null,
            'auto_increment'
        ));
        self::assertFalse($method->invoke(
            $verifier,
            'public_id',
            'auto_increment',
            null,
            ''
        ));
    }

    public function testMySqlIndexesRequirePortableExactMetadata(): void
    {
        $scope = MigrationScope::forTablePrefix('blog', 'index_blog_');
        $verifier = new BlogStructuredContentMigrationPostconditionVerifier();
        $method = new ReflectionMethod($verifier, 'indexSignatures');
        $rows = [
            $this->mysqlIndexRow('PRIMARY', 0, 1, 'id'),
            $this->mysqlIndexRow('idx_updated', 1, 1, 'updated_at'),
            $this->mysqlIndexRow('uq_local', 0, 1, 'localization_id'),
            $this->mysqlIndexRow('uq_public', 0, 1, 'public_id'),
        ];
        $expected = [
            '0:updated_at',
            'p:id',
            '1:localization_id',
            '1:public_id',
        ];
        sort($expected, SORT_STRING);

        $mysql = new BlogStructuredMetadataPdo(fixedRows: $rows);
        $actual = $method->invoke(
            $verifier,
            $mysql,
            $scope,
            'mysql',
            'content_docs',
            '8.0.36'
        );
        sort($actual, SORT_STRING);
        self::assertSame($expected, $actual);
        self::assertStringContainsString(
            'INDEX_TYPE, SUB_PART, COLLATION',
            $mysql->preparedSql[0]
        );
        self::assertStringContainsString(
            "IS_VISIBLE = 'YES'",
            $mysql->preparedSql[0]
        );

        $mariaDb = new BlogStructuredMetadataPdo(fixedRows: $rows);
        self::assertNotSame(['invalid'], $method->invoke(
            $verifier,
            $mariaDb,
            $scope,
            'mysql',
            'content_docs',
            '10.4.32-MariaDB'
        ));
        self::assertStringContainsString(
            "'NO' AS IGNORED",
            $mariaDb->preparedSql[0]
        );
        self::assertStringNotContainsString(
            'IS_VISIBLE',
            $mariaDb->preparedSql[0]
        );

        $mariaDbIgnored = new BlogStructuredMetadataPdo(fixedRows: $rows);
        self::assertNotSame(['invalid'], $method->invoke(
            $verifier,
            $mariaDbIgnored,
            $scope,
            'mysql',
            'content_docs',
            '10.6.18-MariaDB'
        ));
        self::assertStringContainsString(
            'SUB_PART, COLLATION, IGNORED',
            $mariaDbIgnored->preparedSql[0]
        );
        self::assertStringNotContainsString(
            "'NO' AS IGNORED",
            $mariaDbIgnored->preparedSql[0]
        );

        foreach ([
            ['IGNORED', 'YES'],
            ['SUB_PART', 12],
            ['COLLATION', 'D'],
            ['INDEX_TYPE', 'HASH'],
            ['SEQ_IN_INDEX', 2],
        ] as [$field, $invalid]) {
            $drifted = $rows;
            $drifted[1][$field] = $invalid;
            self::assertSame(['invalid'], $method->invoke(
                $verifier,
                new BlogStructuredMetadataPdo(fixedRows: $drifted),
                $scope,
                'mysql',
                'content_docs',
                '8.0.36'
            ), (string) $field);
        }

        self::assertNotSame($expected, $method->invoke(
            $verifier,
            new BlogStructuredMetadataPdo(fixedRows: array_slice($rows, 1)),
            $scope,
            'mysql',
            'content_docs',
            '8.0.36'
        ));

        $swappedPrimary = $rows;
        $swappedPrimary[0]['COLUMN_NAME'] = 'public_id';
        $swappedPrimary[3]['COLUMN_NAME'] = 'id';
        self::assertNotSame($expected, $method->invoke(
            $verifier,
            new BlogStructuredMetadataPdo(fixedRows: $swappedPrimary),
            $scope,
            'mysql',
            'content_docs',
            '8.0.36'
        ));
    }

    public function testForeignKeysRequireTheExpectedUpdateAndDeleteRules(): void
    {
        $scope = MigrationScope::forTablePrefix('blog', 'foreign_blog_');
        $rows = [
            $scope->tableName('content_docs') => [[
                'COLUMN_NAME' => 'localization_id',
                'REFERENCED_TABLE_NAME' =>
                    $scope->tableName('post_localizations'),
                'REFERENCED_COLUMN_NAME' => 'id',
                'SAME_SCHEMA' => 1,
                'UPDATE_RULE' => 'RESTRICT',
                'DELETE_RULE' => 'CASCADE',
            ]],
            $scope->tableName('content_revisions') => [[
                'COLUMN_NAME' => 'localization_id',
                'REFERENCED_TABLE_NAME' =>
                    $scope->tableName('post_localizations'),
                'REFERENCED_COLUMN_NAME' => 'id',
                'SAME_SCHEMA' => 1,
                'UPDATE_RULE' => 'RESTRICT',
                'DELETE_RULE' => 'CASCADE',
            ]],
            $scope->tableName('content_media') => [[
                'COLUMN_NAME' => 'document_id',
                'REFERENCED_TABLE_NAME' => $scope->tableName('content_docs'),
                'REFERENCED_COLUMN_NAME' => 'id',
                'SAME_SCHEMA' => 1,
                'UPDATE_RULE' => 'RESTRICT',
                'DELETE_RULE' => 'CASCADE',
            ]],
            $scope->tableName('revision_media') => [[
                'COLUMN_NAME' => 'revision_id',
                'REFERENCED_TABLE_NAME' =>
                    $scope->tableName('content_revisions'),
                'REFERENCED_COLUMN_NAME' => 'id',
                'SAME_SCHEMA' => 1,
                'UPDATE_RULE' => 'RESTRICT',
                'DELETE_RULE' => 'CASCADE',
            ]],
        ];
        $verifier = new BlogStructuredContentMigrationPostconditionVerifier();
        $method = new ReflectionMethod($verifier, 'foreignKeysAreExact');
        $pdo = new BlogStructuredMetadataPdo(rowsByTable: $rows);

        self::assertTrue($method->invoke(
            $verifier,
            $pdo,
            $scope,
            'mysql'
        ));
        foreach ($pdo->preparedSql as $sql) {
            self::assertStringContainsString('r.UPDATE_RULE', $sql);
            self::assertStringContainsString('r.DELETE_RULE', $sql);
        }

        $drifted = $rows;
        $drifted[$scope->tableName('content_docs')][0]['UPDATE_RULE'] =
            'CASCADE';
        self::assertFalse($method->invoke(
            $verifier,
            new BlogStructuredMetadataPdo(rowsByTable: $drifted),
            $scope,
            'mysql'
        ));

        $crossSchema = $rows;
        $crossSchema[$scope->tableName('content_docs')][0]['SAME_SCHEMA'] = 0;
        self::assertFalse($method->invoke(
            $verifier,
            new BlogStructuredMetadataPdo(rowsByTable: $crossSchema),
            $scope,
            'mysql'
        ));
    }

    public function testDatabaseConstraintsRejectInvalidBoundaries(): void
    {
        [$pdo, $scope] = $this->schema('checks_blog_');
        $localizationId = $this->insertLocalization($pdo, $scope);
        $json = $this->document('Boundary checks.');
        $hash = hash('sha256', $json);
        $bodyHash = hash('sha256', 'Boundary checks.');
        $snapshotHash = $this->snapshotHash(
            $hash,
            'Matrix heading',
            'matrix-heading',
            'Matrix SEO',
            'Matrix description.',
            'Matrix excerpt.',
            'Boundary checks.'
        );
        $sql = 'INSERT INTO '
            . $scope->quotedTable('content_docs', 'sqlite')
            . ' (public_id, localization_id, schema_version, template_key, '
            . 'document_json, document_bytes, document_sha256, '
            . 'body_text_sha256, snapshot_sha256, '
            . 'created_by_user_public_id, updated_by_user_public_id) VALUES '
            . '(:public, :localization, :schema, :template, :document, :bytes, '
            . ':document_hash, :body_hash, :snapshot_hash, :created, :updated)';
        $parameters = [
            'public' => $this->id(20),
            'localization' => $localizationId,
            'schema' => 1,
            'template' => 'article-basic-01',
            'document' => $json,
            'bytes' => strlen($json),
            'document_hash' => $hash,
            'body_hash' => $bodyHash,
            'snapshot_hash' => $snapshotHash,
            'created' => $this->id(90),
            'updated' => $this->id(91),
        ];

        foreach ([
            ['schema', 2],
            ['template', 'Bad Template'],
            ['bytes', 0],
            ['document_hash', strtoupper($hash)],
        ] as [$key, $invalid]) {
            $attempt = $parameters;
            $attempt[$key] = $invalid;
            $this->expectConstraintFailure(
                static fn () => $pdo->prepare($sql)->execute($attempt)
            );
        }
    }

    public function testExactVerifierRejectsIndexCheckForeignKeyTriggerAndDataDrift(): void
    {
        foreach (['index', 'check', 'foreign_key', 'trigger', 'data'] as $drift) {
            [$pdo, $scope] = $this->schema('drift_blog_');
            self::assertTrue((new BlogStructuredContentMigrationPostconditionVerifier())
                ->verify($pdo, $scope), $drift);

            if ($drift === 'index') {
                $pdo->exec('DROP INDEX "drift_blog_ux_cr_loc_variant"');
            } elseif ($drift === 'check') {
                $pdo->exec('PRAGMA ignore_check_constraints = ON');
            } elseif ($drift === 'foreign_key') {
                $pdo->exec('PRAGMA foreign_keys = OFF');
            } elseif ($drift === 'trigger') {
                $pdo->exec(
                    'CREATE TRIGGER drift_blog_forbidden AFTER INSERT ON '
                    . '"drift_blog_content_docs" BEGIN SELECT 1; END'
                );
            } else {
                $localizationId = $this->insertLocalization($pdo, $scope);
                $document = $this->document('Corrupt hash target.');
                $this->insertDocumentAndRevision(
                    $pdo,
                    $scope,
                    $localizationId,
                    $document
                );
                $pdo->exec(
                    'UPDATE "drift_blog_content_docs" SET document_json = '
                    . "'{\"schema\":\"tampered\"}'"
                );
            }

            self::assertFalse((new BlogStructuredContentMigrationPostconditionVerifier())
                ->verify($pdo, $scope), $drift);
        }
    }

    public function testMaximumSupportedPrefixKeepsEveryIdentifierPortable(): void
    {
        $prefix = 'b' . str_repeat(
            'x',
            BlogConfig::MAX_TABLE_PREFIX_LENGTH - 2
        ) . '_';
        $scope = MigrationScope::forTablePrefix('blog', $prefix);
        $migration = $this->migrations()[4];

        foreach (['mysql', 'sqlite'] as $driver) {
            $sql = implode("\n", $migration->statementsFor($driver, $scope));
            self::assertStringNotContainsString('{{', $sql);
            self::assertDoesNotMatchRegularExpression('/\b(?:ALTER|UPDATE)\b/i', $sql);
            preg_match_all('/[`"]([A-Za-z][A-Za-z0-9_]*)[`"]/', $sql, $matches);
            foreach ($matches[1] as $identifier) {
                self::assertLessThanOrEqual(64, strlen($identifier), $identifier);
            }
        }
    }

    public function testDataVerifierRejectsRowsOrphanedWhileChecksWereOff(): void
    {
        [$pdo, $scope] = $this->schema('orphan_blog_');
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec(
            'INSERT INTO "orphan_blog_content_media" '
            . '(document_id, block_public_id, media_asset_public_id, role) '
            . "VALUES (999, '10000000-0000-4000-8000-000000000001', "
            . "'20000000-0000-4000-8000-000000000001', 'image')"
        );
        $pdo->exec('PRAGMA foreign_keys = ON');

        $verifier = new BlogStructuredContentMigrationPostconditionVerifier();
        $method = new ReflectionMethod($verifier, 'dataIsValid');
        self::assertFalse($method->invoke(
            $verifier,
            $pdo,
            $scope,
            'sqlite'
        ));
    }

    public function testSqliteCheckExtractorIgnoresCommentDecoysAndPreservesLiterals(): void
    {
        $verifier = new BlogStructuredContentMigrationPostconditionVerifier();
        $method = new ReflectionMethod($verifier, 'checkExpressions');
        $expressions = $method->invoke($verifier, <<<'SQL'
CREATE TABLE sample (
    "note" TEXT CHECK(note IN ('--keep-literal', '/*keep-literal*/')),
    "CHECK(quoted_decoy = 1)" TEXT,
    /* CHECK(block_decoy = 1) */
    -- CHECK(line_decoy = 1)
    "value" INTEGER CHECK(value > 0)
)
SQL);

        self::assertCount(2, $expressions);
        $actual = implode("\n", $expressions);
        self::assertStringContainsString("'--keep-literal'", $actual);
        self::assertStringContainsString("'/*keep-literal*/'", $actual);
        self::assertStringNotContainsString('quoted_decoy', $actual);
        self::assertStringNotContainsString('block_decoy', $actual);
        self::assertStringNotContainsString('line_decoy', $actual);
    }

    /** @return array{PDO, MigrationScope} */
    private function schema(string $prefix): array
    {
        $pdo = $this->pdo();
        $scope = MigrationScope::forTablePrefix('blog', $prefix);
        $migrations = $this->migrations();
        $this->apply($pdo, $migrations[0], $scope);
        $this->apply($pdo, $migrations[2], $scope);
        $this->apply($pdo, $migrations[4], $scope);

        return [$pdo, $scope];
    }

    /** @return list<MigrationDefinition> */
    private function migrations(): array
    {
        return iterator_to_array(BlogMigrationProvider::migrations(), false);
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function apply(
        PDO $pdo,
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $statement) {
            self::assertNotFalse($pdo->exec($statement));
        }
    }

    private function insertLocalization(PDO $pdo, MigrationScope $scope): int
    {
        $pdo->prepare(
            'INSERT INTO ' . $scope->quotedTable('posts', 'sqlite')
            . ' (public_id, created_by_user_public_id) VALUES (:public, :actor)'
        )->execute([
            'public' => $this->id(1),
            'actor' => $this->id(90),
        ]);
        $postId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO '
            . $scope->quotedTable('post_localizations', 'sqlite')
            . ' (public_id, post_id, locale, slug, h1, seo_title, '
            . 'meta_description, excerpt, body_text, status, '
            . 'created_by_user_public_id, updated_by_user_public_id) VALUES '
            . '(:public, :post, :locale, :slug, :h1, :seo, :description, '
            . ':excerpt, :body, :status, :created, :updated)'
        )->execute([
            'public' => $this->id(2),
            'post' => $postId,
            'locale' => 'es',
            'slug' => 'matrix-heading',
            'h1' => 'Matrix heading',
            'seo' => 'Matrix SEO',
            'description' => 'Matrix description.',
            'excerpt' => 'Matrix excerpt.',
            'body' => 'Structured Matrix content.',
            'status' => 'draft',
            'created' => $this->id(90),
            'updated' => $this->id(91),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertDocumentAndRevision(
        PDO $pdo,
        MigrationScope $scope,
        int $localizationId,
        string $document
    ): void {
        $documentHash = hash('sha256', $document);
        $body = 'Structured Matrix content.';
        $bodyHash = hash('sha256', $body);
        $snapshotHash = $this->snapshotHash(
            $documentHash,
            'Matrix heading',
            'matrix-heading',
            'Matrix SEO',
            'Matrix description.',
            'Matrix excerpt.',
            $body
        );
        $common = [
            'public' => $this->id(20),
            'localization' => $localizationId,
            'template' => 'article-basic-01',
            'document' => $document,
            'bytes' => strlen($document),
            'document_hash' => $documentHash,
            'body_hash' => $bodyHash,
            'snapshot_hash' => $snapshotHash,
            'created' => $this->id(90),
        ];
        $pdo->prepare(
            'INSERT INTO ' . $scope->quotedTable('content_docs', 'sqlite')
            . ' (public_id, localization_id, template_key, document_json, '
            . 'document_bytes, document_sha256, body_text_sha256, '
            . 'snapshot_sha256, created_by_user_public_id, '
            . 'updated_by_user_public_id) VALUES (:public, :localization, '
            . ':template, :document, :bytes, :document_hash, :body_hash, '
            . ':snapshot_hash, :created, :updated)'
        )->execute($common + ['updated' => $this->id(91)]);
        $pdo->prepare(
            'INSERT INTO '
            . $scope->quotedTable('content_revisions', 'sqlite')
            . ' (public_id, localization_id, revision_number, '
            . 'variant_lock_version, template_key, document_json, '
            . 'document_bytes, document_sha256, body_text_sha256, '
            . 'snapshot_sha256, h1, slug, seo_title, meta_description, '
            . 'excerpt, body_text, created_by_user_public_id) VALUES '
            . '(:public, :localization, 1, 1, :template, :document, :bytes, '
            . ':document_hash, :body_hash, :snapshot_hash, :h1, :slug, :seo, '
            . ':description, :excerpt, :body, :created)'
        )->execute(($common + [
            'h1' => 'Matrix heading',
            'slug' => 'matrix-heading',
            'seo' => 'Matrix SEO',
            'description' => 'Matrix description.',
            'excerpt' => 'Matrix excerpt.',
            'body' => $body,
        ]) + ['public' => $this->id(21)]);
    }

    private function document(string $text): string
    {
        return (string) json_encode([
            'schema' => 'liquidstack.blog.document',
            'version' => 1,
            'template' => 'article-basic-01',
            'blocks' => [[
                'id' => $this->id(30),
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => $text,
                    'marks' => [],
                ]],
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function snapshotHash(
        string $documentHash,
        string $h1,
        ?string $slug,
        ?string $seoTitle,
        ?string $metaDescription,
        ?string $excerpt,
        string $bodyText
    ): string {
        return hash('sha256', (string) json_encode([
            'schema' => 'liquidstack.blog.snapshot',
            'version' => 1,
            'document_sha256' => $documentHash,
            'h1' => $h1,
            'slug' => $slug,
            'seo_title' => $seoTitle,
            'meta_description' => $metaDescription,
            'excerpt' => $excerpt,
            'body_text' => $bodyText,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function id(int $sequence): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $sequence);
    }

    /** @return array<string, mixed> */
    private function mysqlIndexRow(
        string $name,
        int $nonUnique,
        int $sequence,
        string $column
    ): array {
        return [
            'INDEX_NAME' => $name,
            'NON_UNIQUE' => $nonUnique,
            'SEQ_IN_INDEX' => $sequence,
            'COLUMN_NAME' => $column,
            'INDEX_TYPE' => 'BTREE',
            'SUB_PART' => null,
            'COLLATION' => 'A',
            'IGNORED' => 'NO',
        ];
    }

    /** @param callable(): void $operation */
    private function expectConstraintFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('The database contract should reject this row.');
        } catch (PDOException) {
            self::addToAssertionCount(1);
        }
    }
}
