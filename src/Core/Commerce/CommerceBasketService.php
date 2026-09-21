<?php

declare(strict_types=1);

namespace App\Core\Commerce;

use App\Core\Commerce\Persistence\CommerceBasketRepositoryInterface;
use DateInterval;
use DateTimeImmutable;

final class CommerceBasketService
{
    public function __construct(
        private readonly CommerceBasketRepositoryInterface $repository,
        private readonly string $primaryLocale
    ) {
        CommerceInput::locale($primaryLocale);
    }

    public function create(
        string $locale,
        DateTimeImmutable $now,
        int $ttlSeconds = 604_800
    ): BasketSnapshot {
        $locale = CommerceInput::locale($locale);
        if ($ttlSeconds < 300 || $ttlSeconds > 2_592_000) {
            throw new CommerceValidationException('Invalid basket lifetime.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $publicId = CommerceInput::newUuid();
        $expiresAt = CommerceInput::utc($now)->add(new DateInterval('PT' . $ttlSeconds . 'S'));
        $this->repository->create($publicId, $token, $locale, $expiresAt, $now);
        $snapshot = $this->repository->snapshot($token, $this->primaryLocale, $now);
        if (!$snapshot instanceof BasketSnapshot) {
            throw new CommerceException('Created basket is unavailable.');
        }

        return $snapshot;
    }

    public function put(
        string $token,
        string $productPublicId,
        int $quantity,
        DateTimeImmutable $now
    ): BasketSnapshot {
        $this->repository->put($token, $productPublicId, $quantity, $now);

        return $this->requiredSnapshot($token, $now);
    }

    public function remove(
        string $token,
        string $productPublicId,
        DateTimeImmutable $now
    ): BasketSnapshot {
        $this->repository->remove($token, $productPublicId, $now);

        return $this->requiredSnapshot($token, $now);
    }

    public function view(string $token, DateTimeImmutable $now): ?BasketSnapshot
    {
        return $this->repository->snapshot($token, $this->primaryLocale, $now);
    }

    private function requiredSnapshot(string $token, DateTimeImmutable $now): BasketSnapshot
    {
        $snapshot = $this->view($token, $now);
        if (!$snapshot instanceof BasketSnapshot) {
            throw new CommerceConflictException(CommerceConflictException::BASKET_UNAVAILABLE);
        }

        return $snapshot;
    }
}
