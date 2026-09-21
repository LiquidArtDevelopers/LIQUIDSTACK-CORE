<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class CommercePublicCatalogPage
{
    /** @param list<CommercePublicProduct> $items */
    public function __construct(
        private readonly array $items,
        private readonly bool $hasNext,
        private readonly CommercePublicCatalogQuery $query
    ) {
        if (!array_is_list($items) || count($items) > $query->limit()) {
            throw new CommerceValidationException('Invalid public catalog page.');
        }
        foreach ($items as $item) {
            if (!$item instanceof CommercePublicProduct) {
                throw new CommerceValidationException('Invalid public catalog page.');
            }
        }
    }

    /** @return list<CommercePublicProduct> */
    public function items(): array { return $this->items; }
    public function hasNext(): bool { return $this->hasNext; }
    public function query(): CommercePublicCatalogQuery { return $this->query; }
}
