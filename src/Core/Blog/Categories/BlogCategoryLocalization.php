<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories;

use DateTimeImmutable;

/** Administrative category projection containing public identifiers only. */
final class BlogCategoryLocalization
{
    private readonly string $categoryPublicId;
    private readonly string $localizationPublicId;
    private readonly string $locale;
    private readonly BlogCategoryDraft $draft;
    private readonly DateTimeImmutable $updatedAt;

    public function __construct(
        string $categoryPublicId,
        string $localizationPublicId,
        string $locale,
        BlogCategoryDraft $draft,
        private readonly int $lockVersion,
        DateTimeImmutable $updatedAt
    ) {
        $this->categoryPublicId = BlogCategoryInput::publicId(
            $categoryPublicId
        );
        $this->localizationPublicId = BlogCategoryInput::publicId(
            $localizationPublicId
        );
        $this->locale = BlogCategoryInput::locale($locale);
        BlogCategoryInput::expectedLockVersion($lockVersion);
        $this->draft = $draft;
        $this->updatedAt = BlogCategoryInput::utc($updatedAt);
    }

    public function categoryPublicId(): string { return $this->categoryPublicId; }
    public function localizationPublicId(): string { return $this->localizationPublicId; }
    public function locale(): string { return $this->locale; }
    public function draft(): BlogCategoryDraft { return $this->draft; }
    public function lockVersion(): int { return $this->lockVersion; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'category_public_id' => $this->categoryPublicId,
            'localization_public_id' => $this->localizationPublicId,
            'locale' => $this->locale,
            'slug' => $this->draft->slug(),
            'name' => $this->draft->name(),
            'lock_version' => $this->lockVersion,
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s.u'),
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return array_replace($this->toArray(), [
            'slug' => '[redacted]',
            'name' => '[redacted]',
        ]);
    }
}
