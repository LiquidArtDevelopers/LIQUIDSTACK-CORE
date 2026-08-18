<?php

declare(strict_types=1);

namespace App\Core\Blog\Tags;

/** Stable ASCII slug generation without optional intl-dependent behavior. */
final class BlogTagSlug
{
    private const LATIN_FOLD = [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ù'=>'u','ü'=>'u',
        'û'=>'u','ñ'=>'n','ç'=>'c','ý'=>'y','ÿ'=>'y','æ'=>'ae','œ'=>'oe',
    ];

    public static function base(string $casefoldedName, string $hash): string
    {
        BlogTagInput::normalizedSha256($hash);
        $folded = strtr($casefoldedName, self::LATIN_FOLD);
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $folded);
        if (!is_string($slug)) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'tag-' . substr($hash, 0, 16);
        }
        if (strlen($slug) > BlogTagInput::MAX_SLUG_BYTES) {
            $slug = rtrim(substr(
                $slug,
                0,
                BlogTagInput::MAX_SLUG_BYTES
            ), '-');
        }

        return BlogTagInput::slug($slug);
    }

    public static function collision(string $base, string $hash): string
    {
        $base = BlogTagInput::slug($base);
        BlogTagInput::normalizedSha256($hash);
        $suffix = '-' . substr($hash, 0, 16);
        $available = BlogTagInput::MAX_SLUG_BYTES - strlen($suffix);
        $prefix = rtrim(substr($base, 0, $available), '-');
        if ($prefix === '') {
            $prefix = 'tag';
        }

        return BlogTagInput::slug($prefix . $suffix);
    }
}
