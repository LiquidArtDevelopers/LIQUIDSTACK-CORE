<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Adoption;

use App\Core\Blog\Admin\BlogAdminCatalogQuery;
use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV2Projector;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredEditorService;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredDocumentRecord;
use PDO;
use Throwable;

/**
 * Plans globally, then adopts each unchanged snapshot through the editor's
 * ordinary transactional save boundary. Published variants therefore only
 * advance their private workspace and are never republished here.
 */
final class BlogUnifiedTextAdoptionService
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly BlogService $catalog,
        private readonly BlogStructuredEditorService $editor,
        private readonly PdoBlogUnifiedTextAdoptionActorGate $actorGate,
        private readonly string $driver,
        private readonly BlogDocumentV2Projector $projector =
            new BlogDocumentV2Projector()
    ) {
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            throw $this->failure('driver_unsupported');
        }
    }

    public function run(
        BlogUnifiedTextAdoptionRequest $request
    ): BlogUnifiedTextAdoptionResult {
        try {
            if ($request->apply()) {
                $actor = $request->actorPublicId();
                if ($actor === null) {
                    throw $this->failure('actor_required');
                }
                $this->actorGate->assertEligible($actor);
            }

            $candidates = $this->candidates($request);
            $unstructured = 0;
            $ineligible = 0;
            $already = 0;
            $draftPending = 0;
            $publishedPending = 0;
            /** @var list<array{summary: BlogPostSummary, snapshot_sha256: string}> $plans */
            $plans = [];

            foreach ($candidates as $summary) {
                $state = $this->editor->loadEditor(
                    $summary->postPublicId(),
                    $summary->locale()
                );
                $snapshot = $state->workingSnapshot();
                if ($snapshot === null) {
                    ++$unstructured;
                    continue;
                }
                $projected = $this->projector->tryProject(
                    $snapshot->document()
                );
                if ($projected === null) {
                    ++$ineligible;
                    continue;
                }
                if ($this->sameDocument($snapshot->document(), $projected)) {
                    ++$already;
                    continue;
                }
                $current = $state->variant();
                $plans[] = [
                    'summary' => $this->summaryAtCurrentVersion(
                        $summary,
                        $current
                    ),
                    'snapshot_sha256' => $snapshot->snapshotSha256(),
                ];
                if ($current->status() === BlogPostVariant::PUBLISHED) {
                    ++$publishedPending;
                } else {
                    ++$draftPending;
                }
            }

            if ($request->apply()) {
                $this->assertPlansStillCurrent($plans);
                $this->applyPlans(
                    $plans,
                    (string) $request->actorPublicId()
                );
            }
            $pendingVariants = array_map(
                static fn (array $plan): array => [
                    'post_public_id' =>
                        $plan['summary']->postPublicId(),
                    'locale' => $plan['summary']->locale(),
                    'status' => $plan['summary']->status(),
                    'lock_version' => $plan['summary']->lockVersion(),
                    'snapshot_sha256' => $plan['snapshot_sha256'],
                ],
                $plans
            );

            return new BlogUnifiedTextAdoptionResult(
                $request->apply(),
                $this->driver,
                count($candidates),
                $unstructured,
                $ineligible,
                $already,
                $draftPending,
                $publishedPending,
                $pendingVariants,
                $request->apply() ? count($plans) : 0
            );
        } catch (BlogUnifiedTextAdoptionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure('operation_failed');
        }
    }

    /** @return list<BlogPostSummary> */
    private function candidates(BlogUnifiedTextAdoptionRequest $request): array
    {
        $locales = $request->locales() ?: [null];
        $statuses = $request->statuses() ?: [null];
        $postFilter = array_fill_keys($request->postPublicIds(), true);
        $found = [];

        foreach ($locales as $locale) {
            foreach ($statuses as $status) {
                $offset = 0;
                do {
                    $rows = $this->catalog->searchPosts(
                        new BlogAdminCatalogQuery(
                            status: $status,
                            locale: $locale,
                            offset: $offset,
                            pageSize: self::PAGE_SIZE,
                            sort: BlogAdminCatalogQuery::SORT_UPDATED,
                            direction: BlogAdminCatalogQuery::DIRECTION_ASC
                        )
                    );
                    $hasMore = count($rows) > self::PAGE_SIZE;
                    foreach (array_slice($rows, 0, self::PAGE_SIZE) as $row) {
                        if (
                            $postFilter !== []
                            && !isset($postFilter[$row->postPublicId()])
                        ) {
                            continue;
                        }
                        $found[$row->localizationPublicId()] = $row;
                        if (count($found) > $request->maximumCandidates()) {
                            throw $this->failure('catalog_overflow');
                        }
                    }
                    $offset += self::PAGE_SIZE;
                } while ($hasMore);
            }
        }

        $result = array_values($found);
        usort(
            $result,
            static fn (BlogPostSummary $a, BlogPostSummary $b): int =>
                [$a->postPublicId(), $a->locale(), $a->localizationPublicId()]
                <=>
                [$b->postPublicId(), $b->locale(), $b->localizationPublicId()]
        );

        return $result;
    }

    /**
     * @param list<array{summary: BlogPostSummary, snapshot_sha256: string}> $plans
     */
    private function assertPlansStillCurrent(array $plans): void
    {
        foreach ($plans as $plan) {
            $summary = $plan['summary'];
            $state = $this->editor->loadEditor(
                $summary->postPublicId(),
                $summary->locale()
            );
            $snapshot = $state->workingSnapshot();
            $variant = $state->variant();
            $projected = $snapshot === null
                ? null
                : $this->projector->tryProject($snapshot->document());
            if (
                $snapshot === null
                || $projected === null
                || $variant->lockVersion() !== $summary->lockVersion()
                || $variant->status() !== $summary->status()
                || !hash_equals(
                    $plan['snapshot_sha256'],
                    $snapshot->snapshotSha256()
                )
                || $this->sameDocument($snapshot->document(), $projected)
            ) {
                throw $this->failure('preflight_stale');
            }
        }
    }

    /**
     * @param list<array{summary: BlogPostSummary, snapshot_sha256: string}> $plans
     */
    private function applyPlans(array $plans, string $actorPublicId): void
    {
        $actorGate = $this->actorGate;
        foreach ($plans as $plan) {
            $summary = $plan['summary'];
            $state = $this->editor->loadEditor(
                $summary->postPublicId(),
                $summary->locale()
            );
            $snapshot = $state->workingSnapshot();
            $projected = $snapshot === null
                ? null
                : $this->projector->tryProject($snapshot->document());
            if (
                $snapshot === null
                || $projected === null
                || $state->variant()->lockVersion()
                    !== $summary->lockVersion()
                || $state->variant()->status() !== $summary->status()
                || !hash_equals(
                    $plan['snapshot_sha256'],
                    $snapshot->snapshotSha256()
                )
                || $this->sameDocument($snapshot->document(), $projected)
            ) {
                throw $this->failure('apply_stale');
            }
            $published = $summary->status() === BlogPostVariant::PUBLISHED;
            $beforePublic = $state->current();
            $beforeWorkspaceRevision = $state->workspace()
                ?->draftRevisionPublicId();
            $beforeWorkspaceBase = $state->workspace()
                ?->basePublicationVersion();
            if ($published && $beforePublic === null) {
                throw $this->failure('published_current_missing');
            }
            $projectedDraft = $this->projectedDraft($snapshot, $projected);
            $stored = $this->editor->save(
                static fn (PDO $pdo): string => $actorGate->authorize(
                    $pdo,
                    $actorPublicId,
                    $summary->postPublicId(),
                    $summary->locale()
                ),
                $summary->postPublicId(),
                $summary->locale(),
                $summary->lockVersion(),
                $projectedDraft
            );
            if (
                $stored->lockVersion() !== $summary->lockVersion() + 1
                || $stored->status() !== $summary->status()
            ) {
                throw $this->failure('postcondition_failed');
            }
            $after = $this->editor->loadEditor(
                $summary->postPublicId(),
                $summary->locale()
            );
            if (
                $after->variant()->lockVersion() !== $stored->lockVersion()
                || $after->variant()->status() !== $summary->status()
                || !$this->sameSnapshot(
                    $after->workingSnapshot(),
                    $projectedDraft
                )
            ) {
                throw $this->failure('postcondition_failed');
            }
            if ($published) {
                $afterPublic = $after->current();
                $afterWorkspace = $after->workspace();
                if (
                    $beforePublic === null
                    || $afterPublic === null
                    || $afterWorkspace === null
                    || !$after->hasPrivateDraft()
                    || $afterWorkspace->draftRevisionPublicId() === null
                    || $afterWorkspace->draftRevisionPublicId()
                        === $beforeWorkspaceRevision
                    || (
                        $beforeWorkspaceBase !== null
                        && $afterWorkspace->basePublicationVersion()
                            !== $beforeWorkspaceBase
                    )
                    || !$this->samePublicCurrent(
                        $beforePublic,
                        $afterPublic
                    )
                ) {
                    throw $this->failure('published_postcondition_failed');
                }
                continue;
            }
            if (
                $after->current() === null
                || !$this->sameSnapshot(
                    $after->current()?->snapshot(),
                    $projectedDraft
                )
            ) {
                throw $this->failure('draft_postcondition_failed');
            }
        }
    }

    private function projectedDraft(
        BlogStructuredDraft $snapshot,
        BlogDocument $projected
    ): BlogStructuredDraft {
        $metadata = $snapshot->compatibilityDraft();

        return new BlogStructuredDraft(
            $metadata->h1(),
            $projected,
            $metadata->slug(),
            $metadata->seoTitle(),
            $metadata->metaDescription(),
            $metadata->excerpt(),
            robotsPreferences: $snapshot->robotsPreferences()
        );
    }

    private function sameDocument(
        BlogDocument $first,
        BlogDocument $second
    ): bool {
        return $first->toArray() === $second->toArray();
    }

    private function sameSnapshot(
        ?BlogStructuredDraft $actual,
        BlogStructuredDraft $expected
    ): bool {
        return $actual !== null
            && hash_equals(
                $expected->snapshotSha256(),
                $actual->snapshotSha256()
            )
            && hash_equals(
                $expected->canonicalJson(),
                $actual->canonicalJson()
            )
            && $actual->document()->toArray()
                === $expected->document()->toArray();
    }

    private function samePublicCurrent(
        BlogStructuredDocumentRecord $before,
        BlogStructuredDocumentRecord $after
    ): bool {
        return $before->documentPublicId() === $after->documentPublicId()
            && hash_equals(
                $before->snapshot()->snapshotSha256(),
                $after->snapshot()->snapshotSha256()
            )
            && hash_equals(
                $before->snapshot()->canonicalJson(),
                $after->snapshot()->canonicalJson()
            )
            && $before->snapshot()->document()->toArray()
                === $after->snapshot()->document()->toArray();
    }

    private function summaryAtCurrentVersion(
        BlogPostSummary $summary,
        BlogPostVariant $current
    ): BlogPostSummary {
        if (
            $summary->postPublicId() !== $current->postPublicId()
            || $summary->localizationPublicId()
                !== $current->localizationPublicId()
            || $summary->locale() !== $current->locale()
        ) {
            throw $this->failure('catalog_state_mismatch');
        }

        return new BlogPostSummary(
            $current->postPublicId(),
            $current->localizationPublicId(),
            $current->locale(),
            $current->draft()->slug(),
            $current->draft()->h1(),
            $current->status(),
            $current->publishedAt(),
            $current->lockVersion(),
            $summary->updatedAt(),
            robotsPreferences: $current->draft()->robotsPreferences()
        );
    }

    private function failure(string $suffix): BlogUnifiedTextAdoptionException
    {
        return new BlogUnifiedTextAdoptionException(
            'blog.unified_text_adoption.' . $suffix
        );
    }
}
