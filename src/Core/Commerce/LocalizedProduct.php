<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class LocalizedProduct
{
    public function __construct(
        private readonly string $publicId,
        private readonly ?string $sku,
        private readonly string $requestedLocale,
        private readonly string $resolvedLocale,
        private readonly bool $fallback,
        private readonly string $title,
        private readonly string $slug,
        private readonly ?string $summary,
        private readonly ?string $description,
        private readonly ?string $seoTitle,
        private readonly ?string $seoDescription,
        private readonly ProductEditorialStatus $editorialStatus,
        private readonly ProductAvailabilityStatus $availabilityStatus,
        private readonly ?Money $price,
        private readonly ?string $publicPath,
        private readonly int $lockVersion
    ) {
        CommerceInput::uuid($publicId);
        CommerceInput::locale($requestedLocale);
        CommerceInput::locale($resolvedLocale);
        CommerceInput::text($title, 240);
        CommerceInput::slug($slug);
        if ($publicPath !== null) {
            CommerceInput::publicPath($publicPath);
        }
        if ($lockVersion < 1) {
            throw new CommerceValidationException('Invalid product version.');
        }
    }

    public function publicId(): string { return $this->publicId; }
    public function sku(): ?string { return $this->sku; }
    public function requestedLocale(): string { return $this->requestedLocale; }
    public function resolvedLocale(): string { return $this->resolvedLocale; }
    public function isFallback(): bool { return $this->fallback; }
    public function title(): string { return $this->title; }
    public function slug(): string { return $this->slug; }
    public function summary(): ?string { return $this->summary; }
    public function description(): ?string { return $this->description; }
    public function seoTitle(): ?string { return $this->seoTitle; }
    public function seoDescription(): ?string { return $this->seoDescription; }
    public function editorialStatus(): ProductEditorialStatus { return $this->editorialStatus; }
    public function availabilityStatus(): ProductAvailabilityStatus { return $this->availabilityStatus; }
    public function price(): ?Money { return $this->price; }
    public function publicPath(): ?string { return $this->publicPath; }
    public function lockVersion(): int { return $this->lockVersion; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'public_id' => $this->publicId,
            'sku' => $this->sku,
            'requested_locale' => $this->requestedLocale,
            'resolved_locale' => $this->resolvedLocale,
            'fallback' => $this->fallback,
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'description' => $this->description,
            'seo_title' => $this->seoTitle,
            'seo_description' => $this->seoDescription,
            'editorial_status' => $this->editorialStatus->value,
            'availability_status' => $this->availabilityStatus->value,
            'price' => $this->price?->toArray(),
            'public_path' => $this->publicPath,
            'lock_version' => $this->lockVersion,
        ];
    }
}
