<?php

declare(strict_types=1);

namespace App\Core\Blog\Configuration;

use App\Core\Database\DatabaseConnectionProfile;
use Throwable;

final class BlogConfigLoader
{
    private const ROOT_KEYS = [
        'public_paths',
        'sitemap_path',
        'public_article_view',
        'database',
        'sitemap_cache',
    ];
    private const DATABASE_KEYS = ['connection', 'table_prefix'];
    private const SITEMAP_CACHE_KEYS = ['enabled', 'ttl_seconds'];

    public function databaseConnection(string $projectRoot): string
    {
        $root = rtrim($projectRoot, '/\\');
        if ($root === '' || !is_dir($root)) {
            throw new BlogConfigException('project.root_missing');
        }

        $path = $root . '/' . BlogConfig::PROJECT_CONFIG_PATH;
        if (!file_exists($path) && !is_link($path)) {
            return DatabaseConnectionProfile::SHARED;
        }
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new BlogConfigException(
                'config.project_file_not_regular',
                BlogConfig::PROJECT_CONFIG_PATH
            );
        }

        $raw = $this->requireArray($path);
        $this->assertOnlyKeys($raw, self::ROOT_KEYS, 'config');
        if (array_key_exists('public_article_view', $raw)) {
            BlogPublicArticleViewPath::fromProject(
                $root,
                $raw['public_article_view']
            );
        }
        $database = $raw['database'] ?? [];
        if (
            !is_array($database)
            || ($database !== [] && array_is_list($database))
        ) {
            throw new BlogConfigException(
                'config.expected_object',
                'database'
            );
        }
        $this->assertOnlyKeys($database, self::DATABASE_KEYS, 'database');
        $this->validateSitemapCache($raw['sitemap_cache'] ?? []);
        $connection = $database['connection']
            ?? DatabaseConnectionProfile::SHARED;
        if (!DatabaseConnectionProfile::isSupported($connection)) {
            throw new BlogConfigException(
                'config.unsupported_database_connection',
                'database.connection'
            );
        }

