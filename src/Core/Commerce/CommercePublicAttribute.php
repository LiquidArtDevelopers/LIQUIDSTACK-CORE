<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class CommercePublicAttribute
{
    /**
     * Number values remain canonical decimal strings; select values are
     * represented by typed options instead of untrusted presentation labels.
     *
     * @param string|bool|list<CommercePublicAttributeOption> $value
     */
    public function __construct(
        private readonly string $publicId,
        private readonly string $code,
        private readonly CommerceAttributeType $type,
        private readonly string $requestedLocale,
        private readonly string $resolvedLocale,
        private readonly bool $fallback,
        private readonly string $name,
        private readonly ?string $unit,
        private readonly bool $filterable,
        private readonly int $sortOrder,
        private readonly ?string $valueResolvedLocale,
        private readonly bool $valueFallback,
        private readonly string|bool|array $value
    ) {
        CommerceInput::uuid($publicId);
        CommerceInput::code($code);
        CommerceInput::locale($requestedLocale);
        CommerceInput::locale($resolvedLocale);
        CommerceInput::text($name, 255);
        if ($unit !== null) {
            CommerceInput::text($unit, 32);
        }
        if ($sortOrder < 0 || $sortOrder > 10_000) {
            throw new CommerceValidationException('Invalid attribute order.');
        }
        if ($valueResolvedLocale !== null) {
            CommerceInput::locale($valueResolvedLocale);
        }
        if (
            ($type === CommerceAttributeType::TEXT)
                !== ($valueResolvedLocale !== null)
            || ($valueFallback && $type !== CommerceAttributeType::TEXT)
        ) {
            throw new CommerceValidationException(
                'Invalid localized attribute value.'
            );
        }
        $this->assertTypedValue($type, $value);
    }

    public function publicId(): string { return $this->publicId; }
    public function code(): string { return $this->code; }
    public function type(): CommerceAttributeType { return $this->type; }
    public function requestedLocale(): string { return $this->requestedLocale; }
    public function resolvedLocale(): string { return $this->resolvedLocale; }
    public function isFallback(): bool { return $this->fallback; }
    public function name(): string { return $this->name; }
    public function unit(): ?string { return $this->unit; }
    public function isFilterable(): bool { return $this->filterable; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function valueResolvedLocale(): ?string { return $this->valueResolvedLocale; }
    public function isValueFallback(): bool { return $this->valueFallback; }

    /** @return string|bool|list<CommercePublicAttributeOption> */
    public function value(): string|bool|array { return $this->value; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'public_id' => $this->publicId,
            'code' => $this->code,
            'type' => $this->type->value,
            'requested_locale' => $this->requestedLocale,
            'resolved_locale' => $this->resolvedLocale,
            'fallback' => $this->fallback,
            'name' => $this->name,
            'unit' => $this->unit,
            'filterable' => $this->filterable,
            'sort_order' => $this->sortOrder,
            'value_resolved_locale' => $this->valueResolvedLocale,
            'value_fallback' => $this->valueFallback,
            'value' => is_array($this->value)
                ? array_map(
                    static fn (CommercePublicAttributeOption $option): array =>
                        $option->toArray(),
                    $this->value
                )
                : $this->value,
        ];
    }

    /** @param string|bool|list<CommercePublicAttributeOption> $value */
    private function assertTypedValue(
        CommerceAttributeType $type,
        string|bool|array $value
    ): void {
        if ($type === CommerceAttributeType::BOOLEAN && is_bool($value)) {
            return;
        }
        if (in_array($type, [
            CommerceAttributeType::TEXT,
            CommerceAttributeType::NUMBER,
            CommerceAttributeType::DATE,
        ], true) && is_string($value) && $value !== '') {
            return;
        }
        if (
            in_array($type, [
                CommerceAttributeType::SELECT,
                CommerceAttributeType::MULTISELECT,
            ], true)
            && is_array($value)
            && array_is_list($value)
            && $value !== []
            && count($value) <= 50
        ) {
            foreach ($value as $option) {
                if (!$option instanceof CommercePublicAttributeOption) {
                    throw new CommerceValidationException(
                        'Invalid public attribute option.'
                    );
                }
            }
            if ($type === CommerceAttributeType::SELECT && count($value) !== 1) {
                throw new CommerceValidationException(
                    'Invalid public select attribute.'
                );
            }
            return;
        }

        throw new CommerceValidationException('Invalid public attribute value.');
    }
}
