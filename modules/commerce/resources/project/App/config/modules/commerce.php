<?php

declare(strict_types=1);

$commerceLanguages = require dirname(__DIR__) . '/langs.php';
$commerceLanguages = is_array($commerceLanguages)
    ? array_values(array_unique(array_filter(
        array_map(
            static fn (mixed $locale): string => is_string($locale)
                ? strtolower(trim($locale))
                : '',
            $commerceLanguages
        ),
        static fn (string $locale): bool => preg_match(
            '/\A[a-z]{2}(?:-[a-z0-9]{2,8})?\z/D',
            $locale
        ) === 1
    )))
    : [];
$commerceSegments = [
    'es' => ['catalog' => 'comercio', 'inquiry' => 'lista-interes'],
    'eu' => ['catalog' => 'merkataritza', 'inquiry' => 'interes-zerrenda'],
    'en' => ['catalog' => 'commerce', 'inquiry' => 'interest-list'],
];
$commercePublicPaths = [];
$commerceInquiryPaths = [];
foreach ($commerceLanguages as $commerceLocale) {
    $commerceLanguage = explode('-', $commerceLocale, 2)[0];
    $commerceSegment = $commerceSegments[$commerceLanguage]
        ?? ['catalog' => 'commerce', 'inquiry' => 'interest-list'];
    $commercePublicPaths[$commerceLocale] = '/' . $commerceLocale . '/'
        . $commerceSegment['catalog'];
    $commerceInquiryPaths[$commerceLocale] = $commercePublicPaths[
        $commerceLocale
    ] . '/' . $commerceSegment['inquiry'];
}

return [
    'public' => [
        'enabled' => false,
    ],
    'public_paths' => $commercePublicPaths,
    'inquiry_paths' => $commerceInquiryPaths,
    'sitemap_path' => '/commerce-sitemap.xml',
    'transaction_mode' => 'inquiry',
    'database' => [
        'connection' => 'liquidstack',
        'table_prefix' => 'ls_commerce_',
    ],
    'basket' => [
        'max_items' => 20,
        'ttl_seconds' => 2_592_000,
    ],
    'social_proof' => [
        'enabled' => false,
        'minimum_count' => 5,
    ],
];
