<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class CommercePublicMediaReference
{
    /**
     * @param list<array{width:int,height:int,path:string}> $variants
     */
    public function __construct(
        private readonly string $publicId,
        private readonly string $role,
        private readonly int $sortOrder,
        private readonly string $requestedLocale,
        private readonly string $resolvedLocale,
        private readonly bool $fallback,
        private readonly string $altText,
        private readonly ?string $caption,
        private readonly array $variants
    ) {
        CommerceInput::uuid($publicId);
        if (!in_array($role, ['cover', 'gallery'], true)) {
            throw new CommerceValidationException('Invalid media role.');
        }
        if ($sortOrder < 0 || $sortOrder > 10_000) {
            throw new CommerceValidationException('Invalid media order.');
        }
        CommerceInput::locale($requestedLocale);
        CommerceInput::locale($resolvedLocale);
        CommerceInput::text($altText, 500);
        if ($caption !== null) {
            CommerceInput::text($caption, 2_000);
        }
        if ($variants === [] || !array_is_list($variants) || count($variants) > 9) {
            throw new CommerceValidationException('Invalid media variants.');
        }
        $previousWidth = 0;
        foreach ($variants as $variant) {
            if (
                !is_array($variant)
                || array_keys($variant) !== ['width', 'height', 'path']
                || !is_int($variant['width'])
                || !is_int($variant['height'])
                || !is_string($variant['path'])
                || $variant['width'] <= $previousWidth
                || $variant['width'] > 2_560
                || $variant['height'] < 1
                || $variant['height'] > 2_560
                || !str_starts_with($variant['path'], '/')
                || str_starts_with($variant['path'], '//')
            ) {
                throw new CommerceValidationException('Invalid media variants.');
            }
            $previousWidth = $variant['width'];
        }
    }

    public function publicId(): string { return $this->publicId; }
    public function role(): string { return $this->role; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function requestedLocale(): string { return $this->requestedLocale; }
    public function resolvedLocale(): string { return $this->resolvedLocale; }
    public function isFallback(): bool { return $this->fallback; }
    public function altText(): string { return $this->altText; }
    public function caption(): ?string { return $this->caption; }

    /** @return list<array{width:int,height:int,path:string}> */
    public function variants(): array { return $this->variants; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'public_id' => $this->publicId,
            'role' => $this->role,
            'sort_order' => $this->sortOrder,
            'requested_locale' => $this->requestedLocale,
            'resolved_locale' => $this->resolvedLocale,
            'fallback' => $this->fallback,
            'alt_text' => $this->altText,
            'caption' => $this->caption,
            'variants' => $this->variants,
        ];
    }
}
