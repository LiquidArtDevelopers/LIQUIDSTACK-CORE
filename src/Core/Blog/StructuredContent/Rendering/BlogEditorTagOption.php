<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use App\Core\Blog\Tags\BlogTagInput;
use App\Core\Blog\Tags\BlogTagException;
use InvalidArgumentException;

/** Safe, ID-free tag projection for one localized editor. */
final class BlogEditorTagOption
{
    public function __construct(
        private readonly string $name,
        private readonly string $slug
    ) {
        try {
            $canonicalName = BlogTagInput::canonicalText($name);
        } catch (BlogTagException) {
            $canonicalName = null;
        }
        $characters = preg_match_all('/./us', $name, $matches);
        if (
            $name === ''
            || $canonicalName !== $name
            || $characters === false
            || $characters > 64
            || strlen($name) > 255
            || str_contains($name, ',')
            || !BlogTagInput::hasSafeTextCharacters($name)
            || strlen($slug) > 190
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog editor tag option.'
            );
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }
}
