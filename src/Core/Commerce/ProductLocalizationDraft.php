<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class ProductLocalizationDraft
{
    private function __construct(
        private readonly string $locale,
        private readonly CommerceTranslationStatus $translationStatus,
        private readonly ?string $title,
        private readonly ?string $slug,
        private readonly ?string $summary,
        private readonly ?string $description,
        private readonly ?string $seoTitle,
        private readonly ?string $seoDescription
    ) {
    }

    public static function source(
        string $locale,
        string $title,
        string $slug,
        ?string $summary = null,
        ?string $description = null,
        ?string $seoTitle = null,
        ?string $seoDescription = null
    ): self {
        return self::content(
            $locale,
            CommerceTranslationStatus::SOURCE,
            $title,
            $slug,
            $summary,
            $description,
            $seoTitle,
            $seoDescription
        );
    }

    public static function translated(
        string $locale,
        string $title,
        string $slug,
        ?string $summary = null,
        ?string $description = null,
        ?string $seoTitle = null,
        ?string $seoDescription = null
    ): self {
        return self::content(
            $locale,
            CommerceTranslationStatus::TRANSLATED,
            $title,
            $slug,
            $summary,
            $description,
            $seoTitle,
            $seoDescription
        );
    }

    public static function fallback(string $locale): self
    {
        return new self(
            CommerceInput::locale($locale),
            CommerceTranslationStatus::FALLBACK,
            null,
            null,
            null,
            null,
            null,
            null
        );
    }

    private static function content(
        string $locale,
        CommerceTranslationStatus $status,
        string $title,
        string $slug,
        ?string $summary,
        ?string $description,
        ?string $seoTitle,
        ?string $seoDescription
    ): self {
        return new self(
            CommerceInput::locale($locale),
            $status,
            CommerceInput::text($title, 240),
            CommerceInput::slug($slug),
            CommerceInput::nullableText($summary, 2_000),
            CommerceInput::nullableText($description, 200_000),
            CommerceInput::nullableText($seoTitle, 240),
            CommerceInput::nullableText($seoDescription, 500)
        );
    }

    public function locale(): string { return $this->locale; }
    public function translationStatus(): CommerceTranslationStatus { return $this->translationStatus; }
    public function title(): ?string { return $this->title; }
    public function slug(): ?string { return $this->slug; }
    public function summary(): ?string { return $this->summary; }
    public function description(): ?string { return $this->description; }
    public function seoTitle(): ?string { return $this->seoTitle; }
    public function seoDescription(): ?string { return $this->seoDescription; }
}
