<?php

declare(strict_types=1);

namespace App\Core\Blog;

use App\Core\Blog\Admin\BlogAdminCatalogQuery;
use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\Editing\BlogDraftMutationCoordinator;
use App\Core\Blog\Editing\BlogPlainDraftWriteGuardInterface;
use App\Core\Blog\EditorialWorkflow\Persistence\BlogEditorialWorkspaceRepositoryInterface;
use App\Core\Blog\Persistence\BlogAdminCatalogRepositoryInterface;
use App\Core\Blog\Persistence\BlogCopyOperationResult;
use App\Core\Blog\Persistence\BlogCopyOperationRepositoryInterface;
use App\Core\Blog\Persistence\BlogEditorialActionRepositoryInterface;
use App\Core\Blog\Persistence\BlogPersistenceConflict;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\Persistence\BlogPostLocaleCatalogRepositoryInterface;
use App\Core\Blog\Persistence\BlogPostLocaleBatchCatalogRepositoryInterface;
use App\Core\Blog\Persistence\BlogPublishedSitemapRepositoryInterface;
use App\Core\Blog\Persistence\BlogRepositoryInterface;
use App\Core\Blog\Sitemap\BlogSitemapPublicationCoordinator;
use App\Core\Blog\Sitemap\BlogSitemapPublicationFence;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV2Projector;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Media\BlogMediaAvailabilityPortInterface;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredContentRepositoryInterface;
use App\Core\Blog\Seo\BlogUrlHistoryRepositoryInterface;
use App\Core\Blog\Seo\BlogUrlResolution;
use App\Core\Blog\Tags\BlogTag;
use App\Core\Blog\Tags\Persistence\BlogTagRepositoryInterface;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Application boundary for the Blog MVP aggregate and locale lifecycle.
 *
 * Every mutation requires an actor gate. The repository opens the write
 * transaction first; only then is that gate invoked with the same PDO. The
 * gate returns the authorized WebAdmin public UUID and must fail closed.
 */
final class BlogService
{
    public const DEFAULT_LIST_LIMIT = BlogAdminCatalogQuery::PAGE_SIZE;
    public const MAX_LIST_LIMIT = BlogInput::MAX_LIST_LIMIT;
    public const MAX_LIST_OFFSET = BlogInput::MAX_LIST_OFFSET;
    public const MAX_SITEMAP_ENTRIES =
        BlogSitemapEntry::MAX_DOCUMENT_ENTRIES;
    public const DEFAULT_PUBLIC_LIST_LIMIT = 12;

    private readonly BlogDraftMutationCoordinator $draftMutationCoordinator;

    public function __construct(
        private readonly BlogRepositoryInterface $repository,
        private readonly UuidGeneratorInterface $uuidGenerator =
            new RandomUuidV4Generator(),
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly ?BlogMutationAuditPortInterface $auditPort = null,
        private readonly ?BlogPlainDraftWriteGuardInterface
            $plainDraftWriteGuard = null,
        ?BlogDraftMutationCoordinator $draftMutationCoordinator = null,
        private readonly ?BlogSitemapPublicationCoordinator
            $sitemapPublicationCoordinator = null,
        private readonly ?BlogStructuredContentRepositoryInterface
            $structuredContentRepository = null,
        private readonly ?BlogMediaAvailabilityPortInterface
            $mediaAvailability = null,
        private readonly ?BlogEditorialWorkspaceRepositoryInterface
            $editorialWorkflowRepository = null,
        private readonly ?BlogUrlHistoryRepositoryInterface $urlHistory = null,
        private readonly ?BlogDocumentV2Projector $layoutProjector = null,
        private readonly ?BlogTagRepositoryInterface $tagRepository = null
    ) {
        $this->draftMutationCoordinator = $draftMutationCoordinator
            ?? new BlogDraftMutationCoordinator(
                $repository,
                $clock,
                $auditPort
            );
    }

    /**
     * Copies one active locale into a new, independent draft aggregate.
     * Only the current private snapshot is copied: revision history,
     * editorial workspaces and publication heads remain source-owned.
     *
     * @param callable(PDO): string $actorGate
     */
    public function duplicatePost(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion,
        string $operationPublicId = ''
    ): BlogPostVariant {
        return $this->copyPrivateDraft(
            $actorGate,
            $postPublicId,
            $locale,
            $locale,
            $expectedLockVersion,
            true,
            $operationPublicId
        );
    }

    /**
     * Copies one locale into a missing locale of the same aggregate.
     *
     * @param callable(PDO): string $actorGate
     */
    public function addLocalizationCopy(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $sourceLocale,
        string $destinationLocale,
        int $expectedLockVersion,
        string $operationPublicId = ''
    ): BlogPostVariant {
        $sourceLocale = BlogInput::locale($sourceLocale);
        $destinationLocale = BlogInput::locale($destinationLocale);
        if ($sourceLocale === $destinationLocale) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $this->copyPrivateDraft(
            $actorGate,
            $postPublicId,
            $sourceLocale,
            $destinationLocale,
            $expectedLockVersion,
            false,
            $operationPublicId
        );
    }

