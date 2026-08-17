<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories;

use App\Core\Blog\BlogException;
use App\Core\Blog\Categories\Audit\BlogCategoryAuditEvent;
use App\Core\Blog\Categories\Audit\BlogCategoryAuditPortInterface;
use App\Core\Blog\Categories\Persistence\BlogCategoryLocaleLookupRepositoryInterface;
use App\Core\Blog\Categories\Persistence\BlogCategoryDeletionRepositoryInterface;
use App\Core\Blog\Categories\Persistence\BlogCategoryPersistenceConflict;
use App\Core\Blog\Categories\Persistence\BlogCategoryPersistenceException;
use App\Core\Blog\Categories\Persistence\BlogCategoryRepositoryInterface;
use App\Core\Blog\Categories\Persistence\BlogReservedCategoryRepositoryInterface;
use App\Core\Blog\EditorialWorkflow\Persistence\BlogEditorialWorkspaceRepositoryInterface;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use PDO;
use Throwable;

/** Transactional application boundary for localized categories. */
final class BlogCategoryService
{
    public const DEFAULT_LIST_LIMIT = 50;
    public const MAX_ASSIGNMENTS = 100;

    public function __construct(
        private readonly BlogCategoryRepositoryInterface $repository,
        private readonly UuidGeneratorInterface $uuidGenerator =
            new RandomUuidV4Generator(),
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly ?BlogCategoryAuditPortInterface $auditPort = null,
        private readonly ?BlogEditorialWorkspaceRepositoryInterface
            $workflowRepository = null
    ) {
    }

