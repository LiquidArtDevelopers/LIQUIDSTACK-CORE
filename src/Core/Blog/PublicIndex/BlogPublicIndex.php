<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicIndex;

use App\Core\Blog\BlogException;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpRuntimeFactory;
use App\Core\Blog\Http\BlogPublicHttpRuntimeFactoryInterface;
use App\Core\Blog\PublicFeed\BlogPublicArchivePeriodsQuery;
use App\Core\Blog\PublicFeed\BlogPublicArchiveQuery;
use App\Core\Blog\PublicFeed\BlogPublicCatalogQuery;
use App\Core\Blog\PublicFeed\BlogPublicResourceFeed;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Http\Response;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Support\Paths;
use Throwable;

/** Generic request-to-view-model composition for a project-owned Blog index. */
final class BlogPublicIndex
{
    public const STATE_READY = 'ready';
    public const STATE_EMPTY = 'empty';
    public const STATE_NO_RESULTS = 'no_results';
    public const STATE_NOT_FOUND = 'not_found';
    public const STATE_UNAVAILABLE = 'unavailable';
    private const RETRY_AFTER_SECONDS = 300;
    private const MAX_CARD_URL_BYTES = 2_048;
    private const MAX_CARD_QUERY_BYTES = 1_024;

    /** @var array<string, self> */
    private static array $current = [];

    public function __construct(
        private readonly BlogConfig $config,
        private readonly BlogPublicOrigin $origin,
        private readonly ?BlogPublicIndexFeedInterface $feed
    ) {
    }

    public static function current(): self
    {
        $projectRoot = Paths::projectRoot();
        if (isset(self::$current[$projectRoot])) {
            return self::$current[$projectRoot];
        }
        $environment = (new ProjectEnvironmentLoader())->load($projectRoot);
        $context = new ModuleRuntimeContext(
            $projectRoot,
            $environment->values(),
            $environment->isUsable()
        );

        return self::$current[$projectRoot] = self::fromContext($context);
    }

    /**
     * Builds the read facade before opening PDO so a runtime/schema failure
     * still resolves through the normal unavailable-page HTTP policy.
     */
    public static function fromContext(
        ModuleRuntimeContext $context,
        ?BlogPublicHttpRuntimeFactoryInterface $runtimeFactory = null,
        ?BlogConfigLoader $configLoader = null
    ): self {
        $config = ($configLoader ?? new BlogConfigLoader())->load(
            $context->projectRoot(),
            $context->languages()
        );
        $origin = BlogPublicOrigin::fromEnvironment(
            $context->environment()
        );

        try {
            $runtime = ($runtimeFactory ?? new BlogPublicHttpRuntimeFactory())
                ->create($context);

            return new self(
                $runtime->config(),
                $runtime->origin(),
                new BlogPublicResourceFeed($runtime->publicFeed())
            );
        } catch (Throwable) {
            return new self($config, $origin, null);
        }
    }

