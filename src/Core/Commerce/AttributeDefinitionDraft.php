<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class AttributeDefinitionDraft
{
    public function __construct(
        private readonly string $code,
        private readonly CommerceAttributeType $type,
        private readonly string $sourceLocale,
        private readonly string $sourceName,
        private readonly ?string $categoryPublicId = null,
        private readonly ?string $unit = null,
        private readonly bool $filterable = false,
        private readonly int $sortOrder = 0
    ) {
        CommerceInput::code($code);
        CommerceInput::locale($sourceLocale);
        CommerceInput::text($sourceName, 180);
        if ($categoryPublicId !== null) {
            CommerceInput::uuid($categoryPublicId);
        }
        if ($unit !== null) {
            CommerceInput::text($unit, 32);
        }
        if ($sortOrder < 0 || $sortOrder > 10_000) {
            throw new CommerceValidationException('Invalid attribute order.');
        }
    }

    public function code(): string { return CommerceInput::code($this->code); }
    public function type(): CommerceAttributeType { return $this->type; }
    public function sourceLocale(): string { return CommerceInput::locale($this->sourceLocale); }
    public function sourceName(): string { return CommerceInput::text($this->sourceName, 180); }
    public function categoryPublicId(): ?string { return $this->categoryPublicId === null ? null : CommerceInput::uuid($this->categoryPublicId); }
    public function unit(): ?string { return $this->unit === null ? null : CommerceInput::text($this->unit, 32); }
    public function filterable(): bool { return $this->filterable; }
    public function sortOrder(): int { return $this->sortOrder; }
}
