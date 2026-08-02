<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Editing;

use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Editing\BlogDraftMutationCoordinator;
use App\Core\Blog\Persistence\BlogPersistenceConflict;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\Persistence\BlogRepositoryInterface;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Media\BlogMediaAvailabilityPortInterface;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredContentRepositoryInterface;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredRevisionRecord;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredRevisionSummary;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use PDO;
use Throwable;

/**
 * Atomic application boundary for canonical Blog documents and revisions.
 *
 * The compatibility Blog row, current document, media references, immutable
 * revision and WebAdmin audit event always share the transaction owned by the
 * base Blog repository.
 */
final class BlogStructuredEditorService
{
    public const DEFAULT_REVISION_LIMIT = 25;

    private readonly BlogDraftMutationCoordinator $coordinator;

    public function __construct(
        private readonly BlogRepositoryInterface $blogRepository,
        private readonly BlogStructuredContentRepositoryInterface
            $contentRepository,
        private readonly BlogMediaAvailabilityPortInterface
            $mediaAvailability,
        private readonly UuidGeneratorInterface $uuidGenerator =
            new RandomUuidV4Generator(),
        ClockInterface $clock = new SystemClock(),
        ?BlogMutationAuditPortInterface $auditPort = null,
        ?BlogDraftMutationCoordinator $coordinator = null
    ) {
        $this->coordinator = $coordinator
            ?? new BlogDraftMutationCoordinator(
                $blogRepository,
                $clock,
                $auditPort
            );
    }

    public function loadEditor(
        string $postPublicId,
        string $locale
    ): BlogStructuredEditorState {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);

