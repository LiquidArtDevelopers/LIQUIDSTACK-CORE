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
use App\Core\Blog\EditorialWorkflow\BlogEditorialWorkspaceState;
use App\Core\Blog\EditorialWorkflow\Persistence\BlogEditorialWorkspaceRepositoryInterface;
use App\Core\Blog\Persistence\BlogPersistenceConflict;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\Persistence\BlogRepositoryInterface;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentValidator;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV2Projector;
use App\Core\Blog\StructuredContent\Media\BlogMediaAvailabilityPortInterface;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredContentRepositoryInterface;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredRevisionRecord;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredRevisionSummary;
use App\Core\Blog\Sitemap\BlogSitemapPublicationCoordinator;
use App\Core\Blog\Sitemap\BlogSitemapPublicationFence;
use App\Core\Blog\Seo\BlogUrlHistoryRepositoryInterface;
use App\Core\Blog\Tags\BlogTag;
use App\Core\Blog\Tags\BlogTagInput;
use App\Core\Blog\Tags\Persistence\BlogTagPersistenceConflict;
use App\Core\Blog\Tags\Persistence\BlogTagRepositoryInterface;
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
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly ?BlogMutationAuditPortInterface $auditPort = null,
        ?BlogDraftMutationCoordinator $coordinator = null,
        private readonly bool $layoutReady = false,
        private readonly BlogDocumentV2Projector $layoutProjector =
            new BlogDocumentV2Projector(),
        private readonly ?BlogEditorialWorkspaceRepositoryInterface
            $workflowRepository = null,
        private readonly ?BlogSitemapPublicationCoordinator
            $sitemapPublicationCoordinator = null,
        private readonly BlogDocumentValidator $publicationDocumentValidator =
            new BlogDocumentValidator(),
        private readonly ?BlogUrlHistoryRepositoryInterface $urlHistory = null,
        private readonly ?BlogTagRepositoryInterface $tagRepository = null
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
            $workspace = $this->workflowRepository?->workspace(
                $variant->localizationPublicId()
            );
            $privateDraft = null;
            if ($workspace?->draftRevisionPublicId() !== null) {
                $privateDraft = $this->contentRepository->revision(
                    $workspace->draftRevisionPublicId()
                );
                if (
                    $privateDraft === null
                    || $privateDraft->localizationPublicId()
                        !== $variant->localizationPublicId()
                ) {
                    throw new BlogStructuredContentException(
                        BlogStructuredContentException::STORAGE_UNAVAILABLE
                    );
                }
            }

            return new BlogStructuredEditorState(
                $variant,
                $this->contentRepository->current(
                    $variant->localizationPublicId()
                ),
                $privateDraft,
                $workspace
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
        if ($this->layoutReady) {
            $draft = $this->projectLayoutSnapshot($draft);
        }

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
        $snapshot = $revision->snapshot();
        if ($this->layoutReady) {
            $snapshot = $this->projectLayoutSnapshot($snapshot);
        }

        return $this->writeSnapshot(
            $actorGate,
            $postPublicId,
            $locale,
            $expectedLockVersion,
            $snapshot,
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
        if (
            $this->workflowRepository !== null
            && $this->read(
                fn (): BlogPostVariant =>
                    $this->requiredVariant($postPublicId, $locale)
            )->status() === BlogPostVariant::PUBLISHED
        ) {
            return $this->writePrivateSnapshot(
                $actorGate,
                $postPublicId,
                $locale,
                $expectedLockVersion,
                $draft,
                $forceRevision,
                $expectedLocalizationPublicId,
                $auditOperation
            );
        }
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
                    if (
                        $this->layoutReady
                        && !$forceRevision
                        && $currentDocument !== null
                        && $currentDocument->snapshot()->schemaVersion()
                            === BlogDocument::LAYOUT_VERSION
                        && $draft->schemaVersion() === BlogDocument::VERSION
                    ) {
                        throw new BlogStructuredContentException(
                            BlogStructuredContentException::INVALID_INPUT
                        );
                    }

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

    /**
     * Saves an immutable private revision while the published projection stays
     * byte-for-byte untouched. The localization lock is the editorial ETag.
     *
     * @param callable(PDO): string $actorGate
     */
    private function writePrivateSnapshot(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion,
        #[\SensitiveParameter] BlogStructuredDraft $draft,
        bool $forceRevision,
        ?string $expectedLocalizationPublicId,
        string $auditOperation
    ): BlogPostVariant {
        $workflow = $this->requiredWorkflowRepository();

        return $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $postPublicId,
            $locale,
            $expectedLockVersion,
            $draft,
            $forceRevision,
            $expectedLocalizationPublicId,
            $auditOperation,
            $workflow
        ): BlogPostVariant {
            $actorPublicId = $this->authorizedActor($actorGate, $pdo);
            $now = $this->now();
            if (!$this->blogRepository->lockPost($postPublicId)) {
                throw new BlogException(BlogException::POST_NOT_FOUND);
            }
            $state = $workflow->variantState($postPublicId, $locale, true);
            if ($state === null) {
                throw new BlogException(BlogException::VARIANT_NOT_FOUND);
            }
            if ($state->lockVersion() !== $expectedLockVersion) {
                throw new BlogException(BlogException::LOCK_CONFLICT);
            }
            if ($state->status() !== BlogPostVariant::PUBLISHED) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            if (
                $expectedLocalizationPublicId !== null
                && $expectedLocalizationPublicId
                    !== $state->localizationPublicId()
            ) {
                throw new BlogStructuredContentException(
                    BlogStructuredContentException::REVISION_NOT_FOUND
                );
            }
            $this->mediaAvailability->assertAvailable(
                $pdo,
                $draft->mediaAssetPublicIds()
            );
            $workspace = $workflow->workspace(
                $state->localizationPublicId(),
                true
            );
            $head = $workflow->publicationHead(
                $state->localizationPublicId(),
                true
            );
            $publicationVersion = $head?->publicationVersion() ?? 0;
            $this->assertWorkspaceBase($workspace, $publicationVersion);
            $working = $this->workingSnapshot(
                $state->localizationPublicId(),
                $workspace
            );
            if (
                $this->layoutReady
                && !$forceRevision
                && $working !== null
                && $working->schemaVersion() === BlogDocument::LAYOUT_VERSION
                && $draft->schemaVersion() === BlogDocument::VERSION
            ) {
                throw new BlogStructuredContentException(
                    BlogStructuredContentException::INVALID_INPUT
                );
            }
            if (
                !$forceRevision
                && $working !== null
                && $this->sameSnapshot($working, $draft)
            ) {
                return $this->requiredStoredVariant($postPublicId, $locale);
            }

            $revisionPublicId = $this->newPublicId();
            $this->contentRepository->appendPrivateRevision(
                $state->localizationPublicId(),
                $revisionPublicId,
                $expectedLockVersion,
                $draft,
                $actorPublicId,
                $now
            );
            $this->contentRepository->appendRevisionMedia(
                $revisionPublicId,
                $draft->mediaReferences(),
                $now
            );
            $workflow->storeDraftRevision(
                $state->localizationPublicId(),
                $revisionPublicId,
                $publicationVersion,
                $actorPublicId,
                $now
            );
            if (!$workflow->advancePrivateLock(
                $state->localizationPublicId(),
                $expectedLockVersion,
                $actorPublicId
            )) {
                throw new BlogException(BlogException::LOCK_CONFLICT);
            }
            $this->auditMutation(
                $pdo,
                $auditOperation,
                $actorPublicId,
                $postPublicId,
                $now
            );

            return $this->requiredStoredVariant($postPublicId, $locale);
        });
    }

    /**
     * Publishes the saved private head in one transaction. No GET endpoint
     * calls this method and no public projection changes before the fence.
     *
     * @param callable(PDO): string $actorGate
     * @param null|callable(string, string): void $publicationRouteGate
     */
    public function publishSaved(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion,
        int $expectedCategoryWorkspaceVersion,
        #[\SensitiveParameter] ?callable $publicationRouteGate = null,
        int $expectedTagWorkspaceVersion = 0
    ): BlogPostVariant {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        BlogInput::expectedLockVersion($expectedLockVersion);
        if ($expectedCategoryWorkspaceVersion < 0) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        BlogTagInput::workspaceVersion($expectedTagWorkspaceVersion);
        if ($this->tagRepository === null && $expectedTagWorkspaceVersion !== 0) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        $workflow = $this->requiredWorkflowRepository();
        $sitemapFence = null;

        try {
            return $this->mutate(function (PDO $pdo) use (
                $actorGate,
                $postPublicId,
                $locale,
                $expectedLockVersion,
                $expectedCategoryWorkspaceVersion,
                $expectedTagWorkspaceVersion,
                $workflow,
                $publicationRouteGate,
                &$sitemapFence
            ): BlogPostVariant {
                $actorPublicId = $this->authorizedActor($actorGate, $pdo);
                $now = $this->now();
                if (!$this->blogRepository->lockPost($postPublicId)) {
                    throw new BlogException(BlogException::POST_NOT_FOUND);
                }
                $state = $workflow->variantState(
                    $postPublicId,
                    $locale,
                    true
                );
                if ($state === null) {
                    throw new BlogException(BlogException::VARIANT_NOT_FOUND);
                }
                if ($state->lockVersion() !== $expectedLockVersion) {
                    throw new BlogException(BlogException::LOCK_CONFLICT);
                }
                $workspace = $workflow->workspace(
                    $state->localizationPublicId(),
                    true
                );
                $head = $workflow->publicationHead(
                    $state->localizationPublicId(),
                    true
                );
                $publicationVersion = $head?->publicationVersion() ?? 0;
                $this->assertWorkspaceBase($workspace, $publicationVersion);
                $categoryWorkspaceVersion = $workflow
                    ->categoryWorkspaceVersion($postPublicId, true);
                if (
                    $categoryWorkspaceVersion
                        !== $expectedCategoryWorkspaceVersion
                ) {
                    throw new BlogException(BlogException::LOCK_CONFLICT);
                }
                $categoryAssignmentVersion = $workflow
                    ->categoryAssignmentVersion($postPublicId, true);
                $tagWorkspace = $this->tagRepository?->workspaceState(
                    $state->localizationPublicId(),
                    true
                );
                $tagWorkspaceVersion = $tagWorkspace?->workspaceVersion() ?? 0;
                if ($tagWorkspaceVersion !== $expectedTagWorkspaceVersion) {
                    throw new BlogException(BlogException::LOCK_CONFLICT);
                }
                $tagAssignmentVersion = $this->tagRepository?->assignmentVersion(
                    $state->localizationPublicId(),
                    true
                ) ?? 0;
                if (
                    $tagWorkspace !== null
                    && $tagWorkspace->baseAssignmentVersion()
                        !== $tagAssignmentVersion
                ) {
                    throw new BlogException(BlogException::LOCK_CONFLICT);
                }
                $draft = $this->workingSnapshot(
                    $state->localizationPublicId(),
                    $workspace
                );
                if ($draft === null) {
                    throw new BlogStructuredContentException(
                        BlogStructuredContentException::INVALID_INPUT
                    );
                }
                try {
                    $this->publicationDocumentValidator->validate(
                        $draft->document()->toArray()
                    );
                } catch (BlogDocumentException) {
                    throw new BlogException(
                        BlogException::PUBLISH_INCOMPLETE
                    );
                }
                $compatibility = $draft->compatibilityDraft();
                if (!$compatibility->isPublishable()) {
                    throw new BlogException(BlogException::PUBLISH_INCOMPLETE);
                }
                $slug = $compatibility->slug();
                if ($slug === null) {
                    throw new BlogException(BlogException::PUBLISH_INCOMPLETE);
                }
                if ($publicationRouteGate !== null) {
                    $publicationRouteGate($locale, $slug);
                }
                if ($this->blogRepository->slugExists(
                    $locale,
                    $slug,
                    $state->localizationPublicId()
                )) {
                    throw new BlogException(BlogException::SLUG_CONFLICT);
                }
                $this->mediaAvailability->assertAvailable(
                    $pdo,
                    $draft->mediaAssetPublicIds()
                );
                $current = $this->contentRepository->current(
                    $state->localizationPublicId()
                );
                $categoryPublicIds = null;
                if ($categoryWorkspaceVersion > 0) {
                    $categoryPublicIds = $workflow
                        ->workspaceCategoryPublicIds($postPublicId);
                    if ($categoryPublicIds === null) {
                        throw new BlogStructuredContentException(
                            BlogStructuredContentException::STORAGE_UNAVAILABLE
                        );
                    }
                }
                $tagPublicIds = null;
                if ($tagWorkspace !== null) {
                    $tagPublicIds = $this->tagPublicIds(
                        $this->tagRepository?->workspaceTags(
                            $state->localizationPublicId()
                        ) ?? throw new BlogStructuredContentException(
                            BlogStructuredContentException::STORAGE_UNAVAILABLE
                        )
                    );
                }
                $contentChanged = $state->status()
                        !== BlogPostVariant::PUBLISHED
                    || $current === null
                    || !$this->sameSnapshot($current->snapshot(), $draft);
                $categoriesChanged = $categoryPublicIds !== null
                    && $categoryPublicIds
                        !== $workflow->liveCategoryPublicIds($postPublicId);
                $tagsChanged = $tagPublicIds !== null
                    && $tagPublicIds !== $this->tagPublicIds(
                        $this->tagRepository?->liveTags(
                            $state->localizationPublicId()
                        ) ?? []
                    );
                if (!$contentChanged && !$categoriesChanged && !$tagsChanged) {
                    $consumedWorkspace = $workspace !== null
                        || $categoryPublicIds !== null
                        || $tagPublicIds !== null;
                    if ($workspace !== null) {
                        $workflow->clearWorkspace(
                            $state->localizationPublicId()
                        );
                    }
                    if ($categoryPublicIds !== null) {
                        $workflow->clearCategoryWorkspace($postPublicId);
                    }
                    if ($tagPublicIds !== null) {
                        $this->tagRepository?->clearWorkspace(
                            $state->localizationPublicId()
                        );
                    }
                    if ($consumedWorkspace) {
                        $this->auditMutation(
                            $pdo,
                            BlogMutationAuditEvent::PUBLISH,
                            $actorPublicId,
                            $postPublicId,
                            $now
                        );
                    }

                    return $this->requiredStoredVariant(
                        $postPublicId,
                        $locale
                    );
                }

                $sitemapFence =
                    $this->sitemapPublicationCoordinator?->begin();
                $revisionPublicId = $this->newPublicId();
                $this->contentRepository->appendPrivateRevision(
                    $state->localizationPublicId(),
                    $revisionPublicId,
                    $expectedLockVersion,
                    $draft,
                    $actorPublicId,
                    $now
                );
                $this->contentRepository->appendRevisionMedia(
                    $revisionPublicId,
                    $draft->mediaReferences(),
                    $now
                );
                $this->urlHistory?->activate(
                    $state->localizationPublicId(),
                    $locale,
                    $slug,
                    $now
                );
                if (!$workflow->publishSnapshot(
                    $state->localizationPublicId(),
                    $expectedLockVersion,
                    $state->status(),
                    $compatibility,
                    $actorPublicId,
                    $now
                )) {
                    throw new BlogException(BlogException::LOCK_CONFLICT);
                }
                $this->contentRepository->upsertCurrent(
                    $state->localizationPublicId(),
                    $current?->documentPublicId() ?? $this->newPublicId(),
                    $draft,
                    $actorPublicId,
                    $now
                );
                $this->contentRepository->replaceCurrentMedia(
                    $state->localizationPublicId(),
                    $draft->mediaReferences(),
                    $now
                );
                if ($categoryPublicIds !== null) {
                    if ($categoriesChanged) {
                        $assignments = [];
                        foreach ($categoryPublicIds as $categoryPublicId) {
                            $assignments[$categoryPublicId] =
                                $this->newPublicId();
                        }
                        $workflow->replaceLiveCategories(
                            $postPublicId,
                            $assignments,
                            $actorPublicId,
                            $now
                        );
                        $workflow->promoteCategoryAssignments(
                            $postPublicId,
                            $categoryAssignmentVersion,
                            $actorPublicId,
                            $now
                        );
                    }
                    $workflow->clearCategoryWorkspace($postPublicId);
                }
                if ($tagPublicIds !== null) {
                    if ($tagsChanged) {
                        $this->tagRepository?->replaceLiveTags(
                            $state->localizationPublicId(),
                            $tagPublicIds,
                            $actorPublicId,
                            $now
                        );
                        $this->tagRepository?->promoteAssignments(
                            $state->localizationPublicId(),
                            $tagAssignmentVersion,
                            $actorPublicId,
                            $now
                        );
                    }
                    $this->tagRepository?->clearWorkspace(
                        $state->localizationPublicId()
                    );
                }
                $workflow->promotePublicationHead(
                    $state->localizationPublicId(),
                    $revisionPublicId,
                    $publicationVersion,
                    $actorPublicId,
                    $now
                );
                $workflow->clearWorkspace($state->localizationPublicId());
                $this->blogRepository->touchPost($postPublicId, $now);
                $this->auditMutation(
                    $pdo,
                    BlogMutationAuditEvent::PUBLISH,
                    $actorPublicId,
                    $postPublicId,
                    $now
                );
                if ($sitemapFence instanceof BlogSitemapPublicationFence) {
                    $this->sitemapPublicationCoordinator?->complete(
                        $sitemapFence,
                        $now
                    );
                }

                return $this->requiredStoredVariant($postPublicId, $locale);
            });
        } finally {
            $sitemapFence?->release();
        }
    }

    private function workingSnapshot(
        string $localizationPublicId,
        ?BlogEditorialWorkspaceState $workspace
    ): ?BlogStructuredDraft {
        if ($workspace?->draftRevisionPublicId() !== null) {
            $revision = $this->contentRepository->revision(
                $workspace->draftRevisionPublicId()
            );
            if (
                $revision === null
                || $revision->localizationPublicId()
                    !== $localizationPublicId
            ) {
                throw new BlogStructuredContentException(
                    BlogStructuredContentException::STORAGE_UNAVAILABLE
                );
            }

            return $revision->snapshot();
        }

        return $this->contentRepository->current(
            $localizationPublicId
        )?->snapshot();
    }

    /** @param list<BlogTag> $tags @return list<string> */
    private function tagPublicIds(array $tags): array
    {
        if (!array_is_list($tags) || count($tags) > 30) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::STORAGE_UNAVAILABLE
            );
        }
        $ids = [];
        foreach ($tags as $tag) {
            if (!$tag instanceof BlogTag || isset($ids[$tag->publicId()])) {
                throw new BlogStructuredContentException(
                    BlogStructuredContentException::STORAGE_UNAVAILABLE
                );
            }
            $ids[$tag->publicId()] = true;
        }
        $result = array_keys($ids);
        sort($result, SORT_STRING);

        return $result;
    }

    private function assertWorkspaceBase(
        ?BlogEditorialWorkspaceState $workspace,
        int $publicationVersion
    ): void {
        if (
            $workspace !== null
            && $workspace->basePublicationVersion() !== $publicationVersion
        ) {
            throw new BlogException(BlogException::LOCK_CONFLICT);
        }
    }

    private function requiredWorkflowRepository():
        BlogEditorialWorkspaceRepositoryInterface
    {
        if ($this->workflowRepository === null) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::STORAGE_UNAVAILABLE
            );
        }

        return $this->workflowRepository;
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
            && $first->robotsPreferences()->equals(
                $second->robotsPreferences()
            )
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

    private function requiredStoredVariant(
        string $postPublicId,
        string $locale
    ): BlogPostVariant {
        $variant = $this->blogRepository->variant($postPublicId, $locale);
        if ($variant === null) {
            throw new BlogPersistenceException();
        }

        return $variant;
    }

    /** @param callable(PDO): string $actorGate */
    private function authorizedActor(
        #[\SensitiveParameter] callable $actorGate,
        PDO $pdo
    ): string {
        try {
            $actorPublicId = $actorGate($pdo);
            if (!is_string($actorPublicId)) {
                throw new \RuntimeException('Invalid actor gate result.');
            }

            return BlogInput::publicId($actorPublicId);
        } catch (Throwable) {
            throw new BlogException(BlogException::ACTOR_GATE_FAILED);
        }
    }

    private function now(): \DateTimeImmutable
    {
        try {
            return BlogInput::utc($this->clock->now());
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::STORAGE_UNAVAILABLE
            );
        }
    }

    private function auditMutation(
        PDO $pdo,
        string $operation,
        string $actorPublicId,
        string $postPublicId,
        \DateTimeImmutable $occurredAt
    ): void {
        if ($this->auditPort === null) {
            return;
        }
        try {
            $this->auditPort->record(
                $pdo,
                new BlogMutationAuditEvent(
                    $operation,
                    $actorPublicId,
                    $postPublicId,
                    $occurredAt
                )
            );
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::STORAGE_UNAVAILABLE
            );
        }
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
        } catch (BlogTagPersistenceConflict) {
            throw new BlogException(BlogException::LOCK_CONFLICT);
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

    private function projectLayoutSnapshot(
        BlogStructuredDraft $snapshot
    ): BlogStructuredDraft {
        $document = $this->layoutProjector->tryProject(
            $snapshot->document()
        );
        if ($document === null) {
            return $snapshot;
        }
        if ($document->toArray() === $snapshot->document()->toArray()) {
            return $snapshot;
        }
        $metadata = $snapshot->compatibilityDraft();

        return new BlogStructuredDraft(
            $metadata->h1(),
            $document,
            $metadata->slug(),
            $metadata->seoTitle(),
            $metadata->metaDescription(),
            $metadata->excerpt(),
            robotsPreferences: $snapshot->robotsPreferences()
        );
    }
}
