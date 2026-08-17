<?php

declare(strict_types=1);

namespace App\Core\Blog\Configuration;

/** Validated, project-owned routing policy for the public Blog index. */
final class BlogPublicIndexConfig
{
    public const DEFAULT_PAGE_SIZE = 12;
    public const MAX_PAGE_SIZE = 49;
    public const PAGE_TOKEN = '{page}';

    /**
     * @param array<string, string> $paginationPaths
     */
    public function __construct(
        private readonly array $paginationPaths,
        private readonly int $pageSize = self::DEFAULT_PAGE_SIZE
    ) {
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new BlogConfigException(
                'config.public_index_page_size_invalid',
                'public_index.page_size'
            );
        }
        if ($paginationPaths === [] || array_is_list($paginationPaths)) {
            throw new BlogConfigException(
                'config.expected_object',
                'public_index.pagination_paths'
            );
        }

        $seen = [];
        foreach ($paginationPaths as $locale => $path) {
            if (
                !is_string($locale)
                || preg_match(
                    '/\A[a-z]{2}(?:-[a-z0-9]{2,8})?\z/D',
                    $locale
                ) !== 1
                || !is_string($path)
                || substr_count($path, self::PAGE_TOKEN) !== 1
                || preg_match(
                    '#(?:\A|/)\{page\}(?:/|\z)#D',
                    $path
                ) !== 1
                || !self::isValidGeneratedPath(str_replace(
                    self::PAGE_TOKEN,
                    '2',
                    $path
                ))
            ) {
                throw new BlogConfigException(
                    'config.public_index_pagination_path_invalid',
                    'public_index.pagination_paths.'
                        . (is_string($locale) ? $locale : '')
                );
            }
            if (isset($seen[$path])) {
                throw new BlogConfigException(
                    'config.duplicate_route',
                    'public_index.pagination_paths.' . $locale
                );
            }
            $seen[$path] = true;
        }
    }

    /** @param array<string, string> $publicPaths */
    public static function defaults(array $publicPaths): self
    {
        $paginationPaths = [];
        foreach ($publicPaths as $locale => $basePath) {
            $paginationPaths[$locale] = $basePath . '/page/{page}';
        }

        return new self($paginationPaths);
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    /** @return array<string, string> */
    public function paginationPaths(): array
    {
        return $this->paginationPaths;
    }

    public function paginationPath(string $locale): ?string
    {
        return $this->paginationPaths[strtolower($locale)] ?? null;
    }

    public function pathForPage(
        string $locale,
        int $page,
        string $basePath
    ): string {
        if ($page <= 1) {
            return $basePath;
        }
        $template = $this->paginationPath($locale);
        if ($template === null) {
            throw new BlogConfigException(
                'config.public_index_pagination_path_missing',
                'public_index.pagination_paths.' . strtolower($locale)
            );
        }

        return str_replace(self::PAGE_TOKEN, (string) $page, $template);
    }

    /** @return array{page_size:int,pagination_paths:array<string,string>} */
    public function toSafeArray(): array
    {
        return [
            'page_size' => $this->pageSize,
            'pagination_paths' => $this->paginationPaths,
        ];
    }

    private static function isValidGeneratedPath(string $path): bool
    {
        return strlen($path) <= 512
            && preg_match(
                '#\A/[a-z0-9](?:[a-z0-9.-]{0,126}[a-z0-9])?'
                    . '(?:/[a-z0-9](?:[a-z0-9.-]{0,126}[a-z0-9])?)*\z#D',
                $path
            ) === 1;
    }
}
