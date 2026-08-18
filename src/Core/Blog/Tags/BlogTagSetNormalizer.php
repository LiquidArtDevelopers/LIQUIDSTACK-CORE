<?php

declare(strict_types=1);

namespace App\Core\Blog\Tags;

/** Parses the no-JS CSV contract into a bounded identity-keyed set. */
final class BlogTagSetNormalizer
{
    public const MAX_TAGS_PER_VARIANT = 30;
    public const MAX_CSV_BYTES = 4096;

    /** @return list<BlogTagCandidate> */
    public function normalize(string $csv): array
    {
        if (
            strlen($csv) > self::MAX_CSV_BYTES
            || !BlogTagInput::hasSafeTextCharacters($csv)
            || !function_exists('mb_convert_case')
        ) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }
        $csv = BlogTagInput::canonicalText($csv);
        if (strlen($csv) > self::MAX_CSV_BYTES) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }
        $candidates = [];
        foreach (explode(',', $csv) as $raw) {
            $name = preg_replace('/\s+/u', ' ', $raw);
            if (!is_string($name)) {
                throw new BlogTagException(BlogTagException::INVALID_INPUT);
            }
            $name = preg_replace('/\A\s+|\s+\z/u', '', $name);
            if (!is_string($name)) {
                throw new BlogTagException(BlogTagException::INVALID_INPUT);
            }
            if ($name === '') {
                continue;
            }
            $name = BlogTagInput::name($name);
            $casefold = mb_convert_case($name, MB_CASE_FOLD, 'UTF-8');
            if (!is_string($casefold) || $casefold === '') {
                throw new BlogTagException(BlogTagException::INVALID_INPUT);
            }
            $hash = hash('sha256', $casefold);
            BlogTagInput::normalizedSha256($hash);
            if (isset($candidates[$hash])) {
                continue;
            }
            if (count($candidates) >= self::MAX_TAGS_PER_VARIANT) {
                throw new BlogTagException(BlogTagException::INVALID_INPUT);
            }
            $candidates[$hash] = new BlogTagCandidate(
                $name,
                BlogTagSlug::base($casefold, $hash),
                $hash
            );
        }
        ksort($candidates, SORT_STRING);

        return array_values($candidates);
    }
}