    public function resolve(
        BlogPublicIndexInput $input,
        BlogPublicIndexTextCatalog $copy,
        ?BlogPublicIndexPreviewSourceInterface $preview = null
    ): BlogPublicIndexResolution {
        $locale = $input->locale();
        $basePath = $this->config->publicPath($locale);
        $paginationTemplate = $this->config->publicIndex()
            ->paginationPath($locale);
        if ($basePath === null || $paginationTemplate === null) {
            return BlogPublicIndexResolution::fromPage($this->pageModel(
                input: $input,
                copy: $copy,
                state: self::STATE_NOT_FOUND,
                basePath: $basePath ?? '/blog',
                page: 1
            ));
        }

        $previewPolicy = $this->previewPolicy($preview);
        $query = $input->query();
        $routeParameters = $input->routeParameters();
        $previewParameterPresent = $previewPolicy !== null
            && array_key_exists($previewPolicy['parameter'], $query);
        $parsed = $this->parseRequest(
            $input,
            $basePath,
            $paginationTemplate,
            $previewPolicy
        );
        if ($parsed['redirect'] instanceof Response) {
            return BlogPublicIndexResolution::fromRedirect(
                $parsed['redirect']
            );
        }

        $page = $parsed['page'];
        $search = $parsed['search'];
        $categories = $parsed['categories'];
        $categoryMode = $parsed['category_mode'];
        $order = $parsed['order'];
        $archiveYear = $parsed['archive_year'];
        $archiveMonth = $parsed['archive_month'];
        $previewRequested = $parsed['preview_requested'];
        $state = $parsed['valid']
            ? self::STATE_READY
            : self::STATE_NOT_FOUND;
        $cards = [];
        $filters = [];
        $periods = [];
        $hasNext = false;
        $catalogQuery = null;
        $archiveQuery = null;
        $offset = ($page - 1) * $this->config->publicIndex()->pageSize();

        if ($state === self::STATE_READY) {
            try {
                if ($archiveYear !== null) {
                    $archiveQuery = new BlogPublicArchiveQuery(
                        $locale,
                        $archiveYear,
                        $archiveMonth,
                        $this->config->publicIndex()->pageSize() + 1,
                        $offset
                    );
                } else {
                    $catalogQuery = new BlogPublicCatalogQuery(
                        locale: $locale,
                        search: $search,
                        categorySlugs: $categories,
                        categoryMode: $categoryMode,
                        limit: $this->config->publicIndex()->pageSize() + 1,
                        offset: $offset,
                        order: $order
                    );
                    $search = $catalogQuery->search();
                    $categories = $catalogQuery->categorySlugs();
                }
            } catch (BlogException $exception) {
                $state = $exception->issueCode() === BlogException::INVALID_INPUT
                    ? self::STATE_NOT_FOUND
                    : self::STATE_UNAVAILABLE;
            } catch (Throwable) {
                $state = self::STATE_UNAVAILABLE;
            }
        }

        if ($state === self::STATE_READY && $this->feed === null) {
            $state = self::STATE_UNAVAILABLE;
        }
        if ($state === self::STATE_READY && $this->feed !== null) {
            try {
                $periods = $this->feed->archivePeriods(
                    new BlogPublicArchivePeriodsQuery($locale)
                );
                $filters = $this->feed->filters($locale);
                if (!$this->categoriesAreKnown($categories, $filters)) {
                    $state = self::STATE_NOT_FOUND;
                    $pageCards = [];
                } elseif ($archiveQuery instanceof BlogPublicArchiveQuery) {
                    $pageCards = $this->feed->cardsForArchive($archiveQuery);
                } elseif (
                    $previewRequested
                    && $catalogQuery instanceof BlogPublicCatalogQuery
                    && $preview !== null
                ) {
                    $pageCards = $this->previewPage(
                        $locale,
                        $offset,
                        $preview
                    );
                    if ($pageCards === null) {
                        $state = self::STATE_NOT_FOUND;
                        $pageCards = [];
                    }
                } elseif ($catalogQuery instanceof BlogPublicCatalogQuery) {
                    $pageCards = $this->feed->cardsForQuery($catalogQuery);
                } else {
                    $state = self::STATE_UNAVAILABLE;
                    $pageCards = [];
                }

                $pageSize = $this->config->publicIndex()->pageSize();
                $hasNext = count($pageCards) > $pageSize;
                $cards = array_slice($pageCards, 0, $pageSize);
                if ($state === self::STATE_READY && $cards === []) {
                    $state = $page > 1
                        ? self::STATE_NOT_FOUND
                        : ($archiveQuery instanceof BlogPublicArchiveQuery
                            || ($catalogQuery?->hasFilters() ?? false)
                                ? self::STATE_NO_RESULTS
                                : self::STATE_EMPTY);
                }
            } catch (BlogException $exception) {
                $state = $exception->issueCode() === BlogException::INVALID_INPUT
                    ? self::STATE_NOT_FOUND
                    : self::STATE_UNAVAILABLE;
                $cards = [];
                $filters = [];
                $periods = [];
                $hasNext = false;
            } catch (Throwable) {
                $state = self::STATE_UNAVAILABLE;
                $cards = [];
                $filters = [];
                $periods = [];
                $hasNext = false;
            }
        }

        $pageUrl = fn (int $target): string => $this->pageUrl(
            locale: $locale,
            basePath: $basePath,
            page: $target,
            search: $search,
            categories: $categories,
            categoryMode: $categoryMode,
            order: $order,
            archiveYear: $archiveYear,
            archiveMonth: $archiveMonth,
            previewPolicy: $previewPolicy,
            previewRequested: $previewRequested
        );
        $paginationPages = [];
        if ($page > 1) {
            $paginationPages[] = [
                'page' => $page - 1,
                'url' => $pageUrl($page - 1),
            ];
        }
        $paginationPages[] = ['page' => $page, 'current' => true];
        if ($hasNext) {
            $paginationPages[] = [
                'page' => $page + 1,
                'url' => $pageUrl($page + 1),
            ];
        }

        return BlogPublicIndexResolution::fromPage($this->pageModel(
            input: $input,
            copy: $copy,
            state: $state,
            basePath: $basePath,
            page: $page,
            search: $search,
            categories: $categories,
            categoryMode: $categoryMode,
            order: $order,
            filters: $filters,
            cards: $cards,
            paginationPages: $paginationPages,
            previousUrl: $page > 1 ? $pageUrl($page - 1) : null,
            nextUrl: $hasNext ? $pageUrl($page + 1) : null,
            archivePeriods: $this->archivePeriodData(
                $periods,
                $copy,
                $locale,
                $basePath,
                $archiveYear,
                $archiveMonth
            ),
            previewParameterPresent: $previewParameterPresent
        ));
    }

