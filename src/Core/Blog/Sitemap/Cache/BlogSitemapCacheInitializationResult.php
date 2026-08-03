<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Cache;

final class BlogSitemapCacheInitializationResult
{
    public function __construct(
        private readonly string $generation,
        private readonly bool $changed
    ) {
    }

    public function generation(): string { return $this->generation; }
    public function changed(): bool { return $this->changed; }

    /** @return array{status: string, changed: bool} */
    public function toSafeArray(): array
    {
        return [
            'status' => $this->changed ? 'initialized' : 'already_initialized',
            'changed' => $this->changed,
        ];
    }
}