    /**
     * @param callable(PDO): string $actorGate
     */
    private function copyPrivateDraft(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $sourceLocale,
        string $destinationLocale,
        int $expectedLockVersion,
        bool $independentPost,
        string $operationPublicId
    ): BlogPostVariant {
        $postPublicId = BlogInput::publicId($postPublicId);
        $sourceLocale = BlogInput::locale($sourceLocale);
        $destinationLocale = BlogInput::locale($destinationLocale);
        BlogInput::expectedLockVersion($expectedLockVersion);
        if ($independentPost !== ($sourceLocale === $destinationLocale)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        $copyOperationRepository = null;
        if ($operationPublicId !== '') {
            $operationPublicId = BlogInput::generatedPublicId(
                $operationPublicId
            );
            if (!$this->repository instanceof
                BlogCopyOperationRepositoryInterface) {
                throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
            }
            $copyOperationRepository = $this->repository;
        }
        $categoryRepository = $independentPost
            ? $this->editorialRepository()
            : null;
        if (
            $this->structuredContentRepository === null
            || $this->mediaAvailability === null
        ) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }

        return $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $postPublicId,
            $sourceLocale,
            $destinationLocale,
            $expectedLockVersion,
            $categoryRepository,
            $independentPost,
            $copyOperationRepository,
            $operationPublicId
        ): BlogPostVariant {
            $actorPublicId = $this->authorizedActor($actorGate, $pdo);
            $now = $this->now();
            $copyPayloadSha256 = null;
            if ($copyOperationRepository !== null) {
                $copyPayloadSha256 = $this->copyOperationPayloadSha256(
                    $actorPublicId,
                    $independentPost ? 'duplicate_post' : 'add_locale',
                    $postPublicId,
                    $sourceLocale,
                    $destinationLocale,
                    $expectedLockVersion
                );
                $replay = $copyOperationRepository->reserveCopyOperation(
                    $operationPublicId,
                    $copyPayloadSha256,
                    $actorPublicId,
                    $independentPost ? 'duplicate_post' : 'add_locale',
                    $postPublicId,
                    $sourceLocale,
                    $destinationLocale,
                    $expectedLockVersion,
                    $now
                );
                if ($replay !== null) {
                    return $this->requiredCopyReplayVariant($replay);
                }
            }
            $destinationPostPublicId = $independentPost
                ? $this->newPublicId()
                : $postPublicId;
            $newLocalizationPublicId = $this->newPublicId();
            if (!$this->repository->lockPost($postPublicId)) {
                throw new BlogException(BlogException::POST_NOT_FOUND);
            }
            $source = $this->requiredLockedVariant(
                $postPublicId,
                $sourceLocale,
                BlogException::POST_NOT_FOUND
            );
            $this->assertExpectedVersion($source, $expectedLockVersion);
            if (
                !$independentPost
                && $this->repository->lockVariant(
                    $postPublicId,
                    $destinationLocale
                ) !== null
            ) {
                throw new BlogException(BlogException::LOCALE_CONFLICT);
            }
            $categoryPublicIds = [];
            if ($categoryRepository !== null) {
                $categoryPublicIds = $this->editorialWorkflowRepository
                    ?->workspaceCategoryPublicIds($postPublicId);
                if ($categoryPublicIds === null) {
                    $categoryPublicIds = $categoryRepository
                        ->assignedCategoryPublicIds($postPublicId, 101);
                }
            }
            if (count($categoryPublicIds) > 100) {
                throw new BlogPersistenceException();
            }
            $tagPublicIds = [];
            if ($independentPost && $this->tagRepository !== null) {
                $tagWorkspace = $this->tagRepository->workspaceState(
                    $source->localizationPublicId(),
                    true
                );
                $tagAssignmentVersion = $this->tagRepository
                    ->assignmentVersion(
                        $source->localizationPublicId(),
                        true
                    );
                if (
                    $tagWorkspace !== null
                    && $tagWorkspace->baseAssignmentVersion()
                        !== $tagAssignmentVersion
                ) {
                    throw new BlogPersistenceException();
                }
                $sourceTags = $tagWorkspace === null
                    ? $this->tagRepository->liveTags(
                        $source->localizationPublicId()
                    )
                    : ($this->tagRepository->workspaceTags(
                        $source->localizationPublicId()
                    ) ?? throw new BlogPersistenceException());
                if (count($sourceTags) > 30) {
                    throw new BlogPersistenceException();
                }
                $tagPublicIds = array_map(
                    static fn (BlogTag $tag): string => $tag->publicId(),
                    $sourceTags
                );
            }

            $sourceSnapshot = null;
            $current = $this->structuredContentRepository->current(
                $source->localizationPublicId()
            );
            if ($current !== null) {
                $sourceSnapshot = $current->snapshot();
            }
            $workspace = $this->editorialWorkflowRepository?->workspace(
                $source->localizationPublicId(),
                true
            );
            if ($workspace !== null) {
                $publicationHead = $this->editorialWorkflowRepository
                    ->publicationHead(
                        $source->localizationPublicId(),
                        true
                    );
                if (
                    $source->status() !== BlogPostVariant::PUBLISHED
                    || $workspace->basePublicationVersion()
                        !== ($publicationHead?->publicationVersion() ?? 0)
                ) {
                    throw new BlogPersistenceException();
                }
            }
            if ($workspace?->draftRevisionPublicId() !== null) {
                $privateRevision = $this->structuredContentRepository
                    ->revision($workspace->draftRevisionPublicId());
                if (
                    $privateRevision === null
                    || $privateRevision->localizationPublicId()
                        !== $source->localizationPublicId()
                ) {
                    throw new BlogPersistenceException();
                }
                $sourceSnapshot = $privateRevision->snapshot();
            }
            $sourceDraft = $sourceSnapshot?->compatibilityDraft()
                ?? $source->draft();
            $copyH1 = $independentPost
                ? $this->duplicateH1($sourceDraft->h1())
                : $sourceDraft->h1();

            $structuredCopy = null;
            if ($sourceSnapshot !== null) {
                $metadata = $sourceSnapshot->compatibilityDraft();
                $document = $sourceSnapshot->document();
                if ($this->layoutProjector !== null) {
                    // New copies must not reintroduce legacy standalone text
                    // modules once the layout editor is available.
                    $document = $this->layoutProjector->project($document);
                }
                $structuredCopy = new BlogStructuredDraft(
                    $copyH1,
                    $document,
                    null,
                    $metadata->seoTitle(),
                    $metadata->metaDescription(),
                    $metadata->excerpt(),
                    robotsPreferences: $sourceSnapshot->robotsPreferences()
                );
                $this->mediaAvailability->assertAvailable(
                    $pdo,
                    $structuredCopy->mediaAssetPublicIds()
                );
            }

            $copyDraft = $structuredCopy?->compatibilityDraft()
                ?? new BlogDraft(
                    $copyH1,
                    $sourceDraft->bodyText(),
                    null,
                    $sourceDraft->seoTitle(),
                    $sourceDraft->metaDescription(),
                    $sourceDraft->excerpt(),
                    $sourceDraft->robotsPreferences()
                );
            if ($independentPost) {
                $this->repository->insertPost(
                    $destinationPostPublicId,
                    $actorPublicId,
                    $now
                );
            }
            $this->repository->insertLocalization(
                $newLocalizationPublicId,
                $destinationPostPublicId,
                $destinationLocale,
                $copyDraft,
                $actorPublicId,
                $now
            );

            $assignmentIds = [];
            foreach ($categoryPublicIds as $categoryPublicId) {
                $assignmentIds[$categoryPublicId] = $this->newPublicId();
            }
            if ($categoryRepository !== null) {
                $categoryRepository->insertCategoryAssignments(
                    $destinationPostPublicId,
                    $assignmentIds,
                    $actorPublicId,
                    $now
                );
            }
            if ($independentPost && $this->tagRepository !== null) {
                $this->tagRepository->replaceLiveTags(
                    $newLocalizationPublicId,
                    $tagPublicIds,
                    $actorPublicId,
                    $now
                );
            }

            if ($structuredCopy !== null) {
                $documentPublicId = $this->newPublicId();
                $revisionPublicId = $this->newPublicId();
                $this->structuredContentRepository->upsertCurrent(
                    $newLocalizationPublicId,
                    $documentPublicId,
                    $structuredCopy,
                    $actorPublicId,
                    $now
                );
                $this->structuredContentRepository->replaceCurrentMedia(
                    $newLocalizationPublicId,
                    $structuredCopy->mediaReferences(),
                    $now
                );
                $revisionNumber = $this->structuredContentRepository
                    ->appendRevision(
                        $newLocalizationPublicId,
                        $revisionPublicId,
                        1,
                        $structuredCopy,
                        $actorPublicId,
                        $now
                    );
                if ($revisionNumber !== 1) {
                    throw new BlogPersistenceException();
                }
                $this->structuredContentRepository->appendRevisionMedia(
                    $revisionPublicId,
                    $structuredCopy->mediaReferences(),
                    $now
                );
            }

            $stored = $this->requiredStoredVariant(
                $destinationPostPublicId,
                $destinationLocale
            );
            if (
                $copyOperationRepository !== null
                && $copyPayloadSha256 !== null
            ) {
                $copyOperationRepository->completeCopyOperation(
                    $operationPublicId,
                    $copyPayloadSha256,
                    $destinationPostPublicId,
                    $destinationLocale,
                    $now
                );
            }
            $this->auditMutation(
                $pdo,
                $independentPost
                    ? BlogMutationAuditEvent::DUPLICATE
                    : BlogMutationAuditEvent::ADD_LOCALE,
                $actorPublicId,
                $destinationPostPublicId,
                $now
            );

            return $stored;
        });
    }

    public function trashAvailable(): bool
    {
        return $this->repository instanceof
                BlogEditorialActionRepositoryInterface
            && $this->repository->postTombstonesEnabled();
    }

    /** @param callable(PDO): string $actorGate */
    public function trashPost(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion
    ): BlogPostVariant {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        BlogInput::expectedLockVersion($expectedLockVersion);
        $editorialRepository = $this->tombstoneRepository();

        return $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $postPublicId,
            $locale,
            $expectedLockVersion,
            $editorialRepository
        ): BlogPostVariant {
            $actorPublicId = $this->authorizedActor($actorGate, $pdo);
            $now = $this->now();
            $current = $this->requiredLockedVariant($postPublicId, $locale);
            $this->assertExpectedVersion($current, $expectedLockVersion);
            if ($current->status() !== BlogPostVariant::DRAFT) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            $editorialRepository->insertTombstone(
                $current->localizationPublicId(),
                $actorPublicId,
                $now
            );
            if (!$editorialRepository->bumpVariantLock(
                $current->localizationPublicId(),
                $expectedLockVersion,
                $actorPublicId,
                $now
            )) {
                throw new BlogException(BlogException::LOCK_CONFLICT);
            }
            $this->repository->touchPost($postPublicId, $now);
            $stored = $this->requiredTrashedVariant(
                $editorialRepository,
                $postPublicId,
                $locale
            );
            $this->auditMutation(
                $pdo,
                BlogMutationAuditEvent::TRASH,
                $actorPublicId,
                $postPublicId,
                $now
            );

            return $stored;
        });
    }

    /** @param callable(PDO): string $actorGate */
    public function restoreTrashedPost(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion
    ): BlogPostVariant {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        BlogInput::expectedLockVersion($expectedLockVersion);
        $editorialRepository = $this->tombstoneRepository();

        return $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $postPublicId,
            $locale,
            $expectedLockVersion,
            $editorialRepository
        ): BlogPostVariant {
            $actorPublicId = $this->authorizedActor($actorGate, $pdo);
            $now = $this->now();
            $current = $this->requiredTrashedVariant(
                $editorialRepository,
                $postPublicId,
                $locale
            );
            $this->assertExpectedVersion($current, $expectedLockVersion);
            if (
                $current->status() !== BlogPostVariant::DRAFT
                || !$editorialRepository->deleteTombstone(
                    $current->localizationPublicId()
                )
            ) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            if (!$editorialRepository->bumpVariantLock(
                $current->localizationPublicId(),
                $expectedLockVersion,
                $actorPublicId,
                $now
            )) {
                throw new BlogException(BlogException::LOCK_CONFLICT);
            }
            $this->repository->touchPost($postPublicId, $now);
            $stored = $this->requiredStoredVariant($postPublicId, $locale);
            $this->auditMutation(
                $pdo,
                BlogMutationAuditEvent::RESTORE_FROM_TRASH,
                $actorPublicId,
                $postPublicId,
                $now
            );

            return $stored;
        });
    }

    /**
     * @param callable(PDO): string $actorGate
     */
    public function createPost(
        #[\SensitiveParameter] callable $actorGate,
        string $locale,
        #[\SensitiveParameter] BlogDraft $draft
    ): BlogPostVariant {
        $locale = BlogInput::locale($locale);
        $postPublicId = $this->newPublicId();
        $localizationPublicId = $this->newPublicId();

        return $this->mutate(
            function (PDO $pdo) use (
                $actorGate,
                $locale,
                $draft,
                $postPublicId,
                $localizationPublicId
            ): BlogPostVariant {
                $actorPublicId = $this->authorizedActor($actorGate, $pdo);
                $now = $this->now();
                $this->assertSlugAvailable($locale, $draft->slug());
                $this->repository->insertPost(
                    $postPublicId,
                    $actorPublicId,
                    $now
                );
                $this->repository->insertLocalization(
                    $localizationPublicId,
                    $postPublicId,
                    $locale,
                    $draft,
                    $actorPublicId,
                    $now
                );

                $stored = $this->requiredStoredVariant($postPublicId, $locale);
                $this->auditMutation(
                    $pdo,
                    BlogMutationAuditEvent::CREATE,
                    $actorPublicId,
                    $postPublicId,
                    $now
                );

                return $stored;
            }
        );
    }

    /**
     * @param callable(PDO): string $actorGate
     */
    public function addLocalization(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        #[\SensitiveParameter] BlogDraft $draft
    ): BlogPostVariant {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        $localizationPublicId = $this->newPublicId();

        return $this->mutate(
            function (PDO $pdo) use (
                $actorGate,
                $postPublicId,
                $locale,
                $draft,
                $localizationPublicId
            ): BlogPostVariant {
                $actorPublicId = $this->authorizedActor($actorGate, $pdo);
                $now = $this->now();
                if (!$this->repository->lockPost($postPublicId)) {
                    throw new BlogException(BlogException::POST_NOT_FOUND);
                }
                if (
                    $this->repository->lockVariant($postPublicId, $locale)
                        !== null
                ) {
                    throw new BlogException(BlogException::LOCALE_CONFLICT);
                }
                $this->assertSlugAvailable($locale, $draft->slug());
                $this->repository->insertLocalization(
                    $localizationPublicId,
                    $postPublicId,
                    $locale,
                    $draft,
                    $actorPublicId,
                    $now
                );
                $this->repository->touchPost($postPublicId, $now);

                $stored = $this->requiredStoredVariant($postPublicId, $locale);
                $this->auditMutation(
                    $pdo,
                    BlogMutationAuditEvent::ADD_LOCALE,
                    $actorPublicId,
                    $postPublicId,
                    $now
                );

                return $stored;
            }
        );
    }

    /**
     * @param callable(PDO): string $actorGate
     */
    public function saveDraft(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion,
        #[\SensitiveParameter] BlogDraft $draft
    ): BlogPostVariant {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        BlogInput::expectedLockVersion($expectedLockVersion);

        return $this->mutate(fn (PDO $pdo): BlogPostVariant =>
            $this->draftMutationCoordinator->saveWithinTransaction(
                $pdo,
                $actorGate,
                $postPublicId,
                $locale,
                $expectedLockVersion,
                $draft,
                $this->plainDraftWriteGuard === null
                    ? null
                    : function (
                        PDO $transaction,
                        BlogPostVariant $current
                    ): bool {
                        $this->plainDraftWriteGuard->assertPlainSaveAllowed(
                            $transaction,
                            $current->localizationPublicId()
                        );

                        return true;
                    }
            )
        );
    }

    /**
     * @param callable(PDO): string $actorGate
     */
    public function publish(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion
    ): BlogPostVariant {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        BlogInput::expectedLockVersion($expectedLockVersion);
        if ($this->editorialWorkflowRepository !== null) {
            // Migration 0014 requires the structured editor publication
            // fence. The compatibility route must never bypass workspaces.
            throw new BlogException(BlogException::INVALID_STATE);
        }

        $sitemapFence = null;
        try {
            return $this->mutate(
                function (PDO $pdo) use (
                    $actorGate,
                    $postPublicId,
                    $locale,
                    $expectedLockVersion,
                    &$sitemapFence
                ): BlogPostVariant {
                    $actorPublicId = $this->authorizedActor($actorGate, $pdo);
                    $now = $this->now();
                    $current = $this->requiredLockedVariant(
                        $postPublicId,
                        $locale
                    );
                    $this->assertExpectedVersion(
                        $current,
                        $expectedLockVersion
                    );
                    if ($current->status() !== BlogPostVariant::DRAFT) {
                        throw new BlogException(BlogException::INVALID_STATE);
                    }
                    if (!$current->draft()->isPublishable()) {
                        throw new BlogException(
                            BlogException::PUBLISH_INCOMPLETE
                        );
                    }
                    $slug = $current->draft()->slug();
                    if ($slug === null) {
                        throw new BlogException(
                            BlogException::PUBLISH_INCOMPLETE
                        );
                    }
                    $this->assertSlugAvailable(
                        $locale,
                        $slug,
                        $current->localizationPublicId()
                    );
                    $this->urlHistory?->activate(
                        $current->localizationPublicId(),
                        $locale,
                        $slug,
                        $now
                    );
                    $sitemapFence =
                        $this->sitemapPublicationCoordinator?->begin();
                    if (!$this->repository->updateStatus(
                        $current->localizationPublicId(),
                        $expectedLockVersion,
                        BlogPostVariant::DRAFT,
                        BlogPostVariant::PUBLISHED,
                        $now,
                        $actorPublicId,
                        $now
                    )) {
                        throw new BlogException(BlogException::LOCK_CONFLICT);
                    }
                    $this->repository->touchPost($postPublicId, $now);

                    $stored = $this->requiredStoredVariant(
                        $postPublicId,
                        $locale
                    );
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

                    return $stored;
                }
            );
        } finally {
            $sitemapFence?->release();
        }
    }

    /**
     * @param callable(PDO): string $actorGate
     */
    public function unpublish(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion
    ): BlogPostVariant {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        BlogInput::expectedLockVersion($expectedLockVersion);

        $sitemapFence = null;
        try {
            return $this->mutate(
                function (PDO $pdo) use (
                    $actorGate,
                    $postPublicId,
                    $locale,
                    $expectedLockVersion,
                    &$sitemapFence
                ): BlogPostVariant {
                    $actorPublicId = $this->authorizedActor($actorGate, $pdo);
                    $now = $this->now();
                    $current = $this->requiredLockedVariant(
                        $postPublicId,
                        $locale
                    );
                    $this->assertExpectedVersion(
                        $current,
                        $expectedLockVersion
                    );
                    if ($current->status() !== BlogPostVariant::PUBLISHED) {
                        throw new BlogException(BlogException::INVALID_STATE);
                    }
                    $publishedSlug = $current->draft()->slug();
                    if ($publishedSlug === null) {
                        throw new BlogPersistenceException();
                    }
                    $this->urlHistory?->markTemporary(
                        $current->localizationPublicId(),
                        $locale,
                        $publishedSlug,
                        $now
                    );
                    $sitemapFence =
                        $this->sitemapPublicationCoordinator?->begin();
                    $workflow = $this->editorialWorkflowRepository;
                    $workspace = $workflow?->workspace(
                        $current->localizationPublicId(),
                        true
                    );
                    $privateSnapshot = null;
                    $currentDocument = null;
                    if ($workspace?->draftRevisionPublicId() !== null) {
                        if (
                            $this->structuredContentRepository === null
                            || $this->mediaAvailability === null
                        ) {
                            throw new BlogException(
                                BlogException::STORAGE_UNAVAILABLE
                            );
                        }
                        $revision = $this->structuredContentRepository
                            ->revision($workspace->draftRevisionPublicId());
                        if (
                            $revision === null
                            || $revision->localizationPublicId()
                                !== $current->localizationPublicId()
                        ) {
                            throw new BlogException(
                                BlogException::STORAGE_UNAVAILABLE
                            );
                        }
                        $privateSnapshot = $revision->snapshot();
                        $this->mediaAvailability->assertAvailable(
                            $pdo,
                            $privateSnapshot->mediaAssetPublicIds()
                        );
                        // Read while the compatibility row still matches the
                        // public snapshot; after unpublishSnapshot it will
                        // deliberately point at the adopted private draft.
                        $currentDocument = $this->structuredContentRepository
                            ->current($current->localizationPublicId());
                    }
                    $statusChanged = $privateSnapshot === null
                        ? $this->repository->updateStatus(
                            $current->localizationPublicId(),
                            $expectedLockVersion,
                            BlogPostVariant::PUBLISHED,
                            BlogPostVariant::DRAFT,
                            null,
                            $actorPublicId,
                            $now
                        )
                        : $workflow?->unpublishSnapshot(
                            $current->localizationPublicId(),
                            $expectedLockVersion,
                            $privateSnapshot->compatibilityDraft(),
                            $actorPublicId,
                            $now
                        );
                    if ($statusChanged !== true) {
                        throw new BlogException(BlogException::LOCK_CONFLICT);
                    }
                    if ($privateSnapshot !== null) {
                        $this->structuredContentRepository?->upsertCurrent(
                            $current->localizationPublicId(),
                            $currentDocument?->documentPublicId()
                                ?? $this->newPublicId(),
                            $privateSnapshot,
                            $actorPublicId,
                            $now
                        );
                        $this->structuredContentRepository
                            ?->replaceCurrentMedia(
                                $current->localizationPublicId(),
                                $privateSnapshot->mediaReferences(),
                                $now
                            );
                    }
                    $workflow?->retirePublicationState(
                        $current->localizationPublicId()
                    );
                    $this->repository->touchPost($postPublicId, $now);

                    $stored = $this->requiredStoredVariant(
                        $postPublicId,
                        $locale
                    );
                    $this->auditMutation(
                        $pdo,
                        BlogMutationAuditEvent::UNPUBLISH,
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

                    return $stored;
                }
            );
        } finally {
            $sitemapFence?->release();
        }
    }

    /**
     * Converts one already retired historical URL into an explicit 410 or
     * a 301 to another currently published, same-locale Blog variant.
     *
     * @param callable(PDO): string $actorGate
     */
    public function finalizeRetiredUrl(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion,
        string $historicalSlug,
        string $resolution,
        ?string $replacementPostPublicId = null
    ): BlogPostVariant {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        BlogInput::expectedLockVersion($expectedLockVersion);
        $historicalSlug = BlogInput::slug($historicalSlug)
            ?? throw new BlogException(BlogException::INVALID_INPUT);
        if (!in_array($resolution, [BlogUrlResolution::GONE, BlogUrlResolution::REDIRECT], true)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        if (($resolution === BlogUrlResolution::REDIRECT) !== ($replacementPostPublicId !== null)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        $history = $this->urlHistory
            ?? throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        $editorial = $this->tombstoneRepository();

        return $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $postPublicId,
            $locale,
            $expectedLockVersion,
            $historicalSlug,
            $resolution,
            $replacementPostPublicId,
            $history,
            $editorial
        ): BlogPostVariant {
            $actor = $this->authorizedActor($actorGate, $pdo);
            $now = $this->now();
            $source = $this->requiredLockedVariant($postPublicId, $locale);
            $this->assertExpectedVersion($source, $expectedLockVersion);
            if (
                !$history->owns(
                    $source->localizationPublicId(),
                    $locale,
                    $historicalSlug
                )
            ) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            $operation = BlogMutationAuditEvent::URL_GONE;
            if ($resolution === BlogUrlResolution::REDIRECT) {
                $target = $this->requiredLockedVariant(
                    BlogInput::publicId((string) $replacementPostPublicId),
                    $locale
                );
                $targetSlug = $target->draft()->slug();
                if (
                    $target->status() !== BlogPostVariant::PUBLISHED
                    || $targetSlug === null
                    || hash_equals($historicalSlug, $targetSlug)
                    || !$history->isRedirectTargetEligible(
                        $target->localizationPublicId()
                    )
                ) {
                    throw new BlogException(BlogException::INVALID_STATE);
                }
                $history->markRedirect(
                    $source->localizationPublicId(),
                    $locale,
                    $historicalSlug,
                    $target->localizationPublicId(),
                    $now
                );
                $operation = BlogMutationAuditEvent::URL_REDIRECT;
            } else {
                $history->markGone(
                    $source->localizationPublicId(),
                    $locale,
                    $historicalSlug,
                    $now
                );
            }
            $versionBumped = $source->status() === BlogPostVariant::PUBLISHED
                ? $this->repository->updateStatus(
                    $source->localizationPublicId(),
                    $expectedLockVersion,
                    BlogPostVariant::PUBLISHED,
                    BlogPostVariant::PUBLISHED,
                    $source->publishedAt(),
                    $actor,
                    $now
                )
                : $editorial->bumpVariantLock(
                    $source->localizationPublicId(),
                    $expectedLockVersion,
                    $actor,
                    $now
                );
            if (!$versionBumped) {
                throw new BlogException(BlogException::LOCK_CONFLICT);
            }
            $this->repository->touchPost($postPublicId, $now);
            $this->auditMutation($pdo, $operation, $actor, $postPublicId, $now);

            return $this->requiredStoredVariant($postPublicId, $locale);
        });
    }

    public function urlResolution(
        string $locale,
        string $slug
    ): ?BlogUrlResolution {
        $locale = BlogInput::locale($locale);
        $slug = BlogInput::slug($slug)
            ?? throw new BlogException(BlogException::INVALID_INPUT);
        $history = $this->urlHistory;
        if ($history === null) {
            return null;
        }

        return $this->read(
            static fn (): ?BlogUrlResolution => $history->resolve(
                $locale,
                $slug
            )
        );
    }

    /** @return list<BlogPostSummary> */
    public function listPosts(
        int $limit = self::DEFAULT_LIST_LIMIT,
        int $offset = 0
    ): array
    {
        $limit = BlogInput::listLimit($limit);
        $offset = BlogInput::listOffset($offset);

        return $this->read(
            fn (): array => $this->repository->listSummaries($limit, $offset)
        );
    }

    /** @return list<BlogPostSummary> */
    public function searchPosts(BlogAdminCatalogQuery $query): array
    {
        if (!$this->repository instanceof BlogAdminCatalogRepositoryInterface) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }

        return $this->read(
            fn (): array => $this->repository->searchSummaries($query)
        );
    }

    /** @return list<BlogPostSummary> */
    public function listTrashedPosts(
        int $limit = self::DEFAULT_LIST_LIMIT,
        int $offset = 0
    ): array {
        $limit = BlogInput::listLimit($limit);
        $offset = BlogInput::listOffset($offset);
        $repository = $this->tombstoneRepository();

        return $this->read(
            fn (): array => $repository->listTrashedSummaries($limit, $offset)
        );
    }

    /** @return list<string> */
    public function localesForPost(string $postPublicId): array
    {
        $postPublicId = BlogInput::publicId($postPublicId);

        return $this->read(function () use ($postPublicId): array {
            if (
                !$this->repository instanceof
                    BlogPostLocaleCatalogRepositoryInterface
            ) {
                throw new BlogPersistenceException();
            }

            $locales = $this->repository->localesForPost(
                $postPublicId,
                BlogSitemapEntry::ALTERNATES_OVERFLOW_QUERY_LIMIT
            );
            if ($locales === null) {
                throw new BlogException(BlogException::POST_NOT_FOUND);
            }
            if (count($locales) > BlogSitemapEntry::MAX_LANGUAGE_ALTERNATES) {
                throw new BlogPersistenceException();
            }

            $unique = [];
            foreach ($locales as $locale) {
                try {
                    $locale = BlogInput::locale($locale);
                } catch (BlogException) {
                    throw new BlogPersistenceException();
                }
                if (isset($unique[$locale])) {
                    throw new BlogPersistenceException();
                }
                $unique[$locale] = true;
            }

            return array_keys($unique);
        });
    }

    /**
     * @param list<string> $postPublicIds
     * @return array<string, list<string>>
     */
    public function localesForPosts(array $postPublicIds): array
    {
        if (
            !array_is_list($postPublicIds)
            || count($postPublicIds) > BlogAdminCatalogQuery::PAGE_SIZE
        ) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
        $validated = [];
        foreach ($postPublicIds as $postPublicId) {
            if (!is_string($postPublicId)) {
                throw new BlogException(BlogException::INVALID_INPUT);
            }
            $postPublicId = BlogInput::publicId($postPublicId);
            if (isset($validated[$postPublicId])) {
                throw new BlogException(BlogException::INVALID_INPUT);
            }
            $validated[$postPublicId] = true;
        }
        if ($validated === []) {
            return [];
        }

        return $this->read(function () use ($validated): array {
            if (
                !$this->repository instanceof
                    BlogPostLocaleBatchCatalogRepositoryInterface
            ) {
                throw new BlogPersistenceException();
            }
            $result = $this->repository->localesForPosts(
                array_keys($validated),
                BlogSitemapEntry::ALTERNATES_OVERFLOW_QUERY_LIMIT
            );
            if (array_keys($result) !== array_keys($validated)) {
                throw new BlogPersistenceException();
            }
            foreach ($result as $postPublicId => $locales) {
                if (
                    !isset($validated[$postPublicId])
                    || !array_is_list($locales)
                    || count($locales)
                        > BlogSitemapEntry::MAX_LANGUAGE_ALTERNATES
                ) {
                    throw new BlogPersistenceException();
                }
                $seen = [];
                foreach ($locales as $locale) {
                    if (!is_string($locale)) {
                        throw new BlogPersistenceException();
                    }
                    $locale = BlogInput::locale($locale);
                    if (isset($seen[$locale])) {
                        throw new BlogPersistenceException();
                    }
                    $seen[$locale] = true;
                }
                $result[$postPublicId] = array_keys($seen);
            }

            return $result;
        });
    }

    public function loadPost(
        string $postPublicId,
        string $locale
    ): BlogPostVariant {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);

        return $this->read(function () use (
            $postPublicId,
            $locale
        ): BlogPostVariant {
            $variant = $this->repository->variant($postPublicId, $locale);
            if ($variant === null) {
                throw new BlogException(BlogException::VARIANT_NOT_FOUND);
            }

            return $variant;
        });
    }

    public function resolvePublished(
        string $locale,
        string $slug
    ): ?BlogPostVariant {
        $locale = BlogInput::locale($locale);
        $slug = BlogInput::slug($slug)
            ?? throw new BlogException(BlogException::INVALID_INPUT);

        return $this->read(
            fn (): ?BlogPostVariant =>
                $this->repository->publishedVariant($locale, $slug)
        );
    }

    /** @return list<PublishedPostCard> */
    public function listPublishedCards(
        string $locale,
        int $limit = self::DEFAULT_PUBLIC_LIST_LIMIT,
        int $offset = 0
    ): array {
        $locale = BlogInput::locale($locale);
        $limit = BlogInput::listLimit($limit);
        $offset = BlogInput::listOffset($offset);

        return $this->read(
            fn (): array => $this->repository->listPublishedCards(
                $locale,
                $limit,
                $offset
            )
        );
    }

    /** @return list<BlogSitemapEntry> */
    public function sitemapEntries(): array
    {
        return $this->read(function (): array {
            $entries = $this->repository->sitemapEntries(
                BlogSitemapEntry::OVERFLOW_QUERY_LIMIT
            );
            if (count($entries) > self::MAX_SITEMAP_ENTRIES) {
                throw new BlogException(BlogException::SITEMAP_OVERFLOW);
            }

            return $entries;
        });
    }

    /** @return list<BlogSitemapEntry> */
    public function publishedSitemapEntriesForPost(
        string $postPublicId
    ): array {
        $postPublicId = BlogInput::publicId($postPublicId);
        if (
            !$this->repository instanceof
                BlogPublishedSitemapRepositoryInterface
        ) {
            return [];
        }

        return $this->read(function () use ($postPublicId): array {
            $entries = $this->repository->publishedSitemapEntriesForPost(
                $postPublicId,
                BlogSitemapEntry::ALTERNATES_OVERFLOW_QUERY_LIMIT
            );
            if (count($entries) > BlogSitemapEntry::MAX_LANGUAGE_ALTERNATES) {
                throw new BlogException(BlogException::SITEMAP_OVERFLOW);
            }
            foreach ($entries as $entry) {
                if (
                    !$entry instanceof BlogSitemapEntry
                    || $entry->postPublicId() === null
                    || !hash_equals(
                        $postPublicId,
                        $entry->postPublicId()
                    )
                ) {
                    throw new BlogPersistenceException();
                }
            }

            return $entries;
        });
    }

    /**
     * @template T
     * @param callable(PDO): T $operation
     * @return T
     */
    private function mutate(callable $operation): mixed
    {
        try {
            return $this->repository->transactional($operation);
        } catch (BlogPersistenceConflict $exception) {
            throw new BlogException(match ($exception->kind()) {
                BlogPersistenceConflict::SLUG =>
                    BlogException::SLUG_CONFLICT,
                BlogPersistenceConflict::IDEMPOTENCY =>
                    BlogException::IDEMPOTENCY_CONFLICT,
                default => BlogException::LOCALE_CONFLICT,
            });
        } catch (BlogPersistenceException) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        } catch (BlogException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }
    }

    /** @template T @param callable(): T $operation @return T */
    private function read(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (BlogException $exception) {
            throw $exception;
        } catch (BlogPersistenceException|BlogPersistenceConflict) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        } catch (Throwable) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }
    }

    /** @param callable(PDO): string $actorGate */
    private function authorizedActor(
        #[\SensitiveParameter] callable $actorGate,
        PDO $pdo
    ): string {
        try {
            $actorPublicId = $actorGate($pdo);
            if (!is_string($actorPublicId)) {
                throw new \RuntimeException('Invalid gate result.');
            }

            return BlogInput::publicId($actorPublicId);
        } catch (Throwable) {
            throw new BlogException(BlogException::ACTOR_GATE_FAILED);
        }
    }

    private function newPublicId(): string
    {
        try {
            return BlogInput::generatedPublicId(
                $this->uuidGenerator->generateV4()
            );
        } catch (Throwable) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }
    }

    private function duplicateH1(string $source): string
    {
        $prefix = 'Copia de ';
        $available = BlogDraft::MAX_H1_BYTES - strlen($prefix);
        $suffix = strlen($source) <= $available
            ? $source
            : substr($source, 0, $available);
        while ($suffix !== '' && preg_match('//u', $suffix) !== 1) {
            $suffix = substr($suffix, 0, -1);
        }
        $suffix = rtrim($suffix);
        if ($suffix === '') {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }

        return $prefix . $suffix;
    }

    private function copyOperationPayloadSha256(
        string $actorPublicId,
        string $operation,
        string $sourcePostPublicId,
        string $sourceLocale,
        string $destinationLocale,
        int $expectedLockVersion
    ): string {
        return hash('sha256', implode("\0", [
            'blog-copy-operation-v1',
            $actorPublicId,
            $operation,
            $sourcePostPublicId,
            $sourceLocale,
            $destinationLocale,
            (string) $expectedLockVersion,
        ]));
    }

    private function now(): DateTimeImmutable
    {
        try {
            return BlogInput::utc($this->clock->now());
        } catch (Throwable) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }
    }

    private function auditMutation(
        PDO $pdo,
        string $operation,
        string $actorPublicId,
        string $postPublicId,
        DateTimeImmutable $occurredAt
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
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }
    }

    private function assertSlugAvailable(
        string $locale,
        ?string $slug,
        ?string $exceptLocalizationPublicId = null
    ): void {
        if (
            $slug !== null
            && $this->repository->slugExists(
                $locale,
                $slug,
                $exceptLocalizationPublicId
            )
        ) {
            throw new BlogException(BlogException::SLUG_CONFLICT);
        }
    }

    private function requiredLockedVariant(
        string $postPublicId,
        string $locale,
        string $missingPostIssue = BlogException::VARIANT_NOT_FOUND
    ): BlogPostVariant {
        // All aggregate mutations acquire locks in the same order: parent
        // post first, then its localization. This avoids duplicate/edit,
        // publish and trash taking the inverse order under MySQL.
        if (!$this->repository->lockPost($postPublicId)) {
            throw new BlogException($missingPostIssue);
        }
        $variant = $this->repository->lockVariant($postPublicId, $locale);
        if ($variant === null) {
            throw new BlogException(BlogException::VARIANT_NOT_FOUND);
        }

        return $variant;
    }

    private function requiredStoredVariant(
        string $postPublicId,
        string $locale
    ): BlogPostVariant {
        $variant = $this->repository->variant($postPublicId, $locale);
        if ($variant === null) {
            throw new BlogPersistenceException();
        }

        return $variant;
    }

    private function requiredCopyReplayVariant(
        BlogCopyOperationResult $replay
    ): BlogPostVariant {
        $postPublicId = $replay->postPublicId();
        $locale = $replay->locale();
        if (!$this->repository->lockPost($postPublicId)) {
            throw new BlogPersistenceException();
        }
        $variant = $this->repository->lockVariant($postPublicId, $locale);
        if ($variant !== null) {
            return $variant;
        }
        if (
            $this->repository instanceof BlogEditorialActionRepositoryInterface
            && $this->repository->postTombstonesEnabled()
            && $this->repository->lockTrashedVariant($postPublicId, $locale)
                !== null
        ) {
            throw new BlogException(BlogException::COPY_RESULT_TRASHED);
        }

        // A completed operation must always resolve to either its active or
        // recoverable destination. Anything else is a persistence invariant
        // failure, not a safe signal to create a second draft.
        throw new BlogPersistenceException();
    }

    private function editorialRepository(): BlogEditorialActionRepositoryInterface
    {
        if (
            !$this->repository instanceof
                BlogEditorialActionRepositoryInterface
        ) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }

        return $this->repository;
    }

    private function tombstoneRepository(): BlogEditorialActionRepositoryInterface
    {
        $repository = $this->editorialRepository();
        if (!$repository->postTombstonesEnabled()) {
            throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
        }

        return $repository;
    }

    private function requiredTrashedVariant(
        BlogEditorialActionRepositoryInterface $repository,
        string $postPublicId,
        string $locale
    ): BlogPostVariant {
        if (!$this->repository->lockPost($postPublicId)) {
            throw new BlogException(BlogException::VARIANT_NOT_FOUND);
        }
        $variant = $repository->lockTrashedVariant($postPublicId, $locale);
        if ($variant === null) {
            throw new BlogException(BlogException::VARIANT_NOT_FOUND);
        }

        return $variant;
    }

    private function assertExpectedVersion(
        BlogPostVariant $variant,
        int $expectedLockVersion
    ): void {
        if ($variant->lockVersion() !== $expectedLockVersion) {
            throw new BlogException(BlogException::LOCK_CONFLICT);
        }
    }
}
