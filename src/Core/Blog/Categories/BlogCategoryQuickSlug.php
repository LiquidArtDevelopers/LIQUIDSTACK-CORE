<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories;

/** Deterministic ASCII slug for the one-field quick-create flow. */
final class BlogCategoryQuickSlug
{
    private const LATIN_FOLD = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'å' => 'a', 'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a',
        'Ã' => 'a', 'Å' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
        'ê' => 'e', 'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'Í' => 'i',
        'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i', 'ó' => 'o', 'ò' => 'o',
        'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'o', 'Ò' => 'o',
        'Ö' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'ú' => 'u', 'ù' => 'u',
        'ü' => 'u', 'û' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u',
        'Û' => 'u', 'ñ' => 'n', 'Ñ' => 'n', 'ç' => 'c', 'Ç' => 'c',
        'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'y', 'æ' => 'ae', 'Æ' => 'ae',
        'œ' => 'oe', 'Œ' => 'oe',
    ];

    public static function fromName(string $name): string
    {
        $name = BlogCategoryInput::name($name);
        $folded = strtolower(strtr($name, self::LATIN_FOLD));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $folded);
        if (!is_string($slug)) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
        $slug = trim($slug, '-');
        if (strlen($slug) > BlogCategoryDraft::MAX_SLUG_BYTES) {
            $slug = rtrim(substr(
                $slug,
                0,
                BlogCategoryDraft::MAX_SLUG_BYTES
            ), '-');
        }

        return BlogCategoryInput::slug($slug);
    }
}
