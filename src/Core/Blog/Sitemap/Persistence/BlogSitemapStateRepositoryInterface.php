<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Persistence;

use DateTimeImmutable;

interface BlogSitemapStateRepositoryInterface
{
    public function current(): BlogSitemapState;

    /** Caller must already own the surrounding write transaction. */
    public function lock(): BlogSitemapState;

    /** Caller must already own the surrounding write transaction. */
    public function incrementRevision(
        int $expectedRevision,
        DateTimeImmutable $now
    ): BlogSitemapState;

    /** Caller must already own the surrounding write transaction. */
    public function activateGeneration(
        string $generation,
        DateTimeImmutable $now
    ): BlogSitemapState;
}
