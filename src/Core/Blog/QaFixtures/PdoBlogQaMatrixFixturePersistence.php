<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogInput;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Blog\EditorialWorkflow\Persistence\PdoBlogEditorialWorkspaceRepository;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\Seo\BlogUrlResolution;
use App\Core\Blog\Seo\PdoBlogUrlHistoryRepository;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\SystemClock;
use DateTimeImmutable;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Narrow CLI-only write port for code-owned QA fixtures.
 *
 * It deliberately bypasses the ordinary category service because that HTTP
 * boundary must reject Dummy. No route or service factory exposes this port.
 */
final class PdoBlogQaMatrixFixturePersistence implements
    BlogQaMatrixFixturePersistencePortInterface
{
    private readonly string $driver;
    private readonly string $posts;
    private readonly string $localizations;
    private readonly string $categories;
    private readonly string $categoryLocales;
    private readonly string $postCategories;
    private readonly string $contentDocuments;
    private readonly string $contentRevisions;
    private readonly PdoBlogRepository $blogRepository;
    private readonly PdoBlogStructuredContentRepository $contentRepository;
    private readonly PdoBlogEditorialWorkspaceRepository $workflowRepository;
    private readonly PdoBlogUrlHistoryRepository $urlHistory;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $blogScope,
        private readonly WebAdminTableNames $webAdminTables,
        private readonly BlogMutationAuditPortInterface $audit,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly BlogQaMatrixFixtureSnapshotComparator $snapshots =
            new BlogQaMatrixFixtureSnapshotComparator()
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, ['sqlite', 'mysql'], true)
                || $driver !== $webAdminTables->driver()
                || $blogScope->moduleId() !== 'blog'
                || $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || ($driver === 'mysql' && !in_array(
                    $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                    [false, 0, '0'],
                    true
                ))
                || ($driver === 'sqlite' && !in_array(
                    $pdo->query('PRAGMA foreign_keys')->fetchColumn(),
                    [1, '1'],
                    true
                ))
            ) {
                throw $this->failure('storage_contract_invalid');
            }
            $this->driver = $driver;
            $this->posts = $blogScope->quotedTable('posts', $driver);
            $this->localizations = $blogScope->quotedTable(
                'post_localizations',
                $driver
            );
            $this->categories = $blogScope->quotedTable(
                'categories',
                $driver
            );
            $this->categoryLocales = $blogScope->quotedTable(
                'category_locales',
                $driver
            );
            $this->postCategories = $blogScope->quotedTable(
                'post_categories',
                $driver
            );
            $this->contentDocuments = $blogScope->quotedTable(
                'content_docs',
                $driver
            );
            $this->contentRevisions = $blogScope->quotedTable(
                'content_revisions',
                $driver
            );
            $this->blogRepository = new PdoBlogRepository(
                $pdo,
                $blogScope,
                robotsSettingsEnabled: true,
                reservedCategoryPolicyEnabled: true
            );
            $this->contentRepository =
                new PdoBlogStructuredContentRepository(
                    $pdo,
                    $blogScope,
                    layoutReady: true,
                    robotsSettingsReady: true
                );
            $this->workflowRepository =
                new PdoBlogEditorialWorkspaceRepository(
                    $pdo,
                    $blogScope,
                    true
                );
            $this->urlHistory = new PdoBlogUrlHistoryRepository(
                $pdo,
                $blogScope
            );
        } catch (BlogQaMatrixFixtureException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure('storage_contract_invalid');
        }
    }

    public function run(
        array $articles,
        array $requestedLocales,
        string $actorPublicId,
        bool $apply
    ): BlogQaMatrixFixtureResult {
        try {
            $articles = $this->articles($articles);
            $requestedLocales = $this->requestedLocales($requestedLocales);
            BlogInput::publicId($actorPublicId);
            $this->assertActorActive($actorPublicId);
            $dummyCategoryPublicId = $this->dummyCategoryPublicId();
            $plans = $this->preflight(
                $articles,
                $requestedLocales,
                $dummyCategoryPublicId
            );
            $pendingVariants = array_sum(array_map(
                static fn (array $plan): int => count(
                    $plan['pending_locales']
                ),
                $plans
            ));
            $pendingAggregates = count(array_filter(
                $plans,
                static fn (array $plan): bool =>
                    $plan['pending_locales'] !== []
            ));
            $requestedVariants = count($articles)
                * count($requestedLocales);
            if ($apply) {
                foreach ($plans as $plan) {
                    if ($plan['pending_locales'] === []) {
                        continue;
                    }
                    $this->applyAggregate(
                        $plan,
                        $dummyCategoryPublicId,
                        $actorPublicId
                    );
                }
            }

            return new BlogQaMatrixFixtureResult(
                $apply,
                $this->driver,
                $requestedLocales,
                count($articles),
                $requestedVariants,
                $pendingAggregates,
                $pendingVariants,
                $requestedVariants - $pendingVariants
            );
        } catch (BlogQaMatrixFixtureException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure('preflight_failed');
        }
    }

    /**
     * @param list<BlogQaMatrixFixtureArticle> $articles
     * @param list<string> $requestedLocales
     * @return list<array{
     *     article: BlogQaMatrixFixtureArticle,
     *     create_post: bool,
     *     pending_locales: list<string>
     * }>
     */
    private function preflight(
        array $articles,
        array $requestedLocales,
        string $dummyCategoryPublicId
    ): array {
        $plans = [];
        foreach ($articles as $article) {
            $this->assertStaticIdentifiers($article);
            $postExists = $this->postExists($article->postPublicId());
            $rows = $this->localizationRows($article->postPublicId());
            if (!$postExists && $rows !== []) {
                throw $this->failure('aggregate_identity_conflict');
            }
            if ($postExists) {
                if ($rows === []) {
                    throw $this->failure('aggregate_state_conflict');
                }
                $assigned = $this->assignedCategories(
                    $article->postPublicId()
                );
                if ($assigned !== [$dummyCategoryPublicId]) {
                    throw $this->failure('dummy_assignment_conflict');
                }
                if (
                    $this->workflowRepository->categoryAssignmentVersion(
                        $article->postPublicId()
                    ) !== 1
                    || $this->workflowRepository->categoryWorkspaceVersion(
                        $article->postPublicId()
                    ) !== 0
                ) {
                    throw $this->failure('category_state_conflict');
                }
            }

            $existingLocales = [];
            foreach ($rows as $row) {
                $locale = $this->requiredString($row, 'locale');
                $publicId = $this->requiredString($row, 'public_id');
                if (
                    !in_array(
                        $locale,
                        BlogQaMatrixFixtureCatalog::LOCALES,
                        true
                    )
                    || $publicId !== $article->variant($locale)
                        ->localizationPublicId()
                    || isset($existingLocales[$locale])
                ) {
                    throw $this->failure('aggregate_state_conflict');
                }
                $this->assertExistingVariant(
                    $article,
                    $article->variant($locale)
                );
                $existingLocales[$locale] = true;
            }

            $pending = [];
            foreach ($requestedLocales as $locale) {
                if (!isset($existingLocales[$locale])) {
                    $pending[] = $locale;
                }
            }
            $plans[] = [
                'article' => $article,
                'create_post' => !$postExists,
                'pending_locales' => $pending,
            ];
        }

        return $plans;
    }

    /**
     * @param array{
     *     article: BlogQaMatrixFixtureArticle,
     *     create_post: bool,
     *     pending_locales: list<string>
     * } $plan
     */
    private function applyAggregate(
        array $plan,
        string $dummyCategoryPublicId,
        string $actorPublicId
    ): void {
        $article = $plan['article'];
        try {
            $this->blogRepository->transactional(
                function (PDO $transaction) use (
                    $article,
                    $plan,
                    $dummyCategoryPublicId,
                    $actorPublicId
                ): void {
                    if ($transaction !== $this->pdo) {
                        throw $this->failure('transaction_invalid');
                    }
                    $now = $this->clock->now();
                    if ($plan['create_post']) {
                        if ($this->postExists($article->postPublicId())) {
                            throw $this->failure('aggregate_race_conflict');
                        }
                        $this->blogRepository->insertPost(
                            $article->postPublicId(),
                            $actorPublicId,
                            $now
                        );
                        $this->workflowRepository->replaceLiveCategories(
                            $article->postPublicId(),
                            [
                                $dummyCategoryPublicId =>
                                    $article->assignmentPublicId(),
                            ],
                            $actorPublicId,
                            $now
                        );
                        if ($this->workflowRepository
                            ->promoteCategoryAssignments(
                                $article->postPublicId(),
                                0,
                                $actorPublicId,
                                $now
                            ) !== 1
                        ) {
                            throw $this->failure('category_publish_failed');
                        }
                    } elseif (!$this->blogRepository->lockPost(
                        $article->postPublicId()
                    )) {
                        throw $this->failure('aggregate_race_conflict');
                    }

                    foreach ($plan['pending_locales'] as $locale) {
                        $variant = $article->variant($locale);
                        $slug = $variant->draft()->compatibilityDraft()->slug();
                        if (
                            $slug === null
                            || $this->blogRepository->variant(
                                $article->postPublicId(),
                                $locale
                            ) !== null
                            || $this->blogRepository->slugExists(
                                $locale,
                                $slug
                            )
                        ) {
                            throw $this->failure('variant_race_conflict');
                        }
                        $this->insertVariant(
                            $article,
                            $variant,
                            $actorPublicId,
                            $now
                        );
                    }
                    if (!$plan['create_post']) {
                        $this->blogRepository->touchPost(
                            $article->postPublicId(),
                            $now
                        );
                    }
                    if (
                        $this->assignedCategories($article->postPublicId())
                            !== [$dummyCategoryPublicId]
                        || $this->workflowRepository
                            ->categoryAssignmentVersion(
                                $article->postPublicId()
                            ) !== 1
                    ) {
                        throw $this->failure('aggregate_postcondition_failed');
                    }
                    foreach ($plan['pending_locales'] as $locale) {
                        $this->assertExistingVariant(
                            $article,
                            $article->variant($locale)
                        );
                    }
                    $this->audit->record(
                        $transaction,
                        new BlogMutationAuditEvent(
                            $plan['create_post']
                                ? BlogMutationAuditEvent::CREATE
                                : BlogMutationAuditEvent::ADD_LOCALE,
                            $actorPublicId,
                            $article->postPublicId(),
                            $now
                        )
                    );
                    $this->audit->record(
                        $transaction,
                        new BlogMutationAuditEvent(
                            BlogMutationAuditEvent::PUBLISH,
                            $actorPublicId,
                            $article->postPublicId(),
                            $now
                        )
                    );
                }
            );
        } catch (BlogQaMatrixFixtureException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure('aggregate_apply_failed');
        }
    }

    private function insertVariant(
        BlogQaMatrixFixtureArticle $article,
        BlogQaMatrixFixtureVariant $variant,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $draft = $variant->draft();
        $plain = $draft->compatibilityDraft();
        $this->blogRepository->insertLocalization(
            $variant->localizationPublicId(),
            $article->postPublicId(),
            $variant->locale(),
            $plain,
            $actorPublicId,
            $now
        );
        $this->contentRepository->upsertCurrent(
            $variant->localizationPublicId(),
            $variant->documentPublicId(),
            $draft,
            $actorPublicId,
            $now
        );
        $this->contentRepository->replaceCurrentMedia(
            $variant->localizationPublicId(),
            $draft->mediaReferences(),
            $now
        );
        if ($this->contentRepository->appendRevision(
            $variant->localizationPublicId(),
            $variant->revisionPublicId(),
            1,
            $draft,
            $actorPublicId,
            $now
        ) !== 1) {
            throw $this->failure('revision_create_failed');
        }
        $this->contentRepository->appendRevisionMedia(
            $variant->revisionPublicId(),
            $draft->mediaReferences(),
            $now
        );
        if (!$this->workflowRepository->publishSnapshot(
            $variant->localizationPublicId(),
            1,
            BlogPostVariant::DRAFT,
            $plain,
            $actorPublicId,
            $now
        )) {
            throw $this->failure('variant_publish_failed');
        }
        if ($this->workflowRepository->promotePublicationHead(
            $variant->localizationPublicId(),
            $variant->revisionPublicId(),
            0,
            $actorPublicId,
            $now
        ) !== 1) {
            throw $this->failure('publication_head_failed');
        }
        $slug = $plain->slug();
        if ($slug === null) {
            throw $this->failure('variant_not_publishable');
        }
        $this->urlHistory->activate(
            $variant->localizationPublicId(),
            $variant->locale(),
            $slug,
            $now
        );
    }

    private function assertExistingVariant(
        BlogQaMatrixFixtureArticle $article,
        BlogQaMatrixFixtureVariant $expected
    ): void {
        $stored = $this->blogRepository->variant(
            $article->postPublicId(),
            $expected->locale()
        );
        if (
            $stored === null
            || $stored->localizationPublicId()
                !== $expected->localizationPublicId()
            || $stored->status() !== BlogPostVariant::PUBLISHED
            || $stored->lockVersion() !== 2
            || !$this->sameDraft(
                $stored->draft(),
                $expected->draft()->compatibilityDraft()
            )
        ) {
            throw $this->failure('variant_state_conflict');
        }

        $current = $this->contentRepository->current(
            $expected->localizationPublicId()
        );
        if (
            $current === null
            || $current->documentPublicId() !== $expected->documentPublicId()
            || !$this->snapshots->matches(
                $current->snapshot(),
                $expected->draft()
            )
        ) {
            throw $this->failure('document_state_conflict');
        }

        $revisions = $this->contentRepository->listRevisions(
            $expected->localizationPublicId(),
            2,
            0
        );
        $revision = $this->contentRepository->revision(
            $expected->revisionPublicId()
        );
        if (
            count($revisions) !== 1
            || $revision === null
            || $revision->revisionPublicId()
                !== $expected->revisionPublicId()
            || $revision->revisionNumber() !== 1
            || $revision->variantLockVersion() !== 1
            || !$this->snapshots->matches(
                $revision->snapshot(),
                $expected->draft()
            )
        ) {
            throw $this->failure('revision_state_conflict');
        }

        $head = $this->workflowRepository->publicationHead(
            $expected->localizationPublicId()
        );
        $resolution = $this->urlHistory->resolve(
            $expected->locale(),
            (string) $expected->draft()->compatibilityDraft()->slug()
        );
        if (
            $head === null
            || $head->revisionPublicId() !== $expected->revisionPublicId()
            || $head->publicationVersion() !== 1
            || $this->workflowRepository->workspace(
                $expected->localizationPublicId()
            ) !== null
            || $resolution === null
            || $resolution->state() !== BlogUrlResolution::ACTIVE
        ) {
            throw $this->failure('publication_state_conflict');
        }
    }

    private function sameDraft(BlogDraft $first, BlogDraft $second): bool
    {
        return $first->h1() === $second->h1()
            && $first->bodyText() === $second->bodyText()
            && $first->slug() === $second->slug()
            && $first->seoTitle() === $second->seoTitle()
            && $first->metaDescription() === $second->metaDescription()
            && $first->excerpt() === $second->excerpt()
            && $first->robotsPreferences()->equals(
                $second->robotsPreferences()
            );
    }

    private function assertStaticIdentifiers(
        BlogQaMatrixFixtureArticle $article
    ): void {
        $post = $this->one(
            'SELECT public_id FROM ' . $this->posts
                . ' WHERE public_id = :public_id',
            ['public_id' => $article->postPublicId()]
        );
        $assignment = $this->one(
            'SELECT p.public_id AS post_public_id, c.public_id AS '
                . 'category_public_id FROM ' . $this->postCategories
                . ' pc JOIN ' . $this->posts . ' p ON p.id = pc.post_id '
                . 'JOIN ' . $this->categories
                . ' c ON c.id = pc.category_id WHERE pc.public_id = :public',
            ['public' => $article->assignmentPublicId()]
        );
        if ($assignment !== null && (
            $post === null
            || $this->requiredString($assignment, 'post_public_id')
                !== $article->postPublicId()
        )) {
            throw $this->failure('assignment_identity_conflict');
        }

        foreach ($article->variants() as $variant) {
            $localization = $this->one(
                'SELECT p.public_id AS post_public_id, l.locale FROM '
                    . $this->localizations . ' l JOIN ' . $this->posts
                    . ' p ON p.id = l.post_id WHERE l.public_id = :public',
                ['public' => $variant->localizationPublicId()]
            );
            if ($localization !== null && (
                $this->requiredString($localization, 'post_public_id')
                    !== $article->postPublicId()
                || $this->requiredString($localization, 'locale')
                    !== $variant->locale()
            )) {
                throw $this->failure('localization_identity_conflict');
            }
            $this->assertOwnedIdentifier(
                $this->contentDocuments,
                $variant->documentPublicId(),
                $variant->localizationPublicId(),
                'document_identity_conflict'
            );
            $this->assertOwnedIdentifier(
                $this->contentRevisions,
                $variant->revisionPublicId(),
                $variant->localizationPublicId(),
                'revision_identity_conflict'
            );

            $slug = $variant->draft()->compatibilityDraft()->slug();
            if ($slug === null) {
                throw $this->failure('variant_not_publishable');
            }
            $slugOwner = $this->one(
                'SELECT p.public_id AS post_public_id FROM '
                    . $this->localizations . ' l JOIN ' . $this->posts
                    . ' p ON p.id = l.post_id WHERE l.locale = :locale '
                    . 'AND l.slug = :slug',
                ['locale' => $variant->locale(), 'slug' => $slug]
            );
            if ($slugOwner !== null && $this->requiredString(
                $slugOwner,
                'post_public_id'
            ) !== $article->postPublicId()) {
                throw $this->failure('slug_conflict');
            }
        }
    }

    private function assertOwnedIdentifier(
        string $table,
        string $publicId,
        string $expectedLocalizationPublicId,
        string $issue
    ): void {
        $row = $this->one(
            'SELECT l.public_id AS localization_public_id FROM ' . $table
                . ' owned JOIN ' . $this->localizations
                . ' l ON l.id = owned.localization_id '
                . 'WHERE owned.public_id = :public',
            ['public' => $publicId]
        );
        if ($row !== null && $this->requiredString(
            $row,
            'localization_public_id'
        ) !== $expectedLocalizationPublicId) {
            throw $this->failure($issue);
        }
    }

    private function assertActorActive(string $actorPublicId): void
    {
        $rows = $this->rows(
            'SELECT status FROM ' . $this->webAdminTables->table('users')
                . ' WHERE public_id = :public_id',
            ['public_id' => $actorPublicId]
        );
        if (
            count($rows) !== 1
            || $this->requiredString($rows[0], 'status') !== 'active'
        ) {
            throw $this->failure('actor_not_active');
        }
    }

    private function dummyCategoryPublicId(): string
    {
        $rows = $this->rows(
            'SELECT c.public_id FROM ' . $this->categories
                . ' c JOIN ' . $this->categoryLocales
                . ' cl ON cl.category_id = c.id '
                . 'WHERE c.public_id = :category_public_id '
                . 'AND cl.slug = :slug ORDER BY cl.public_id LIMIT 2',
            [
                'category_public_id' =>
                    BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
                'slug' => BlogReservedCategoryPolicy::DUMMY_SLUG,
            ]
        );
        if (count($rows) !== 1) {
            throw $this->failure('dummy_category_unavailable');
        }
        $publicId = $this->requiredString($rows[0], 'public_id');
        try {
            $publicId = BlogInput::publicId($publicId);
        } catch (Throwable) {
            throw $this->failure('dummy_category_unavailable');
        }
        if ($publicId !== BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID) {
            throw $this->failure('dummy_category_unavailable');
        }

        return $publicId;
    }

    private function postExists(string $postPublicId): bool
    {
        return $this->one(
            'SELECT public_id FROM ' . $this->posts
                . ' WHERE public_id = :public_id',
            ['public_id' => $postPublicId]
        ) !== null;
    }

    /** @return list<array<string, mixed>> */
    private function localizationRows(string $postPublicId): array
    {
        return $this->rows(
            'SELECT l.public_id, l.locale FROM ' . $this->posts
                . ' p JOIN ' . $this->localizations
                . ' l ON l.post_id = p.id WHERE p.public_id = :public '
                . 'ORDER BY l.locale ASC',
            ['public' => $postPublicId]
        );
    }

    /** @return list<string> */
    private function assignedCategories(string $postPublicId): array
    {
        $rows = $this->rows(
            'SELECT c.public_id FROM ' . $this->posts . ' p JOIN '
                . $this->postCategories . ' pc ON pc.post_id = p.id JOIN '
                . $this->categories . ' c ON c.id = pc.category_id '
                . 'WHERE p.public_id = :public ORDER BY c.public_id ASC',
            ['public' => $postPublicId]
        );

        return array_map(
            fn (array $row): string => $this->requiredString(
                $row,
                'public_id'
            ),
            $rows
        );
    }

    /** @param list<BlogQaMatrixFixtureArticle> $articles */
    private function articles(array $articles): array
    {
        if (count($articles) !== BlogQaMatrixFixtureCatalog::ARTICLE_COUNT) {
            throw $this->failure('catalog_invalid');
        }
        foreach ($articles as $index => $article) {
            if (
                !$article instanceof BlogQaMatrixFixtureArticle
                || $article->number() !== $index + 1
            ) {
                throw $this->failure('catalog_invalid');
            }
        }

        return $articles;
    }

    /** @param list<string> $locales @return list<string> */
    private function requestedLocales(array $locales): array
    {
        if ($locales === [] || !array_is_list($locales)) {
            throw $this->failure('locales_invalid');
        }
        $expectedOrder = [];
        foreach (BlogQaMatrixFixtureCatalog::LOCALES as $locale) {
            if (in_array($locale, $locales, true)) {
                $expectedOrder[] = $locale;
            }
        }
        if ($expectedOrder !== $locales) {
            throw $this->failure('locales_invalid');
        }

        return $locales;
    }

    /** @return null|array<string, mixed> */
    private function one(string $sql, array $parameters = []): ?array
    {
        $rows = $this->rows($sql, $parameters, 2);
        if (count($rows) > 1) {
            throw $this->failure('storage_cardinality_invalid');
        }

        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function rows(
        string $sql,
        array $parameters = [],
        ?int $maximum = null
    ): array {
        try {
            $statement = $this->pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw $this->failure('storage_query_failed');
            }
            if (!$statement->execute($parameters)) {
                throw $this->failure('storage_query_failed');
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || ($maximum !== null && count($rows) > $maximum)) {
                throw $this->failure('storage_query_failed');
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw $this->failure('storage_query_failed');
                }
            }

            return array_values($rows);
        } catch (BlogQaMatrixFixtureException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure('storage_query_failed');
        }
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw $this->failure('storage_row_invalid');
        }

        return $value;
    }

    private function failure(string $suffix): BlogQaMatrixFixtureException
    {
        if (preg_match('/\A[a-z0-9_]+\z/D', $suffix) !== 1) {
            $suffix = 'internal_failure';
        }

        return new BlogQaMatrixFixtureException(
            'blog.qa_fixture.' . $suffix
        );
    }
}
