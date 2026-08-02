<?php

declare(strict_types=1);

namespace App\Core\Blog;

use DateTimeImmutable;

/** Minimal published projection used to build the dynamic sitemap. */
final class BlogSitemapEntry
{
    public const MAX_DOCUMENT_ENTRIES = 50_000;
    public const OVERFLOW_QUERY_LIMIT = self::MAX_DOCUMENT_ENTRIES + 1;
    public const MAX_LANGUAGE_ALTERNATES = 100;
    public const ALTERNATES_OVERFLOW_QUERY_LIMIT =
        self::MAX_LANGUAGE_ALTERNATES + 1;

    private readonly string $locale;
    private readonly string $slug;
    private readonly DateTimeImmutable $publishedAt;
    private readonly DateTimeImmutable $updatedAt;
    private readonly ?string $postPublicId;

    public function __construct(
        string $locale,
        string $slug,
        DateTimeImmutable $publishedAt,
        DateTimeImmutable $updatedAt,
        ?string $postPublicId = null
    ) {
        $this->locale = BlogInput::locale($locale);
        $this->slug = BlogInput::slug($slug)
            ?? throw new BlogException(BlogException::INVALID_INPUT);
        $this->publishedAt = BlogInput::utc($publishedAt);
        $this->updatedAt = BlogInput::utc($updatedAt);
        $this->postPublicId = $postPublicId === null
            ? null
            : BlogInput::publicId($postPublicId);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function publishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function postPublicId(): ?string
    {
        return $this->postPublicId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $entry = [
            'locale' => $this->locale,
            'slug' => $this->slug,
            'published_at' => $this->publishedAt->format('Y-m-d H:i:s.u'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s.u'),
        ];
        if ($this->postPublicId !== null) {
            $entry['post_public_id'] = $this->postPublicId;
        }

        return $entry;
    }
}
