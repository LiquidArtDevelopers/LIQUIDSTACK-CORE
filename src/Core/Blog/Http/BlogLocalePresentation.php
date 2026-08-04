<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

/**
 * Stable, display-only metadata for an editorial locale.
 *
 * Locale identity remains the normalized BCP 47 code. Flag asset paths are
 * deliberately allowlisted and never derived from untrusted input.
 */
final class BlogLocalePresentation
{
    private const FLAG_PUBLIC_DIRECTORY = '/assets/modules/blog/flags/';
    private const MAX_LOCALE_BYTES = 35;
    private const VALID_LOCALE = '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/';

    /** @var array<string, array{label: string, flag: string}> */
    private const KNOWN = [
        'es' => [
            'label' => 'Español',
            'flag' => 'es.svg',
        ],
        'eu' => [
            'label' => 'Euskera',
            'flag' => 'es-pv.svg',
        ],
        'en' => [
            'label' => 'Inglés',
            'flag' => 'gb.svg',
        ],
    ];

    public static function label(string $locale): string
    {
        $normalized = self::normalize($locale);
        if ($normalized === null) {
            return 'Idioma desconocido';
        }

        return self::KNOWN[$normalized]['label'] ?? strtoupper($normalized);
    }

    public static function flagAsset(string $locale): ?string
    {
        $normalized = self::normalize($locale);
        if ($normalized === null || !isset(self::KNOWN[$normalized])) {
            return null;
        }

        return self::FLAG_PUBLIC_DIRECTORY
            . self::KNOWN[$normalized]['flag'];
    }

    private static function normalize(string $locale): ?string
    {
        $normalized = strtolower(trim($locale));
        if (
            $normalized === ''
            || strlen($normalized) > self::MAX_LOCALE_BYTES
            || preg_match(self::VALID_LOCALE, $normalized) !== 1
        ) {
            return null;
        }

        return $normalized;
    }
}
