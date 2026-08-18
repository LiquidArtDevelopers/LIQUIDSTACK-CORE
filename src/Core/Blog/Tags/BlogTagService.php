<?php

declare(strict_types=1);

namespace App\Core\Blog\Tags;

use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\BlogException;
use App\Core\Blog\Tags\Persistence\BlogTagPersistenceConflict;
use App\Core\Blog\Tags\Persistence\BlogTagRepositoryInterface;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use PDO;
use Throwable;

/** Transactional application boundary for per-localization tag workspaces. */
final class BlogTagService
{
    public const MAX_TAGS_PER_VARIANT =
        BlogTagSetNormalizer::MAX_TAGS_PER_VARIANT;
    public const MAX_CSV_BYTES = BlogTagSetNormalizer::MAX_CSV_BYTES;

    public function __construct(
        private readonly BlogTagRepositoryInterface $repository,
        private readonly UuidGeneratorInterface $uuidGenerator =
            new RandomUuidV4Generator(),
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly ?BlogMutationAuditPortInterface $auditPort = null,
        private readonly BlogTagSetNormalizer $normalizer =
            new BlogTagSetNormalizer()
    ) {
    }

    /**
     * @param callable(PDO): string $actorGate
     */
    public function assignToVariant(
        #[\SensitiveParameter] callable $actorGate,
        string $postPublicId,
        string $locale,
        int $expectedLockVersion,
        int $expectedWorkspaceVersion,
        string $csv
    ): BlogTagAssignmentResult {
        $postPublicId = BlogTagInput::publicId($postPublicId);
        $locale = BlogTagInput::locale($locale);
        BlogTagInput::expectedLockVersion($expectedLockVersion);
        BlogTagInput::workspaceVersion($expectedWorkspaceVersion);
        $candidates = $this->normalizer->normalize($csv);

        return $this->mutate(function (PDO $pdo) use (
            $actorGate,
            $postPublicId,
            $locale,
            $expectedLockVersion,
            $expectedWorkspaceVersion,
            $candidates
        ): BlogTagAssignmentResult {
            $actor = $this->authorizedActor($actorGate, $pdo);
            $now = $this->now();
            $variant = $this->repository->variantState(
                $postPublicId,
                $locale,
                true
            );
            if ($variant === null) {
                throw new BlogTagException(
                    BlogTagException::VARIANT_NOT_FOUND
                );
            }
            if ($variant->lockVersion() !== $expectedLockVersion) {
                throw new BlogTagException(BlogTagException::LOCK_CONFLICT);
            }
            $localization = $variant->localizationPublicId();
            $workspace = $this->repository->workspaceState(
                $localization,
                true
            );
            $actualWorkspaceVersion = $workspace?->workspaceVersion() ?? 0;
            if ($actualWorkspaceVersion !== $expectedWorkspaceVersion) {
                throw new BlogTagException(BlogTagException::LOCK_CONFLICT);
            }
            $baseAssignmentVersion = $this->repository->assignmentVersion(
                $localization,
                true
            );
            if (
                $workspace !== null
                && $workspace->baseAssignmentVersion()
                    !== $baseAssignmentVersion
            ) {
                throw new BlogTagException(BlogTagException::LOCK_CONFLICT);
            }

            $tags = [];
            foreach ($candidates as $candidate) {
                $tags[] = $this->resolveOrCreate(
                    $locale,
                    $candidate,
                    $actor,
                    $now
                );
            }
            $tags = $this->canonicalTags($tags, $locale);
            $effective = $this->repository->workspaceTags($localization)
                ?? $this->repository->liveTags($localization);
            if ($this->sameTags($effective, $tags)) {
                return new BlogTagAssignmentResult(
                    $expectedLockVersion,
                    $actualWorkspaceVersion,
                    $tags
                );
            }
            $workspaceVersion = $this->repository->replaceWorkspace(
                $localization,
                array_map(
                    static fn (BlogTag $tag): string => $tag->publicId(),
                    $tags
                ),
                $expectedWorkspaceVersion,
                $baseAssignmentVersion,
                $actor,
                $now
            );
            $this->audit($pdo, $actor, $postPublicId, $now);

            return new BlogTagAssignmentResult(
                $expectedLockVersion,
                $workspaceVersion,
                $tags
            );
        });
    }

    /** @return list<BlogTag> */
    public function assignedToVariant(
        string $postPublicId,
        string $locale
    ): array {
        $postPublicId = BlogTagInput::publicId($postPublicId);
        $locale = BlogTagInput::locale($locale);

        return $this->read(function () use ($postPublicId, $locale): array {
            $variant = $this->repository->variantState(
                $postPublicId,
                $locale
            );
            if ($variant === null) {
                throw new BlogTagException(
                    BlogTagException::VARIANT_NOT_FOUND
                );
            }
            return $this->canonicalTags(
                $this->repository->workspaceTags(
                    $variant->localizationPublicId()
                ) ?? $this->repository->liveTags(
                    $variant->localizationPublicId()
                ),
                $locale
            );
        });
    }

    public function workspaceVersion(
        string $postPublicId,
        string $locale
    ): int {
        $postPublicId = BlogTagInput::publicId($postPublicId);
        $locale = BlogTagInput::locale($locale);

        return $this->read(function () use ($postPublicId, $locale): int {
            $variant = $this->repository->variantState(
                $postPublicId,
                $locale
            );
            if ($variant === null) {
                throw new BlogTagException(
                    BlogTagException::VARIANT_NOT_FOUND
                );
            }

            return $this->repository->workspaceState(
                $variant->localizationPublicId()
            )?->workspaceVersion() ?? 0;
        });
    }

