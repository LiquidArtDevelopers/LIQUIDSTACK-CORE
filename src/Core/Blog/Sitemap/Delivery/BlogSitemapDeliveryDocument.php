<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Delivery;

final class BlogSitemapDeliveryDocument
{
    public function __construct(
        private readonly string $xml,
        private readonly string $etag,
        private readonly bool $stale
    ) {
    }

    public function xml(): string { return $this->xml; }
    public function etag(): string { return $this->etag; }
    public function stale(): bool { return $this->stale; }
}
