<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\BlogException;
use App\Core\Blog\PublicIndex\BlogPublicIndexFeedInterface;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Support\Paths;
use RuntimeException;

/**
 * Request-scoped public Blog facade for project views and resource snipers.
 *
 * It is the infrastructure boundary: callers receive presentation arrays and
 * visual controllers remain unaware of PDO, table prefixes and internal IDs.
 */
final class BlogPublicResourceFeed implements
    BlogPublicIndexFeedInterface,
    BlogPublicCollectionFeedInterface
{
    /** @var array<string, self> */
    private static array $current = [];

    public function __construct(private readonly BlogPublicFeed $feed)
    {
    }

    public static function current(): self
    {
        $projectRoot = Paths::projectRoot();
        if (isset(self::$current[$projectRoot])) {
            return self::$current[$projectRoot];
        }

        $environment = (new ProjectEnvironmentLoader())->load($projectRoot);
        if (!$environment->isUsable()) {
            throw new RuntimeException('Project environment is unavailable.');
        }

        return self::$current[$projectRoot] = new self(
            (new BlogPublicFeedFactory())->create(
                $projectRoot,
                $environment->values(),
                true
            )
        );
    }

    /**
     * @param array{
     *     locale:mixed,
     *     search?:mixed,
     *     categories?:mixed,
     *     category_mode?:mixed,
     *     categoryMode?:mixed,
     *     category_scope?:mixed,
     *     categoryScope?:mixed,
     *     exclude_dummy?:mixed,
     *     excludeDummy?:mixed,
     *     items?:mixed,
     *     limit?:mixed,
     *     offset?:mixed,
     *     exclude_slug?:mixed,
     *     order?:mixed
     * } $options
     * @return list<array<string, mixed>>
     */
    public function cards(array|BlogPublicResourceQuery $options): array
    {
        return $this->cardsForResource(
            $options instanceof BlogPublicResourceQuery
                ? $options
                : $this->queryFromOptions($options)
        );
    }

    /**
     * Loads one SSR/load-more page plus a lookahead row for `has_next`.
     * A supplied next URL must be root-relative and is never queried here.
     *
     * @param array<string, mixed>|BlogPublicResourceQuery $options
     */
    public function batch(
        array|BlogPublicResourceQuery $options,
        ?string $nextUrl = null
    ): BlogPublicResourceBatch {
        $query = $options instanceof BlogPublicResourceQuery
            ? $options
            : $this->queryFromOptions($options, true);
        if ($query->items() === 0) {
            return new BlogPublicResourceBatch([], false, null);
        }
        if ($query->items() >= $query->limit()) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        $loaded = $this->feed->cardsForQuery($query->catalogQuery());
        $hasNext = count($loaded) > $query->items();
        $items = array_slice($loaded, 0, $query->items());

        return new BlogPublicResourceBatch(
            $items,
            $hasNext,
            $hasNext ? $query->offset() + count($items) : null,
            $hasNext ? $nextUrl : null
        );
    }

    /** @return list<array<string, mixed>> */
    public function cardsForResource(BlogPublicResourceQuery $query): array
    {
        if ($query->items() === 0) {
            return [];
        }

        return array_slice(
            $this->feed->cardsForQuery($query->catalogQuery()),
            0,
            $query->items()
        );
    }

    /** @return list<array{locale:string,slug:string,name:string,count:int}> */
    public function filters(string $locale): array
    {
        return $this->feed->filtersForLocale($locale);
    }

    /**
     * @param array{locale:mixed,source_slug:mixed,limit?:mixed} $options
     * @return list<array<string, mixed>>
     */
    public function related(array $options): array
    {
        $this->assertKnownKeys(
            $options,
            ['locale', 'source_slug', 'limit']
        );
        $limit = $options['limit'] ?? BlogPublicRelatedQuery::DEFAULT_LIMIT;
        if (!is_int($limit)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $this->feed->cardsForRelated(
            new BlogPublicRelatedQuery(
                $this->requiredString($options, 'locale'),
                $this->requiredString($options, 'source_slug'),
                $limit
            )
        );
    }

    /** @return list<array<string, mixed>> */
    public function cardsForQuery(BlogPublicCatalogQuery $query): array
    {
        return $this->feed->cardsForQuery($query);
    }

    /** @return list<array<string, mixed>> */
    public function cardsForArchive(BlogPublicArchiveQuery $query): array
    {
        return $this->feed->cardsForArchive($query);
    }

    /** @return list<array{locale:string,year:int,month:int,count:int}> */
    public function archivePeriods(
        BlogPublicArchivePeriodsQuery $query
    ): array {
        return $this->feed->archivePeriods($query);
    }

    /** @param array<string, mixed> $options */
    private function queryFromOptions(
        array $options,
        bool $forBatch = false
    ): BlogPublicResourceQuery {
        $this->assertKnownKeys($options, [
            'locale',
            'search',
            'categories',
            'category_mode',
            'categoryMode',
            'category_scope',
            'categoryScope',
            'exclude_dummy',
            'excludeDummy',
            'items',
            'limit',
            'offset',
            'exclude_slug',
            'order',
        ]);

        $categories = $options['categories'] ?? [];
        if (!is_array($categories)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        $categoryMode = $this->aliasedOption(
            $options,
            'category_mode',
            'categoryMode',
            BlogPublicResourceQuery::MODE_ANY
        );
        $categoryScope = $this->aliasedOption(
            $options,
            'category_scope',
            'categoryScope',
            $categories === []
                ? BlogPublicResourceQuery::SCOPE_ALL
                : BlogPublicResourceQuery::SCOPE_SELECTED
        );
        $excludeDummy = $this->aliasedOption(
            $options,
            'exclude_dummy',
            'excludeDummy',
            true
        );
        if ($forBatch) {
            $items = $options['items']
                ?? BlogPublicResourceQuery::DEFAULT_ITEMS;
            $limit = $options['limit'] ?? (
                is_int($items) ? $items + 1 : null
            );
        } else {
            $limit = $options['limit']
                ?? BlogPublicResourceQuery::DEFAULT_ITEMS;
            $items = $options['items'] ?? $limit;
        }
        $offset = $options['offset'] ?? 0;
        $order = $options['order'] ?? BlogPublicResourceQuery::ORDER_NEWEST;
        if (
            !is_string($categoryMode)
            || !is_string($categoryScope)
            || !is_bool($excludeDummy)
            || !$excludeDummy
            || !is_int($items)
            || !is_int($limit)
            || !is_int($offset)
            || !is_string($order)
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return new BlogPublicResourceQuery(
            $this->requiredString($options, 'locale'),
            $categoryScope,
            array_values($categories),
            $categoryMode,
            $excludeDummy,
            $items,
            $limit,
            $this->nullableString($options, 'search'),
            $offset,
            $this->nullableString($options, 'exclude_slug'),
            $order
        );
    }

    /** @param list<string> $allowed */
    private function assertKnownKeys(array $options, array $allowed): void
    {
        foreach (array_keys($options) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new BlogException(BlogException::INVALID_INPUT);
            }
        }
    }

    private function requiredString(array $options, string $key): string
    {
        $value = $options[$key] ?? null;
        if (!is_string($value)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    private function nullableString(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $value;
    }

    private function aliasedOption(
        array $options,
        string $legacyKey,
        string $canonicalKey,
        mixed $default
    ): mixed {
        $hasLegacy = array_key_exists($legacyKey, $options);
        $hasCanonical = array_key_exists($canonicalKey, $options);
        if ($hasLegacy && $hasCanonical) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $hasCanonical
            ? $options[$canonicalKey]
            : ($hasLegacy ? $options[$legacyKey] : $default);
    }
}
