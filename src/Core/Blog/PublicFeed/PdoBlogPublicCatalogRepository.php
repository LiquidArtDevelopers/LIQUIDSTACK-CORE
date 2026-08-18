<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
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
    BlogPublicCatalogRepositoryInterface,
    BlogPublicDiscoveryRepositoryInterface,
    BlogPublicCardTaxonomyRepositoryInterface
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
    private readonly string $tags;
    private readonly string $tagRelations;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $scope,
        private readonly bool $tagsReady = false
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
            $this->tags = $scope->quotedTable('tags', $driver);
            $this->tagRelations = $scope->quotedTable(
                'localization_tags',
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
            [$reservedSql, $reservedParameters] =
                $this->reservedCategoryPredicate('p', 'catalog_reserved');
            $sql .= $reservedSql;
            $parameters = array_replace($parameters, $reservedParameters);

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
                    . " ESCAPE '!'";
                $parameters['search_h1'] = [$pattern, PDO::PARAM_STR];
                $parameters['search_excerpt'] = [$pattern, PDO::PARAM_STR];
                $parameters['search_body'] = [$pattern, PDO::PARAM_STR];
                if ($this->tagsReady) {
                    $sql .= ' OR EXISTS (SELECT 1 FROM '
                        . $this->tagRelations . ' search_lt JOIN '
                        . $this->tags . ' search_t ON search_t.id = '
                        . 'search_lt.tag_id WHERE search_lt.localization_id = '
                        . 'l.id AND search_t.locale = l.locale AND ('
                        . $this->casefoldExpression('search_t.name') . ' LIKE '
                        . $this->casefoldExpression(':search_tag_name')
                        . " ESCAPE '!' OR "
                        . $this->casefoldExpression('search_t.slug') . ' LIKE '
                        . $this->casefoldExpression(':search_tag_slug')
                        . " ESCAPE '!'))";
                    $parameters['search_tag_name'] = [
                        $pattern,
                        PDO::PARAM_STR,
                    ];
                    $parameters['search_tag_slug'] = [
                        $pattern,
                        PDO::PARAM_STR,
                    ];
                }
                $sql .= ')';
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

            if ($query->excludedCategorySlugs() !== []) {
                [$excludedCategorySql, $excludedCategoryParameters] =
                    $this->excludedCategoryPredicate($query);
                $sql .= $excludedCategorySql;
                $parameters = array_replace(
                    $parameters,
                    $excludedCategoryParameters
                );
            }

            if ($query->excludeSlug() !== null) {
                $sql .= ' AND l.slug <> :exclude_slug';
                $parameters['exclude_slug'] = [
                    $query->excludeSlug(),
                    PDO::PARAM_STR,
                ];
            }

            $sql .= ' ORDER BY ' . $this->catalogOrder($query) . ' '
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

    public function categoriesForCards(
        BlogPublicCardCategoryQuery $query
    ): array {
        return $this->taxonomiesForCards(new BlogPublicCardTaxonomyQuery(
            $query->locale(),
            $query->cardSlugs()
        ))->categoriesBySlug();
    }

    public function taxonomiesForCards(
        BlogPublicCardTaxonomyQuery $query
    ): BlogPublicCardTaxonomyBatch {
        $cardSlugs = $query->cardSlugs();
        $categories = array_fill_keys($cardSlugs, []);
        $tags = array_fill_keys($cardSlugs, []);
        if ($cardSlugs === []) {
            return new BlogPublicCardTaxonomyBatch($categories, $tags);
        }

        try {
            $categoryPlaceholders = [];
            $parameters = [
                'taxonomy_category_card_locale' => [
                    $query->locale(),
                    PDO::PARAM_STR,
                ],
                'taxonomy_category_card_status' => [
                    BlogPostVariant::PUBLISHED,
                    PDO::PARAM_STR,
                ],
                'taxonomy_category_locale' => [
                    $query->locale(),
                    PDO::PARAM_STR,
                ],
                'taxonomy_category_reserved_slug' => [
                    BlogReservedCategoryPolicy::DUMMY_SLUG,
                    PDO::PARAM_STR,
                ],
            ];
            foreach ($cardSlugs as $position => $slug) {
                $key = 'taxonomy_category_card_slug_' . $position;
                $categoryPlaceholders[] = ':' . $key;
                $parameters[$key] = [$slug, PDO::PARAM_STR];
            }
            [$reservedSql, $reservedParameters] =
                $this->reservedCategoryPredicate(
                    'p',
                    'taxonomy_category_card_reserved'
                );
            $parameters = array_replace($parameters, $reservedParameters);

            $sql = "SELECT l.slug AS card_slug, 'category' AS taxonomy_kind, "
                . 'cl.locale, cl.slug, cl.name, cl.name AS category_sort, '
                . "cl.public_id AS category_tie, '' AS tag_sort "
                . 'FROM ' . $this->posts . ' p JOIN '
                . $this->localizations . ' l ON l.post_id = p.id JOIN '
                . $this->relations . ' pc ON pc.post_id = p.id JOIN '
                . $this->categoryLocalizations
                . ' cl ON cl.category_id = pc.category_id '
                . 'WHERE l.locale = :taxonomy_category_card_locale '
                . 'AND l.status = :taxonomy_category_card_status '
                . 'AND l.slug IN ('
                . implode(', ', $categoryPlaceholders) . ') '
                . 'AND l.published_at IS NOT NULL '
                . 'AND cl.locale = :taxonomy_category_locale '
                . 'AND cl.slug <> :taxonomy_category_reserved_slug '
                . $reservedSql;

            if ($this->tagsReady) {
                $tagPlaceholders = [];
                $parameters['taxonomy_tag_card_locale'] = [
                    $query->locale(),
                    PDO::PARAM_STR,
                ];
                $parameters['taxonomy_tag_card_status'] = [
                    BlogPostVariant::PUBLISHED,
                    PDO::PARAM_STR,
                ];
                $parameters['taxonomy_tag_locale'] = [
                    $query->locale(),
                    PDO::PARAM_STR,
                ];
                foreach ($cardSlugs as $position => $slug) {
                    $key = 'taxonomy_tag_card_slug_' . $position;
                    $tagPlaceholders[] = ':' . $key;
                    $parameters[$key] = [$slug, PDO::PARAM_STR];
                }
                [$tagReservedSql, $tagReservedParameters] =
                    $this->reservedCategoryPredicate(
                        'tag_p',
                        'taxonomy_tag_card_reserved'
                    );
                $parameters = array_replace(
                    $parameters,
                    $tagReservedParameters
                );
                $sql .= " UNION ALL SELECT tag_l.slug AS card_slug, 'tag' "
                    . 'AS taxonomy_kind, tag_t.locale, tag_t.slug, '
                    . "tag_t.name, '' AS category_sort, '' AS category_tie, "
                    . 'tag_t.slug AS tag_sort FROM ' . $this->posts
                    . ' tag_p JOIN ' . $this->localizations
                    . ' tag_l ON tag_l.post_id = tag_p.id JOIN '
                    . $this->tagRelations
                    . ' tag_lt ON tag_lt.localization_id = tag_l.id JOIN '
                    . $this->tags . ' tag_t ON tag_t.id = tag_lt.tag_id '
                    . 'WHERE tag_l.locale = :taxonomy_tag_card_locale '
                    . 'AND tag_l.status = :taxonomy_tag_card_status '
                    . 'AND tag_l.slug IN ('
                    . implode(', ', $tagPlaceholders) . ') '
                    . 'AND tag_l.published_at IS NOT NULL '
                    . 'AND tag_t.locale = :taxonomy_tag_locale '
                    . 'AND tag_t.locale = tag_l.locale '
                    . $tagReservedSql;
            }
            $sql .= 'ORDER BY card_slug ASC, taxonomy_kind ASC, '
                . 'category_sort ASC, category_tie ASC, tag_sort ASC';
            $statement = $this->prepare($sql);
            $this->execute($statement, $parameters);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                throw new BlogPersistenceException();
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new BlogPersistenceException();
                }
                $cardSlug = $this->requiredString($row, 'card_slug');
                if (!array_key_exists($cardSlug, $categories)) {
                    throw new BlogPersistenceException();
                }
                $locale = $this->requiredString($row, 'locale');
                if (!hash_equals($query->locale(), $locale)) {
                    throw new BlogPersistenceException();
                }
                $kind = $this->requiredString($row, 'taxonomy_kind');
                if ($kind === 'category') {
                    if (count($categories[$cardSlug]) >=
                        BlogPublicCardTaxonomyBatch::MAX_CATEGORIES_PER_CARD) {
                        throw new BlogPersistenceException();
                    }
                    $category = new BlogPublicCardCategory(
                        $locale,
                        $this->requiredString($row, 'slug'),
                        $this->requiredString($row, 'name')
                    );
                    foreach ($categories[$cardSlug] as $knownCategory) {
                        if (hash_equals(
                            $knownCategory->slug(),
                            $category->slug()
                        )) {
                            throw new BlogPersistenceException();
                        }
                    }
                    $categories[$cardSlug][] = $category;
                    continue;
                }
                if ($kind !== 'tag' || !$this->tagsReady) {
                    throw new BlogPersistenceException();
                }
                if (count($tags[$cardSlug]) >=
                    BlogPublicCardTaxonomyBatch::MAX_TAGS_PER_CARD) {
                    throw new BlogPersistenceException();
                }
                $tag = new BlogPublicCardTag(
                    $locale,
                    $this->requiredString($row, 'slug'),
                    $this->requiredString($row, 'name')
                );
                foreach ($tags[$cardSlug] as $knownTag) {
                    if (hash_equals($knownTag->slug(), $tag->slug())) {
                        throw new BlogPersistenceException();
                    }
                }
                $tags[$cardSlug][] = $tag;
            }

            foreach ($tags as &$cardTags) {
                usort(
                    $cardTags,
                    static fn (
                        BlogPublicCardTag $left,
                        BlogPublicCardTag $right
                    ): int => strcmp($left->slug(), $right->slug())
                );
            }
            unset($cardTags);

            return new BlogPublicCardTaxonomyBatch($categories, $tags);
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function relatedPosts(BlogPublicRelatedQuery $query): array
    {
        try {
            $sql = 'SELECT candidate.locale, candidate.slug, candidate.h1, '
                . 'candidate.excerpt, candidate.published_at, '
                . 'candidate.updated_at, '
                . 'COUNT(DISTINCT source_pc.category_id) AS shared_count '
                . 'FROM ' . $this->localizations . ' source JOIN '
                . $this->posts . ' source_post ON source_post.id = '
                . 'source.post_id JOIN ' . $this->relations
                . ' source_pc ON source_pc.post_id = source_post.id JOIN '
                . $this->categoryLocalizations
                . ' source_category ON source_category.category_id = '
                . 'source_pc.category_id AND source_category.locale = '
                . ':category_locale JOIN ' . $this->relations
                . ' candidate_pc ON candidate_pc.category_id = '
                . 'source_pc.category_id JOIN ' . $this->posts
                . ' candidate_post ON candidate_post.id = '
                . 'candidate_pc.post_id AND candidate_post.id <> '
                . 'source_post.id JOIN ' . $this->localizations
                . ' candidate ON candidate.post_id = candidate_post.id '
                . 'WHERE source.locale = :source_locale '
                . 'AND source.slug = :source_slug '
                . 'AND source.status = :source_status '
                . 'AND source.published_at IS NOT NULL '
                . 'AND candidate.locale = :candidate_locale '
                . 'AND candidate.status = :candidate_status '
                . 'AND candidate.slug IS NOT NULL '
                . 'AND candidate.excerpt IS NOT NULL '
                . 'AND candidate.published_at IS NOT NULL '
                . $this->reservedCategoryPredicate(
                    'candidate_post',
                    'related_reserved'
                )[0]
                . 'GROUP BY candidate.locale, candidate.slug, '
                . 'candidate.h1, candidate.excerpt, '
                . 'candidate.published_at, candidate.updated_at, '
                . 'candidate.public_id '
                . 'ORDER BY shared_count DESC, '
                . 'candidate.published_at DESC, candidate.public_id ASC '
                . 'LIMIT :related_limit';
            $statement = $this->prepare($sql);
            $this->execute($statement, [
                'category_locale' => [$query->locale(), PDO::PARAM_STR],
                'source_locale' => [$query->locale(), PDO::PARAM_STR],
                'source_slug' => [$query->sourceSlug(), PDO::PARAM_STR],
                'source_status' => [
                    BlogPostVariant::PUBLISHED,
                    PDO::PARAM_STR,
                ],
                'candidate_locale' => [$query->locale(), PDO::PARAM_STR],
                'candidate_status' => [
                    BlogPostVariant::PUBLISHED,
                    PDO::PARAM_STR,
                ],
                'related_limit' => [$query->limit(), PDO::PARAM_INT],
                'related_reserved_slug' => [
                    BlogReservedCategoryPolicy::DUMMY_SLUG,
                    PDO::PARAM_STR,
                ],
            ]);

            return $this->cardsFromStatement($statement);
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function archivePosts(BlogPublicArchiveQuery $query): array
    {
        try {
            $endExclusive = $query->endExclusive();
            $sql = 'SELECT l.locale, l.slug, l.h1, l.excerpt, '
                . 'l.published_at, l.updated_at FROM ' . $this->posts
                . ' p JOIN ' . $this->localizations
                . ' l ON l.post_id = p.id WHERE l.locale = :locale '
                . 'AND l.status = :status AND l.slug IS NOT NULL '
                . 'AND l.excerpt IS NOT NULL '
                . $this->reservedCategoryPredicate(
                    'p',
                    'archive_reserved'
                )[0]
                . 'AND l.published_at >= :archive_start '
                . 'AND l.published_at '
                . ($endExclusive === null ? '<= ' : '< ')
                . ':archive_end ORDER BY l.published_at DESC, '
                . 'l.public_id ASC LIMIT :archive_limit '
                . 'OFFSET :archive_offset';
            $statement = $this->prepare($sql);
            $this->execute($statement, [
                'locale' => [$query->locale(), PDO::PARAM_STR],
                'status' => [BlogPostVariant::PUBLISHED, PDO::PARAM_STR],
                'archive_start' => [
                    $query->startInclusive()->format(self::UTC_FORMAT),
                    PDO::PARAM_STR,
                ],
                'archive_end' => [
                    $endExclusive?->format(self::UTC_FORMAT)
                        ?? BlogPublicArchiveQuery::MAX_STORAGE_TIMESTAMP,
                    PDO::PARAM_STR,
                ],
                'archive_limit' => [$query->limit(), PDO::PARAM_INT],
                'archive_offset' => [$query->offset(), PDO::PARAM_INT],
                'archive_reserved_slug' => [
                    BlogReservedCategoryPolicy::DUMMY_SLUG,
                    PDO::PARAM_STR,
                ],
            ]);

            return $this->cardsFromStatement($statement);
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function archivePeriods(
        BlogPublicArchivePeriodsQuery $query
    ): array {
        try {
            $year = $this->archiveDatePartExpression('year');
            $month = $this->archiveDatePartExpression('month');
            $sql = 'SELECT l.locale, ' . $year . ' AS archive_year, '
                . $month . ' AS archive_month, COUNT(*) AS post_count '
                . 'FROM ' . $this->localizations . ' l JOIN '
                . $this->posts . ' p ON p.id = l.post_id '
                . 'WHERE l.locale = :locale AND l.status = :status '
                . 'AND l.slug IS NOT NULL AND l.excerpt IS NOT NULL '
                . 'AND l.published_at IS NOT NULL '
                . $this->reservedCategoryPredicate(
                    'p',
                    'period_reserved'
                )[0]
                . 'GROUP BY l.locale, ' . $year . ', ' . $month . ' '
                . 'ORDER BY archive_year DESC, archive_month DESC '
                . 'LIMIT :period_limit OFFSET :period_offset';
            $statement = $this->prepare($sql);
            $this->execute($statement, [
                'locale' => [$query->locale(), PDO::PARAM_STR],
                'status' => [BlogPostVariant::PUBLISHED, PDO::PARAM_STR],
                'period_limit' => [$query->limit(), PDO::PARAM_INT],
                'period_offset' => [$query->offset(), PDO::PARAM_INT],
                'period_reserved_slug' => [
                    BlogReservedCategoryPolicy::DUMMY_SLUG,
                    PDO::PARAM_STR,
                ],
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                throw new BlogPersistenceException();
            }

            return array_map(
                fn (mixed $row): BlogPublicArchivePeriod =>
                    $this->archivePeriodFromRow($row),
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

    /**
     * @return array{string, array<string, array{mixed, int}>}
     */
    private function excludedCategoryPredicate(
        BlogPublicCatalogQuery $query
    ): array {
        $placeholders = [];
        $parameters = [
            'category_excluded_locale' => [
                $query->locale(),
                PDO::PARAM_STR,
            ],
        ];
        foreach ($query->excludedCategorySlugs() as $position => $slug) {
            $key = 'category_excluded_' . $position;
            $placeholders[] = ':' . $key;
            $parameters[$key] = [$slug, PDO::PARAM_STR];
        }

        return [
            ' AND NOT EXISTS (SELECT 1 FROM ' . $this->relations
                . ' excluded_pc JOIN ' . $this->categoryLocalizations
                . ' excluded_cl ON excluded_cl.category_id = '
                . 'excluded_pc.category_id WHERE excluded_pc.post_id = p.id '
                . 'AND excluded_cl.locale = :category_excluded_locale '
                . 'AND excluded_cl.slug IN ('
                . implode(', ', $placeholders) . '))',
            $parameters,
        ];
    }

    /** @return array{string, array<string, array{mixed, int}>} */
    private function reservedCategoryPredicate(
        string $postAlias,
        string $parameterPrefix
    ): array {
        if (preg_match('/\A[a-z_]+\z/', $postAlias) !== 1
            || preg_match('/\A[a-z_]+\z/', $parameterPrefix) !== 1) {
            throw new BlogPersistenceException();
        }
        $key = $parameterPrefix . '_slug';

        return [
            ' AND NOT EXISTS (SELECT 1 FROM ' . $this->relations
                . ' reserved_pc JOIN ' . $this->categoryLocalizations
                . ' reserved_cl ON reserved_cl.category_id = '
                . 'reserved_pc.category_id WHERE reserved_pc.post_id = '
                . $postAlias . '.id AND reserved_cl.slug = :' . $key . ') ',
            [
                $key => [
                    BlogReservedCategoryPolicy::DUMMY_SLUG,
                    PDO::PARAM_STR,
                ],
            ],
        ];
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

    private function archiveDatePartExpression(string $part): string
    {
        if (!in_array($part, ['year', 'month'], true)) {
            throw new BlogPersistenceException();
        }
        if ($this->driver === 'mysql') {
            return strtoupper($part) . '(l.published_at)';
        }

        return "CAST(strftime('"
            . ($part === 'year' ? '%Y' : '%m')
            . "', l.published_at) AS INTEGER)";
    }

    private function catalogOrder(BlogPublicCatalogQuery $query): string
    {
        return match ($query->order()) {
            BlogPublicCatalogQuery::ORDER_NEWEST =>
                'l.published_at DESC, l.public_id ASC',
            BlogPublicCatalogQuery::ORDER_OLDEST =>
                'l.published_at ASC, l.public_id ASC',
            BlogPublicCatalogQuery::ORDER_UPDATED =>
                'l.updated_at DESC, l.public_id ASC',
        };
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

    /** @return list<PublishedPostCard> */
    private function cardsFromStatement(PDOStatement $statement): array
    {
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            throw new BlogPersistenceException();
        }

        return array_map(
            fn (mixed $row): PublishedPostCard => $this->cardFromRow($row),
            array_values($rows)
        );
    }

    private function archivePeriodFromRow(mixed $row): BlogPublicArchivePeriod
    {
        if (!is_array($row)) {
            throw new BlogPersistenceException();
        }
        try {
            return new BlogPublicArchivePeriod(
                $this->requiredString($row, 'locale'),
                $this->requiredPositiveInteger($row, 'archive_year'),
                $this->requiredPositiveInteger($row, 'archive_month'),
                $this->requiredPositiveInteger($row, 'post_count')
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

    /** @param array<string, mixed> $row */
    private function requiredPositiveInteger(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (
            !is_int($value)
            && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))
        ) {
            throw new BlogPersistenceException();
        }
        $integer = (int) $value;
        if ($integer < 1 || (string) $integer !== (string) $value) {
            throw new BlogPersistenceException();
        }

        return $integer;
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
