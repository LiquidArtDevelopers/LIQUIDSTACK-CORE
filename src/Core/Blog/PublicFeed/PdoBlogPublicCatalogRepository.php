<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\PublishedPostCard;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

/** Portable, fail-closed PDO read model for public Blog cards. */
final class PdoBlogPublicCatalogRepository implements
    BlogPublicCatalogRepositoryInterface
{
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];
    private const SQLITE_CASEFOLD_FUNCTION =
        'liquidstack_blog_unicode_casefold';

    /** @var null|\WeakMap<PDO, bool> */
    private static ?\WeakMap $casefoldRegisteredConnections = null;

    private readonly string $driver;
    private readonly string $posts;
    private readonly string $localizations;
    private readonly string $categoryLocalizations;
    private readonly string $relations;

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
                $this->registerSqliteCasefold();
            }

            $this->driver = $driver;
            $this->posts = $scope->quotedTable('posts', $driver);
            $this->localizations = $scope->quotedTable(
                'post_localizations',
                $driver
            );
            $this->categoryLocalizations = $scope->quotedTable(
                'category_locales',
                $driver
            );
            $this->relations = $scope->quotedTable(
                'post_categories',
                $driver
            );
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function search(BlogPublicCatalogQuery $query): array
    {
        try {
            $sql = 'SELECT l.locale, l.slug, l.h1, l.excerpt, '
                . 'l.published_at, l.updated_at FROM ' . $this->posts
                . ' p JOIN ' . $this->localizations
                . ' l ON l.post_id = p.id WHERE l.locale = :locale '
                . 'AND l.status = :status AND l.slug IS NOT NULL '
                . 'AND l.excerpt IS NOT NULL '
                . 'AND l.published_at IS NOT NULL';
            $parameters = [
                'locale' => [$query->locale(), PDO::PARAM_STR],
                'status' => [BlogPostVariant::PUBLISHED, PDO::PARAM_STR],
            ];

            if ($query->search() !== null) {
                $pattern = '%' . self::escapeLike($query->search()) . '%';
                $sql .= ' AND (' . $this->casefoldExpression('l.h1')
                    . ' LIKE ' . $this->casefoldExpression(':search_h1')
                    . " ESCAPE '!' OR "
                    . $this->casefoldExpression('l.excerpt') . ' LIKE '
                    . $this->casefoldExpression(':search_excerpt')
                    . " ESCAPE '!' OR "
                    . $this->casefoldExpression('l.body_text') . ' LIKE '
                    . $this->casefoldExpression(':search_body')
                    . " ESCAPE '!')";
                $parameters['search_h1'] = [$pattern, PDO::PARAM_STR];
                $parameters['search_excerpt'] = [$pattern, PDO::PARAM_STR];
                $parameters['search_body'] = [$pattern, PDO::PARAM_STR];
            }

            if ($query->categorySlugs() !== []) {
                [$categorySql, $categoryParameters] =
                    $this->categoryPredicate($query);
                $sql .= $categorySql;
                $parameters = array_replace(
                    $parameters,
                    $categoryParameters
                );
            }

            if ($query->excludeSlug() !== null) {
                $sql .= ' AND l.slug <> :exclude_slug';
                $parameters['exclude_slug'] = [
                    $query->excludeSlug(),
                    PDO::PARAM_STR,
                ];
            }

            $sql .= ' ORDER BY l.published_at DESC, l.public_id ASC '
                . 'LIMIT :catalog_limit OFFSET :catalog_offset';
            $parameters['catalog_limit'] = [$query->limit(), PDO::PARAM_INT];
            $parameters['catalog_offset'] = [
                $query->offset(),
                PDO::PARAM_INT,
            ];

            $statement = $this->prepare($sql);
            $this->execute($statement, $parameters);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                throw new BlogPersistenceException();
            }

            return array_map(
                fn (mixed $row): PublishedPostCard =>
                    $this->cardFromRow($row),
                array_values($rows)
            );
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    /**
     * @return array{string, array<string, array{mixed, int}>}
     */
    private function categoryPredicate(
        BlogPublicCatalogQuery $query
    ): array {
        $parameters = [];
        if ($query->categoryMode() === BlogPublicCatalogQuery::MODE_ANY) {
            $placeholders = [];
            foreach ($query->categorySlugs() as $position => $slug) {
                $key = 'category_any_' . $position;
                $placeholders[] = ':' . $key;
                $parameters[$key] = [$slug, PDO::PARAM_STR];
            }
            $parameters['category_any_locale'] = [
                $query->locale(),
                PDO::PARAM_STR,
            ];

            return [
                ' AND EXISTS (SELECT 1 FROM ' . $this->relations
                    . ' pc JOIN ' . $this->categoryLocalizations
                    . ' cl ON cl.category_id = pc.category_id '
                    . 'WHERE pc.post_id = p.id '
                    . 'AND cl.locale = :category_any_locale '
                    . 'AND cl.slug IN (' . implode(', ', $placeholders)
                    . '))',
                $parameters,
            ];
        }

        $sql = '';
        foreach ($query->categorySlugs() as $position => $slug) {
            $localeKey = 'category_all_locale_' . $position;
            $slugKey = 'category_all_slug_' . $position;
            $sql .= ' AND EXISTS (SELECT 1 FROM ' . $this->relations
                . ' pc' . $position . ' JOIN '
                . $this->categoryLocalizations . ' cl' . $position
                . ' ON cl' . $position . '.category_id = pc' . $position
                . '.category_id WHERE pc' . $position . '.post_id = p.id '
                . 'AND cl' . $position . '.locale = :' . $localeKey . ' '
                . 'AND cl' . $position . '.slug = :' . $slugKey . ')';
            $parameters[$localeKey] = [$query->locale(), PDO::PARAM_STR];
            $parameters[$slugKey] = [$slug, PDO::PARAM_STR];
        }

        return [$sql, $parameters];
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $value
        );
    }

    private function registerSqliteCasefold(): void
    {
        self::$casefoldRegisteredConnections ??= new \WeakMap();
        if (isset(self::$casefoldRegisteredConnections[$this->pdo])) {
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
            self::SQLITE_CASEFOLD_FUNCTION,
            static function (mixed $value): string {
                if (
                    !is_string($value)
                    || preg_match('//u', $value) !== 1
                ) {
                    throw new \RuntimeException(
                        'Invalid text supplied to Blog casefold.'
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
        self::$casefoldRegisteredConnections[$this->pdo] = true;
    }

    private function casefoldExpression(string $expression): string
    {
        return $this->driver === 'sqlite'
            ? self::SQLITE_CASEFOLD_FUNCTION . '(' . $expression . ')'
            : 'LOWER(' . $expression . ')';
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

    /** @param array<string, array{mixed, int}> $parameters */
    private function execute(
        PDOStatement $statement,
        array $parameters
    ): void {
        try {
            foreach ($parameters as $key => [$value, $type]) {
                if (!$statement->bindValue(':' . $key, $value, $type)) {
                    throw new BlogPersistenceException();
                }
            }
            if (!$statement->execute()) {
                throw new BlogPersistenceException();
            }
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    private function cardFromRow(mixed $row): PublishedPostCard
    {
        if (!is_array($row)) {
            throw new BlogPersistenceException();
        }
        try {
            return new PublishedPostCard(
                $this->requiredString($row, 'locale'),
                $this->requiredString($row, 'slug'),
                $this->requiredString($row, 'h1'),
                $this->requiredString($row, 'excerpt'),
                $this->timestamp($row['published_at'] ?? null),
                $this->timestamp($row['updated_at'] ?? null)
            );
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new BlogPersistenceException();
        }

        return $value;
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
}
