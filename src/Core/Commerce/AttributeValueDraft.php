<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class AttributeValueDraft
{
    /** @param list<string> $optionPublicIds */
    private function __construct(
        private readonly CommerceAttributeType $type,
        private readonly ?string $locale,
        private readonly ?string $textValue,
        private readonly ?string $numberValue,
        private readonly ?bool $booleanValue,
        private readonly ?string $dateValue,
        private readonly array $optionPublicIds
    ) {
    }

    public static function text(string $locale, string $value): self
    {
        return new self(
            CommerceAttributeType::TEXT,
            CommerceInput::locale($locale),
            CommerceInput::text($value, 4_000),
            null,
            null,
            null,
            []
        );
    }

    public static function number(string $value): self
    {
        $value = trim($value);
        if (preg_match('/\A-?(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,6})?\z/', $value) !== 1) {
            throw new CommerceValidationException('Invalid decimal attribute value.');
        }

        return new self(CommerceAttributeType::NUMBER, null, null, $value, null, null, []);
    }

    public static function boolean(bool $value): self
    {
        return new self(CommerceAttributeType::BOOLEAN, null, null, null, $value, null, []);
    }

    public static function date(string $value): self
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof \DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $value
        ) {
            throw new CommerceValidationException('Invalid date attribute value.');
        }

        return new self(CommerceAttributeType::DATE, null, null, null, null, $value, []);
    }

    public static function select(string $optionPublicId): self
    {
        return new self(
            CommerceAttributeType::SELECT,
            null,
            null,
            null,
            null,
            null,
            [CommerceInput::uuid($optionPublicId)]
        );
    }

    /** @param list<string> $optionPublicIds */
    public static function multiselect(array $optionPublicIds): self
    {
        $normalized = [];
        foreach ($optionPublicIds as $publicId) {
            if (!is_string($publicId)) {
                throw new CommerceValidationException('Invalid attribute option.');
            }
            $normalized[CommerceInput::uuid($publicId)] = true;
        }
        if ($normalized === [] || count($normalized) > 50) {
            throw new CommerceValidationException('Invalid attribute option count.');
        }

        return new self(
            CommerceAttributeType::MULTISELECT,
            null,
            null,
            null,
            null,
            null,
            array_keys($normalized)
        );
    }

    public function type(): CommerceAttributeType { return $this->type; }
    public function locale(): ?string { return $this->locale; }
    public function storageLocale(): string { return $this->locale ?? ''; }
    public function textValue(): ?string { return $this->textValue; }
    public function numberValue(): ?string { return $this->numberValue; }
    public function booleanValue(): ?bool { return $this->booleanValue; }
    public function dateValue(): ?string { return $this->dateValue; }
    /** @return list<string> */
    public function optionPublicIds(): array { return $this->optionPublicIds; }
}
