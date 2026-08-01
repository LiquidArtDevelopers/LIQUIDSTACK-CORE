<?php

declare(strict_types=1);

namespace App\Core\Blog\Diagnostics;

final class BlogDiagnosticReport
{
    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload)
    {
    }

    public function isReady(): bool
    {
        return ($this->payload['readiness']['blog_ready'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
