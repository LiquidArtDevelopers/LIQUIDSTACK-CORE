<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class CommercePublicAttributeOption
{
    public function __construct(
        private readonly string $publicId,
        private readonly string $code,
        private readonly string $requestedLocale,
        private readonly string $resolvedLocale,
        private readonly bool $fallback,
        private readonly string $label
    ) {
        CommerceInput::uuid($publicId);
        CommerceInput::code($code);
        CommerceInput::locale($requestedLocale);
        CommerceInput::locale($resolvedLocale);
        CommerceInput::text($label, 255);
    }

    public function publicId(): string { return $this->publicId; }
    public function code(): string { return $this->code; }
    public function requestedLocale(): string { return $this->requestedLocale; }
    public function resolvedLocale(): string { return $this->resolvedLocale; }
    public function isFallback(): bool { return $this->fallback; }
    public function label(): string { return $this->label; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'public_id' => $this->publicId,
            'code' => $this->code,
            'requested_locale' => $this->requestedLocale,
            'resolved_locale' => $this->resolvedLocale,
            'fallback' => $this->fallback,
            'label' => $this->label,
        ];
    }
}
