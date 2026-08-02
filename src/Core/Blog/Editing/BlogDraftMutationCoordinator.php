<?php

declare(strict_types=1);

namespace App\Core\Blog\Editing;

use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\Persistence\BlogRepositoryInterface;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\SystemClock;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Shared save pipeline for plain and structured Blog drafts.
 *
 * The caller owns the transaction through BlogRepositoryInterface. Hooks run
 * after authorization and row locking, and any exception rolls back the same
 * transaction. A before hook may return false for an authorized, exact no-op.
 */
final class BlogDraftMutationCoordinator
{
    public function __construct(
        private readonly BlogRepositoryInterface $repository,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly ?BlogMutationAuditPortInterface $auditPort = null
    ) {
    }

    /**
     * @param callable(PDO): string $actorGate
     * @param null|callable(PDO, BlogPostVariant, string, DateTimeImmutable): bool $beforeWrite
     * @param null|callable(PDO, BlogPostVariant, BlogPostVariant, string, DateTimeImmutable): void $afterWrite
     * @param BlogMutationAuditEvent::CREATE|BlogMutationAuditEvent::ADD_LOCALE|BlogMutationAuditEvent::SAVE|BlogMutationAuditEvent::RESTORE|BlogMutationAuditEvent::PUBLISH|BlogMutationAuditEvent::UNPUBLISH $auditOperation
     */
    public function saveWithinTransaction(
        PDO $pdo,
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion,
        #[\SensitiveParameter] BlogDraft $draft,
        ?callable $beforeWrite = null,
        ?callable $afterWrite = null,
        string $auditOperation = BlogMutationAuditEvent::SAVE
    ): BlogPostVariant {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        BlogInput::expectedLockVersion($expectedLockVersion);

        $actorPublicId = $this->authorizedActor($actorGate, $pdo);
        $now = $this->now();
        $current = $this->repository->lockVariant($postPublicId, $locale);
        if ($current === null) {
            throw new BlogException(BlogException::VARIANT_NOT_FOUND);
        }
        if ($current->lockVersion() !== $expectedLockVersion) {
            throw new BlogException(BlogException::LOCK_CONFLICT);
        }
        if ($current->status() !== BlogPostVariant::DRAFT) {
            throw new BlogException(BlogException::INVALID_STATE);
        }
        if (
            $draft->slug() !== null
            && $this->repository->slugExists(
                $locale,
                $draft->slug(),
                $current->localizationPublicId()
            )
        ) {
            throw new BlogException(BlogException::SLUG_CONFLICT);
        }

        if ($beforeWrite !== null && $beforeWrite(
            $pdo,
            $current,
            $actorPublicId,
            $now
        ) === false) {
            return $current;
        }

        if (!$this->repository->updateDraft(
            $current->localizationPublicId(),
            $expectedLockVersion,
            $draft,
            $actorPublicId,
            $now
        )) {
            throw new BlogException(BlogException::LOCK_CONFLICT);
        }
        $this->repository->touchPost($postPublicId, $now);
        $stored = $this->repository->variant($postPublicId, $locale);
        if ($stored === null) {
            throw new BlogPersistenceException();
        }

        if ($afterWrite !== null) {
            $afterWrite(
                $pdo,
                $current,
                $stored,
                $actorPublicId,
                $now
            );
        }
        $this->audit(
            $pdo,
            $auditOperation,
            $actorPublicId,
            $postPublicId,
            $now
        );

        return $stored;
    }

    /** @param callable(PDO): string $actorGate */
    private function authorizedActor(callable $actorGate, PDO $pdo): string
    {
        try {
            $actor = $actorGate($pdo);
            if (!is_string($actor)) {
                throw new \RuntimeException('Invalid gate result.');
            }

            return BlogInput::publicId($actor);
        } catch (Throwable) {
            throw new BlogException(BlogException::ACTOR_GATE_FAILED);
        }
    }

    private function now(): DateTimeImmutable
    {
        try {
            return BlogInput::utc($this->clock->now());
        } catch (Throwable) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }
    }

    private function audit(
        PDO $pdo,
        string $operation,
        string $actorPublicId,
        string $postPublicId,
        DateTimeImmutable $now
    ): void {
        if ($this->auditPort === null) {
            return;
        }
        try {
            $this->auditPort->record($pdo, new BlogMutationAuditEvent(
                $operation,
                $actorPublicId,
                $postPublicId,
                $now
            ));
        } catch (Throwable) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }
    }
}
