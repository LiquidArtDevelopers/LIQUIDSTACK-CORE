<?php

declare(strict_types=1);

namespace App\Core\Commerce;

use DateTimeImmutable;

final class BasketSnapshot
{
    /** @param list<BasketLine> $lines */
    public function __construct(
        private readonly string $publicId,
        private readonly string $token,
        private readonly string $locale,
        private readonly string $status,
        private readonly DateTimeImmutable $expiresAt,
        private readonly array $lines
    ) {
        CommerceInput::uuid($publicId);
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
            throw new CommerceValidationException('Invalid basket token.');
        }
        CommerceInput::locale($locale);
        if (!in_array($status, ['open', 'submitted', 'expired'], true)) {
            throw new CommerceValidationException('Invalid basket status.');
        }
        foreach ($lines as $line) {
            if (!$line instanceof BasketLine) {
                throw new CommerceValidationException('Invalid basket line.');
            }
        }
    }

    public function publicId(): string { return $this->publicId; }
    public function token(): string { return $this->token; }
    public function locale(): string { return $this->locale; }
    public function status(): string { return $this->status; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    /** @return list<BasketLine> */
    public function lines(): array { return $this->lines; }
}
