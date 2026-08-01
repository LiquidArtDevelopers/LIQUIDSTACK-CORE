<?php

declare(strict_types=1);

namespace App\Core\Blog;

use DateTimeImmutable;

/** Full private/public projection of one localized post variant. */
final class BlogPostVariant
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';

    private readonly string $postPublicId;
    private readonly string $localizationPublicId;
    private readonly string $locale;
    private readonly string $createdByUserPublicId;
    private readonly string $updatedByUserPublicId;
    private readonly DateTimeImmutable $createdAt;
    private readonly DateTimeImmutable $updatedAt;
    private readonly ?DateTimeImmutable $publishedAt;

    public function __construct(
        string $postPublicId,
        string $localizationPublicId,
        string $locale,
        private readonly BlogDraft $draft,
        private readonly string $status,
        ?DateTimeImmutable $publishedAt,
        private readonly int $lockVersion,
        string $createdByUserPublicId,
        string $updatedByUserPublicId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ) {
        $this->postPublicId = BlogInput::publicId($postPublicId);
        $this->localizationPublicId = BlogInput::publicId(
            $localizationPublicId
        );
        $this->locale = BlogInput::locale($locale);
        $this->createdByUserPublicId = BlogInput::publicId(
            $createdByUserPublicId
        );
        $this->updatedByUserPublicId = BlogInput::publicId(
            $updatedByUserPublicId
        );
        BlogInput::lockVersion($lockVersion);
        if (!in_array($status, [self::DRAFT, self::PUBLISHED], true)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        if (
            ($status === self::DRAFT && $publishedAt !== null)
            || ($status === self::PUBLISHED && $publishedAt === null)
            || ($status === self::PUBLISHED && !$draft->isPublishable())
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        $this->publishedAt = $publishedAt === null
            ? null
            : BlogInput::utc($publishedAt);
        $this->createdAt = BlogInput::utc($createdAt);
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

    public function draft(): BlogDraft
    {
        return $this->draft;
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

    public function createdByUserPublicId(): string
    {
        return $this->createdByUserPublicId;
    }

    public function updatedByUserPublicId(): string
    {
        return $this->updatedByUserPublicId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
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
            'slug' => $this->draft->slug(),
            'h1' => $this->draft->h1(),
            'seo_title' => $this->draft->seoTitle(),
            'meta_description' => $this->draft->metaDescription(),
            'excerpt' => $this->draft->excerpt(),
            'body_text' => $this->draft->bodyText(),
            'status' => $this->status,
            'published_at' => $this->publishedAt?->format('Y-m-d H:i:s.u'),
            'lock_version' => $this->lockVersion,
            'created_by_user_public_id' => $this->createdByUserPublicId,
            'updated_by_user_public_id' => $this->updatedByUserPublicId,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s.u'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s.u'),
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'post_public_id' => $this->postPublicId,
            'localization_public_id' => $this->localizationPublicId,
            'locale' => $this->locale,
            'content' => '[redacted]',
            'status' => $this->status,
            'lock_version' => $this->lockVersion,
            'published_at' => $this->publishedAt?->format(DATE_ATOM),
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
