<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class CommercePublicTaxonomyTerm
{
    public const CATEGORY = 'category';
    public const TAG = 'tag';

    public function __construct(
        private readonly string $kind,
        private readonly string $publicId,
        private readonly ?string $parentPublicId,
        private readonly string $requestedLocale,
        private readonly string $resolvedLocale,
        private readonly bool $fallback,
        private readonly string $name,
        private readonly string $slug,
        private readonly bool $canonical = false
    ) {
        if (!in_array($kind, [self::CATEGORY, self::TAG], true)) {
            throw new CommerceValidationException('Invalid taxonomy kind.');
        }
        CommerceInput::uuid($publicId);
        if ($parentPublicId !== null) {
            CommerceInput::uuid($parentPublicId);
        }
        CommerceInput::locale($requestedLocale);
        CommerceInput::locale($resolvedLocale);
        CommerceInput::text($name, 255);
        CommerceInput::slug($slug);
        if ($kind === self::TAG && ($parentPublicId !== null || $canonical)) {
            throw new CommerceValidationException('Invalid public tag projection.');
        }
    }

    public function kind(): string { return $this->kind; }
    public function publicId(): string { return $this->publicId; }
    public function parentPublicId(): ?string { return $this->parentPublicId; }
    public function requestedLocale(): string { return $this->requestedLocale; }
    public function resolvedLocale(): string { return $this->resolvedLocale; }
    public function isFallback(): bool { return $this->fallback; }
    public function name(): string { return $this->name; }
    public function slug(): string { return $this->slug; }
    public function isCanonical(): bool { return $this->canonical; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'public_id' => $this->publicId,
            'parent_public_id' => $this->parentPublicId,
            'requested_locale' => $this->requestedLocale,
            'resolved_locale' => $this->resolvedLocale,
            'fallback' => $this->fallback,
            'name' => $this->name,
            'slug' => $this->slug,
            'canonical' => $this->canonical,
        ];
    }
}
