<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Profile;

use DateTimeZone;
use InvalidArgumentException;

/** Validated IANA time-zone identifier. UTC is the only safe fallback. */
final class WebAdminTimeZone
{
    private const MAX_BYTES = 64;

    private function __construct(private readonly string $value)
    {
    }

    public static function fromIana(string $value): self
    {
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > self::MAX_BYTES
            || preg_match('/\A[A-Za-z0-9_+\/-]+\z/D', $value) !== 1
            || !in_array(
                $value,
                DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
                true
            )
        ) {
            throw new InvalidArgumentException('Invalid IANA time zone.');
        }

        return new self($value);
    }

    public static function utc(): self
    {
        return new self('UTC');
    }

    public function value(): string
    {
        return $this->value;
    }
}
