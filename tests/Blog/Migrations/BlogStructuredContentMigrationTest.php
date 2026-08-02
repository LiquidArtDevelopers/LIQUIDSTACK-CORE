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
use PHPUnit\Framework\TestCase;

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
