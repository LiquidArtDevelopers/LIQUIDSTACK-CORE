<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Cache;

use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogSitemapRenderer;
use JsonException;

final class BlogSitemapCacheIdentity
{
    private function __construct(private readonly string $hash)
    {
    }

    public static function fromContract(
        BlogConfig $config,
        BlogPublicOrigin $origin,
        string $databaseIdentity = ''
    ): self {
        try {
            $payload = json_encode([
                'schema' => 2,
                'renderer' => BlogSitemapRenderer::CONTRACT_VERSION,
                'origin' => $origin->value(),
                'public_paths' => $config->publicPaths(),
                'default_locale' => $config->defaultLocale(),
                'sitemap_path' => $config->sitemapPath(),
                'table_prefix' => $config->tablePrefix(),
                'ttl_seconds' => $config->sitemapCache()->ttlSeconds(),
                'database_identity' => $databaseIdentity,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BlogSitemapCacheException(
                'blog.sitemap_cache.identity_invalid'
            );
        }

        return new self(hash('sha256', $payload));
    }

    public function hash(): string
    {
        return $this->hash;
    }
}
