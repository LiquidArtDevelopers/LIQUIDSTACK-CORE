<?php

declare(strict_types=1);

namespace App\Core\Commerce\Configuration;

use App\Core\Database\DatabaseConnectionProfile;
use Throwable;

final class CommerceConfigLoader
{
    private const ROOT_KEYS = [
        'public',
        'public_paths',
        'inquiry_paths',
        'sitemap_path',
        'transaction_mode',
        'database',
        'basket',
        'social_proof',
    ];
    private const PUBLIC_KEYS = ['enabled'];
    private const DATABASE_KEYS = ['connection', 'table_prefix'];
    private const BASKET_KEYS = ['max_items', 'ttl_seconds'];
    private const SOCIAL_PROOF_KEYS = ['enabled', 'minimum_count'];

    public function databaseConnection(string $projectRoot): string
    {
        $raw = $this->loadRawProjectConfig($projectRoot);
        if ($raw === null) {
            return DatabaseConnectionProfile::SHARED;
        }
        $this->assertOnlyKeys($raw, self::ROOT_KEYS, 'config');
        $database = $this->object($raw['database'] ?? [], 'database');
        $this->assertOnlyKeys($database, self::DATABASE_KEYS, 'database');
        $connection = $database['connection']
            ?? DatabaseConnectionProfile::SHARED;
        if (!DatabaseConnectionProfile::isSupported($connection)) {
            throw new CommerceConfigException(
                'config.unsupported_database_connection',
                'database.connection'
            );
        }

        return $connection;
    }

    /** @param list<string> $languages */
    public function load(string $projectRoot, array $languages): CommerceConfig
    {
        $languages = $this->normalizeLanguages($languages);
        if ($languages === []) {
            throw new CommerceConfigException(
                'config.languages_missing',
                'public_paths'
            );
        }
        $defaults = CommerceConfig::defaults($languages);
        $raw = $this->loadRawProjectConfig($projectRoot);
        if ($raw === null) {
            return $defaults;
        }

        $this->assertOnlyKeys($raw, self::ROOT_KEYS, 'config');
        $public = $this->object($raw['public'] ?? [], 'public');
        $database = $this->object($raw['database'] ?? [], 'database');
        $basket = $this->object($raw['basket'] ?? [], 'basket');
        $socialProof = $this->object(
            $raw['social_proof'] ?? [],
            'social_proof'
        );
        $this->assertOnlyKeys($public, self::PUBLIC_KEYS, 'public');
        $this->assertOnlyKeys($database, self::DATABASE_KEYS, 'database');
        $this->assertOnlyKeys($basket, self::BASKET_KEYS, 'basket');
        $this->assertOnlyKeys(
            $socialProof,
            self::SOCIAL_PROOF_KEYS,
            'social_proof'
        );

        $enabled = $public['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new CommerceConfigException(
                'config.public_enabled_invalid',
                'public.enabled'
            );
        }
        $publicPaths = $this->validatePublicPaths(
            $raw['public_paths'] ?? $defaults->publicPaths(),
            $languages
        );
        $defaultInquiryPaths = [];
        foreach ($publicPaths as $locale => $publicPath) {
            $defaultInquiryPaths[$locale] = $publicPath . '/inquiry';
        }
        $inquiryPaths = $this->validateInquiryPaths(
            $raw['inquiry_paths'] ?? $defaultInquiryPaths,
            $languages,
            $publicPaths
        );
        $sitemapPath = $raw['sitemap_path'] ?? $defaults->sitemapPath();
        $this->validatePath($sitemapPath, 'sitemap_path');
        if (in_array($sitemapPath, $publicPaths, true)) {
            throw new CommerceConfigException(
                'config.duplicate_route',
                'sitemap_path'
            );
        }
        foreach ($publicPaths as $locale => $publicPath) {
            if (str_starts_with($sitemapPath . '/', $publicPath . '/')) {
                throw new CommerceConfigException(
                    'config.nested_sitemap_path',
                    'public_paths.' . $locale
                );
            }
        }

        $mode = $raw['transaction_mode']
            ?? CommerceConfig::TRANSACTION_MODE_INQUIRY;
        if ($mode !== CommerceConfig::TRANSACTION_MODE_INQUIRY) {
            throw new CommerceConfigException(
                'config.transaction_mode_unsupported',
                'transaction_mode'
            );
        }
        $connection = $database['connection']
            ?? DatabaseConnectionProfile::SHARED;
        if (!DatabaseConnectionProfile::isSupported($connection)) {
            throw new CommerceConfigException(
                'config.unsupported_database_connection',
                'database.connection'
            );
        }
        $prefix = $database['table_prefix']
            ?? CommerceConfig::DEFAULT_TABLE_PREFIX;
        $this->validateTablePrefix($prefix);

        $maxItems = $basket['max_items']
            ?? CommerceConfig::DEFAULT_BASKET_MAX_ITEMS;
        if (!is_int($maxItems) || $maxItems < 1 || $maxItems > 50) {
            throw new CommerceConfigException(
                'config.basket_max_items_invalid',
                'basket.max_items'
            );
        }
        $ttl = $basket['ttl_seconds']
            ?? CommerceConfig::DEFAULT_BASKET_TTL_SECONDS;
        if (!is_int($ttl) || $ttl < 300 || $ttl > 2592000) {
            throw new CommerceConfigException(
                'config.basket_ttl_invalid',
                'basket.ttl_seconds'
            );
        }
        $socialEnabled = $socialProof['enabled'] ?? false;
        if (!is_bool($socialEnabled)) {
            throw new CommerceConfigException(
                'config.social_proof_enabled_invalid',
                'social_proof.enabled'
            );
        }
        $minimumCount = $socialProof['minimum_count']
            ?? CommerceConfig::DEFAULT_SOCIAL_PROOF_MINIMUM_COUNT;
        if (
            !is_int($minimumCount)
            || $minimumCount < 1
            || $minimumCount > 100
        ) {
            throw new CommerceConfigException(
                'config.social_proof_minimum_count_invalid',
                'social_proof.minimum_count'
            );
        }

