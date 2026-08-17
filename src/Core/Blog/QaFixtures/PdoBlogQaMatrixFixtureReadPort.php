<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Blog\EditorialWorkflow\Persistence\PdoBlogEditorialWorkspaceRepository;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Environment\ProjectRuntimeProfile;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Explicit development-only read port for deterministic Matrix fixtures.
 *
 * Public feed/resource contracts remain closed over Dummy. A private
 * showroom may compose this port deliberately, but no HTTP provider does so.
 */
final class PdoBlogQaMatrixFixtureReadPort implements
    BlogQaMatrixFixtureReadPortInterface
{
    private readonly string $posts;
    private readonly string $categories;
    private readonly string $categoryLocales;
    private readonly string $postCategories;
    private readonly PdoBlogRepository $blogRepository;
    private readonly PdoBlogStructuredContentRepository $contentRepository;
    private readonly PdoBlogEditorialWorkspaceRepository $workflowRepository;

    /** @param array<string, mixed> $environment */
    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $blogScope,
        #[\SensitiveParameter] array $environment,
        private readonly BlogQaMatrixFixtureSnapshotComparator $snapshots =
            new BlogQaMatrixFixtureSnapshotComparator()
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                ($environment['DEV_MODE'] ?? null) !== '1'
                || !ProjectRuntimeProfile::fromEnvironment($environment)
                    ->isDevelopmentLoopbackHttp()
            ) {
                throw $this->failure('read_dev_mode_required');
            }
            if (
                !is_string($driver)
                || !in_array($driver, ['sqlite', 'mysql'], true)
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
                throw $this->failure('read_storage_invalid');
            }
            $this->posts = $blogScope->quotedTable('posts', $driver);
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
        } catch (BlogQaMatrixFixtureException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure('read_storage_invalid');
        }
    }

    public function publishedViews(array $articles, array $locales): array
    {
        if (
            count($articles) !== BlogQaMatrixFixtureCatalog::ARTICLE_COUNT
            || $locales === []
            || !array_is_list($locales)
        ) {
            throw $this->failure('read_request_invalid');
        }
        $orderedLocales = array_values(array_filter(
            BlogQaMatrixFixtureCatalog::LOCALES,
            static fn (string $locale): bool => in_array(
                $locale,
                $locales,
                true
            )
        ));
        if ($orderedLocales !== $locales) {
            throw $this->failure('read_request_invalid');
        }

        try {
            $views = [];
            foreach ($articles as $index => $article) {
                if (
                    !$article instanceof BlogQaMatrixFixtureArticle
                    || $article->number() !== $index + 1
                    || !$this->hasOnlyDummyAssignment(
                        $article->postPublicId()
                    )
                ) {
                    throw $this->failure('read_fixture_unavailable');
                }
                foreach ($locales as $locale) {
                    $expected = $article->variant($locale);
                    $variant = $this->blogRepository->variant(
                        $article->postPublicId(),
                        $locale
                    );
                    $head = $this->workflowRepository->publicationHead(
                        $expected->localizationPublicId()
                    );
                    $revision = $this->contentRepository->revision(
                        $expected->revisionPublicId()
                    );
                    if (
                        $variant === null
                        || $variant->status() !== BlogPostVariant::PUBLISHED
                        || $variant->localizationPublicId()
                            !== $expected->localizationPublicId()
                        || $variant->draft()->robotsPreferences()->directive()
                            !== 'noindex,nofollow'
                        || $head === null
                        || $head->revisionPublicId()
                            !== $expected->revisionPublicId()
                        || $revision === null
                        || !$this->snapshots->matches(
                            $revision->snapshot(),
                            $expected->draft()
                        )
                    ) {
                        throw $this->failure('read_fixture_unavailable');
                    }
                    $views[] = new BlogQaMatrixFixtureView(
                        $article->number(),
                        $locale,
                        $variant,
                        $revision->snapshot()
                    );
                }
            }

            return $views;
        } catch (BlogQaMatrixFixtureException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure('read_fixture_unavailable');
        }
    }

    private function hasOnlyDummyAssignment(string $postPublicId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT c.public_id, cl.slug FROM ' . $this->posts
                . ' p JOIN '
                . $this->postCategories . ' pc ON pc.post_id = p.id JOIN '
                . $this->categories . ' c ON c.id = pc.category_id JOIN '
                . $this->categoryLocales
                . ' cl ON cl.category_id = c.id WHERE p.public_id = :public '
                . 'ORDER BY c.public_id ASC, cl.slug ASC'
        );
        if (!$statement instanceof PDOStatement) {
            throw $this->failure('read_storage_invalid');
        }
        $statement->execute(['public' => $postPublicId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows)
            && count($rows) === 1
            && is_array($rows[0])
            && ($rows[0]['public_id'] ?? null)
                === BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID
            && ($rows[0]['slug'] ?? null)
                === BlogReservedCategoryPolicy::DUMMY_SLUG;
    }

    private function failure(string $suffix): BlogQaMatrixFixtureException
    {
        return new BlogQaMatrixFixtureException(
            'blog.qa_fixture.' . $suffix
        );
    }
}
