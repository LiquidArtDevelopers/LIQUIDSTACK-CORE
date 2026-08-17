<?php

declare(strict_types=1);

namespace App\Core\Blog\Persistence;

use App\Core\Blog\Admin\BlogAdminCatalogQuery;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogInput;
use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Blog\BlogSitemapEntry;
use App\Core\Blog\BlogTransactionalExceptionInterface;
use App\Core\Blog\PublishedPostCard;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/** Portable PDO implementation for the Blog posts aggregate. */
final class PdoBlogRepository implements
    BlogRepositoryInterface,
    BlogAdminCatalogRepositoryInterface,
    BlogCopyOperationRepositoryInterface,
    BlogEditorialActionRepositoryInterface,
    BlogPostLocaleBatchCatalogRepositoryInterface,
    BlogPostLocaleCatalogRepositoryInterface,
    BlogPublishedSitemapRepositoryInterface
{
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];
    private const SQLITE_ADMIN_CASEFOLD_FUNCTION =
        'liquidstack_blog_admin_unicode_casefold';

    /** @var null|\WeakMap<PDO, bool> */
    private static ?\WeakMap $adminCasefoldRegisteredConnections = null;

    private readonly string $driver;
    private readonly string $posts;
    private readonly string $localizations;
    private readonly string $tombstones;
    private readonly string $categories;
    private readonly string $categoryLocales;
    private readonly string $postCategories;
    private readonly string $robotsSettings;
    private readonly string $copyOperations;
    private readonly ?string $adminUsers;
    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $scope,
        private readonly bool $postTombstonesEnabled = false,
        private readonly bool $robotsSettingsEnabled = false,
        ?MigrationScope $adminUserScope = null,
        private readonly bool $adminCategoryProjectionEnabled = false,
        private readonly bool $reservedCategoryPolicyEnabled = false
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, self::SUPPORTED_DRIVERS, true)
                || $scope->moduleId() !== 'blog'
                || $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || (
                    $driver === 'mysql'
                    && !in_array(
                        $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                        [false, 0, '0'],
                        true
                    )
                )
            ) {
                throw new BlogPersistenceException();
            }
            if ($driver === 'sqlite') {
                $foreignKeys = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$foreignKeys instanceof PDOStatement
                    || !in_array($foreignKeys->fetchColumn(), [1, '1'], true)
                ) {
                    throw new BlogPersistenceException();
                }
                $this->registerSqliteAdminCasefold();
            }

            $this->driver = $driver;
            $this->posts = $scope->quotedTable('posts', $driver);
            $this->localizations = $scope->quotedTable(
                'post_localizations',
                $driver
            );
            $this->tombstones = $scope->quotedTable(
                'post_tombstones',
                $driver
            );
            $this->categories = $scope->quotedTable('categories', $driver);
            $this->categoryLocales = $scope->quotedTable(
                'category_locales',
                $driver
            );
            $this->postCategories = $scope->quotedTable(
                'post_categories',
                $driver
            );
            $this->robotsSettings = $scope->quotedTable(
                'robots_settings',
                $driver
            );
            $this->copyOperations = $scope->quotedTable(
                'copy_operations',
                $driver
            );
            if (
                $adminUserScope !== null
                && $adminUserScope->moduleId() !== 'webadmin'
            ) {
                throw new BlogPersistenceException();
            }
            $this->adminUsers = $adminUserScope?->quotedTable(
                'users',
                $driver
            );
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function postTombstonesEnabled(): bool
    {
        return $this->postTombstonesEnabled;
    }

    public function reserveCopyOperation(
        string $requestPublicId,
        string $payloadSha256,
        string $actorPublicId,
        string $operation,
        string $sourcePostPublicId,
        string $sourceLocale,
        string $destinationLocale,
        int $expectedLockVersion,
        DateTimeImmutable $now
    ): ?BlogCopyOperationResult {
        $this->assertTransaction();
        if (
            preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $requestPublicId
            ) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $payloadSha256) !== 1
            || !in_array($operation, ['duplicate_post', 'add_locale'], true)
            || $expectedLockVersion < 1
        ) {
            throw new BlogPersistenceException();
        }

        try {
            $insert = 'INSERT INTO ' . $this->copyOperations
                . ' (request_public_id, payload_sha256, actor_public_id, '
                . 'operation, source_post_public_id, source_locale, '
                . 'destination_locale, expected_lock_version, created_at) '
                . 'VALUES (:request_public_id, :payload_sha256, '
                . ':actor_public_id, :operation, :source_post_public_id, '
                . ':source_locale, :destination_locale, '
                . ':expected_lock_version, :created_at)';
            $insert .= $this->driver === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE request_public_id = VALUES(request_public_id)'
                : ' ON CONFLICT(request_public_id) DO NOTHING';
            $statement = $this->prepare($insert);
            $this->execute($statement, [
                'request_public_id' => $requestPublicId,
                'payload_sha256' => $payloadSha256,
                'actor_public_id' => $actorPublicId,
                'operation' => $operation,
                'source_post_public_id' => $sourcePostPublicId,
                'source_locale' => $sourceLocale,
                'destination_locale' => $destinationLocale,
                'expected_lock_version' => $expectedLockVersion,
                'created_at' => self::format($now),
            ]);

            $select = 'SELECT payload_sha256, actor_public_id, operation, '
                . 'source_post_public_id, source_locale, destination_locale, '
                . 'expected_lock_version, result_post_public_id, '
                . 'result_locale, completed_at FROM ' . $this->copyOperations
                . ' WHERE request_public_id = :request_public_id LIMIT 1';
            if ($this->driver === 'mysql') {
                $select .= ' FOR UPDATE';
            }
            $query = $this->prepare($select);
            $this->execute($query, ['request_public_id' => $requestPublicId]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                throw new BlogPersistenceException();
            }
            $row = $rows[0];
            if (
                !is_string($row['payload_sha256'] ?? null)
                || !hash_equals($payloadSha256, $row['payload_sha256'])
                || ($row['actor_public_id'] ?? null) !== $actorPublicId
                || ($row['operation'] ?? null) !== $operation
                || ($row['source_post_public_id'] ?? null)
                    !== $sourcePostPublicId
                || ($row['source_locale'] ?? null) !== $sourceLocale
                || ($row['destination_locale'] ?? null)
                    !== $destinationLocale
                || (int) ($row['expected_lock_version'] ?? 0)
                    !== $expectedLockVersion
            ) {
                throw new BlogPersistenceConflict(
                    BlogPersistenceConflict::IDEMPOTENCY
                );
            }

            $resultPost = $row['result_post_public_id'] ?? null;
            $resultLocale = $row['result_locale'] ?? null;
            $completedAt = $row['completed_at'] ?? null;
            if ($resultPost === null && $resultLocale === null
                && $completedAt === null) {
                return null;
            }
            if (
                !is_string($resultPost)
                || !is_string($resultLocale)
                || !is_string($completedAt)
                || $resultLocale !== $destinationLocale
            ) {
                throw new BlogPersistenceException();
            }

            return new BlogCopyOperationResult(
                BlogInput::publicId($resultPost),
                BlogInput::locale($resultLocale)
            );
        } catch (BlogPersistenceConflict|BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function completeCopyOperation(
        string $requestPublicId,
        string $payloadSha256,
        string $resultPostPublicId,
        string $resultLocale,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        try {
            $statement = $this->prepare(
                'UPDATE ' . $this->copyOperations
                . ' SET result_post_public_id = :result_post_public_id, '
                . 'result_locale = :result_locale, completed_at = :completed_at '
                . 'WHERE request_public_id = :request_public_id '
                . 'AND payload_sha256 = :payload_sha256 '
                . 'AND result_post_public_id IS NULL '
                . 'AND result_locale IS NULL AND completed_at IS NULL'
            );
            $this->execute($statement, [
                'result_post_public_id' => $resultPostPublicId,
                'result_locale' => $resultLocale,
                'completed_at' => self::format($now),
                'request_public_id' => $requestPublicId,
                'payload_sha256' => $payloadSha256,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new BlogPersistenceException();
            }
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function transactional(callable $operation): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            throw new BlogPersistenceException();
        }

        try {
            return $this->transactionOnce($operation);
        } catch (
            BlogTransactionalExceptionInterface|BlogPersistenceConflict|BlogPersistenceException $exception
        ) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    /** @template T @param callable(PDO): T $operation @return T */
    private function transactionOnce(callable $operation): mixed
    {
        $sqlite = $this->driver === 'sqlite';
        $started = false;
        $this->transactionActive = true;

        try {
            if (!$this->pdo->beginTransaction()) {
                throw new BlogPersistenceException();
            }
            $started = true;
            if ($sqlite) {
                /*
                 * PDO does not expose BEGIN IMMEDIATE as an in-transaction
                 * state. A no-op DML keeps PDO transaction detection honest
                 * while acquiring SQLite's write lock before the actor gate.
                 */
                $writeLock = $this->prepare(
                    'UPDATE ' . $this->posts
                    . ' SET updated_at = updated_at WHERE 1 = 0'
                );
                $this->execute($writeLock);
            }

            $result = $operation($this->pdo);

            if (!$this->pdo->commit()) {
                throw new BlogPersistenceException();
            }
            $started = false;
            $this->transactionActive = false;

            return $result;
        } catch (Throwable $exception) {
            try {
                if ($started) {
                    if (
                        $this->pdo->inTransaction()
                        && $this->pdo->rollBack()
                    ) {
                        $started = false;
                    } elseif (!$this->pdo->inTransaction()) {
                        $started = false;
                    }
                }
            } catch (Throwable) {
                // The repository stays poisoned if rollback cannot be proven.
            }

            if ($started) {
                throw new BlogPersistenceException();
            }
            $this->transactionActive = false;

            throw $exception;
        }
    }

    public function insertPost(
        string $postPublicId,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        $timestamp = self::format($now);
        $statement = $this->prepare(
            'INSERT INTO ' . $this->posts . ' '
            . '(public_id, created_by_user_public_id, created_at, updated_at) '
            . 'VALUES (:public_id, :actor_public_id, :created_at, :updated_at)'
        );
        $this->execute($statement, [
            'public_id' => $postPublicId,
            'actor_public_id' => $actorPublicId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new BlogPersistenceException();
        }
    }

    public function lockPost(string $postPublicId): bool
    {
        $this->assertTransaction();
        $row = $this->one(
            'SELECT public_id FROM ' . $this->posts
                . ' WHERE public_id = :public_id' . $this->forUpdate(),
            ['public_id' => $postPublicId]
        );

        return $row !== null;
    }

    public function insertLocalization(
        string $localizationPublicId,
        string $postPublicId,
        string $locale,
        BlogDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        $timestamp = self::format($now);
        $statement = $this->prepare(
            'INSERT INTO ' . $this->localizations . ' '
            . '(public_id, post_id, locale, slug, h1, seo_title, '
            . 'meta_description, excerpt, body_text, status, published_at, '
            . 'lock_version, created_by_user_public_id, '
            . 'updated_by_user_public_id, created_at, updated_at) '
            . 'SELECT :localization_public_id, p.id, :locale, :slug, :h1, '
            . ':seo_title, :meta_description, :excerpt, :body_text, '
            . ':status, NULL, 1, :created_actor, :updated_actor, '
            . ':created_at, :updated_at FROM ' . $this->posts . ' p '
            . 'WHERE p.public_id = :post_public_id'
        );
        $this->executeConflictAware($statement, [
            'localization_public_id' => $localizationPublicId,
            'locale' => $locale,
            'slug' => $draft->slug(),
            'h1' => $draft->h1(),
            'seo_title' => $draft->seoTitle(),
            'meta_description' => $draft->metaDescription(),
            'excerpt' => $draft->excerpt(),
            'body_text' => $draft->bodyText(),
            'status' => BlogPostVariant::DRAFT,
            'created_actor' => $actorPublicId,
            'updated_actor' => $actorPublicId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'post_public_id' => $postPublicId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new BlogPersistenceException();
        }
        $this->upsertRobotsSettings(
            $localizationPublicId,
            $draft->robotsPreferences()
        );
    }

    public function lockVariant(
        string $postPublicId,
        string $locale
    ): ?BlogPostVariant {
        $this->assertTransaction();

        return $this->variantByPostAndLocale($postPublicId, $locale, true);
    }

    public function lockTrashedVariant(
        string $postPublicId,
        string $locale
    ): ?BlogPostVariant {
        $this->assertEditorialActionsAvailable();
        $this->assertTransaction();

        return $this->variantByPostAndLocale(
            $postPublicId,
            $locale,
            true,
            true
        );
    }

    public function slugExists(
        string $locale,
        string $slug,
        ?string $exceptLocalizationPublicId = null
    ): bool {
        $sql = 'SELECT public_id FROM ' . $this->localizations
            . ' WHERE locale = :locale AND slug = :slug';
        $parameters = ['locale' => $locale, 'slug' => $slug];
        if ($exceptLocalizationPublicId !== null) {
            $sql .= ' AND public_id <> :except_public_id';
            $parameters['except_public_id'] = $exceptLocalizationPublicId;
        }
        if ($this->transactionActive) {
            $sql .= $this->forUpdate();
        }

        return $this->one($sql, $parameters) !== null;
    }

    public function updateDraft(
        string $localizationPublicId,
        int $expectedLockVersion,
        BlogDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool {
        $this->assertTransaction();
        $statement = $this->prepare(
            'UPDATE ' . $this->localizations . ' SET slug = :slug, '
            . 'h1 = :h1, seo_title = :seo_title, '
            . 'meta_description = :meta_description, excerpt = :excerpt, '
            . 'body_text = :body_text, '
            . 'updated_by_user_public_id = :actor_public_id, '
            . 'updated_at = :updated_at, lock_version = lock_version + 1 '
            . 'WHERE public_id = :public_id '
            . 'AND lock_version = :expected_lock_version '
            . 'AND status = :expected_status'
            . $this->activeUpdatePredicate()
        );
        $this->executeConflictAware($statement, [
            'slug' => $draft->slug(),
            'h1' => $draft->h1(),
            'seo_title' => $draft->seoTitle(),
            'meta_description' => $draft->metaDescription(),
            'excerpt' => $draft->excerpt(),
            'body_text' => $draft->bodyText(),
            'actor_public_id' => $actorPublicId,
            'updated_at' => self::format($now),
            'public_id' => $localizationPublicId,
            'expected_lock_version' => $expectedLockVersion,
            'expected_status' => BlogPostVariant::DRAFT,
        ], BlogPersistenceConflict::SLUG);

        $updated = $statement->rowCount() === 1;
        if ($updated) {
            $this->upsertRobotsSettings(
                $localizationPublicId,
                $draft->robotsPreferences()
            );
        }

        return $updated;
    }

    public function updateStatus(
        string $localizationPublicId,
        int $expectedLockVersion,
        string $expectedStatus,
        string $nextStatus,
        ?DateTimeImmutable $publishedAt,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool {
        $this->assertTransaction();
        $statement = $this->prepare(
            'UPDATE ' . $this->localizations . ' SET status = :next_status, '
            . 'published_at = :published_at, '
            . 'updated_by_user_public_id = :actor_public_id, '
            . 'updated_at = :updated_at, lock_version = lock_version + 1 '
            . 'WHERE public_id = :public_id '
            . 'AND lock_version = :expected_lock_version '
            . 'AND status = :expected_status'
            . $this->activeUpdatePredicate()
        );
        $this->execute($statement, [
            'next_status' => $nextStatus,
            'published_at' => $publishedAt === null
                ? null
                : self::format($publishedAt),
            'actor_public_id' => $actorPublicId,
            'updated_at' => self::format($now),
            'public_id' => $localizationPublicId,
            'expected_lock_version' => $expectedLockVersion,
            'expected_status' => $expectedStatus,
        ]);

        return $statement->rowCount() === 1;
    }

    public function touchPost(
        string $postPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        $statement = $this->prepare(
            'UPDATE ' . $this->posts . ' SET updated_at = :updated_at '
            . 'WHERE public_id = :public_id'
        );
        $this->execute($statement, [
            'updated_at' => self::format($now),
            'public_id' => $postPublicId,
        ]);
        if ($statement->rowCount() > 1) {
            throw new BlogPersistenceException();
        }
    }

    public function insertTombstone(
        string $localizationPublicId,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertEditorialActionsAvailable();
        $this->assertTransaction();
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tombstones . ' '
                . '(post_localization_id, trashed_by_user_public_id, '
                . 'trashed_at) SELECT l.id, :actor_public_id, :trashed_at '
                . 'FROM ' . $this->localizations . ' l '
                . 'WHERE l.public_id = :localization_public_id '
                . "AND l.status = 'draft'"
        );
        $this->execute($statement, [
            'actor_public_id' => $actorPublicId,
            'trashed_at' => self::format($now),
            'localization_public_id' => $localizationPublicId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new BlogPersistenceException();
        }
    }

    public function deleteTombstone(string $localizationPublicId): bool
    {
        $this->assertEditorialActionsAvailable();
        $this->assertTransaction();
        $statement = $this->prepare(
            'DELETE FROM ' . $this->tombstones . ' WHERE '
                . 'post_localization_id = (SELECT id FROM '
                . $this->localizations . ' WHERE public_id = '
                . ':localization_public_id)'
        );
        $this->execute($statement, [
            'localization_public_id' => $localizationPublicId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function bumpVariantLock(
        string $localizationPublicId,
        int $expectedLockVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool {
        $this->assertEditorialActionsAvailable();
        $this->assertTransaction();
        $statement = $this->prepare(
            'UPDATE ' . $this->localizations . ' SET '
                . 'lock_version = lock_version + 1, '
                . 'updated_by_user_public_id = :actor_public_id, '
                . 'updated_at = :updated_at WHERE public_id = :public_id '
                . 'AND lock_version = :expected_lock_version '
                . "AND status = 'draft'"
        );
        $this->execute($statement, [
            'actor_public_id' => $actorPublicId,
            'updated_at' => self::format($now),
            'public_id' => $localizationPublicId,
            'expected_lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function listSummaries(int $limit, int $offset): array
    {
        $statement = $this->prepare(
            $this->adminSummarySelect() . ' WHERE 1 = 1'
            . $this->activeVariantPredicate('l') . ' '
            . 'ORDER BY l.updated_at DESC, p.public_id ASC, l.locale ASC, '
            . 'l.public_id ASC LIMIT :list_limit OFFSET :list_offset'
        );
        try {
            if (
                !$statement->bindValue(':list_limit', $limit, PDO::PARAM_INT)
                || !$statement->bindValue(
                    ':list_offset',
                    $offset,
                    PDO::PARAM_INT
                )
                || !$statement->execute()
            ) {
                throw new BlogPersistenceException();
            }
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }

        $rows = $this->enrichAdminSummaryRows(
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );

        return array_map(
            fn (array $row): BlogPostSummary => $this->summaryFromRow($row),
            $rows
        );
    }

    public function searchSummaries(BlogAdminCatalogQuery $query): array
    {
        $sql = $this->adminSummarySelect() . ' WHERE 1 = 1'
            . $this->activeVariantPredicate('l');
        /** @var array<string, array{0: string, 1: int}> $parameters */
        $parameters = [];

        if ($query->search() !== null) {
            $pattern = '%' . self::escapeLike($query->search()) . '%';
            $sql .= ' AND (' . $this->adminCasefoldExpression('l.h1')
                . ' LIKE ' . $this->adminCasefoldExpression(':search_h1')
                . " ESCAPE '!' OR "
                . $this->adminCasefoldExpression("COALESCE(l.slug, '')")
                . ' LIKE ' . $this->adminCasefoldExpression(':search_slug')
                . " ESCAPE '!')";
            $parameters['search_h1'] = [$pattern, PDO::PARAM_STR];
            $parameters['search_slug'] = [$pattern, PDO::PARAM_STR];
        }
        if ($query->status() !== null) {
            $sql .= ' AND l.status = :catalog_status';
            $parameters['catalog_status'] = [
                $query->status(),
                PDO::PARAM_STR,
            ];
        }
        if ($query->locale() !== null) {
            $sql .= ' AND l.locale = :catalog_locale';
            $parameters['catalog_locale'] = [
                $query->locale(),
                PDO::PARAM_STR,
            ];
        }
        if (!$this->reservedCategoryPolicyEnabled) {
            // The private management catalog must never fall back to exposing
            // deterministic QA fixtures when the reserved-category schema is
            // unavailable. Keep this fail-closed at the persistence boundary.
            throw new BlogPersistenceException();
        }
        $sql .= $this->dummyExclusionPredicate('l');
        $parameters['reserved_dummy_public_id'] = [
            BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
            PDO::PARAM_STR,
        ];
        $sql .= $this->adminSummaryOrderBy($query)
            . ' LIMIT :list_limit OFFSET :list_offset';

        $statement = $this->prepare($sql);
        try {
            foreach ($parameters as $name => [$value, $type]) {
                if (!$statement->bindValue(':' . $name, $value, $type)) {
                    throw new BlogPersistenceException();
                }
            }
            if (
                !$statement->bindValue(
                    ':list_limit',
                    $query->limit(),
                    PDO::PARAM_INT
                )
                || !$statement->bindValue(
                    ':list_offset',
                    $query->offset(),
                    PDO::PARAM_INT
                )
                || !$statement->execute()
            ) {
                throw new BlogPersistenceException();
            }
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }

        $rows = $this->enrichAdminSummaryRows(
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );

        return array_map(
            fn (array $row): BlogPostSummary => $this->summaryFromRow($row),
            $rows
        );
    }

    public function listTrashedSummaries(int $limit, int $offset): array
    {
        $this->assertEditorialActionsAvailable();
        $statement = $this->prepare(
            'SELECT p.public_id AS post_public_id, '
                . 'l.public_id AS localization_public_id, l.locale, l.slug, '
                . 'l.h1, l.status, l.published_at, l.lock_version, '
                . 'l.updated_at FROM ' . $this->posts . ' p JOIN '
                . $this->localizations . ' l ON l.post_id = p.id JOIN '
                . $this->tombstones . ' t ON t.post_localization_id = l.id '
                . "WHERE l.status = 'draft' "
                . 'ORDER BY t.trashed_at DESC, p.public_id ASC, '
                . 'l.locale ASC, l.public_id ASC '
                . 'LIMIT :list_limit OFFSET :list_offset'
        );
        try {
            if (
                !$statement->bindValue(':list_limit', $limit, PDO::PARAM_INT)
                || !$statement->bindValue(
                    ':list_offset',
                    $offset,
                    PDO::PARAM_INT
                )
                || !$statement->execute()
            ) {
                throw new BlogPersistenceException();
            }
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }

        return array_map(
            fn (array $row): BlogPostSummary => $this->summaryFromRow($row),
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );
    }

    public function localesForPost(string $postPublicId, int $limit): ?array
    {
        if (
            $limit < 1
            || $limit > BlogSitemapEntry::ALTERNATES_OVERFLOW_QUERY_LIMIT
        ) {
            throw new BlogPersistenceException();
        }
        $statement = $this->prepare(
            'SELECT p.public_id AS post_public_id, l.locale FROM '
            . $this->posts . ' p LEFT JOIN ' . $this->localizations
            . ' l ON l.post_id = p.id WHERE p.public_id = :public_id '
            . 'ORDER BY l.locale ASC LIMIT :locale_limit'
        );
        try {
            if (
                !$statement->bindValue(
                    ':public_id',
                    $postPublicId,
                    PDO::PARAM_STR
                )
                || !$statement->bindValue(
                    ':locale_limit',
                    $limit,
                    PDO::PARAM_INT
                )
                || !$statement->execute()
            ) {
                throw new BlogPersistenceException();
            }
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }

        $rows = $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
        if ($rows === []) {
            return null;
        }

        $locales = [];
        foreach ($rows as $row) {
            if (($row['post_public_id'] ?? null) !== $postPublicId) {
                throw new BlogPersistenceException();
            }
            $locale = $row['locale'] ?? null;
            if ($locale === null) {
                continue;
            }
            if (!is_string($locale)) {
                throw new BlogPersistenceException();
            }
            $locales[] = $locale;
        }

        return $locales;
    }

    public function localesForPosts(
        array $postPublicIds,
        int $limitPerPost
    ): array {
        if (
            !array_is_list($postPublicIds)
            || count($postPublicIds) > BlogAdminCatalogQuery::PAGE_SIZE
            || $limitPerPost < 1
            || $limitPerPost
                > BlogSitemapEntry::ALTERNATES_OVERFLOW_QUERY_LIMIT
        ) {
            throw new BlogPersistenceException();
        }
        $ids = [];
        foreach ($postPublicIds as $postPublicId) {
            if (!is_string($postPublicId)) {
                throw new BlogPersistenceException();
            }
            try {
                $postPublicId = BlogInput::publicId($postPublicId);
            } catch (Throwable) {
                throw new BlogPersistenceException();
            }
            if (isset($ids[$postPublicId])) {
                throw new BlogPersistenceException();
            }
            $ids[$postPublicId] = true;
        }
        if ($ids === []) {
            return [];
        }

        $parameters = [];
        $placeholders = [];
        foreach (array_keys($ids) as $position => $postPublicId) {
            $name = 'batch_post_' . $position;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $postPublicId;
        }
        $maximumRows = count($ids) * $limitPerPost + 1;
        $statement = $this->prepare(
            'SELECT p.public_id AS post_public_id, l.locale FROM '
                . $this->posts . ' p JOIN ' . $this->localizations
                . ' l ON l.post_id = p.id WHERE p.public_id IN ('
                . implode(', ', $placeholders) . ') '
                . 'ORDER BY p.public_id ASC, l.locale ASC '
                . 'LIMIT :batch_locale_limit'
        );
        try {
            foreach ($parameters as $name => $value) {
                if (!$statement->bindValue(
                    ':' . $name,
                    $value,
                    PDO::PARAM_STR
                )) {
                    throw new BlogPersistenceException();
                }
            }
            if (
                !$statement->bindValue(
                    ':batch_locale_limit',
                    $maximumRows,
                    PDO::PARAM_INT
                )
                || !$statement->execute()
            ) {
                throw new BlogPersistenceException();
            }
            $result = array_fill_keys(array_keys($ids), []);
            $seenPosts = [];
            foreach ($this->rows($statement->fetchAll(PDO::FETCH_ASSOC)) as $row) {
                $postPublicId = $this->requiredString($row, 'post_public_id');
                if (!isset($ids[$postPublicId])) {
                    throw new BlogPersistenceException();
                }
                $seenPosts[$postPublicId] = true;
                if (count($result[$postPublicId]) >= $limitPerPost) {
                    throw new BlogPersistenceException();
                }
                $result[$postPublicId][] = BlogInput::locale(
                    $this->requiredString($row, 'locale')
                );
            }
            if (count($seenPosts) !== count($ids)) {
                throw new BlogPersistenceException();
            }

            return $result;
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function variant(
        string $postPublicId,
        string $locale
    ): ?BlogPostVariant {
        return $this->variantByPostAndLocale($postPublicId, $locale, false);
    }

    public function publishedVariant(
        string $locale,
        string $slug
    ): ?BlogPostVariant {
        $parameters = [
            'locale' => $locale,
            'slug' => $slug,
            'status' => BlogPostVariant::PUBLISHED,
        ];
        if ($this->reservedCategoryPolicyEnabled) {
            $parameters['reserved_dummy_public_id'] =
                BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID;
        }
        $row = $this->one(
            $this->variantSelect()
                . ' WHERE l.locale = :locale AND l.slug = :slug '
                . 'AND l.status = :status AND l.published_at IS NOT NULL'
                . $this->dummyExclusionPredicate('l')
                . $this->activeVariantPredicate('l'),
            $parameters
        );

        return $row === null ? null : $this->variantFromRow($row);
    }

    public function listPublishedCards(
        string $locale,
        int $limit,
        int $offset
    ): array {
        $statement = $this->prepare(
            'SELECT l.locale, l.slug, l.h1, l.excerpt, l.published_at, '
            . 'l.updated_at FROM ' . $this->localizations
            . ' l WHERE l.locale = :locale '
            . 'AND l.status = :status AND l.slug IS NOT NULL '
            . 'AND l.excerpt IS NOT NULL AND l.published_at IS NOT NULL'
            . $this->dummyExclusionPredicate('l')
            . $this->activeVariantPredicate('l') . ' '
            . 'ORDER BY l.published_at DESC, l.public_id ASC '
            . 'LIMIT :list_limit OFFSET :list_offset'
        );
        try {
            if (
                !$statement->bindValue(':locale', $locale, PDO::PARAM_STR)
                || !$statement->bindValue(
                    ':status',
                    BlogPostVariant::PUBLISHED,
                    PDO::PARAM_STR
                )
                || !$this->bindReservedDummyCategory($statement)
                || !$statement->bindValue(
                    ':list_limit',
                    $limit,
                    PDO::PARAM_INT
                )
                || !$statement->bindValue(
                    ':list_offset',
                    $offset,
                    PDO::PARAM_INT
                )
                || !$statement->execute()
            ) {
                throw new BlogPersistenceException();
            }
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }

        return array_map(
            fn (array $row): PublishedPostCard =>
                $this->publishedCardFromRow($row),
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );
    }

    public function sitemapEntries(int $limit): array
    {
        if (
            $limit < 1
            || $limit > BlogSitemapEntry::OVERFLOW_QUERY_LIMIT
        ) {
            throw new BlogPersistenceException();
        }
        $statement = $this->prepare(
            'SELECT p.public_id AS post_public_id, l.locale, l.slug, '
            . 'l.published_at, l.updated_at FROM ' . $this->posts . ' p '
            . 'JOIN ' . $this->localizations . ' l ON l.post_id = p.id '
            . 'WHERE l.status = :status AND l.slug IS NOT NULL '
            . 'AND l.published_at IS NOT NULL'
            . $this->dummyExclusionPredicate('l')
            . $this->activeVariantPredicate('l') . ' '
            . 'ORDER BY l.locale ASC, l.slug ASC, l.public_id ASC '
            . 'LIMIT :sitemap_limit'
        );
        try {
            if (
                !$statement->bindValue(
                    ':status',
                    BlogPostVariant::PUBLISHED,
                    PDO::PARAM_STR
                )
                || !$this->bindReservedDummyCategory($statement)
                || !$statement->bindValue(
                    ':sitemap_limit',
                    $limit,
                    PDO::PARAM_INT
                )
                || !$statement->execute()
            ) {
                throw new BlogPersistenceException();
            }
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }

        return array_map(
            fn (array $row): BlogSitemapEntry =>
                $this->sitemapEntryFromRow($row),
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );
    }

    public function publishedSitemapEntriesForPost(
        string $postPublicId,
        int $limit
    ): array {
        if (
            $limit < 1
            || $limit > BlogSitemapEntry::ALTERNATES_OVERFLOW_QUERY_LIMIT
        ) {
            throw new BlogPersistenceException();
        }
        $statement = $this->prepare(
            'SELECT p.public_id AS post_public_id, l.locale, l.slug, '
            . 'l.published_at, l.updated_at FROM ' . $this->posts . ' p '
            . 'JOIN ' . $this->localizations . ' l ON l.post_id = p.id '
            . 'WHERE p.public_id = :post_public_id '
            . 'AND l.status = :status AND l.slug IS NOT NULL '
            . 'AND l.published_at IS NOT NULL'
            . $this->dummyExclusionPredicate('l')
            . $this->activeVariantPredicate('l') . ' '
            . 'ORDER BY l.locale ASC, l.slug ASC, l.public_id ASC '
            . 'LIMIT :alternate_limit'
        );
        try {
            if (
                !$statement->bindValue(
                    ':post_public_id',
                    $postPublicId,
                    PDO::PARAM_STR
                )
                || !$statement->bindValue(
                    ':status',
                    BlogPostVariant::PUBLISHED,
                    PDO::PARAM_STR
                )
                || !$this->bindReservedDummyCategory($statement)
                || !$statement->bindValue(
                    ':alternate_limit',
                    $limit,
                    PDO::PARAM_INT
                )
                || !$statement->execute()
            ) {
                throw new BlogPersistenceException();
            }
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }

        return array_map(
            fn (array $row): BlogSitemapEntry =>
                $this->sitemapEntryFromRow($row),
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );
    }

    public function assignedCategoryPublicIds(
        string $postPublicId,
        int $limit
    ): array {
        $this->assertTransaction();
        if ($limit < 1 || $limit > 101) {
            throw new BlogPersistenceException();
        }
        $statement = $this->prepare(
            'SELECT c.public_id FROM ' . $this->postCategories . ' pc JOIN '
                . $this->posts . ' p ON p.id = pc.post_id JOIN '
                . $this->categories . ' c ON c.id = pc.category_id '
                . 'WHERE p.public_id = :post_public_id '
                . 'ORDER BY c.public_id LIMIT :category_limit'
                . $this->forUpdate()
        );
        try {
            if (
                !$statement->bindValue(
                    ':post_public_id',
                    $postPublicId,
                    PDO::PARAM_STR
                )
                || !$statement->bindValue(
                    ':category_limit',
                    $limit,
                    PDO::PARAM_INT
                )
                || !$statement->execute()
            ) {
                throw new BlogPersistenceException();
            }
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }

        $result = [];
        foreach ($this->rows($statement->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $value = $this->requiredString($row, 'public_id');
            if (isset($result[$value])) {
                throw new BlogPersistenceException();
            }
            $result[$value] = true;
        }

        return array_keys($result);
    }

    public function insertCategoryAssignments(
        string $postPublicId,
        array $assignmentPublicIdsByCategory,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        if (count($assignmentPublicIdsByCategory) > 100) {
            throw new BlogPersistenceException();
        }
        $timestamp = self::format($now);
        foreach ($assignmentPublicIdsByCategory as $category => $assignment) {
            if (!is_string($category) || !is_string($assignment)) {
                throw new BlogPersistenceException();
            }
            $statement = $this->prepare(
                'INSERT INTO ' . $this->postCategories . ' '
                    . '(public_id, post_id, category_id, '
                    . 'assigned_by_user_public_id, created_at, updated_at) '
                    . 'SELECT :assignment_public_id, p.id, c.id, '
                    . ':actor_public_id, :created_at, :updated_at FROM '
                    . $this->posts . ' p CROSS JOIN ' . $this->categories
                    . ' c WHERE p.public_id = :post_public_id '
                    . 'AND c.public_id = :category_public_id'
            );
            $this->execute($statement, [
                'assignment_public_id' => $assignment,
                'actor_public_id' => $actorPublicId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'post_public_id' => $postPublicId,
                'category_public_id' => $category,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new BlogPersistenceException();
            }
        }
    }

    private function variantByPostAndLocale(
        string $postPublicId,
        string $locale,
        bool $lock,
        bool $trashed = false
    ): ?BlogPostVariant {
        if ($trashed) {
            $this->assertEditorialActionsAvailable();
        }
        $row = $this->one(
            $this->variantSelect()
                . ' WHERE p.public_id = :post_public_id '
                . 'AND l.locale = :locale'
                . ($trashed
                    ? $this->trashedVariantPredicate('l')
                    : $this->activeVariantPredicate('l'))
                . ($lock ? $this->forUpdate() : ''),
            ['post_public_id' => $postPublicId, 'locale' => $locale]
        );

        return $row === null ? null : $this->variantFromRow($row);
    }

    private function adminSummarySelect(): string
    {
        $robotsProjection = $this->robotsSettingsEnabled
            ? ', rs.allow_index AS robots_index, '
                . 'rs.allow_follow AS robots_follow, '
                . 'rs.settings_sha256 AS robots_sha256'
            : ', NULL AS robots_index, NULL AS robots_follow, '
                . 'NULL AS robots_sha256';
        $robotsJoin = $this->robotsSettingsEnabled
            ? ' LEFT JOIN ' . $this->robotsSettings
                . ' rs ON rs.localization_id = l.id'
            : '';
        $authorProjection = $this->adminUsers === null
            ? ', NULL AS author_display_name'
            : ', au.display_name AS author_display_name';
        $authorJoin = $this->adminUsers === null
            ? ''
            : ' LEFT JOIN ' . $this->adminUsers
                . ' au ON au.public_id = p.created_by_user_public_id';

        return 'SELECT p.public_id AS post_public_id, '
            . 'l.public_id AS localization_public_id, l.locale, l.slug, '
            . 'l.h1, l.status, l.published_at, l.lock_version, l.updated_at'
            . $robotsProjection . $authorProjection
            . ' FROM ' . $this->posts . ' p JOIN ' . $this->localizations
            . ' l ON l.post_id = p.id' . $robotsJoin . $authorJoin;
    }

    private function adminSummaryOrderBy(
        BlogAdminCatalogQuery $query
    ): string {
        $direction = match ($query->direction()) {
            BlogAdminCatalogQuery::DIRECTION_ASC => 'ASC',
            BlogAdminCatalogQuery::DIRECTION_DESC => 'DESC',
            default => throw new BlogPersistenceException(),
        };
        $author = $this->adminUsers === null
            ? "''"
            : "COALESCE(au.display_name, '')";
        $robotsIndex = $this->robotsSettingsEnabled
            ? 'COALESCE(rs.allow_index, 1)'
            : '1';
        $robotsFollow = $this->robotsSettingsEnabled
            ? 'COALESCE(rs.allow_follow, 1)'
            : '1';
        $primary = match ($query->sort()) {
            BlogAdminCatalogQuery::SORT_TITLE =>
                $this->adminCasefoldExpression('l.h1') . ' ' . $direction,
            BlogAdminCatalogQuery::SORT_LOCALE =>
                'l.locale ' . $direction,
            BlogAdminCatalogQuery::SORT_STATUS =>
                "CASE l.status WHEN 'draft' THEN 0 "
                    . "WHEN 'published' THEN 1 ELSE 2 END " . $direction,
            BlogAdminCatalogQuery::SORT_AUTHOR =>
                'CASE WHEN ' . $author . " = '' THEN 1 ELSE 0 END ASC, "
                    . $this->adminCasefoldExpression($author)
                    . ' ' . $direction,
            BlogAdminCatalogQuery::SORT_ROBOTS =>
                $robotsIndex . ' ' . $direction . ', '
                    . $robotsFollow . ' ' . $direction,
            BlogAdminCatalogQuery::SORT_UPDATED =>
                'l.updated_at ' . $direction,
            default => throw new BlogPersistenceException(),
        };

        // Public identifiers make offset pagination deterministic when the
        // selected business value is shared by several variants.
        return ' ORDER BY ' . $primary
            . ', p.public_id ASC, l.locale ASC, l.public_id ASC';
    }

    /**
     * Adds the localized category labels in one bounded query. Author and
     * robots data are already projected by the base statement. No internal
     * identifiers cross the repository boundary.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function enrichAdminSummaryRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['category_names'] = [];
        }
        unset($row);
        if (!$this->adminCategoryProjectionEnabled || $rows === []) {
            return $rows;
        }

        $predicates = [];
        $parameters = [];
        $rowKeys = [];
        foreach ($rows as $index => $row) {
            $postPublicId = $this->requiredString($row, 'post_public_id');
            $locale = $this->requiredString($row, 'locale');
            $key = $postPublicId . "\0" . $locale;
            $rowKeys[$index] = $key;
            $predicates[] = '(p.public_id = :admin_post_' . $index
                . ' AND cl.locale = :admin_locale_' . $index . ')';
            $parameters['admin_post_' . $index] = $postPublicId;
            $parameters['admin_locale_' . $index] = $locale;
        }

        $statement = $this->prepare(
            'SELECT p.public_id AS post_public_id, cl.locale, cl.name, '
                . 'c.public_id AS category_public_id FROM ' . $this->posts
                . ' p JOIN ' . $this->postCategories
                . ' pc ON pc.post_id = p.id JOIN ' . $this->categories
                . ' c ON c.id = pc.category_id JOIN '
                . $this->categoryLocales
                . ' cl ON cl.category_id = c.id WHERE '
                . implode(' OR ', $predicates)
                . ' ORDER BY p.public_id ASC, cl.locale ASC, cl.name ASC, '
                . 'c.public_id ASC'
        );
        $this->execute($statement, $parameters);
        $categoriesByRow = [];
        foreach ($this->rows($statement->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $key = $this->requiredString($row, 'post_public_id') . "\0"
                . $this->requiredString($row, 'locale');
            $categoriesByRow[$key] ??= [];
            if (count($categoriesByRow[$key]) >= 100) {
                throw new BlogPersistenceException();
            }
            $categoriesByRow[$key][] = $this->requiredString($row, 'name');
        }

        foreach ($rows as $index => &$row) {
            $row['category_names'] = $categoriesByRow[$rowKeys[$index]] ?? [];
        }
        unset($row);

        return $rows;
    }

    private function variantSelect(): string
    {
        $robotsProjection = $this->robotsSettingsEnabled
            ? ', rs.allow_index AS robots_index, '
                . 'rs.allow_follow AS robots_follow, '
                . 'rs.settings_sha256 AS robots_sha256 '
            : ', NULL AS robots_index, NULL AS robots_follow, '
                . 'NULL AS robots_sha256 ';
        $robotsJoin = $this->robotsSettingsEnabled
            ? ' LEFT JOIN ' . $this->robotsSettings
                . ' rs ON rs.localization_id = l.id'
            : '';

        return 'SELECT p.public_id AS post_public_id, '
            . 'l.public_id AS localization_public_id, l.locale, l.slug, '
            . 'l.h1, l.seo_title, l.meta_description, l.excerpt, '
            . 'l.body_text, l.status, l.published_at, l.lock_version, '
            . 'l.created_by_user_public_id, l.updated_by_user_public_id, '
            . 'l.created_at, l.updated_at' . $robotsProjection
            . 'FROM ' . $this->posts . ' p JOIN '
            . $this->localizations . ' l ON l.post_id = p.id'
            . $robotsJoin;
    }

    private function variantFromRow(array $row): BlogPostVariant
    {
        try {
            return new BlogPostVariant(
                $this->requiredString($row, 'post_public_id'),
                $this->requiredString($row, 'localization_public_id'),
                $this->requiredString($row, 'locale'),
                new BlogDraft(
                    $this->requiredString($row, 'h1'),
                    $this->requiredString($row, 'body_text'),
                    $this->nullableString($row, 'slug'),
                    $this->nullableString($row, 'seo_title'),
                    $this->nullableString($row, 'meta_description'),
                    $this->nullableString($row, 'excerpt'),
                    $this->robotsPreferencesFromRow($row)
                ),
                $this->requiredString($row, 'status'),
                $this->nullableTimestamp($row['published_at'] ?? null),
                $this->positiveInteger($row['lock_version'] ?? null),
                $this->requiredString($row, 'created_by_user_public_id'),
                $this->requiredString($row, 'updated_by_user_public_id'),
                $this->timestamp($row['created_at'] ?? null),
                $this->timestamp($row['updated_at'] ?? null)
            );
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    private function upsertRobotsSettings(
        string $localizationPublicId,
        BlogRobotsPreferences $preferences
    ): void {
        if (!$this->robotsSettingsEnabled) {
            return;
        }
        $sql = 'INSERT INTO ' . $this->robotsSettings
            . ' (localization_id, allow_index, allow_follow, settings_sha256) '
            . 'SELECT l.id, :allow_index, :allow_follow, :settings_sha256 '
            . 'FROM ' . $this->localizations
            . ' l WHERE l.public_id = :localization_public_id ';
        $sql .= $this->driver === 'mysql'
            ? 'ON DUPLICATE KEY UPDATE allow_index = VALUES(allow_index), '
                . 'allow_follow = VALUES(allow_follow), '
                . 'settings_sha256 = VALUES(settings_sha256)'
            : 'ON CONFLICT(localization_id) DO UPDATE SET '
                . 'allow_index = excluded.allow_index, '
                . 'allow_follow = excluded.allow_follow, '
                . 'settings_sha256 = excluded.settings_sha256';
        $statement = $this->prepare($sql);
        $this->execute($statement, [
            'allow_index' => $preferences->index() ? 1 : 0,
            'allow_follow' => $preferences->follow() ? 1 : 0,
            'settings_sha256' => $preferences->integrityHash(),
            'localization_public_id' => $localizationPublicId,
        ]);
        $stored = $this->one(
            'SELECT r.allow_index, r.allow_follow, r.settings_sha256 FROM '
                . $this->robotsSettings . ' r JOIN ' . $this->localizations
                . ' l ON l.id = r.localization_id '
                . 'WHERE l.public_id = :localization_public_id',
            ['localization_public_id' => $localizationPublicId]
        );
        if (
            $stored === null
            || (int) ($stored['allow_index'] ?? -1)
                !== ($preferences->index() ? 1 : 0)
            || (int) ($stored['allow_follow'] ?? -1)
                !== ($preferences->follow() ? 1 : 0)
            || !is_string($stored['settings_sha256'] ?? null)
            || !hash_equals(
                $preferences->integrityHash(),
                (string) $stored['settings_sha256']
            )
        ) {
            throw new BlogPersistenceException();
        }
    }

    /** @param array<string, mixed> $row */
    private function robotsPreferencesFromRow(
        array $row
    ): BlogRobotsPreferences {
        $index = $row['robots_index'] ?? null;
        $follow = $row['robots_follow'] ?? null;
        $hash = $row['robots_sha256'] ?? null;
        if ($index === null && $follow === null && $hash === null) {
            return BlogRobotsPreferences::defaults();
        }
        if (
            !in_array($index, [0, 1, '0', '1'], true)
            || !in_array($follow, [0, 1, '0', '1'], true)
            || !is_string($hash)
        ) {
            throw new BlogPersistenceException();
        }
        $preferences = new BlogRobotsPreferences(
            (int) $index === 1,
            (int) $follow === 1
        );
        if (!hash_equals($preferences->integrityHash(), $hash)) {
            throw new BlogPersistenceException();
        }

        return $preferences;
    }

    private function summaryFromRow(array $row): BlogPostSummary
    {
        try {
            return new BlogPostSummary(
                $this->requiredString($row, 'post_public_id'),
                $this->requiredString($row, 'localization_public_id'),
                $this->requiredString($row, 'locale'),
                $this->nullableString($row, 'slug'),
                $this->requiredString($row, 'h1'),
                $this->requiredString($row, 'status'),
                $this->nullableTimestamp($row['published_at'] ?? null),
                $this->positiveInteger($row['lock_version'] ?? null),
                $this->timestamp($row['updated_at'] ?? null),
                $this->nullableString($row, 'author_display_name'),
                $row['category_names'] ?? [],
                $this->robotsPreferencesFromRow($row)
            );
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    private function sitemapEntryFromRow(array $row): BlogSitemapEntry
    {
        try {
            return new BlogSitemapEntry(
                $this->requiredString($row, 'locale'),
                $this->requiredString($row, 'slug'),
                $this->timestamp($row['published_at'] ?? null),
                $this->timestamp($row['updated_at'] ?? null),
                $this->nullableString($row, 'post_public_id')
            );
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    private function publishedCardFromRow(array $row): PublishedPostCard
    {
        try {
            return new PublishedPostCard(
                $this->requiredString($row, 'locale'),
                $this->requiredString($row, 'slug'),
                $this->requiredString($row, 'h1'),
                $this->requiredString($row, 'excerpt'),
                $this->timestamp($row['published_at'] ?? null),
                $this->timestamp($row['updated_at'] ?? null)
            );
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
        if (!$statement instanceof PDOStatement) {
            throw new BlogPersistenceException();
        }

        return $statement;
    }

    /** @param array<string, mixed> $parameters */
    private function execute(
        PDOStatement $statement,
        array $parameters = []
    ): void {
        try {
            $success = $parameters === []
                ? $statement->execute()
                : $statement->execute($parameters);
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
        if (!$success) {
            throw new BlogPersistenceException();
        }
    }

    /** @param array<string, mixed> $parameters */
    private function executeConflictAware(
        PDOStatement $statement,
        array $parameters,
        ?string $uniqueFallback = null
    ): void {
        try {
            if (!$statement->execute($parameters)) {
                throw new BlogPersistenceException();
            }
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $kind = $this->uniqueConflictKind($exception, $uniqueFallback);
            if ($kind !== null) {
                throw new BlogPersistenceConflict($kind);
            }

            throw new BlogPersistenceException();
        }
    }

    /** @param array<string, mixed> $parameters @return array<string, mixed>|null */
    private function one(string $sql, array $parameters = []): ?array
    {
        $statement = $this->prepare($sql);
        $this->execute($statement, $parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new BlogPersistenceException();
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new BlogPersistenceException();
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new BlogPersistenceException();
            }
        }

        /** @var list<array<string, mixed>> $rows */
        return array_values($rows);
    }

    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new BlogPersistenceException();
        }

        return $value;
    }

    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new BlogPersistenceException();
        }

        return $value;
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        throw new BlogPersistenceException();
    }

    private function timestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new BlogPersistenceException();
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof DateTimeImmutable
            || (
                $errors !== false
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
            )
            || $parsed->format(self::UTC_FORMAT) !== $value
        ) {
            throw new BlogPersistenceException();
        }

        return $parsed;
    }

    private function nullableTimestamp(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->timestamp($value);
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))
            ->format(self::UTC_FORMAT);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $value
        );
    }

    private function registerSqliteAdminCasefold(): void
    {
        self::$adminCasefoldRegisteredConnections ??= new \WeakMap();
        if (isset(self::$adminCasefoldRegisteredConnections[$this->pdo])) {
            return;
        }
        if (
            !method_exists($this->pdo, 'sqliteCreateFunction')
            || !function_exists('mb_convert_case')
            || !defined('MB_CASE_FOLD')
        ) {
            throw new BlogPersistenceException();
        }
        $registered = $this->pdo->sqliteCreateFunction(
            self::SQLITE_ADMIN_CASEFOLD_FUNCTION,
            static function (mixed $value): string {
                if (
                    !is_string($value)
                    || preg_match('//u', $value) !== 1
                ) {
                    throw new \RuntimeException(
                        'Invalid text supplied to Blog admin casefold.'
                    );
                }

                return mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
            },
            1,
            PDO::SQLITE_DETERMINISTIC
        );
        if (!$registered) {
            throw new BlogPersistenceException();
        }
        self::$adminCasefoldRegisteredConnections[$this->pdo] = true;
    }

    private function adminCasefoldExpression(string $expression): string
    {
        return $this->driver === 'sqlite'
            ? self::SQLITE_ADMIN_CASEFOLD_FUNCTION . '(' . $expression . ')'
            : 'LOWER(' . $expression . ')';
    }

    private function forUpdate(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function activeVariantPredicate(string $alias): string
    {
        if (!$this->postTombstonesEnabled) {
            return '';
        }

        return ' AND NOT EXISTS (SELECT 1 FROM ' . $this->tombstones
            . ' tombstone WHERE tombstone.post_localization_id = '
            . $alias . '.id)';
    }

    private function dummyExclusionPredicate(string $localizationAlias): string
    {
        if (!$this->reservedCategoryPolicyEnabled) {
            return '';
        }
        if (preg_match('/\A[a-z_]+\z/', $localizationAlias) !== 1) {
            throw new BlogPersistenceException();
        }

        return ' AND NOT EXISTS (SELECT 1 FROM ' . $this->postCategories
            . ' reserved_pc JOIN ' . $this->categories
            . ' reserved_category ON reserved_category.id = '
            . 'reserved_pc.category_id WHERE reserved_pc.post_id = '
            . $localizationAlias . '.post_id AND reserved_category.public_id = '
            . ':reserved_dummy_public_id)';
    }

    private function bindReservedDummyCategory(PDOStatement $statement): bool
    {
        return !$this->reservedCategoryPolicyEnabled
            || $statement->bindValue(
                ':reserved_dummy_public_id',
                BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
                PDO::PARAM_STR
            );
    }

    private function trashedVariantPredicate(string $alias): string
    {
        return ' AND EXISTS (SELECT 1 FROM ' . $this->tombstones
            . ' tombstone WHERE tombstone.post_localization_id = '
            . $alias . '.id)';
    }

    private function activeUpdatePredicate(): string
    {
        if (!$this->postTombstonesEnabled) {
            return '';
        }

        return ' AND id NOT IN (SELECT post_localization_id FROM '
            . $this->tombstones . ')';
    }

    private function assertEditorialActionsAvailable(): void
    {
        if (!$this->postTombstonesEnabled) {
            throw new BlogPersistenceException();
        }
    }

    private function assertTransaction(): void
    {
        if (!$this->transactionActive) {
            throw new BlogPersistenceException();
        }
    }

    private function uniqueConflictKind(
        Throwable $exception,
        ?string $fallback
    ): ?string {
        for (
            $current = $exception;
            $current !== null;
            $current = $current->getPrevious()
        ) {
            if (!$current instanceof PDOException) {
                continue;
            }
            $sqlState = (string) ($current->errorInfo[0] ?? $current->getCode());
            $driverCode = (int) ($current->errorInfo[1] ?? 0);
            if (
                !in_array($sqlState, ['23000', '23505'], true)
                && !in_array($driverCode, [19, 1062], true)
            ) {
                continue;
            }

            $detail = strtolower((string) (
                $current->errorInfo[2] ?? $current->getMessage()
            ));
            if (
                str_contains($detail, 'uq_blog_locale_slug')
                || str_contains($detail, 'ux_pl_locale_slug')
                || (
                    str_contains($detail, '.locale')
                    && str_contains($detail, '.slug')
                )
            ) {
                return BlogPersistenceConflict::SLUG;
            }
            if (
                str_contains($detail, 'uq_blog_post_locale')
                || str_contains($detail, 'ux_pl_post_locale')
                || (
                    str_contains($detail, '.post_id')
                    && str_contains($detail, '.locale')
                )
            ) {
                return BlogPersistenceConflict::LOCALE;
            }

            return $fallback;
        }

        return null;
    }
}