    /** @param callable(PDO): string $actorGate */
    public function create(
        #[\SensitiveParameter] callable $actorGate,
        string $locale,
        BlogCategoryDraft $draft
    ): BlogCategoryLocalization {
        $locale = BlogCategoryInput::locale($locale);
        $this->assertOrdinaryDraft($draft);
        $categoryPublicId = $this->newPublicId();
        $localizationPublicId = $this->newPublicId();

        return $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $locale,
            $draft,
            $categoryPublicId,
            $localizationPublicId
        ): BlogCategoryLocalization {
            $actor = $this->authorizedActor($actorGate, $pdo);
            $now = $this->now();
            $this->assertSlugAvailable($locale, $draft->slug());
            $this->repository->insertCategory(
                $categoryPublicId,
                $actor,
                $now
            );
            $this->repository->insertLocalization(
                $localizationPublicId,
                $categoryPublicId,
                $locale,
                $draft,
                $actor,
                $now
            );
            $stored = $this->required($categoryPublicId, $locale);
            $this->audit(
                $pdo,
                BlogCategoryAuditEvent::CREATE,
                $actor,
                $categoryPublicId,
                $now
            );

            return $stored;
        });
    }

    /** @param callable(PDO): string $actorGate */
    public function addLocalization(
        #[\SensitiveParameter] callable $actorGate,
        string $categoryPublicId,
        string $locale,
        BlogCategoryDraft $draft
    ): BlogCategoryLocalization {
        $categoryPublicId = BlogCategoryInput::publicId($categoryPublicId);
        $locale = BlogCategoryInput::locale($locale);
        $this->assertOrdinaryDraft($draft);
        $localizationPublicId = $this->newPublicId();

        return $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $categoryPublicId,
            $locale,
            $draft,
            $localizationPublicId
        ): BlogCategoryLocalization {
            $actor = $this->authorizedActor($actorGate, $pdo);
            $now = $this->now();
            $this->assertMutableCategory($categoryPublicId);
            if (!$this->repository->lockCategory($categoryPublicId)) {
                throw new BlogCategoryException(
                    BlogCategoryException::NOT_FOUND
                );
            }
            if ($this->repository->lockLocalization(
                $categoryPublicId,
                $locale
            ) !== null) {
                throw new BlogCategoryException(
                    BlogCategoryException::LOCALE_CONFLICT
                );
            }
            $this->assertSlugAvailable($locale, $draft->slug());
            $this->repository->insertLocalization(
                $localizationPublicId,
                $categoryPublicId,
                $locale,
                $draft,
                $actor,
                $now
            );
            $this->repository->touchCategory($categoryPublicId, $now);
            $stored = $this->required($categoryPublicId, $locale);
            $this->audit(
                $pdo,
                BlogCategoryAuditEvent::ADD_LOCALE,
                $actor,
                $categoryPublicId,
                $now
            );

            return $stored;
        });
    }

    /** @param callable(PDO): string $actorGate */
    public function save(
        #[\SensitiveParameter] callable $actorGate,
        string $categoryPublicId,
        string $locale,
        int $expectedLockVersion,
        BlogCategoryDraft $draft
    ): BlogCategoryLocalization {
        $categoryPublicId = BlogCategoryInput::publicId($categoryPublicId);
        $locale = BlogCategoryInput::locale($locale);
        BlogCategoryInput::expectedLockVersion($expectedLockVersion);
        $this->assertOrdinaryDraft($draft);

        return $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $categoryPublicId,
            $locale,
            $expectedLockVersion,
            $draft
        ): BlogCategoryLocalization {
            $actor = $this->authorizedActor($actorGate, $pdo);
            $now = $this->now();
            $current = $this->repository->lockLocalization(
                $categoryPublicId,
                $locale
            );
            if ($current === null) {
                throw new BlogCategoryException(
                    BlogCategoryException::NOT_FOUND
                );
            }
            $this->assertMutableCategory($categoryPublicId, $current);
            if ($current->lockVersion() !== $expectedLockVersion) {
                throw new BlogCategoryException(
                    BlogCategoryException::LOCK_CONFLICT
                );
            }
            $this->assertSlugAvailable(
                $locale,
                $draft->slug(),
                $current->localizationPublicId()
            );
            if (!$this->repository->updateLocalization(
                $current->localizationPublicId(),
                $expectedLockVersion,
                $draft,
                $actor,
                $now
            )) {
                throw new BlogCategoryException(
                    BlogCategoryException::LOCK_CONFLICT
                );
            }
            $this->repository->touchCategory($categoryPublicId, $now);
            $stored = $this->required($categoryPublicId, $locale);
            $this->audit(
                $pdo,
                BlogCategoryAuditEvent::SAVE,
                $actor,
                $categoryPublicId,
                $now
            );

            return $stored;
        });
    }

    /**
     * Deletes one localization using optimistic locking. The aggregate is
     * removed only when that was its final localization. Assigned categories
     * fail closed so neither live nor private workspace relations disappear.
     *
     * @param callable(PDO): string $actorGate
     * @return bool true when the category aggregate was also deleted
     */
    public function deleteLocalization(
        #[\SensitiveParameter] callable $actorGate,
        string $categoryPublicId,
        string $locale,
        int $expectedLockVersion
    ): bool {
        $categoryPublicId = BlogCategoryInput::publicId($categoryPublicId);
        $locale = BlogCategoryInput::locale($locale);
        BlogCategoryInput::expectedLockVersion($expectedLockVersion);
        $deletions = $this->repository;
        if (!$deletions instanceof BlogCategoryDeletionRepositoryInterface) {
            throw new BlogCategoryException(
                BlogCategoryException::STORAGE_UNAVAILABLE
            );
        }

        return $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $categoryPublicId,
            $locale,
            $expectedLockVersion,
            $deletions
        ): bool {
            $actor = $this->authorizedActor($actorGate, $pdo);
            $now = $this->now();
            if (!$this->repository->lockCategory($categoryPublicId)) {
                throw new BlogCategoryException(
                    BlogCategoryException::NOT_FOUND
                );
            }
            $current = $this->repository->lockLocalization(
                $categoryPublicId,
                $locale
            );
            if ($current === null) {
                throw new BlogCategoryException(
                    BlogCategoryException::NOT_FOUND
                );
            }
            $this->assertMutableCategory($categoryPublicId, $current);
            if ($current->lockVersion() !== $expectedLockVersion) {
                throw new BlogCategoryException(
                    BlogCategoryException::LOCK_CONFLICT
                );
            }
            if (
                $deletions->categoryHasAssignments($categoryPublicId)
                || ($this->workflowRepository !== null
                    && $this->workflowRepository
                        ->categoryHasWorkspaceReference(
                            $categoryPublicId,
                            true
                        ))
            ) {
                throw new BlogCategoryException(BlogCategoryException::IN_USE);
            }
            if (!$deletions->deleteLocalization(
                $current->localizationPublicId(),
                $expectedLockVersion
            )) {
                throw new BlogCategoryException(
                    BlogCategoryException::LOCK_CONFLICT
                );
            }
            $aggregateDeleted = $deletions->localizationCount(
                $categoryPublicId
            ) === 0;
            if ($aggregateDeleted) {
                if (!$deletions->deleteCategory($categoryPublicId)) {
                    throw new BlogCategoryException(
                        BlogCategoryException::LOCK_CONFLICT
                    );
                }
            } else {
                $this->repository->touchCategory($categoryPublicId, $now);
            }
            $this->audit(
                $pdo,
                BlogCategoryAuditEvent::DELETE,
                $actor,
                $categoryPublicId,
                $now
            );

            return $aggregateDeleted;
        });
    }

    /**
     * @param callable(PDO): string $actorGate
     * @param list<string> $categoryPublicIds
     */
    public function assignToPost(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        array $categoryPublicIds
    ): void {
        $postPublicId = BlogCategoryInput::publicId($postPublicId);
        if (
            !array_is_list($categoryPublicIds)
            || count($categoryPublicIds) > self::MAX_ASSIGNMENTS
        ) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
        $normalized = [];
        foreach ($categoryPublicIds as $publicId) {
            if (!is_string($publicId)) {
                throw new BlogCategoryException(
                    BlogCategoryException::INVALID_INPUT
                );
            }
            $publicId = BlogCategoryInput::publicId($publicId);
            if (isset($normalized[$publicId])) {
                throw new BlogCategoryException(
                    BlogCategoryException::INVALID_INPUT
                );
            }
            $normalized[$publicId] = $this->newPublicId();
        }

        $normalized = $this->preserveReservedAssignment(
            $normalized,
            $this->assignedToPost($postPublicId)
        );

        $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $postPublicId,
            $normalized
        ): void {
            $actor = $this->authorizedActor($actorGate, $pdo);
            $now = $this->now();
            if (!$this->repository->lockPost($postPublicId)) {
                throw new BlogCategoryException(
                    BlogCategoryException::POST_NOT_FOUND
                );
            }
            if (!$this->repository->categoriesExist(array_keys($normalized))) {
                throw new BlogCategoryException(
                    BlogCategoryException::NOT_FOUND
                );
            }
            $this->repository->replaceAssignments(
                $postPublicId,
                $normalized,
                $actor,
                $now
            );
            $this->audit(
                $pdo,
                BlogCategoryAuditEvent::ASSIGN,
                $actor,
                $postPublicId,
                $now
            );
        });
    }

    /**
     * Locale-aware editor entry point. With 0014 enabled, assignments always
     * stay in the shared post-wide workspace until an explicit publication.
     *
     * @param callable(PDO): string $actorGate
     * @param list<string> $categoryPublicIds
     */
    public function assignToVariant(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion,
        int $expectedWorkspaceVersion,
        array $categoryPublicIds
    ): int {
        $postPublicId = BlogCategoryInput::publicId($postPublicId);
        $locale = BlogCategoryInput::locale($locale);
        BlogCategoryInput::expectedLockVersion($expectedLockVersion);
        if ($expectedWorkspaceVersion < 0) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
        $normalized = $this->normalizedAssignments($categoryPublicIds);
        $normalized = $this->preserveReservedAssignment(
            $normalized,
            $this->assignedToVariant($postPublicId, $locale)
        );

        return $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $postPublicId,
            $locale,
            $expectedLockVersion,
            $expectedWorkspaceVersion,
            $normalized
        ): int {
            $actor = $this->authorizedActor($actorGate, $pdo);
            $now = $this->now();
            if (!$this->repository->lockPost($postPublicId)) {
                throw new BlogCategoryException(
                    BlogCategoryException::POST_NOT_FOUND
                );
            }
            if (!$this->repository->categoriesExist(array_keys($normalized))) {
                throw new BlogCategoryException(
                    BlogCategoryException::NOT_FOUND
                );
            }
            if ($this->workflowRepository === null) {
                $this->repository->replaceAssignments(
                    $postPublicId,
                    $normalized,
                    $actor,
                    $now
                );
                $this->audit(
                    $pdo,
                    BlogCategoryAuditEvent::ASSIGN,
                    $actor,
                    $postPublicId,
                    $now
                );

                return 0;
            }
            $state = $this->workflowRepository->variantState(
                $postPublicId,
                $locale,
                true
            );
            if ($state === null) {
                throw new BlogCategoryException(
                    BlogCategoryException::POST_NOT_FOUND
                );
            }
            if ($state->lockVersion() !== $expectedLockVersion) {
                throw new BlogCategoryException(
                    BlogCategoryException::LOCK_CONFLICT
                );
            }
            $actualWorkspaceVersion = $this->workflowRepository
                ->categoryWorkspaceVersion($postPublicId, true);
            if ($actualWorkspaceVersion !== $expectedWorkspaceVersion) {
                throw new BlogCategoryException(
                    BlogCategoryException::LOCK_CONFLICT
                );
            }
            $categoryAssignmentVersion = $this->workflowRepository
                ->categoryAssignmentVersion($postPublicId, true);
            $workspaceVersion = $this->workflowRepository
                ->replaceWorkspaceCategories(
                    $postPublicId,
                    array_keys($normalized),
                    $expectedWorkspaceVersion,
                    $categoryAssignmentVersion,
                    $actor,
                    $now
                );
            $this->audit(
                $pdo,
                BlogCategoryAuditEvent::ASSIGN,
                $actor,
                $postPublicId,
                $now
            );

            return $workspaceVersion;
        });
    }

    /** @return list<BlogCategoryLocalization> */
    public function list(
        int $limit = self::DEFAULT_LIST_LIMIT,
        int $offset = 0,
        ?string $locale = null
    ): array {
        $limit = BlogCategoryInput::listLimit($limit);
        $offset = BlogCategoryInput::listOffset($offset);
        $locale = $locale === null ? null : BlogCategoryInput::locale($locale);

        return $this->read(function () use ($limit, $offset, $locale): array {
            $reservedPublicId = $this->reservedCategoryPublicId();

            return array_values(array_filter(
                $this->repository->listLocalizations(
                    $limit,
                    $offset,
                    $locale
                ),
                static fn (BlogCategoryLocalization $category): bool =>
                    $category->categoryPublicId() !== $reservedPublicId
                    && !BlogReservedCategoryPolicy::isDummySlug(
                        $category->draft()->slug()
                    )
            ));
        });
    }

    public function load(
        string $categoryPublicId,
        string $locale
    ): BlogCategoryLocalization {
        $categoryPublicId = BlogCategoryInput::publicId($categoryPublicId);
        $locale = BlogCategoryInput::locale($locale);

        return $this->read(function () use (
            $categoryPublicId,
            $locale
        ): BlogCategoryLocalization {
            $category = $this->repository->category(
                $categoryPublicId,
                $locale
            );
            if ($category === null) {
                throw new BlogCategoryException(
                    BlogCategoryException::NOT_FOUND
                );
            }
            $this->assertMutableCategory($categoryPublicId, $category);

            return $category;
        });
    }

    /**
     * @param list<string> $candidateLocales
     * @return list<string>
     */
    public function localesForCategory(
        string $categoryPublicId,
        array $candidateLocales = []
    ): array {
        $categoryPublicId = BlogCategoryInput::publicId($categoryPublicId);
        if (
            !array_is_list($candidateLocales)
            || count($candidateLocales) > 100
        ) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
        $normalizedCandidates = [];
        foreach ($candidateLocales as $candidateLocale) {
            if (!is_string($candidateLocale)) {
                throw new BlogCategoryException(
                    BlogCategoryException::INVALID_INPUT
                );
            }
            $candidateLocale = BlogCategoryInput::locale($candidateLocale);
            if (isset($normalizedCandidates[$candidateLocale])) {
                throw new BlogCategoryException(
                    BlogCategoryException::INVALID_INPUT
                );
            }
            $normalizedCandidates[$candidateLocale] = true;
        }

        return $this->read(function () use (
            $categoryPublicId,
            $normalizedCandidates
        ): array {
            $this->assertMutableCategory($categoryPublicId);
            $locales = $this->repository instanceof
                BlogCategoryLocaleLookupRepositoryInterface
                ? $this->repository->categoryLocales($categoryPublicId)
                : $this->categoryLocalesFromBaseContract(
                    $categoryPublicId,
                    array_keys($normalizedCandidates)
                );
            if ($locales === null) {
                throw new BlogCategoryException(
                    BlogCategoryException::NOT_FOUND
                );
            }

            return $locales;
        });
    }

    /** @return list<string> */
    public function assignedToPost(string $postPublicId): array
    {
        $postPublicId = BlogCategoryInput::publicId($postPublicId);

        return $this->read(fn (): array =>
            $this->repository->assignedCategoryPublicIds($postPublicId));
    }

    public function privateWorkflowEnabled(): bool
    {
        return $this->workflowRepository !== null;
    }

    public function categoryWorkspaceVersion(string $postPublicId): int
    {
        $postPublicId = BlogCategoryInput::publicId($postPublicId);

        return $this->read(fn (): int => $this->workflowRepository === null
            ? 0
            : $this->workflowRepository->categoryWorkspaceVersion(
                $postPublicId
            ));
    }

    /** @return list<string> */
    public function assignedToVariant(
        string $postPublicId,
        string $locale
    ): array {
        $postPublicId = BlogCategoryInput::publicId($postPublicId);
        $locale = BlogCategoryInput::locale($locale);

        return $this->read(function () use ($postPublicId, $locale): array {
            if ($this->workflowRepository === null) {
                return $this->repository->assignedCategoryPublicIds(
                    $postPublicId
                );
            }
            $state = $this->workflowRepository->variantState(
                $postPublicId,
                $locale
            );
            if ($state === null) {
                throw new BlogCategoryException(
                    BlogCategoryException::POST_NOT_FOUND
                );
            }
            $private = $this->workflowRepository
                ->workspaceCategoryPublicIds($postPublicId);
            if ($private !== null) {
                return $private;
            }

            return $this->repository->assignedCategoryPublicIds(
                $postPublicId
            );
        });
    }

    public function reservedCategoryAssignedToVariant(
        string $postPublicId,
        string $locale
    ): bool {
        $reservedPublicId = $this->reservedCategoryPublicId();
        if ($reservedPublicId === null) {
            return false;
        }

        return in_array(
            $reservedPublicId,
            $this->assignedToVariant($postPublicId, $locale),
            true
        );
    }

    /**
     * @param list<string> $categoryPublicIds
     * @return array<string, string>
     */
    private function normalizedAssignments(array $categoryPublicIds): array
    {
        if (
            !array_is_list($categoryPublicIds)
            || count($categoryPublicIds) > self::MAX_ASSIGNMENTS
        ) {
            throw new BlogCategoryException(
                BlogCategoryException::INVALID_INPUT
            );
        }
        $normalized = [];
        foreach ($categoryPublicIds as $publicId) {
            if (!is_string($publicId)) {
                throw new BlogCategoryException(
                    BlogCategoryException::INVALID_INPUT
                );
            }
            $publicId = BlogCategoryInput::publicId($publicId);
            if (isset($normalized[$publicId])) {
                throw new BlogCategoryException(
                    BlogCategoryException::INVALID_INPUT
                );
            }
            $normalized[$publicId] = $this->newPublicId();
        }

        return $normalized;
    }

    /**
     * Ordinary assignment forms never expose the internal category. Preserve
     * an existing assignment and reject attempts to add it through this API.
     *
     * @param array<string, string> $normalized
     * @param list<string> $currentPublicIds
     * @return array<string, string>
     */
    private function preserveReservedAssignment(
        array $normalized,
        array $currentPublicIds
    ): array {
        $reservedPublicId = $this->reservedCategoryPublicId();
        if ($reservedPublicId === null) {
            return $normalized;
        }
        $currentlyAssigned = in_array(
            $reservedPublicId,
            $currentPublicIds,
            true
        );
        $requested = isset($normalized[$reservedPublicId]);
        if ($requested && !$currentlyAssigned) {
            throw new BlogCategoryException(BlogCategoryException::RESERVED);
        }
        if ($currentlyAssigned && !$requested) {
            $normalized[$reservedPublicId] = $this->newPublicId();
        }

        return $normalized;
    }

    public function reservedCategoryPublicId(): ?string
    {
        $repository = $this->repository;
        if (!$repository instanceof BlogReservedCategoryRepositoryInterface) {
            return null;
        }
        try {
            return $repository->reservedCategoryPublicId(
                BlogReservedCategoryPolicy::DUMMY_SLUG
            );
        } catch (Throwable) {
            throw new BlogCategoryException(
                BlogCategoryException::STORAGE_UNAVAILABLE
            );
        }
    }

    private function assertOrdinaryDraft(BlogCategoryDraft $draft): void
    {
        if (BlogReservedCategoryPolicy::isDummySlug($draft->slug())) {
            throw new BlogCategoryException(BlogCategoryException::RESERVED);
        }
    }

    private function assertMutableCategory(
        string $categoryPublicId,
        ?BlogCategoryLocalization $current = null
    ): void {
        if ($current !== null && BlogReservedCategoryPolicy::isDummySlug(
            $current->draft()->slug()
        )) {
            throw new BlogCategoryException(BlogCategoryException::RESERVED);
        }
        $repository = $this->repository;
        if (
            $repository instanceof BlogReservedCategoryRepositoryInterface
            && $repository->categoryHasReservedSlug(
                $categoryPublicId,
                BlogReservedCategoryPolicy::DUMMY_SLUG
            )
        ) {
            throw new BlogCategoryException(BlogCategoryException::RESERVED);
        }
    }

    /**
     * @param list<string> $candidateLocales
     * @return null|list<string>
     */
    private function categoryLocalesFromBaseContract(
        string $categoryPublicId,
        array $candidateLocales
    ): ?array {
        if ($candidateLocales === []) {
            throw new BlogCategoryPersistenceException();
        }
        $locales = [];
        foreach ($candidateLocales as $candidateLocale) {
            $localization = $this->repository->category(
                $categoryPublicId,
                $candidateLocale
            );
            if ($localization !== null) {
                $locales[$localization->locale()] = true;
            }
        }

        return $locales === [] ? null : array_keys($locales);
    }

    /** @template T @param callable(PDO): T $operation @return T */
    private function mutate(callable $operation): mixed
    {
        try {
            return $this->repository->transactional($operation);
        } catch (BlogCategoryException|BlogException $exception) {
            throw $exception;
        } catch (BlogCategoryPersistenceConflict) {
            throw new BlogCategoryException(
                BlogCategoryException::SLUG_CONFLICT
            );
        } catch (Throwable) {
            throw new BlogCategoryException(
                BlogCategoryException::STORAGE_UNAVAILABLE
            );
        }
    }

    /** @template T @param callable(): T $operation @return T */
    private function read(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (BlogCategoryException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogCategoryException(
                BlogCategoryException::STORAGE_UNAVAILABLE
            );
        }
    }

    /** @param callable(PDO): string $actorGate */
    private function authorizedActor(callable $actorGate, PDO $pdo): string
    {
        try {
            $actor = $actorGate($pdo);
            if (!is_string($actor)) {
                throw new \RuntimeException('Invalid actor gate result.');
            }

            return BlogCategoryInput::publicId($actor);
        } catch (Throwable) {
            throw new BlogException(BlogException::ACTOR_GATE_FAILED);
        }
    }

    private function newPublicId(): string
    {
        try {
            return BlogCategoryInput::generatedPublicId(
                $this->uuidGenerator->generateV4()
            );
        } catch (Throwable) {
            throw new BlogCategoryException(
                BlogCategoryException::STORAGE_UNAVAILABLE
            );
        }
    }

    private function now(): DateTimeImmutable
    {
        try {
            return BlogCategoryInput::utc($this->clock->now());
        } catch (Throwable) {
            throw new BlogCategoryException(
                BlogCategoryException::STORAGE_UNAVAILABLE
            );
        }
    }

    private function assertSlugAvailable(
        string $locale,
        string $slug,
        ?string $exceptLocalizationPublicId = null
    ): void {
        if ($this->repository->slugExists(
            $locale,
            $slug,
            $exceptLocalizationPublicId
        )) {
            throw new BlogCategoryException(
                BlogCategoryException::SLUG_CONFLICT
            );
        }
    }

    private function required(
        string $categoryPublicId,
        string $locale
    ): BlogCategoryLocalization {
        $stored = $this->repository->category($categoryPublicId, $locale);
        if ($stored === null) {
            throw new BlogCategoryPersistenceException();
        }

        return $stored;
    }

    private function audit(
        PDO $pdo,
        string $operation,
        string $actorPublicId,
        string $targetPublicId,
        DateTimeImmutable $occurredAt
    ): void {
        if ($this->auditPort === null) {
            return;
        }
        try {
            $this->auditPort->record($pdo, new BlogCategoryAuditEvent(
                $operation,
                $actorPublicId,
                $targetPublicId,
                $occurredAt
            ));
        } catch (Throwable) {
            throw new BlogCategoryException(
                BlogCategoryException::STORAGE_UNAVAILABLE
            );
        }
    }
}
