<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\Categories\BlogCategoryInput;

/** Localized, ID-free category projection safe for public resources. */
final class BlogPublicCardCategory
{
    private readonly string $locale;
    private readonly string $slug;
    private readonly string $name;

    public function __construct(string $locale, string $slug, string $name)
    {
        $this->locale = BlogCategoryInput::locale($locale);
        $this->slug = BlogCategoryInput::slug($slug);
        $this->name = BlogCategoryInput::name($name);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array{locale:string,slug:string,name:string} */
    public function toResourceData(): array
    {
        return [
            'locale' => $this->locale,
            'slug' => $this->slug,
            'name' => $this->name,
        ];
    }
}
