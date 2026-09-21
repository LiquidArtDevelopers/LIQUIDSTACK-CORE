<?php

declare(strict_types=1);

namespace App\Core\Commerce\Configuration;

use App\Core\Database\DatabaseConnectionProfile;

final class CommerceConfig
{
    public const PROJECT_CONFIG_PATH = 'App/config/modules/commerce.php';
    public const DEFAULT_PUBLIC_SEGMENT = 'commerce';
    public const DEFAULT_SITEMAP_PATH = '/commerce-sitemap.xml';
    public const DEFAULT_TABLE_PREFIX = 'ls_commerce_';
    public const TRANSACTION_MODE_INQUIRY = 'inquiry';
    public const DEFAULT_BASKET_MAX_ITEMS = 20;
    public const DEFAULT_BASKET_TTL_SECONDS = 2592000;
    public const DEFAULT_SOCIAL_PROOF_MINIMUM_COUNT = 5;
    public const MYSQL_IDENTIFIER_MAX_LENGTH = 64;
    public const LONGEST_TABLE_SUFFIX = 'product_attribute_value_options';
    public const LONGEST_TABLE_SUFFIX_LENGTH = 31;
    public const MAX_TABLE_PREFIX_LENGTH =
        self::MYSQL_IDENTIFIER_MAX_LENGTH
        - self::LONGEST_TABLE_SUFFIX_LENGTH;

    /**
     * @param array<string, string> $publicPaths
     * @param array<string, string> $inquiryPaths
     */
    public function __construct(
        private readonly bool $publicEnabled,
        private readonly array $publicPaths,
        private readonly array $inquiryPaths,
        private readonly string $sitemapPath,
        private readonly string $transactionMode,
        private readonly string $databaseConnection,
        private readonly string $tablePrefix,
        private readonly int $basketMaxItems,
        private readonly int $basketTtlSeconds,
        private readonly bool $socialProofEnabled,
        private readonly int $socialProofMinimumCount,
        private readonly string $source,
        private readonly string $defaultLocale
    ) {
    }

    /** @param list<string> $languages */
    public static function defaults(array $languages): self
    {
        $paths = [];
        foreach (array_values($languages) as $index => $language) {
            $paths[$language] = $index === 0
                ? '/' . self::DEFAULT_PUBLIC_SEGMENT
                : '/' . $language . '/' . self::DEFAULT_PUBLIC_SEGMENT;
        }

        $defaultLocale = array_key_first($paths);
        if (!is_string($defaultLocale)) {
            throw new CommerceConfigException(
                'config.languages_missing',
                'public_paths'
            );
        }

        $inquiryPaths = [];
        foreach ($paths as $locale => $path) {
            $inquiryPaths[$locale] = $path . '/inquiry';
        }

        return new self(
            false,
            $paths,
            $inquiryPaths,
            self::DEFAULT_SITEMAP_PATH,
            self::TRANSACTION_MODE_INQUIRY,
            DatabaseConnectionProfile::SHARED,
            self::DEFAULT_TABLE_PREFIX,
            self::DEFAULT_BASKET_MAX_ITEMS,
            self::DEFAULT_BASKET_TTL_SECONDS,
            false,
            self::DEFAULT_SOCIAL_PROOF_MINIMUM_COUNT,
            'defaults',
            $defaultLocale
        );
    }

    public function publicEnabled(): bool
    {
        return $this->publicEnabled;
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

    /** @return array<string, string> */
    public function inquiryPaths(): array
    {
        return $this->inquiryPaths;
    }

    public function inquiryPath(string $locale): ?string
    {
        return $this->inquiryPaths[strtolower($locale)] ?? null;
    }

    public function sitemapPath(): string
    {
        return $this->sitemapPath;
    }

    public function transactionMode(): string
    {
        return $this->transactionMode;
    }

    public function databaseConnection(): string
    {
        return $this->databaseConnection;
    }

    public function tablePrefix(): string
    {
        return $this->tablePrefix;
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function basketMaxItems(): int
    {
        return $this->basketMaxItems;
    }

    public function basketTtlSeconds(): int
    {
        return $this->basketTtlSeconds;
    }

    public function socialProofEnabled(): bool
    {
        return $this->socialProofEnabled;
    }

    public function socialProofMinimumCount(): int
    {
        return $this->socialProofMinimumCount;
    }

    public function source(): string
    {
        return $this->source;
    }

    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        return [
            'source' => $this->source,
            'public' => ['enabled' => $this->publicEnabled],
            'public_paths' => $this->publicPaths,
            'inquiry_paths' => $this->inquiryPaths,
            'sitemap_path' => $this->sitemapPath,
            'transaction_mode' => $this->transactionMode,
            'database' => [
                'connection' => $this->databaseConnection,
                'table_prefix' => $this->tablePrefix,
            ],
            'basket' => [
                'max_items' => $this->basketMaxItems,
                'ttl_seconds' => $this->basketTtlSeconds,
            ],
            'social_proof' => [
                'enabled' => $this->socialProofEnabled,
                'minimum_count' => $this->socialProofMinimumCount,
            ],
        ];
    }
}
