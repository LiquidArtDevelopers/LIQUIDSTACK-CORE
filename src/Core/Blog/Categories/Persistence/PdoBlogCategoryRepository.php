<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories\Persistence;

use App\Core\Blog\Categories\BlogCategoryDraft;
use App\Core\Blog\Categories\BlogCategoryLocalization;
use App\Core\Blog\Categories\PublishedCategoryFilter;
use App\Core\Blog\PublishedPostCard;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/** Portable PDO repository for the category aggregate. */
final class PdoBlogCategoryRepository implements
    BlogCategoryRepositoryInterface,
    BlogCategoryLocaleLookupRepositoryInterface
{
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';
    private readonly string $driver;
    private readonly string $categories;
    private readonly string $localizations;
    private readonly string $relations;
    private readonly string $posts;
    private readonly string $postLocalizations;
    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $scope
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, ['mysql', 'sqlite'], true)
                || $scope->moduleId() !== 'blog'
                || $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || ($driver === 'mysql' && !in_array(
                    $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                    [false, 0, '0'],
                    true
                ))
            ) {
                throw new BlogCategoryPersistenceException();
            }
            if ($driver === 'sqlite') {
                $foreignKeys = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$foreignKeys instanceof PDOStatement
                    || !in_array($foreignKeys->fetchColumn(), [1, '1'], true)
                ) {
                    throw new BlogCategoryPersistenceException();
                }
            }
            $this->driver = $driver;
            $this->categories = $scope->quotedTable('categories', $driver);
            $this->localizations = $scope->quotedTable(
                'category_locales',
                $driver
            );
            $this->relations = $scope->quotedTable(
                'post_categories',
                $driver
            );
            $this->posts = $scope->quotedTable('posts', $driver);
            $this->postLocalizations = $scope->quotedTable(
                'post_localizations',
                $driver
            );
        } catch (BlogCategoryPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogCategoryPersistenceException();
        }
    }

    public function transactional(callable $operation): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            throw new BlogCategoryPersistenceException();
        }
        $started = false;
        $this->transactionActive = true;
        try {
            if (!$this->pdo->beginTransaction()) {
                throw new BlogCategoryPersistenceException();
            }
            $started = true;
            if ($this->driver === 'sqlite') {
                $this->execute($this->prepare(
                    'UPDATE ' . $this->categories
                    . ' SET updated_at = updated_at WHERE 1 = 0'
                ));
            }
            $result = $operation($this->pdo);
            if (!$this->pdo->commit()) {
                throw new BlogCategoryPersistenceException();
            }
            $started = false;
            $this->transactionActive = false;

            return $result;
        } catch (Throwable $exception) {
            try {
                if ($started && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                    $started = false;
                }
            } catch (Throwable) {
                // Fail closed if rollback cannot be proven.
            }
            $this->transactionActive = false;
            if ($started) {
                throw new BlogCategoryPersistenceException();
            }
            throw $exception;
        }
    }

    public function insertCategory(
        string $categoryPublicId,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        $timestamp = self::format($now);
        $statement = $this->prepare(
            'INSERT INTO ' . $this->categories . ' '
            . '(public_id, created_by_user_public_id, created_at, updated_at) '
            . 'VALUES (:public_id, :actor, :created_at, :updated_at)'
        );
        $this->executeConflictAware($statement, [
            'public_id' => $categoryPublicId,
            'actor' => $actorPublicId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new BlogCategoryPersistenceException();
        }
    }

    public function lockCategory(string $categoryPublicId): bool
    {
        $this->assertTransaction();

        return $this->one(
            'SELECT public_id FROM ' . $this->categories
            . ' WHERE public_id = :public_id' . $this->forUpdate(),
            ['public_id' => $categoryPublicId]
        ) !== null;
    }

    public function insertLocalization(
        string $localizationPublicId,
        string $categoryPublicId,
        string $locale,
        BlogCategoryDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        $timestamp = self::format($now);
        $statement = $this->prepare(
            'INSERT INTO ' . $this->localizations . ' '
            . '(public_id, category_id, locale, slug, name, lock_version, '
            . 'created_by_user_public_id, updated_by_user_public_id, '
            . 'created_at, updated_at) SELECT :public_id, c.id, :locale, '
            . ':slug, :name, 1, :created_actor, :updated_actor, '
            . ':created_at, :updated_at FROM ' . $this->categories
            . ' c WHERE c.public_id = :category_public_id'
        );
        $this->executeConflictAware($statement, [
            'public_id' => $localizationPublicId,
            'locale' => $locale,
            'slug' => $draft->slug(),
            'name' => $draft->name(),
            'created_actor' => $actorPublicId,
            'updated_actor' => $actorPublicId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'category_public_id' => $categoryPublicId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new BlogCategoryPersistenceException();
        }
    }

    public function lockLocalization(
        string $categoryPublicId,
        string $locale
    ): ?BlogCategoryLocalization {
        $this->assertTransaction();

        return $this->hydrate($this->one(
            $this->localizationSelect()
            . ' WHERE c.public_id = :category_public_id '
            . 'AND l.locale = :locale' . $this->forUpdate(),
            [
                'category_public_id' => $categoryPublicId,
                'locale' => $locale,
            ]
        ));
    }

    public function slugExists(
        string $locale,
        string $slug,
        ?string $exceptLocalizationPublicId = null
    ): bool {
        $sql = 'SELECT public_id FROM ' . $this->localizations
            . ' WHERE locale = :locale AND slug = :slug';
        $params = ['locale' => $locale, 'slug' => $slug];
        if ($exceptLocalizationPublicId !== null) {
            $sql .= ' AND public_id <> :except_public_id';
            $params['except_public_id'] = $exceptLocalizationPublicId;
        }

        return $this->one($sql, $params) !== null;
    }

    public function updateLocalization(
        string $localizationPublicId,
        int $expectedLockVersion,
        BlogCategoryDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool {
        $this->assertTransaction();
        $statement = $this->prepare(
            'UPDATE ' . $this->localizations . ' SET slug = :slug, '
            . 'name = :name, lock_version = lock_version + 1, '
            . 'updated_by_user_public_id = :actor, updated_at = :updated_at '
            . 'WHERE public_id = :public_id '
            . 'AND lock_version = :lock_version'
        );
        $this->executeConflictAware($statement, [
            'slug' => $draft->slug(),
            'name' => $draft->name(),
            'actor' => $actorPublicId,
            'updated_at' => self::format($now),
            'public_id' => $localizationPublicId,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function touchCategory(
        string $categoryPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        $statement = $this->prepare(
            'UPDATE ' . $this->categories . ' SET updated_at = :updated_at '
            . 'WHERE public_id = :public_id'
        );
        $this->execute($statement, [
            'updated_at' => self::format($now),
            'public_id' => $categoryPublicId,
        ]);
        if ($statement->rowCount() > 1) {
            throw new BlogCategoryPersistenceException();
        }
    }

    public function category(
        string $categoryPublicId,
        string $locale
    ): ?BlogCategoryLocalization {
        return $this->hydrate($this->one(
            $this->localizationSelect()
            . ' WHERE c.public_id = :category_public_id '
            . 'AND l.locale = :locale',
            [
                'category_public_id' => $categoryPublicId,
                'locale' => $locale,
            ]
        ));
    }

    public function categoryLocales(string $categoryPublicId): ?array
    {
        $statement = $this->prepare(
            'SELECT l.locale FROM ' . $this->categories . ' c LEFT JOIN '
            . $this->localizations . ' l ON l.category_id = c.id '
            . 'WHERE c.public_id = :category_public_id ORDER BY l.locale'
        );
        $this->execute($statement, [
            'category_public_id' => $categoryPublicId,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        $locales = [];
        foreach ($rows as $row) {
            $locale = $row['locale'] ?? null;
            if ($locale === null) {
                continue;
            }
            if (!is_string($locale) || $locale === '') {
                throw new BlogCategoryPersistenceException();
            }
            if (isset($locales[$locale])) {
                throw new BlogCategoryPersistenceException();
            }
            $locales[$locale] = true;
        }

        return array_keys($locales);
    }

    public function listLocalizations(
        int $limit,
        int $offset,
        ?string $locale = null
    ): array {
        $sql = $this->localizationSelect();
        $params = [];
        if ($locale !== null) {
            $sql .= ' WHERE l.locale = :locale';
            $params['locale'] = $locale;
        }
        $sql .= ' ORDER BY l.name, l.locale, c.public_id LIMIT '
            . $limit . ' OFFSET ' . $offset;
        $statement = $this->prepare($sql);
        $this->execute($statement, $params);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = $this->hydrate($row);
            if ($value === null) {
                throw new BlogCategoryPersistenceException();
            }
            $result[] = $value;
        }

        return $result;
    }

    public function lockPost(string $postPublicId): bool
    {
        $this->assertTransaction();

        return $this->one(
            'SELECT public_id FROM ' . $this->posts
            . ' WHERE public_id = :public_id' . $this->forUpdate(),
            ['public_id' => $postPublicId]
        ) !== null;
    }

    public function categoriesExist(array $categoryPublicIds): bool
    {
        $this->assertTransaction();
        if ($categoryPublicIds === []) {
            return true;
        }
        [$in, $params] = $this->inClause($categoryPublicIds, 'category');
        $statement = $this->prepare(
            'SELECT COUNT(*) FROM ' . $this->categories
            . ' WHERE public_id IN (' . $in . ')'
        );
        $this->execute($statement, $params);

        return (int) $statement->fetchColumn() === count($categoryPublicIds);
    }

    public function assignedCategoryPublicIds(string $postPublicId): array
    {
        $statement = $this->prepare(
            'SELECT c.public_id FROM ' . $this->relations . ' pc JOIN '
            . $this->posts . ' p ON p.id = pc.post_id JOIN '
            . $this->categories . ' c ON c.id = pc.category_id '
            . 'WHERE p.public_id = :post_public_id ORDER BY c.public_id'
        );
        $this->execute($statement, ['post_public_id' => $postPublicId]);

        return array_map(
            static fn (array $row): string => (string) $row['public_id'],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function replaceAssignments(
        string $postPublicId,
        array $assignmentPublicIdsByCategory,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        $current = $this->assignedCategoryPublicIds($postPublicId);
        $desired = array_keys($assignmentPublicIdsByCategory);
        $remove = array_values(array_diff($current, $desired));
        if ($remove !== []) {
            [$in, $params] = $this->inClause($remove, 'remove');
            $params['post_public_id'] = $postPublicId;
            $statement = $this->prepare(
                'DELETE FROM ' . $this->relations . ' WHERE post_id = '
                . '(SELECT id FROM ' . $this->posts
                . ' WHERE public_id = :post_public_id) AND category_id IN '
                . '(SELECT id FROM ' . $this->categories
                . ' WHERE public_id IN (' . $in . '))'
            );
            $this->execute($statement, $params);
        }

        $timestamp = self::format($now);
        foreach (array_diff($desired, $current) as $categoryPublicId) {
            $statement = $this->prepare(
                'INSERT INTO ' . $this->relations . ' '
                . '(public_id, post_id, category_id, '
                . 'assigned_by_user_public_id, created_at, updated_at) '
                . 'SELECT :public_id, p.id, c.id, :actor, :created_at, '
                . ':updated_at FROM ' . $this->posts . ' p CROSS JOIN '
                . $this->categories . ' c WHERE p.public_id = '
                . ':post_public_id AND c.public_id = :category_public_id'
            );
            $this->executeConflictAware($statement, [
                'public_id' => $assignmentPublicIdsByCategory[
                    $categoryPublicId
                ],
                'actor' => $actorPublicId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'post_public_id' => $postPublicId,
                'category_public_id' => $categoryPublicId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new BlogCategoryPersistenceException();
            }
        }
    }

    public function publicFilters(string $locale): array
    {
        $statement = $this->prepare(
            'SELECT c.public_id AS category_public_id, cl.locale, cl.slug, '
            . 'cl.name, COUNT(DISTINCT p.id) AS published_count FROM '
            . $this->categories . ' c JOIN ' . $this->localizations
            . ' cl ON cl.category_id = c.id JOIN ' . $this->relations
            . ' pc ON pc.category_id = c.id JOIN ' . $this->posts
            . ' p ON p.id = pc.post_id JOIN ' . $this->postLocalizations
            . " pl ON pl.post_id = p.id AND pl.locale = cl.locale "
            . "AND pl.status = 'published' WHERE cl.locale = :locale "
            . 'GROUP BY c.id, c.public_id, cl.locale, cl.slug, cl.name '
            . 'ORDER BY cl.name, c.public_id'
        );
        $this->execute($statement, ['locale' => $locale]);

        return array_map(
            static fn (array $row): PublishedCategoryFilter =>
                new PublishedCategoryFilter(
                    (string) $row['category_public_id'],
                    (string) $row['locale'],
                    (string) $row['slug'],
                    (string) $row['name'],
                    (int) $row['published_count']
                ),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function publicPostCards(
        string $locale,
        string $categorySlug,
        int $limit,
        int $offset
    ): array {
        $statement = $this->prepare(
            'SELECT pl.locale, pl.slug, pl.h1, pl.excerpt, '
            . 'pl.published_at, pl.updated_at FROM '
            . $this->posts . ' p JOIN ' . $this->postLocalizations
            . ' pl ON pl.post_id = p.id JOIN ' . $this->relations
            . ' pc ON pc.post_id = p.id JOIN ' . $this->localizations
            . ' cl ON cl.category_id = pc.category_id WHERE pl.locale = '
            . ':post_locale AND cl.locale = :category_locale '
            . 'AND cl.slug = :category_slug '
            . "AND pl.status = 'published' ORDER BY pl.published_at DESC, "
            . 'p.public_id LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $this->execute($statement, [
            'post_locale' => $locale,
            'category_locale' => $locale,
            'category_slug' => $categorySlug,
        ]);

        return array_map(
            static fn (array $row): PublishedPostCard =>
                new PublishedPostCard(
                    (string) $row['locale'],
                    (string) $row['slug'],
                    (string) $row['h1'],
                    (string) $row['excerpt'],
                    new DateTimeImmutable(
                        (string) $row['published_at'],
                        new DateTimeZone('UTC')
                    ),
                    new DateTimeImmutable(
                        (string) $row['updated_at'],
                        new DateTimeZone('UTC')
                    )
                ),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function localizationSelect(): string
    {
        return 'SELECT c.public_id AS category_public_id, '
            . 'l.public_id AS localization_public_id, l.locale, l.slug, '
            . 'l.name, l.lock_version, l.updated_at FROM '
            . $this->categories . ' c JOIN ' . $this->localizations
            . ' l ON l.category_id = c.id';
    }

    /** @param null|array<string, mixed> $row */
    private function hydrate(?array $row): ?BlogCategoryLocalization
    {
        if ($row === null) {
            return null;
        }
        try {
            return new BlogCategoryLocalization(
                (string) $row['category_public_id'],
                (string) $row['localization_public_id'],
                (string) $row['locale'],
                new BlogCategoryDraft(
                    (string) $row['name'],
                    (string) $row['slug']
                ),
                (int) $row['lock_version'],
                new DateTimeImmutable(
                    (string) $row['updated_at'],
                    new DateTimeZone('UTC')
                )
            );
        } catch (Throwable) {
            throw new BlogCategoryPersistenceException();
        }
    }

    /** @param array<string, mixed> $params */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->prepare($sql);
        $this->execute($statement, $params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new BlogCategoryPersistenceException();
        }

        return $row;
    }

    /** @param list<string> $values @return array{string, array<string, string>} */
    private function inClause(array $values, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach ($values as $position => $value) {
            $key = $prefix . '_' . $position;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }

        return [implode(', ', $placeholders), $params];
    }

    private function forUpdate(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function assertTransaction(): void
    {
        if (!$this->transactionActive || !$this->pdo->inTransaction()) {
            throw new BlogCategoryPersistenceException();
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new BlogCategoryPersistenceException();
        }

        return $statement;
    }

    /** @param array<string, mixed> $params */
    private function execute(PDOStatement $statement, array $params = []): void
    {
        try {
            if (!$statement->execute($params)) {
                throw new BlogCategoryPersistenceException();
            }
        } catch (BlogCategoryPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogCategoryPersistenceException();
        }
    }

    /** @param array<string, mixed> $params */
    private function executeConflictAware(
        PDOStatement $statement,
        array $params
    ): void {
        try {
            if (!$statement->execute($params)) {
                throw new BlogCategoryPersistenceException();
            }
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                throw new BlogCategoryPersistenceConflict();
            }
            throw new BlogCategoryPersistenceException();
        } catch (BlogCategoryPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogCategoryPersistenceException();
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))
            ->format(self::UTC_FORMAT);
    }
}
