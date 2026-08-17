<?php

declare(strict_types=1);

namespace App\Core\Blog;

use App\Core\Blog\Categories\BlogCategoryDraft;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use DateTimeImmutable;

/** Bounded list projection: no internal IDs, excerpt or full body. */
final class BlogPostSummary
{
    private readonly string $postPublicId;
    private readonly string $localizationPublicId;
    private readonly string $locale;
    private readonly ?string $slug;
    private readonly string $h1;
    private readonly DateTimeImmutable $updatedAt;
    private readonly ?DateTimeImmutable $publishedAt;
    private readonly ?string $authorName;

    /** @var list<string> */
    private readonly array $categoryNames;

    private readonly BlogRobotsPreferences $robotsPreferences;

    public function __construct(
        string $postPublicId,
        string $localizationPublicId,
        string $locale,
        ?string $slug,
        string $h1,
        private readonly string $status,
        ?DateTimeImmutable $publishedAt,
        private readonly int $lockVersion,
        DateTimeImmutable $updatedAt,
        ?string $authorName = null,
        array $categoryNames = [],
        ?BlogRobotsPreferences $robotsPreferences = null
    ) {
        $this->postPublicId = BlogInput::publicId($postPublicId);
        $this->localizationPublicId = BlogInput::publicId(
            $localizationPublicId
        );
        $this->locale = BlogInput::locale($locale);
        $this->slug = BlogInput::slug($slug);
        $this->h1 = BlogInput::requiredSingleLine(
            $h1,
            BlogDraft::MAX_H1_BYTES
        );
        BlogInput::lockVersion($lockVersion);
        if (!in_array(
            $status,
            [BlogPostVariant::DRAFT, BlogPostVariant::PUBLISHED],
            true
        )) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        if (
            ($status === BlogPostVariant::DRAFT && $publishedAt !== null)
            || ($status === BlogPostVariant::PUBLISHED && $publishedAt === null)
            || ($status === BlogPostVariant::PUBLISHED && $slug === null)
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        $this->publishedAt = $publishedAt === null
            ? null
            : BlogInput::utc($publishedAt);
        $this->updatedAt = BlogInput::utc($updatedAt);
        $this->authorName = BlogInput::nullableSingleLine($authorName, 255);
        if (!array_is_list($categoryNames) || count($categoryNames) > 100) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        $validatedCategoryNames = [];
        foreach ($categoryNames as $categoryName) {
            if (!is_string($categoryName)) {
                throw new BlogException(BlogException::INVALID_INPUT);
            }
            $validatedCategoryNames[] = BlogInput::requiredSingleLine(
                $categoryName,
                BlogCategoryDraft::MAX_NAME_BYTES
            );
        }
        $this->categoryNames = $validatedCategoryNames;
        $this->robotsPreferences = $robotsPreferences
            ?? BlogRobotsPreferences::defaults();
    }

    public function postPublicId(): string
    {
        return $this->postPublicId;
    }

    public function localizationPublicId(): string
    {
        return $this->localizationPublicId;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function slug(): ?string
    {
        return $this->slug;
    }

    public function h1(): string
    {
        return $this->h1;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function lockVersion(): int
    {
        return $this->lockVersion;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function authorName(): ?string
    {
        return $this->authorName;
    }

    /** @return list<string> */
    public function categoryNames(): array
    {
        return $this->categoryNames;
    }

    public function robotsPreferences(): BlogRobotsPreferences
    {
        return $this->robotsPreferences;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'post_public_id' => $this->postPublicId,
            'localization_public_id' => $this->localizationPublicId,
            'locale' => $this->locale,
            'slug' => $this->slug,
            'h1' => $this->h1,
            'status' => $this->status,
            'published_at' => $this->publishedAt?->format('Y-m-d H:i:s.u'),
            'lock_version' => $this->lockVersion,
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s.u'),
            'author_name' => $this->authorName,
            'category_names' => $this->categoryNames,
            'robots' => $this->robotsPreferences->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return array_replace($this->toArray(), [
            'slug' => $this->slug === null ? null : '[redacted]',
            'h1' => '[redacted]',
            'author_name' => $this->authorName === null
                ? null
                : '[redacted]',
            'category_names' => array_fill(
                0,
                count($this->categoryNames),
                '[redacted]'
            ),
        ]);
    }
}
