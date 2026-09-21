<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class CategoryLocalizationDraft
{
    private function __construct(
        private readonly string $locale,
        private readonly CommerceTranslationStatus $translationStatus,
        private readonly ?string $name,
        private readonly ?string $slug
    ) {
    }

    public static function source(string $locale, string $name, string $slug): self
    {
        return self::content($locale, CommerceTranslationStatus::SOURCE, $name, $slug);
    }

    public static function translated(string $locale, string $name, string $slug): self
    {
        return self::content($locale, CommerceTranslationStatus::TRANSLATED, $name, $slug);
    }

    public static function fallback(string $locale): self
    {
        return new self(
            CommerceInput::locale($locale),
            CommerceTranslationStatus::FALLBACK,
            null,
            null
        );
    }

    private static function content(
        string $locale,
        CommerceTranslationStatus $status,
        string $name,
        string $slug
    ): self {
        return new self(
            CommerceInput::locale($locale),
            $status,
            CommerceInput::text($name, 180),
            CommerceInput::slug($slug)
        );
    }

    public function locale(): string { return $this->locale; }
    public function translationStatus(): CommerceTranslationStatus { return $this->translationStatus; }
    public function name(): ?string { return $this->name; }
    public function slug(): ?string { return $this->slug; }
}
