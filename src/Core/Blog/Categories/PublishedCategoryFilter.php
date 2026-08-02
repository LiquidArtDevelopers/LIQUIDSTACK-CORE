<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories;

/** Safe public projection passed to resources; it never contains DB IDs. */
final class PublishedCategoryFilter
{
    private readonly string $categoryPublicId;
    private readonly string $locale;
    private readonly string $slug;
    private readonly string $name;

    public function __construct(
        string $categoryPublicId,
        string $locale,
        string $slug,
        string $name,
        private readonly int $publishedPostCount
    ) {
        $this->categoryPublicId = BlogCategoryInput::publicId(
            $categoryPublicId
        );
        $this->locale = BlogCategoryInput::locale($locale);
        $this->slug = BlogCategoryInput::slug($slug);
        $this->name = BlogCategoryInput::name($name);
        if ($publishedPostCount < 1) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
    }

    public function categoryPublicId(): string { return $this->categoryPublicId; }
    public function locale(): string { return $this->locale; }
    public function slug(): string { return $this->slug; }
    public function name(): string { return $this->name; }
    public function publishedPostCount(): int { return $this->publishedPostCount; }

    /** @return array{locale: string, slug: string, name: string, count: int} */
    public function toResourceData(): array
    {
        return [
            'locale' => $this->locale,
            'slug' => $this->slug,
            'name' => $this->name,
            'count' => $this->publishedPostCount,
        ];
    }
}
