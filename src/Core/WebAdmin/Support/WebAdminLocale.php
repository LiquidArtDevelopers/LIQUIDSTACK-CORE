<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Support;

/** Canonical locale subset currently supported by WebAdmin mail templates. */
final class WebAdminLocale
{
    public const UNDETERMINED = 'und';

    public static function normalize(string $locale): string
    {
        $locale = trim($locale);
        if (strtolower($locale) === self::UNDETERMINED) {
            return self::UNDETERMINED;
        }
        if (preg_match('/\A([A-Za-z]{2})(?:-([A-Za-z]{2}))?\z/', $locale, $matches) !== 1) {
            return self::UNDETERMINED;
        }

        return strtolower($matches[1])
            . (isset($matches[2]) ? '-' . strtoupper($matches[2]) : '');
    }

    public static function isCanonical(string $locale): bool
    {
        return self::normalize($locale) === $locale;
    }
}