    /**
     * @param array{parameter:string,value:string}|null $previewPolicy
     * @return array{
     *   valid:bool,redirect:?Response,page:int,search:?string,
     *   categories:list<string>,category_mode:string,order:string,
     *   archive_year:?int,archive_month:?int,preview_requested:bool
     * }
     */
    private function parseRequest(
        BlogPublicIndexInput $input,
        string $basePath,
        string $paginationTemplate,
        ?array $previewPolicy
    ): array {
        $request = $input->request();
        $query = $input->query();
        $routes = $input->routeParameters();
        $valid = $request->isValid()
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && count($routes) <= 1;
        foreach (array_keys($routes) as $key) {
            if ($key !== 'page') {
                $valid = false;
            }
        }
        $routePagePresent = array_key_exists('page', $routes);
        $queryPagePresent = array_key_exists('page', $query);
        if ($routePagePresent && $queryPagePresent) {
            $valid = false;
        }
        $rawPage = $routePagePresent
            ? $routes['page']
            : ($query['page'] ?? '1');
        $page = 1;
        if (
            !is_string($rawPage)
            || preg_match('/\A[1-9][0-9]{0,5}\z/D', $rawPage) !== 1
        ) {
            $valid = false;
        } else {
            $page = (int) $rawPage;
            if (
                (($page - 1) * $this->config->publicIndex()->pageSize())
                    > BlogPublicCatalogQuery::MAX_OFFSET
            ) {
                $valid = false;
            }
        }
        $expectedPath = $routePagePresent
            ? str_replace('{page}', (string) $page, $paginationTemplate)
            : $basePath;
        if ($request->path() !== $expectedPath) {
            $valid = false;
        }

        $search = null;
        if (array_key_exists('q', $query)) {
            if (!is_string($query['q'])) {
                $valid = false;
            } else {
                $search = $query['q'];
            }
        }
        $categories = [];
        if (array_key_exists('category', $query)) {
            $rawCategories = is_string($query['category'])
                ? [$query['category']]
                : $query['category'];
            if (!is_array($rawCategories) || count($rawCategories) > 10) {
                $valid = false;
            } else {
                foreach ($rawCategories as $rawCategory) {
                    if (
                        !is_string($rawCategory)
                        || strlen($rawCategory) > 190
                        || preg_match(
                            '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',
                            $rawCategory
                        ) !== 1
                    ) {
                        $valid = false;
                        break;
                    }
                    $categories[$rawCategory] = $rawCategory;
                }
                $categories = array_values($categories);
            }
        }
        $categoryMode = BlogPublicCatalogQuery::MODE_ANY;
        if (array_key_exists('category_mode', $query)) {
            if (
                !is_string($query['category_mode'])
                || !in_array($query['category_mode'], [
                    BlogPublicCatalogQuery::MODE_ANY,
                    BlogPublicCatalogQuery::MODE_ALL,
                ], true)
            ) {
                $valid = false;
            } else {
                $categoryMode = $query['category_mode'];
            }
        }
        $order = BlogPublicCatalogQuery::ORDER_NEWEST;
        if (array_key_exists('order', $query)) {
            if (
                !is_string($query['order'])
                || !in_array($query['order'], [
                    BlogPublicCatalogQuery::ORDER_NEWEST,
                    BlogPublicCatalogQuery::ORDER_OLDEST,
                    BlogPublicCatalogQuery::ORDER_UPDATED,
                ], true)
            ) {
                $valid = false;
            } else {
                $order = $query['order'];
            }
        }

        $hasYear = array_key_exists('year', $query);
        $hasMonth = array_key_exists('month', $query);
        $archiveYear = null;
        $archiveMonth = null;
        if ($hasYear) {
            if (
                !is_string($query['year'])
                || preg_match('/\A[1-9][0-9]{3}\z/D', $query['year']) !== 1
            ) {
                $valid = false;
            } else {
                $archiveYear = (int) $query['year'];
            }
        }
        if ($hasMonth) {
            if (
                !$hasYear
                || !is_string($query['month'])
                || preg_match(
                    '/\A(?:[1-9]|1[0-2])\z/D',
                    $query['month']
                ) !== 1
            ) {
                $valid = false;
            } else {
                $archiveMonth = (int) $query['month'];
            }
        }
        if ($hasYear && (
            array_key_exists('q', $query)
            || array_key_exists('category', $query)
            || array_key_exists('category_mode', $query)
            || array_key_exists('order', $query)
        )) {
            $valid = false;
        }

        $previewRequested = false;
        if ($previewPolicy !== null
            && array_key_exists($previewPolicy['parameter'], $query)) {
            $value = $query[$previewPolicy['parameter']];
            if (!is_string($value)
                || !hash_equals($previewPolicy['value'], $value)) {
                $valid = false;
            } else {
                $previewRequested = true;
            }
            if (
                $hasYear
                || array_key_exists('q', $query)
                || array_key_exists('category', $query)
                || array_key_exists('category_mode', $query)
                || array_key_exists('order', $query)
            ) {
                $valid = false;
            }
        }

        if ($valid && (
            ($routePagePresent && $page === 1)
            || (!$routePagePresent && $queryPagePresent)
        )) {
            $redirectQuery = $query;
            unset($redirectQuery['page']);
            $target = $this->config->publicIndex()->pathForPage(
                $input->locale(),
                $page,
                $basePath
            );
            if ($redirectQuery !== []) {
                $target .= '?' . http_build_query(
                    $redirectQuery,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
            }

            return [
                'valid' => true,
                'redirect' => new Response(301, '', [
                    'Location' => $target,
                    'Cache-Control' =>
                        'public, max-age=300, must-revalidate',
                    'X-Robots-Tag' => 'noindex, follow',
                    'Vary' => 'X-LiquidStack-Partial',
                ]),
                'page' => $page,
                'search' => $search,
                'categories' => $categories,
                'category_mode' => $categoryMode,
                'order' => $order,
                'archive_year' => $archiveYear,
                'archive_month' => $archiveMonth,
                'preview_requested' => $previewRequested,
            ];
        }

        return [
            'valid' => $valid,
            'redirect' => null,
            'page' => $page,
            'search' => $search,
            'categories' => $categories,
            'category_mode' => $categoryMode,
            'order' => $order,
            'archive_year' => $archiveYear,
            'archive_month' => $archiveMonth,
            'preview_requested' => $previewRequested,
        ];
    }

    /**
     * @return array{parameter:string,value:string}|null
     */
    private function previewPolicy(
        ?BlogPublicIndexPreviewSourceInterface $preview
    ): ?array {
        if ($preview === null) {
            return null;
        }
        $parameter = trim($preview->queryParameter());
        $value = $preview->queryValue();
        if (
            preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $parameter) !== 1
            || $value === ''
            || strlen($value) > 64
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
            || in_array($parameter, [
                'page', 'q', 'category', 'category_mode', 'order',
                'year', 'month',
            ], true)
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return ['parameter' => $parameter, 'value' => $value];
    }

    /**
     * @param list<string> $categories
     * @param list<array<string, mixed>> $filters
     */
    private function categoriesAreKnown(array $categories, array $filters): bool
    {
        $known = [];
        foreach ($filters as $filter) {
            $slug = is_array($filter) ? ($filter['slug'] ?? null) : null;
            if (is_string($slug)) {
                $known[$slug] = true;
            }
        }
        foreach ($categories as $category) {
            if (!isset($known[$category])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>>|null */
    private function previewPage(
        string $locale,
        int $offset,
        BlogPublicIndexPreviewSourceInterface $preview
    ): ?array {
        if ($this->feed === null) {
            return null;
        }
        $publicCards = $this->feed->cardsForQuery(
            new BlogPublicCatalogQuery(
                locale: $locale,
                limit: BlogPublicCatalogQuery::MAX_LIMIT,
                order: BlogPublicCatalogQuery::ORDER_NEWEST
            )
        );
        $previewCards = $preview->cards($locale);
        if (
            $previewCards === []
            || count($previewCards) > BlogPublicCatalogQuery::MAX_LIMIT
            || count($publicCards) >= BlogPublicCatalogQuery::MAX_LIMIT
        ) {
            return null;
        }
        $byUrl = [];
        foreach (array_merge($publicCards, $previewCards) as $card) {
            if (!is_array($card) || !$this->cardIsSafe($card)) {
                return null;
            }
            $byUrl[$card['url']] = $card;
        }
        $cards = array_values($byUrl);
        usort($cards, static function (array $left, array $right): int {
            $date = strcmp(
                (string) ($right['published_at'] ?? ''),
                (string) ($left['published_at'] ?? '')
            );
            if ($date !== 0) {
                return $date;
            }
            $slug = strcmp(
                (string) ($left['slug'] ?? ''),
                (string) ($right['slug'] ?? '')
            );

            return $slug !== 0 ? $slug : strcmp(
                (string) $left['url'],
                (string) $right['url']
            );
        });

        return array_slice(
            $cards,
            $offset,
            $this->config->publicIndex()->pageSize() + 1
        );
    }

    /** @param array<string, mixed> $card */
    private function cardIsSafe(array $card): bool
    {
        $url = $card['url'] ?? null;
        foreach (['h1', 'excerpt', 'published_at'] as $field) {
            if (!is_string($card[$field] ?? null)) {
                return false;
            }
        }

        return is_string($url) && $this->isSafeRootRelativeUrl($url);
    }

    private function isSafeRootRelativeUrl(string $url): bool
    {
        if (
            $url === ''
            || strlen($url) > self::MAX_CARD_URL_BYTES
            || trim($url) !== $url
            || !str_starts_with($url, '/')
            || str_starts_with($url, '//')
            || str_contains($url, '\\')
            || str_contains($url, '#')
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $url) === 1
            || preg_match('/%(?:2f|5c)/i', $url) === 1
        ) {
            return false;
        }

        $parts = parse_url($url);
        if (
            !is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }
        $path = $parts['path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/')) {
            return false;
        }
        $decodedPath = $this->stableUrlDecode($path);
        if (
            $decodedPath === null
            || !str_starts_with($decodedPath, '/')
            || str_contains($decodedPath, '//')
            || str_contains($decodedPath, '\\')
            || str_contains($decodedPath, '?')
            || str_contains($decodedPath, '#')
            || preg_match('/[\x00-\x20\x7F]/', $decodedPath) === 1
            || preg_match('#(?:\A|/)\.\.?(?:/|\z)#D', $decodedPath) === 1
            || preg_match('//u', $decodedPath) !== 1
        ) {
            return false;
        }

        if (!array_key_exists('query', $parts)) {
            return !str_ends_with($url, '?');
        }
        $query = $parts['query'];
        if (
            !is_string($query)
            || $query === ''
            || strlen($query) > self::MAX_CARD_QUERY_BYTES
            || preg_match(
                "/\\A[A-Za-z0-9._~!$&'()*+,;=:@\\/?%~-]+\\z/D",
                $query
            ) !== 1
        ) {
            return false;
        }
        $decodedQuery = $this->stableUrlDecode($query);

        return $decodedQuery !== null
            && preg_match('/[\x00-\x1F\x7F]/', $decodedQuery) !== 1
            && !str_contains($decodedQuery, '\\')
            && preg_match('//u', $decodedQuery) === 1;
    }

    private function stableUrlDecode(string $value): ?string
    {
        $decoded = $value;
        for ($pass = 0; $pass < 8; ++$pass) {
            if (
                preg_match('/%(?![0-9A-Fa-f]{2})/', $decoded) === 1
                || preg_match('/%(?:2f|5c)/i', $decoded) === 1
            ) {
                return null;
            }
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                return $decoded;
            }
            $decoded = $next;
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $periods
     * @return list<array{url:string,label:string,count:int,active:bool}>
     */
    private function archivePeriodData(
        array $periods,
        BlogPublicIndexTextCatalog $copy,
        string $locale,
        string $basePath,
        ?int $activeYear,
        ?int $activeMonth
    ): array {
        $data = [];
        foreach ($periods as $period) {
            if (!is_array($period)) {
                continue;
            }
            $year = filter_var($period['year'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1000, 'max_range' => 9999],
            ]);
            $month = filter_var(
                $period['month'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 12]]
            );
            $count = filter_var(
                $period['count'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($year === false || $month === false || $count === false) {
                continue;
            }
            $data[] = [
                'url' => $basePath . '?' . http_build_query([
                    'year' => $year,
                    'month' => $month,
                ], '', '&', PHP_QUERY_RFC3986),
                'label' => $copy->archivePeriodLabel(
                    $locale,
                    $year,
                    $month
                ),
                'count' => $count,
                'active' => $activeYear === $year
                    && $activeMonth === $month,
            ];
        }

        return $data;
    }

    /**
     * @param list<string> $categories
     * @param array{parameter:string,value:string}|null $previewPolicy
     */
    private function pageUrl(
        string $locale,
        string $basePath,
        int $page,
        ?string $search,
        array $categories,
        string $categoryMode,
        string $order,
        ?int $archiveYear,
        ?int $archiveMonth,
        ?array $previewPolicy,
        bool $previewRequested
    ): string {
        $query = [];
        if ($archiveYear !== null) {
            $query['year'] = $archiveYear;
            if ($archiveMonth !== null) {
                $query['month'] = $archiveMonth;
            }
        } elseif ($search !== null) {
            $query['q'] = $search;
        }
        if ($categories !== []) {
            $query['category'] = $categories;
            $query['category_mode'] = $categoryMode;
        }
        if ($order !== BlogPublicCatalogQuery::ORDER_NEWEST) {
            $query['order'] = $order;
        }
        if ($previewRequested && $previewPolicy !== null) {
            $query[$previewPolicy['parameter']] = $previewPolicy['value'];
        }
        $path = $this->config->publicIndex()->pathForPage(
            $locale,
            $page,
            $basePath
        );

        return ($query === [] ? $path : $path . '?' . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        )) . '#blog-results';
    }

    /**
     * @param list<string> $categories
     * @param list<array<string, mixed>> $filters
     * @param list<array<string, mixed>> $cards
     * @param list<array{page:int,url?:string,current?:bool}> $paginationPages
     * @param list<array{url:string,label:string,count:int,active:bool}> $archivePeriods
     */
    private function pageModel(
        BlogPublicIndexInput $input,
        BlogPublicIndexTextCatalog $copy,
        string $state,
        string $basePath,
        int $page,
        ?string $search = null,
        array $categories = [],
        string $categoryMode = BlogPublicCatalogQuery::MODE_ANY,
        string $order = BlogPublicCatalogQuery::ORDER_NEWEST,
        array $filters = [],
        array $cards = [],
        array $paginationPages = [],
        ?string $previousUrl = null,
        ?string $nextUrl = null,
        array $archivePeriods = [],
        bool $previewParameterPresent = false
    ): BlogPublicIndexPage {
        $hasQuery = $input->query() !== [];
        $indexablePagination = $state === self::STATE_READY
            && !$previewParameterPresent
            && !$hasQuery;
        $canonicalPath = $indexablePagination
            ? $this->config->publicIndex()->pathForPage(
                $input->locale(),
                $page,
                $basePath
            )
            : $basePath;
        $canonical = $this->origin->absoluteUrl($canonicalPath);
        $title = $indexablePagination && $page > 1
            ? $copy->title() . ' — ' . $copy->pageLabel() . ' ' . $page
            : $copy->title();
        $alternates = [];
        if ($indexablePagination && $page === 1) {
            foreach ($this->config->publicPaths() as $locale => $path) {
                $alternates[$locale] = $this->origin->absoluteUrl($path);
            }
        }
        $xDefault = $indexablePagination && $page === 1
            ? $this->origin->absoluteUrl(
                (string) $this->config->publicPath(
                    $this->config->defaultLocale()
                )
            )
            : null;
        $navigation = [];
        foreach ($this->config->publicPaths() as $locale => $path) {
            $navigationPath = $locale === $input->locale()
                && $indexablePagination
                    ? $this->config->publicIndex()->pathForPage(
                        $locale,
                        $page,
                        $path
                    )
                    : $path;
            $navigation[$locale] = $this->origin->absoluteUrl($navigationPath);
        }
        $status = match ($state) {
            self::STATE_NOT_FOUND => 404,
            self::STATE_UNAVAILABLE => 503,
            default => 200,
        };
        $headers = [
            'Cache-Control' => $state === self::STATE_UNAVAILABLE
                || $previewParameterPresent
                    ? 'no-store, no-cache, must-revalidate'
                    : 'public, no-cache, must-revalidate',
            'Vary' => 'X-LiquidStack-Partial',
        ];
        if ($status === 503) {
            $headers['Retry-After'] = (string) self::RETRY_AFTER_SECONDS;
        }
        $robots = $state === self::STATE_UNAVAILABLE
            || $previewParameterPresent
                ? 'noindex, nofollow'
                : ($state === self::STATE_NOT_FOUND
                    || $state === self::STATE_EMPTY
                    || $hasQuery
                        ? 'noindex, follow'
                        : 'index, follow');
        $headers['X-Robots-Tag'] = $robots;

        return new BlogPublicIndexPage(
            $input->locale(),
            $input->isPartialRequest(),
            $input->isHeadRequest(),
            $state,
            $status,
            $headers,
            $basePath,
            $page,
            $search,
            $categories,
            $categoryMode,
            $order,
            $filters,
            $cards,
            $paginationPages,
            $previousUrl,
            $nextUrl,
            $archivePeriods,
            $title,
            $canonical,
            $robots,
            [
                'title' => $title,
                'description' => $copy->description(),
                'canonical' => $canonical,
                'alternates' => $alternates,
                'x_default' => $xDefault,
            ],
            $navigation,
            $copy
        );
    }
}
