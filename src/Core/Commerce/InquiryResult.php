<?php

declare(strict_types=1);

namespace App\Core\Commerce;

use DateTimeImmutable;

final class InquiryResult
{
    public function __construct(
        private readonly string $publicId,
        private readonly bool $replayed,
        private readonly int $lineCount,
        private readonly DateTimeImmutable $createdAt
    ) {
        CommerceInput::uuid($publicId);
        if ($lineCount < 1 || $lineCount > 50) {
            throw new CommerceValidationException('Invalid inquiry line count.');
        }
    }

    public function publicId(): string { return $this->publicId; }
    public function replayed(): bool { return $this->replayed; }
    public function lineCount(): int { return $this->lineCount; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
}
