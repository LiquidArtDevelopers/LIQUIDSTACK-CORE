<?php

declare(strict_types=1);

namespace App\Core\Blog\Configuration;

use App\Core\Database\DatabaseConnectionProfile;

final class BlogConfig
{
    public const PROJECT_CONFIG_PATH = 'App/config/modules/blog.php';
    public const DEFAULT_PUBLIC_SEGMENT = 'blog';
    public const DEFAULT_SITEMAP_PATH = '/blog-sitemap.xml';
    public const DEFAULT_TABLE_PREFIX = 'ls_blog_';
    public const MYSQL_IDENTIFIER_MAX_LENGTH = 64;
    public const LONGEST_TABLE_SUFFIX = 'post_localizations';
    public const LONGEST_TABLE_SUFFIX_LENGTH = 18;
    public const MAX_TABLE_PREFIX_LENGTH =
        self::MYSQL_IDENTIFIER_MAX_LENGTH
        - self::LONGEST_TABLE_SUFFIX_LENGTH;

    /**
     * @param array<string, string> $publicPaths
     */
    public function __construct(
        private readonly array $publicPaths,
        private readonly string $sitemapPath,
        private readonly string $tablePrefix,
        private readonly string $source,
        private readonly string $databaseConnection =
            DatabaseConnectionProfile::SHARED,
        ?string $defaultLocale = null,
        private readonly ?BlogPublicArticleViewPath $publicArticleView = null
    ) {
        $defaultLocale ??= array_key_first($publicPaths);
        if (
            !is_string($defaultLocale)
            || !array_key_exists($defaultLocale, $publicPaths)
        ) {
            throw new BlogConfigException(
                'config.default_language_missing',
                'public_paths'
            );
        }
        $this->defaultLocale = $defaultLocale;
    }

    private readonly string $defaultLocale;

    /** @param list<string> $languages */
    public static function defaults(array $languages): self
    {
        $paths = [];
        foreach (array_values($languages) as $index => $language) {
            $paths[$language] = $index === 0
                ? '/' . self::DEFAULT_PUBLIC_SEGMENT
                : '/' . $language . '/' . self::DEFAULT_PUBLIC_SEGMENT;
        }

        return new self(
            $paths,
            self::DEFAULT_SITEMAP_PATH,
            self::DEFAULT_TABLE_PREFIX,
            'defaults',
            DatabaseConnectionProfile::SHARED,
            array_key_first($paths)
        );
    }

    /** @return array<string, string> */
    public function publicPaths(): array
    {
        return $this->publicPaths;
    }

    public function publicPath(string $locale): ?string
    {
        return $this->publicPaths[strtolower($locale)] ?? null;
    }

    public function sitemapPath(): string
    {
        return $this->sitemapPath;
    }

    public function databaseConnection(): string
    {
        return $this->databaseConnection;
    }

    public function tablePrefix(): string
    {
        return $this->tablePrefix;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function publicArticleView(): ?string
    {
        return $this->publicArticleView?->relativePath();
    }

    public function publicArticleViewPath(): ?string
    {
        return $this->publicArticleView?->absolutePath();
    }

    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        $safe = [
            'source' => $this->source,
            'public_paths' => $this->publicPaths,
            'sitemap_path' => $this->sitemapPath,
            'database' => [
                'connection' => $this->databaseConnection(),
                'table_prefix' => $this->tablePrefix,
            ],
        ];

        if ($this->publicArticleView !== null) {
            $safe['public_article_view'] =
                $this->publicArticleView->relativePath();
        }

        return $safe;
    }
}
