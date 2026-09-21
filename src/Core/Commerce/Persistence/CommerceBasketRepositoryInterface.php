<?php

declare(strict_types=1);

namespace App\Core\Commerce\Persistence;

use App\Core\Commerce\BasketSnapshot;
use DateTimeImmutable;

interface CommerceBasketRepositoryInterface
{
    public function create(
        string $publicId,
        string $token,
        string $locale,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now
    ): void;

    public function put(
        string $token,
        string $productPublicId,
        int $quantity,
        DateTimeImmutable $now
    ): void;

    public function remove(
        string $token,
        string $productPublicId,
        DateTimeImmutable $now
    ): void;

    public function snapshot(
        string $token,
        string $primaryLocale,
        DateTimeImmutable $now
    ): ?BasketSnapshot;
}
