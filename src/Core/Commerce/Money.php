<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class Money
{
    public function __construct(
        private readonly int $minorUnits,
        private readonly string $currency
    ) {
        if ($minorUnits < 0) {
            throw new CommerceValidationException('Money cannot be negative.');
        }
        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new CommerceValidationException('Invalid ISO currency.');
        }
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /** @return array{minor_units: int, currency: string} */
    public function toArray(): array
    {
        return [
            'minor_units' => $this->minorUnits,
            'currency' => $this->currency,
        ];
    }
}
