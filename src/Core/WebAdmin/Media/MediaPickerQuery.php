<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

/** Closed, bounded query for the reusable private media picker. */
final class MediaPickerQuery
{
    public const DEFAULT_PAGE_SIZE = 24;
    public const ALLOWED_PAGE_SIZES = [12, 24, 48];
    public const MAX_SEARCH_CHARACTERS = 120;
    public const MAX_SEARCH_BYTES = 480;

    private readonly ?string $search;

    public function __construct(
        ?string $search = null,
        private readonly int $page = 1,
        private readonly int $pageSize = self::DEFAULT_PAGE_SIZE
    ) {
        $this->search = self::normalizeSearch($search);
        if (
            $page < 1
            || $page > 999_999
            || !in_array($pageSize, self::ALLOWED_PAGE_SIZES, true)
        ) {
            throw new MediaException('webadmin.media.picker_query_invalid');
        }
    }

    public function search(): ?string
    {
        return $this->search;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }

    public function fetchLimit(): int
    {
        return $this->pageSize + 1;
    }

    private static function normalizeSearch(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }
        if (
            strlen($search) > self::MAX_SEARCH_BYTES
            || preg_match('//u', $search) !== 1
            || preg_match('/[\x00-\x1F\x7F]/u', $search) === 1
        ) {
            throw new MediaException('webadmin.media.picker_query_invalid');
        }
        $search = preg_replace('/\s+/u', ' ', trim($search));
        $characters = is_string($search)
            ? preg_match_all('/./us', $search, $matches)
            : false;
        if (
            !is_string($search)
            || $characters === false
            || $characters > self::MAX_SEARCH_CHARACTERS
        ) {
            throw new MediaException('webadmin.media.picker_query_invalid');
        }

        return $search === '' ? null : $search;
    }
}
