<?php

declare(strict_types=1);

namespace App\Core\Blog\Persistence;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogSitemapEntry;
use DateTimeImmutable;
use PDO;

interface BlogRepositoryInterface
{
    /**
     * @template T
     * @param callable(PDO): T $operation
     * @return T
     *
     * The callback receives the exact connection after the repository has
     * opened its write transaction. It must not commit, roll back or start a
     * nested transaction. This is the integration point for the WebAdmin
     * actor/capability gate before any Blog lock or write.
     */
    public function transactional(callable $operation): mixed;

    public function insertPost(
        string $postPublicId,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;

    public function lockPost(string $postPublicId): bool;

    public function insertLocalization(
        string $localizationPublicId,
        string $postPublicId,
        string $locale,
        BlogDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;

    public function lockVariant(
        string $postPublicId,
        string $locale
    ): ?BlogPostVariant;

    public function slugExists(
        string $locale,
        string $slug,
        ?string $exceptLocalizationPublicId = null
    ): bool;

    public function updateDraft(
        string $localizationPublicId,
        int $expectedLockVersion,
        BlogDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool;

    public function updateStatus(
        string $localizationPublicId,
        int $expectedLockVersion,
        string $expectedStatus,
        string $nextStatus,
        ?DateTimeImmutable $publishedAt,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool;

    public function touchPost(
        string $postPublicId,
        DateTimeImmutable $now
    ): void;

    /** @return list<BlogPostSummary> */
    public function listSummaries(int $limit, int $offset): array;

    public function variant(
        string $postPublicId,
        string $locale
    ): ?BlogPostVariant;

    public function publishedVariant(
        string $locale,
        string $slug
    ): ?BlogPostVariant;

    /** @return list<BlogSitemapEntry> */
    public function sitemapEntries(int $limit): array;
}
