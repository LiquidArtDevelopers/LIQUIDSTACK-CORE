<?php

declare(strict_types=1);

namespace App\Core\Commerce;

/** Immutable, bounded query accepted by the public Commerce catalog. */
final class CommercePublicCatalogQuery
{
    private readonly ?string $search;
    private readonly ?string $categorySlug;
    private readonly ?string $tagSlug;

    public function __construct(
        ?string $search = null,
        ?string $categorySlug = null,
        ?string $tagSlug = null,
        private readonly int $limit = 24,
        private readonly int $offset = 0
    ) {
        $this->search = CommerceInput::nullableText($search, 160);
        $this->categorySlug = $categorySlug === null
            || trim($categorySlug) === ''
                ? null
                : CommerceInput::slug($categorySlug);
        $this->tagSlug = $tagSlug === null || trim($tagSlug) === ''
            ? null
            : CommerceInput::slug($tagSlug);
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1_000_000) {
            throw new CommerceValidationException('Invalid catalog window.');
        }
    }

    public function search(): ?string
    {
        return $this->search;
    }

    public function categorySlug(): ?string
    {
        return $this->categorySlug;
    }

    public function tagSlug(): ?string
    {
        return $this->tagSlug;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function offset(): int
    {
        return $this->offset;
    }
}