        return $this->read(function () use (
            $postPublicId,
            $locale
        ): BlogStructuredEditorState {
            $variant = $this->requiredVariant($postPublicId, $locale);

            return new BlogStructuredEditorState(
                $variant,
                $this->contentRepository->current(
                    $variant->localizationPublicId()
                )
            );
        });
    }

    /** @return list<BlogStructuredRevisionSummary> */
    public function listRevisions(
        string $postPublicId,
        string $locale,
        int $limit = self::DEFAULT_REVISION_LIMIT,
        int $offset = 0
    ): array {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        $limit = BlogInput::listLimit($limit);
        $offset = BlogInput::listOffset($offset);

        return $this->read(function () use (
            $postPublicId,
            $locale,
            $limit,
            $offset
        ): array {
            $variant = $this->requiredVariant($postPublicId, $locale);

            return $this->contentRepository->listRevisions(
                $variant->localizationPublicId(),
                $limit,
                $offset
            );
        });
    }

    public function loadRevision(
        string $postPublicId,
        string $locale,
        string $revisionPublicId
    ): BlogStructuredRevisionRecord {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        $revisionPublicId = BlogInput::publicId($revisionPublicId);

        return $this->read(function () use (
            $postPublicId,
            $locale,
            $revisionPublicId
        ): BlogStructuredRevisionRecord {
            $variant = $this->requiredVariant($postPublicId, $locale);
            $revision = $this->contentRepository->revision(
                $revisionPublicId
            );
            if (
                $revision === null
                || $revision->localizationPublicId()
                    !== $variant->localizationPublicId()
            ) {
                throw new BlogStructuredContentException(
                    BlogStructuredContentException::REVISION_NOT_FOUND
                );
            }

            return $revision;
        });
    }

    /** @param callable(PDO): string $actorGate */
    public function save(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion,
        #[\SensitiveParameter] BlogStructuredDraft $draft
    ): BlogPostVariant {
        return $this->writeSnapshot(
            $actorGate,
            $postPublicId,
            $locale,
            $expectedLockVersion,
            $draft,
            false,
            null,
            BlogMutationAuditEvent::SAVE
        );
    }

    /** @param callable(PDO): string $actorGate */
    public function restore(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion,
        string $revisionPublicId
    ): BlogPostVariant {
        $revision = $this->loadRevision(
            $postPublicId,
            $locale,
            $revisionPublicId
        );

        return $this->writeSnapshot(
            $actorGate,
            $postPublicId,
            $locale,
            $expectedLockVersion,
            $revision->snapshot(),
            true,
            $revision->localizationPublicId(),
            BlogMutationAuditEvent::RESTORE
        );
    }

    /**
     * @param callable(PDO): string $actorGate
     */
    private function writeSnapshot(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion,
        #[\SensitiveParameter] BlogStructuredDraft $draft,
        bool $forceRevision,
        ?string $expectedLocalizationPublicId,
        string $auditOperation
    ): BlogPostVariant {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        BlogInput::expectedLockVersion($expectedLockVersion);
        $currentDocument = null;

        return $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $postPublicId,
            $locale,
            $expectedLockVersion,
            $draft,
            $forceRevision,
            $expectedLocalizationPublicId,
            $auditOperation,
            &$currentDocument
        ): BlogPostVariant {
            return $this->coordinator->saveWithinTransaction(
                $pdo,
                $actorGate,
                $postPublicId,
                $locale,
                $expectedLockVersion,
                $draft->compatibilityDraft(),
                function (
                    PDO $transaction,
                    BlogPostVariant $current
                ) use (
                    $draft,
                    $forceRevision,
                    $expectedLocalizationPublicId,
                    &$currentDocument
                ): bool {
                    if (
                        $expectedLocalizationPublicId !== null
                        && $expectedLocalizationPublicId
                            !== $current->localizationPublicId()
                    ) {
                        throw new BlogStructuredContentException(
                            BlogStructuredContentException::REVISION_NOT_FOUND
                        );
                    }
                    $this->mediaAvailability->assertAvailable(
                        $transaction,
                        $draft->mediaAssetPublicIds()
                    );
                    $currentDocument = $this->contentRepository->current(
                        $current->localizationPublicId()
                    );

                    return $forceRevision
                        || $currentDocument === null
                        || !$this->sameSnapshot(
                            $currentDocument->snapshot(),
                            $draft
                        );
                },
                function (
                    PDO $transaction,
                    BlogPostVariant $before,
                    BlogPostVariant $stored,
                    string $actorPublicId,
                    \DateTimeImmutable $now
                ) use ($draft, &$currentDocument): void {
                    if ($transaction->inTransaction() === false) {
                        throw new BlogStructuredContentException(
                            BlogStructuredContentException::STORAGE_UNAVAILABLE
                        );
                    }
                    $localization = $stored->localizationPublicId();
                    if ($localization !== $before->localizationPublicId()) {
                        throw new BlogStructuredContentException(
                            BlogStructuredContentException::STORAGE_UNAVAILABLE
                        );
                    }
                    $documentPublicId = $currentDocument === null
                        ? $this->newPublicId()
                        : $currentDocument->documentPublicId();
                    $this->contentRepository->upsertCurrent(
                        $localization,
                        $documentPublicId,
                        $draft,
                        $actorPublicId,
                        $now
                    );
                    $this->contentRepository->replaceCurrentMedia(
                        $localization,
                        $draft->mediaReferences(),
                        $now
                    );
                    $revisionPublicId = $this->newPublicId();
                    $this->contentRepository->appendRevision(
                        $localization,
                        $revisionPublicId,
                        $stored->lockVersion(),
                        $draft,
                        $actorPublicId,
                        $now
                    );
                    $this->contentRepository->appendRevisionMedia(
                        $revisionPublicId,
                        $draft->mediaReferences(),
                        $now
                    );
                },
                $auditOperation
            );
        });
    }

    private function sameSnapshot(
        BlogStructuredDraft $first,
        BlogStructuredDraft $second
    ): bool {
        if (!hash_equals($first->snapshotSha256(), $second->snapshotSha256())) {
            return false;
        }
        $a = $first->compatibilityDraft();
        $b = $second->compatibilityDraft();

        return hash_equals($first->canonicalJson(), $second->canonicalJson())
            && $a->h1() === $b->h1()
            && $a->slug() === $b->slug()
            && $a->seoTitle() === $b->seoTitle()
            && $a->metaDescription() === $b->metaDescription()
            && $a->excerpt() === $b->excerpt()
            && $a->bodyText() === $b->bodyText();
    }

    private function requiredVariant(
        string $postPublicId,
        string $locale
    ): BlogPostVariant {
        $variant = $this->blogRepository->variant($postPublicId, $locale);
        if ($variant === null) {
            throw new BlogException(BlogException::VARIANT_NOT_FOUND);
        }

        return $variant;
    }

    /** @template T @param callable(): T $operation @return T */
    private function read(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (BlogException|BlogStructuredContentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::STORAGE_UNAVAILABLE
            );
        }
    }

    /** @template T @param callable(PDO): T $operation @return T */
    private function mutate(callable $operation): mixed
    {
        try {
            return $this->blogRepository->transactional($operation);
        } catch (BlogPersistenceConflict $exception) {
            throw new BlogException(
                $exception->kind() === BlogPersistenceConflict::SLUG
                    ? BlogException::SLUG_CONFLICT
                    : BlogException::LOCALE_CONFLICT
            );
        } catch (BlogException|BlogStructuredContentException $exception) {
            throw $exception;
        } catch (BlogPersistenceException|Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::STORAGE_UNAVAILABLE
            );
        }
    }

    private function newPublicId(): string
    {
        try {
            return BlogInput::generatedPublicId(
                $this->uuidGenerator->generateV4()
            );
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::STORAGE_UNAVAILABLE
            );
        }
    }
}
