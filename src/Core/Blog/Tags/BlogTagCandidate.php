<?php

declare(strict_types=1);

namespace App\Core\Blog\Tags;

/** Normalized, non-persisted tag supplied by one assignment request. */
final class BlogTagCandidate
{
    public function __construct(
        private readonly string $name,
        private readonly string $baseSlug,
        private readonly string $normalizedSha256
    ) {
        BlogTagInput::name($name);
        BlogTagInput::slug($baseSlug);
        BlogTagInput::normalizedSha256($normalizedSha256);
    }

    public function name(): string { return $this->name; }
    public function baseSlug(): string { return $this->baseSlug; }
    public function normalizedSha256(): string
    {
        return $this->normalizedSha256;
    }
}