        return $connection;
    }

    /** @param list<string> $languages */
    public function load(string $projectRoot, array $languages): BlogConfig
    {
        $root = rtrim($projectRoot, '/\\');
        if ($root === '' || !is_dir($root)) {
            throw new BlogConfigException('project.root_missing');
        }

        $languages = $this->normalizeLanguages($languages);
        if ($languages === []) {
            throw new BlogConfigException(
                'config.languages_missing',
                'public_paths'
            );
        }

        $path = $root . '/' . BlogConfig::PROJECT_CONFIG_PATH;
        if (!file_exists($path) && !is_link($path)) {
            return BlogConfig::defaults($languages);
        }
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new BlogConfigException(
                'config.project_file_not_regular',
                BlogConfig::PROJECT_CONFIG_PATH
            );
        }

        $raw = $this->requireArray($path);
        $this->assertOnlyKeys($raw, self::ROOT_KEYS, 'config');

        $defaults = BlogConfig::defaults($languages);
        $publicPaths = $raw['public_paths'] ?? $defaults->publicPaths();
        $sitemapPath = $raw['sitemap_path'] ?? $defaults->sitemapPath();
        $database = $raw['database'] ?? [];
        $sitemapCache = $this->validateSitemapCache(
            $raw['sitemap_cache'] ?? []
        );
        $publicArticleView = array_key_exists('public_article_view', $raw)
            ? BlogPublicArticleViewPath::fromProject(
                $root,
                $raw['public_article_view']
            )
            : null;

        if (
            !is_array($publicPaths)
            || $publicPaths === []
            || array_is_list($publicPaths)
        ) {
            throw new BlogConfigException(
                'config.expected_object',
                'public_paths'
            );
        }
        if (
            !is_array($database)
            || ($database !== [] && array_is_list($database))
        ) {
            throw new BlogConfigException(
                'config.expected_object',
                'database'
            );
        }
        $this->assertOnlyKeys($database, self::DATABASE_KEYS, 'database');

        $connection = $database['connection']
            ?? DatabaseConnectionProfile::SHARED;
        if (!DatabaseConnectionProfile::isSupported($connection)) {
            throw new BlogConfigException(
                'config.unsupported_database_connection',
                'database.connection'
            );
        }
        $tablePrefix = $database['table_prefix']
            ?? $defaults->tablePrefix();

        $normalizedPaths = $this->validatePublicPaths(
            $publicPaths,
            $languages
        );
        $this->validatePath($sitemapPath, 'sitemap_path');
        if (in_array($sitemapPath, $normalizedPaths, true)) {
            throw new BlogConfigException(
                'config.duplicate_route',
                'sitemap_path'
            );
        }
        foreach ($normalizedPaths as $locale => $publicPath) {
            if (str_starts_with($sitemapPath . '/', $publicPath . '/')) {
                throw new BlogConfigException(
                    'config.nested_sitemap_path',
                    'public_paths.' . $locale
                );
            }
        }
        $this->validateTablePrefix($tablePrefix);

        return new BlogConfig(
            $normalizedPaths,
            $sitemapPath,
            $tablePrefix,
            'project',
            $connection,
            $languages[0],
            $publicArticleView,
            $sitemapCache
        );
    }

    private function validateSitemapCache(mixed $value): BlogSitemapCacheConfig
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new BlogConfigException(
                'config.expected_object',
                'sitemap_cache'
            );
        }
        $this->assertOnlyKeys(
            $value,
            self::SITEMAP_CACHE_KEYS,
            'sitemap_cache'
        );
        $enabled = $value['enabled'] ?? false;
        $ttl = $value['ttl_seconds']
            ?? BlogSitemapCacheConfig::DEFAULT_TTL_SECONDS;
        if (!is_bool($enabled)) {
            throw new BlogConfigException(
                'config.sitemap_cache_enabled_invalid',
                'sitemap_cache.enabled'
            );
        }
        if (!is_int($ttl)) {
            throw new BlogConfigException(
                'config.sitemap_cache_ttl_invalid',
                'sitemap_cache.ttl_seconds'
            );
        }

        return new BlogSitemapCacheConfig($enabled, $ttl);
    }

    /** @param list<string> $languages @return list<string> */
    private function normalizeLanguages(array $languages): array
    {
        $normalized = [];
        foreach ($languages as $language) {
            if (
                !is_string($language)
                || preg_match(
                    '/\A[a-z]{2}(?:-[a-z0-9]{2,8})?\z/i',
                    $language
                ) !== 1
            ) {
                throw new BlogConfigException(
                    'config.invalid_language',
                    'public_paths'
                );
            }
            $language = strtolower($language);
            if (isset($normalized[$language])) {
                throw new BlogConfigException(
                    'config.duplicate_language',
                    'public_paths.' . $language
                );
            }
            $normalized[$language] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @param array<mixed> $paths
     * @param list<string> $languages
     * @return array<string, string>
     */
    private function validatePublicPaths(
        array $paths,
        array $languages
    ): array {
        $normalized = [];
        foreach ($paths as $locale => $path) {
            if (!is_string($locale) || !in_array($locale, $languages, true)) {
                throw new BlogConfigException(
                    'config.unknown_language',
                    'public_paths'
                );
            }
            $this->validatePath($path, 'public_paths.' . $locale);
            if (in_array($path, $normalized, true)) {
                throw new BlogConfigException(
                    'config.duplicate_route',
                    'public_paths.' . $locale
                );
            }
            $normalized[$locale] = $path;
        }

        foreach ($languages as $language) {
            if (!array_key_exists($language, $normalized)) {
                throw new BlogConfigException(
                    'config.language_route_missing',
                    'public_paths.' . $language
                );
            }
        }

        foreach ($normalized as $locale => $path) {
            foreach ($normalized as $otherLocale => $otherPath) {
                if (
                    $locale !== $otherLocale
                    && str_starts_with($otherPath . '/', $path . '/')
                ) {
                    throw new BlogConfigException(
                        'config.nested_public_path',
                        'public_paths.' . $otherLocale
                    );
                }
            }
        }

        return $normalized;
    }

    private function validatePath(mixed $value, string $key): void
    {
        if (
            !is_string($value)
            || strlen($value) > 512
            || preg_match(
                '#\A/[a-z0-9](?:[a-z0-9.-]{0,126}[a-z0-9])?(?:/[a-z0-9](?:[a-z0-9.-]{0,126}[a-z0-9])?)*\z#',
                $value
            ) !== 1
        ) {
            throw new BlogConfigException('config.invalid_path', $key);
        }
    }

    private function validateTablePrefix(mixed $value): void
    {
        if (
            !is_string($value)
            || preg_match('/\A[a-z][a-z0-9_]+_\z/', $value) !== 1
            || strlen($value) > BlogConfig::MAX_TABLE_PREFIX_LENGTH
        ) {
            throw new BlogConfigException(
                'config.invalid_table_prefix',
                'database.table_prefix'
            );
        }
    }

    /** @return array<string, mixed> */
    private function requireArray(string $path): array
    {
        $bufferLevel = ob_get_level();
        ob_start();

        try {
            $value = (static function (string $configPath): mixed {
                return require $configPath;
            })($path);
            $output = ob_get_clean();
        } catch (Throwable) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw new BlogConfigException(
                'config.project_file_load_failed',
                BlogConfig::PROJECT_CONFIG_PATH
            );
        }

        if ($output !== '') {
            throw new BlogConfigException(
                'config.project_file_emitted_output',
                BlogConfig::PROJECT_CONFIG_PATH
            );
        }
        if (
            !is_array($value)
            || ($value !== [] && array_is_list($value))
        ) {
            throw new BlogConfigException(
                'config.expected_object',
                BlogConfig::PROJECT_CONFIG_PATH
            );
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $allowed
     */
    private function assertOnlyKeys(
        array $values,
        array $allowed,
        string $prefix
    ): void {
        foreach (array_keys($values) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $safeKey = is_string($key)
                    && preg_match('/\A[a-zA-Z0-9_.-]+\z/', $key) === 1
                    ? $prefix . '.' . $key
                    : $prefix;
                throw new BlogConfigException(
                    'config.unknown_key',
                    $safeKey
                );
            }
        }
    }
}
