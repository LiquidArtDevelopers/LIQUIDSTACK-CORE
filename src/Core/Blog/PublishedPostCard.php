<?php

declare(strict_types=1);

namespace App\Core\Blog;

use DateTimeImmutable;

/**
 * Public, bounded projection used by lists and LiquidStack resources.
 *
 * It deliberately contains neither the full article body nor persistence
 * identifiers. Consumers receive only data that is already publishable.
 */
final class PublishedPostCard
{
    private readonly string $locale;
    private readonly string $slug;
    private readonly string $h1;
    private readonly string $excerpt;
    private readonly DateTimeImmutable $publishedAt;
    private readonly DateTimeImmutable $updatedAt;

    public function __construct(
        string $locale,
        string $slug,
        string $h1,
        string $excerpt,
        DateTimeImmutable $publishedAt,
        DateTimeImmutable $updatedAt
    ) {
        $this->locale = BlogInput::locale($locale);
        $this->slug = BlogInput::slug($slug)
            ?? throw new BlogException(BlogException::INVALID_INPUT);
        $this->h1 = BlogInput::requiredSingleLine(
            $h1,
            BlogDraft::MAX_H1_BYTES
        );
        $this->excerpt = BlogInput::nullableMultiline(
            $excerpt,
            BlogDraft::MAX_EXCERPT_BYTES
        ) ?? throw new BlogException(BlogException::INVALID_INPUT);
        $this->publishedAt = BlogInput::utc($publishedAt);
        $this->updatedAt = BlogInput::utc($updatedAt);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function h1(): string
    {
        return $this->h1;
    }

    public function excerpt(): string
    {
        return $this->excerpt;
    }

    public function publishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'slug' => $this->slug,
            'h1' => $this->h1,
            'excerpt' => $this->excerpt,
            'published_at' => $this->publishedAt->format('Y-m-d H:i:s.u'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s.u'),
        ];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return array_replace($this->toArray(), [
            'slug' => '[redacted]',
            'h1' => '[redacted]',
            'excerpt' => '[redacted]',
        ]);
    }
}
