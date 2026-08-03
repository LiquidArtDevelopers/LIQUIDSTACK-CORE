<?php

declare(strict_types=1);

namespace App\Core\Blog;

use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\Editing\BlogDraftMutationCoordinator;
use App\Core\Blog\Editing\BlogPlainDraftWriteGuardInterface;
use App\Core\Blog\Persistence\BlogPersistenceConflict;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\Persistence\BlogPostLocaleCatalogRepositoryInterface;
use App\Core\Blog\Persistence\BlogPublishedSitemapRepositoryInterface;
use App\Core\Blog\Persistence\BlogRepositoryInterface;
use App\Core\Blog\Sitemap\BlogSitemapPublicationCoordinator;
use App\Core\Blog\Sitemap\BlogSitemapPublicationFence;
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
    public const DEFAULT_LIST_LIMIT = 50;
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
            $sitemapPublicationCoordinator = null
    ) {
        $this->draftMutationCoordinator = $draftMutationCoordinator
            ?? new BlogDraftMutationCoordinator(
                $repository,
                $clock,
                $auditPort
            );
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
                    $sitemapFence =
                        $this->sitemapPublicationCoordinator?->begin();
                    if (!$this->repository->updateStatus(
                        $current->localizationPublicId(),
                        $expectedLockVersion,
                        BlogPostVariant::PUBLISHED,
                        BlogPostVariant::DRAFT,
                        null,
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
            throw new BlogException(
                $exception->kind() === BlogPersistenceConflict::SLUG
                    ? BlogException::SLUG_CONFLICT
                    : BlogException::LOCALE_CONFLICT
            );
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
        string $locale
    ): BlogPostVariant {
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

    private function assertExpectedVersion(
        BlogPostVariant $variant,
        int $expectedLockVersion
    ): void {
        if ($variant->lockVersion() !== $expectedLockVersion) {
            throw new BlogException(BlogException::LOCK_CONFLICT);
        }
    }
}
