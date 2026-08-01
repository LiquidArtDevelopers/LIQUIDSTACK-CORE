<?php

declare(strict_types=1);

namespace App\Core\Blog\Persistence;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogSitemapEntry;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/** Portable PDO implementation for the Blog posts aggregate. */
final class PdoBlogRepository implements BlogRepositoryInterface
{
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];

    private readonly string $driver;
    private readonly string $posts;
    private readonly string $localizations;
    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $scope
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
            }

            $this->driver = $driver;
            $this->posts = $scope->quotedTable('posts', $driver);
            $this->localizations = $scope->quotedTable(
                'post_localizations',
                $driver
            );
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
            BlogException|BlogPersistenceConflict|BlogPersistenceException $exception
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
    }

    public function lockVariant(
        string $postPublicId,
        string $locale
    ): ?BlogPostVariant {
        $this->assertTransaction();

        return $this->variantByPostAndLocale($postPublicId, $locale, true);
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

        return $statement->rowCount() === 1;
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

    public function listSummaries(int $limit, int $offset): array
    {
        $statement = $this->prepare(
            'SELECT p.public_id AS post_public_id, '
            . 'l.public_id AS localization_public_id, l.locale, l.slug, '
            . 'l.h1, l.status, l.published_at, l.lock_version, l.updated_at '
            . 'FROM ' . $this->posts . ' p JOIN ' . $this->localizations
            . ' l ON l.post_id = p.id '
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

        return array_map(
            fn (array $row): BlogPostSummary => $this->summaryFromRow($row),
            $this->rows($statement->fetchAll(PDO::FETCH_ASSOC))
        );
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
        $row = $this->one(
            $this->variantSelect()
                . ' WHERE l.locale = :locale AND l.slug = :slug '
                . 'AND l.status = :status AND l.published_at IS NOT NULL',
            [
                'locale' => $locale,
                'slug' => $slug,
                'status' => BlogPostVariant::PUBLISHED,
            ]
        );

        return $row === null ? null : $this->variantFromRow($row);
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
            'SELECT locale, slug, published_at, updated_at FROM '
            . $this->localizations . ' WHERE status = :status '
            . 'AND slug IS NOT NULL AND published_at IS NOT NULL '
            . 'ORDER BY locale ASC, slug ASC, public_id ASC '
            . 'LIMIT :sitemap_limit'
        );
        try {
            if (
                !$statement->bindValue(
                    ':status',
                    BlogPostVariant::PUBLISHED,
                    PDO::PARAM_STR
                )
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

    private function variantByPostAndLocale(
        string $postPublicId,
        string $locale,
        bool $lock
    ): ?BlogPostVariant {
        $row = $this->one(
            $this->variantSelect()
                . ' WHERE p.public_id = :post_public_id '
                . 'AND l.locale = :locale'
                . ($lock ? $this->forUpdate() : ''),
            ['post_public_id' => $postPublicId, 'locale' => $locale]
        );

        return $row === null ? null : $this->variantFromRow($row);
    }

    private function variantSelect(): string
    {
        return 'SELECT p.public_id AS post_public_id, '
            . 'l.public_id AS localization_public_id, l.locale, l.slug, '
            . 'l.h1, l.seo_title, l.meta_description, l.excerpt, '
            . 'l.body_text, l.status, l.published_at, l.lock_version, '
            . 'l.created_by_user_public_id, l.updated_by_user_public_id, '
            . 'l.created_at, l.updated_at FROM ' . $this->posts . ' p JOIN '
            . $this->localizations . ' l ON l.post_id = p.id';
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
                    $this->nullableString($row, 'excerpt')
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
                $this->timestamp($row['updated_at'] ?? null)
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

    private function forUpdate(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
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
