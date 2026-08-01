<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

final class EmailAddress
{
    public const MAX_BYTES = 254;
    public const MAX_LOCAL_PART_BYTES = 64;

    private function __construct(private readonly string $canonical)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidEmailAddress();
        }

        $canonical = strtolower(trim($value, ' '));
        $separator = strrpos($canonical, '@');

        if (
            $canonical === ''
            || strlen($canonical) > self::MAX_BYTES
            || $separator === false
            || $separator > self::MAX_LOCAL_PART_BYTES
            || filter_var($canonical, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidEmailAddress();
        }

        return new self($canonical);
    }

    public function value(): string
    {
        return $this->canonical;
    }

    public function equals(self $other): bool
    {
        return ConstantTime::equals($this->canonical, $other->canonical);
    }

    public function __toString(): string
    {
        return $this->canonical;
    }
}
