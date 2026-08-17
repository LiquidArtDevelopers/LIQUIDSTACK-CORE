<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use DateTimeImmutable;
use InvalidArgumentException;

/** Public-safe, live author signature for the canonical Blog hero. */
final class BlogArticleHeaderAttribution
{
    public function __construct(
        private readonly string $displayName,
        private readonly string $roleLabel,
        private readonly string $localizedDate,
        private readonly DateTimeImmutable $publishedAt
    ) {
        if (
            trim($displayName) === ''
            || trim($roleLabel) === ''
            || trim($localizedDate) === ''
        ) {
            throw new InvalidArgumentException('Invalid Blog attribution.');
        }
    }

    public function displayName(): string { return $this->displayName; }
    public function roleLabel(): string { return $this->roleLabel; }
    public function localizedDate(): string { return $this->localizedDate; }
    public function publishedAt(): DateTimeImmutable { return $this->publishedAt; }
}