        return new CommerceConfig(
            $enabled,
            $publicPaths,
            $inquiryPaths,
            $sitemapPath,
            $mode,
            $connection,
            $prefix,
            $maxItems,
            $ttl,
            $socialEnabled,
            $minimumCount,
            'project',
            $languages[0]
        );
    }

    /** @return array<string, mixed>|null */
    private function loadRawProjectConfig(string $projectRoot): ?array
    {
        $root = rtrim($projectRoot, '/\\');
        if ($root === '' || !is_dir($root)) {
            throw new CommerceConfigException('project.root_missing');
        }
        $path = $root . '/' . CommerceConfig::PROJECT_CONFIG_PATH;
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new CommerceConfigException(
                'config.project_file_not_regular',
                CommerceConfig::PROJECT_CONFIG_PATH
            );
        }

        $level = ob_get_level();
        ob_start();
        try {
            $value = (static fn (string $file): mixed => require $file)($path);
            $output = (string) ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw new CommerceConfigException(
                'config.project_file_invalid',
                CommerceConfig::PROJECT_CONFIG_PATH
            );
        }
        if ($output !== '') {
            throw new CommerceConfigException(
                'config.project_file_emitted_output',
                CommerceConfig::PROJECT_CONFIG_PATH
            );
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new CommerceConfigException(
                'config.expected_object',
                'config'
            );
        }

        return $value;
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
                throw new CommerceConfigException(
                    'config.invalid_language',
                    'public_paths'
                );
            }
            $language = strtolower($language);
            if (isset($normalized[$language])) {
                throw new CommerceConfigException(
                    'config.duplicate_language',
                    'public_paths.' . $language
                );
            }
            $normalized[$language] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @param mixed $value
     * @param list<string> $languages
     * @return array<string, string>
     */
    private function validatePublicPaths(
        mixed $value,
        array $languages
    ): array {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new CommerceConfigException(
                'config.expected_object',
                'public_paths'
            );
        }
        $normalized = [];
        foreach ($value as $locale => $path) {
            if (!is_string($locale) || !in_array($locale, $languages, true)) {
                throw new CommerceConfigException(
                    'config.unknown_language',
                    'public_paths'
                );
            }
            $this->validatePath($path, 'public_paths.' . $locale);
            foreach ($normalized as $otherLocale => $otherPath) {
                if ($path === $otherPath) {
                    throw new CommerceConfigException(
                        'config.duplicate_route',
                        'public_paths.' . $locale
                    );
                }
                if (
                    str_starts_with($path . '/', $otherPath . '/')
                    || str_starts_with($otherPath . '/', $path . '/')
                ) {
                    throw new CommerceConfigException(
                        'config.nested_public_path',
                        'public_paths.' . $locale
                    );
                }
            }
            $normalized[$locale] = $path;
        }
        foreach ($languages as $language) {
            if (!array_key_exists($language, $normalized)) {
                throw new CommerceConfigException(
                    'config.language_route_missing',
                    'public_paths.' . $language
                );
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @param list<string> $languages
     * @param array<string, string> $publicPaths
     * @return array<string, string>
     */
    private function validateInquiryPaths(
        mixed $value,
        array $languages,
        array $publicPaths
    ): array {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new CommerceConfigException(
                'config.expected_object',
                'inquiry_paths'
            );
        }
        $normalized = [];
        foreach ($languages as $locale) {
            $path = $value[$locale] ?? null;
            $this->validatePath($path, 'inquiry_paths.' . $locale);
            $base = $publicPaths[$locale];
            if (!str_starts_with($path, $base . '/')) {
                throw new CommerceConfigException(
                    'config.inquiry_path_outside_public_path',
                    'inquiry_paths.' . $locale
                );
            }
            if (in_array($path, $normalized, true)) {
                throw new CommerceConfigException(
                    'config.duplicate_route',
                    'inquiry_paths.' . $locale
                );
            }
            $normalized[$locale] = $path;
        }
        if (count($value) !== count($normalized)) {
            throw new CommerceConfigException(
                'config.unknown_language',
                'inquiry_paths'
            );
        }

        return $normalized;
    }

    private function validatePath(mixed $path, string $key): void
    {
        if (
            !is_string($path)
            || preg_match(
                '#\A/(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?/)*'
                    . '[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z#',
                $path
            ) !== 1
            || str_contains($path, '..')
        ) {
            throw new CommerceConfigException('config.invalid_path', $key);
        }
    }

    private function validateTablePrefix(mixed $prefix): void
    {
        if (
            !is_string($prefix)
            || preg_match('/\A[a-z][a-z0-9_]+_\z/', $prefix) !== 1
            || strlen($prefix) > CommerceConfig::MAX_TABLE_PREFIX_LENGTH
        ) {
            throw new CommerceConfigException(
                'config.invalid_table_prefix',
                'database.table_prefix'
            );
        }
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $key): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new CommerceConfigException('config.expected_object', $key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowed
     */
    private function assertOnlyKeys(
        array $value,
        array $allowed,
        string $prefix
    ): void {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new CommerceConfigException(
                    'config.unknown_key',
                    $prefix . '.' . (is_string($key) ? $key : '?')
                );
            }
        }
    }
}
