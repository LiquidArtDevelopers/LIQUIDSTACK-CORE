<?php

declare(strict_types=1);

namespace App\Core\Commerce;

enum ProductAvailabilityStatus: string
{
    case AVAILABLE = 'available';
    case RESERVED = 'reserved';
    case SOLD = 'sold';
    case UNAVAILABLE = 'unavailable';

    public function acceptsInquiries(): bool
    {
        return $this === self::AVAILABLE || $this === self::RESERVED;
    }
}