    private function resolveOrCreate(
        string $locale,
        BlogTagCandidate $candidate,
        string $actor,
        DateTimeImmutable $now
    ): BlogTag {
        $stored = $this->repository->tagByIdentity(
            $locale,
            $candidate->normalizedSha256()
        );
        if ($stored !== null) {
            return $stored;
        }
        $slug = $candidate->baseSlug();
        $slugOwner = $this->repository->tagBySlug($locale, $slug);
        if ($slugOwner !== null) {
            $slug = BlogTagSlug::collision(
                $slug,
                $candidate->normalizedSha256()
            );
        }
        $publicId = $this->newPublicId();
        try {
            $this->repository->insertTag(
                $publicId,
                $locale,
                $slug,
                $candidate->name(),
                $candidate->normalizedSha256(),
                $actor,
                $now
            );
        } catch (BlogTagPersistenceConflict) {
            $concurrent = $this->repository->tagByIdentity(
                $locale,
                $candidate->normalizedSha256(),
                true
            );
            if ($concurrent !== null) {
                return $concurrent;
            }
            $owner = $this->repository->tagBySlug($locale, $slug, true);
            if ($owner === null) {
                throw new BlogTagException(
                    BlogTagException::STORAGE_UNAVAILABLE
                );
            }
            $slug = BlogTagSlug::collision(
                $candidate->baseSlug(),
                $candidate->normalizedSha256()
            );
            try {
                $this->repository->insertTag(
                    $publicId,
                    $locale,
                    $slug,
                    $candidate->name(),
                    $candidate->normalizedSha256(),
                    $actor,
                    $now
                );
            } catch (BlogTagPersistenceConflict) {
                $concurrent = $this->repository->tagByIdentity(
                    $locale,
                    $candidate->normalizedSha256(),
                    true
                );
                if ($concurrent !== null) {
                    return $concurrent;
                }
                throw new BlogTagException(
                    BlogTagException::STORAGE_UNAVAILABLE
                );
            }
        }

        return new BlogTag(
            $publicId,
            $locale,
            $slug,
            $candidate->name(),
            $candidate->normalizedSha256()
        );
    }

    /** @param list<BlogTag> $tags @return list<BlogTag> */
    private function canonicalTags(array $tags, string $locale): array
    {
        if (!array_is_list($tags) || count($tags) > self::MAX_TAGS_PER_VARIANT) {
            throw new BlogTagException(
                BlogTagException::STORAGE_UNAVAILABLE
            );
        }
        $seen = [];
        foreach ($tags as $tag) {
            if (!$tag instanceof BlogTag || $tag->locale() !== $locale) {
                throw new BlogTagException(
                    BlogTagException::STORAGE_UNAVAILABLE
                );
            }
            if (isset($seen[$tag->publicId()])) {
                throw new BlogTagException(
                    BlogTagException::STORAGE_UNAVAILABLE
                );
            }
            $seen[$tag->publicId()] = true;
        }
        usort(
            $tags,
            static fn (BlogTag $left, BlogTag $right): int =>
                strcmp($left->slug(), $right->slug())
        );

        return $tags;
    }

    /** @param list<BlogTag> $left @param list<BlogTag> $right */
    private function sameTags(array $left, array $right): bool
    {
        $ids = static fn (array $tags): array => array_map(
            static fn (BlogTag $tag): string => $tag->publicId(),
            $tags
        );
        $first = $ids($left);
        $second = $ids($right);
        sort($first, SORT_STRING);
        sort($second, SORT_STRING);

        return $first === $second;
    }

    /** @template T @param callable(PDO): T $operation @return T */
    private function mutate(callable $operation): mixed
    {
        try {
            return $this->repository->transactional($operation);
        } catch (BlogTagPersistenceConflict) {
            throw new BlogTagException(BlogTagException::LOCK_CONFLICT);
        } catch (BlogTagException|BlogException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogTagException(
                BlogTagException::STORAGE_UNAVAILABLE
            );
        }
    }

    /** @template T @param callable(): T $operation @return T */
    private function read(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (BlogTagException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogTagException(
                BlogTagException::STORAGE_UNAVAILABLE
            );
        }
    }

    /** @param callable(PDO): string $actorGate */
    private function authorizedActor(callable $actorGate, PDO $pdo): string
    {
        try {
            $actor = $actorGate($pdo);
            if (!is_string($actor)) {
                throw new \RuntimeException('Invalid actor.');
            }
            return BlogTagInput::publicId($actor);
        } catch (Throwable) {
            throw new BlogException(BlogException::ACTOR_GATE_FAILED);
        }
    }

    private function newPublicId(): string
    {
        try {
            return BlogTagInput::generatedPublicId(
                $this->uuidGenerator->generateV4()
            );
        } catch (Throwable) {
            throw new BlogTagException(
                BlogTagException::STORAGE_UNAVAILABLE
            );
        }
    }

    private function now(): DateTimeImmutable
    {
        try {
            return BlogTagInput::utc($this->clock->now());
        } catch (Throwable) {
            throw new BlogTagException(
                BlogTagException::STORAGE_UNAVAILABLE
            );
        }
    }

    private function audit(
        PDO $pdo,
        string $actor,
        string $post,
        DateTimeImmutable $now
    ): void {
        $this->auditPort?->record(
            $pdo,
            new BlogMutationAuditEvent(
                BlogMutationAuditEvent::SAVE,
                $actor,
                $post,
                $now
            )
        );
    }
}
