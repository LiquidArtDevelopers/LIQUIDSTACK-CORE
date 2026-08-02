<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories;

final class BlogCategoryDraft
{
    public const MAX_NAME_BYTES = 255;
    public const MAX_SLUG_BYTES = 190;

    private readonly string $name;
    private readonly string $slug;

    public function __construct(string $name, string $slug)
    {
        $this->name = BlogCategoryInput::name($name);
        $this->slug = BlogCategoryInput::slug($slug);
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
