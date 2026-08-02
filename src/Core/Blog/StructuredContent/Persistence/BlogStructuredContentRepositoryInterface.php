<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Persistence;

use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredMediaReference;
use DateTimeImmutable;

interface BlogStructuredContentRepositoryInterface
{
    public function hasCurrent(string $localizationPublicId): bool;

    public function current(
        string $localizationPublicId
    ): ?BlogStructuredDocumentRecord;

    public function revision(
        string $revisionPublicId
    ): ?BlogStructuredRevisionRecord;

    /** @return list<BlogStructuredRevisionSummary> */
    public function listRevisions(
        string $localizationPublicId,
        int $limit,
        int $offset
    ): array;

    /**
     * Writes never own a transaction. They must be called from the active
     * transaction opened by BlogRepositoryInterface on the same PDO.
     */
    public function upsertCurrent(
        string $localizationPublicId,
        string $documentPublicId,
        BlogStructuredDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;

    /** @param list<BlogStructuredMediaReference> $references */
    public function replaceCurrentMedia(
        string $localizationPublicId,
        array $references,
        DateTimeImmutable $now
    ): void;

    /** Returns the immutable revision number allocated under the locale lock. */
    public function appendRevision(
        string $localizationPublicId,
        string $revisionPublicId,
        int $variantLockVersion,
        BlogStructuredDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int;

    /** @param list<BlogStructuredMediaReference> $references */
    public function appendRevisionMedia(
        string $revisionPublicId,
        array $references,
        DateTimeImmutable $now
    ): void;
}
