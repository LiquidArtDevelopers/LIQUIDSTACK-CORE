<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class BasketLine
{
    public function __construct(
        private readonly LocalizedProduct $product,
        private readonly int $quantity
    ) {
        if ($quantity < 1 || $quantity > 99) {
            throw new CommerceValidationException('Invalid basket quantity.');
        }
    }

    public function product(): LocalizedProduct { return $this->product; }
    public function quantity(): int { return $this->quantity; }
}
