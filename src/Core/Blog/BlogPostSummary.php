<?php

declare(strict_types=1);

namespace App\Core\Blog;

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

    public function __construct(
        string $postPublicId,
        string $localizationPublicId,
        string $locale,
        ?string $slug,
        string $h1,
        private readonly string $status,
        ?DateTimeImmutable $publishedAt,
        private readonly int $lockVersion,
        DateTimeImmutable $updatedAt
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
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return array_replace($this->toArray(), [
            'slug' => $this->slug === null ? null : '[redacted]',
            'h1' => '[redacted]',
        ]);
    }
}
