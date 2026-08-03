<?php

declare(strict_types=1);

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\Sitemap\BlogSitemapPublicationCoordinator;
use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use App\Core\Blog\Sitemap\Persistence\PdoBlogSitemapStateRepository;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogSitemapPublicationCoordinatorTest extends TestCase
{
    private PDO $pdo;
    private MigrationScope $scope;
    private PdoBlogSitemapStateRepository $state;
    private PrivateBlogSitemapCacheStorage $storage;
    private BlogService $service;
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if ($migration->targetScopeModuleId() !== null) { continue; }
            foreach ($migration->statementsFor('sqlite', $this->scope) as $sql) {
                $this->pdo->exec($sql);
            }
        }
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir() . '/ls-blog-fence-test-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root);
        $this->storage = PrivateBlogSitemapCacheStorage::forProject(
            $this->root,
            ['RAIZ' => 'http://localhost:1309', 'DEV_MODE' => '1']
        );
        $generation = $this->storage->initialize()->generation();
        $this->state = new PdoBlogSitemapStateRepository(
            $this->pdo,
            $this->scope
        );
        $this->pdo->beginTransaction();
        $this->state->lock();
        $this->state->activateGeneration(
            $generation,
            new DateTimeImmutable('2026-08-03 12:00:00', new DateTimeZone('UTC'))
        );
        $this->pdo->commit();
        $this->service = new BlogService(
            new PdoBlogRepository($this->pdo, $this->scope),
            sitemapPublicationCoordinator: new BlogSitemapPublicationCoordinator(
                $this->state,
                $this->storage
            )
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->filesystem, $this->root)) {
            $this->filesystem->remove($this->root);
        }
    }

    public function testPublishAndUnpublishIncrementRevisionAndLeaveDurableFence(): void
    {
        $actor = '123e4567-e89b-42d3-a456-426614174000';
        $variant = $this->service->createPost(
            static fn (PDO $_pdo): string => $actor,
            'es',
            $this->draft()
        );
        self::assertSame(1, $this->state->current()->publicRevision());

        $published = $this->service->publish(
            static fn (PDO $_pdo): string => $actor,
            $variant->postPublicId(),
            'es',
            $variant->lockVersion()
        );
        self::assertSame(BlogPostVariant::PUBLISHED, $published->status());
        self::assertSame(2, $this->state->current()->publicRevision());
        self::assertSame('blocked', $this->storage->diagnostic()['status']);

        $draft = $this->service->unpublish(
            static fn (PDO $_pdo): string => $actor,
            $variant->postPublicId(),
            'es',
            $published->lockVersion()
        );
        self::assertSame(BlogPostVariant::DRAFT, $draft->status());
        self::assertSame(3, $this->state->current()->publicRevision());
        self::assertSame('blocked', $this->storage->diagnostic()['status']);
    }

    public function testInvalidationFailureRollsBackVisibilityAndRevision(): void
    {
        $actor = '123e4567-e89b-42d3-a456-426614174000';
        $variant = $this->service->createPost(
            static fn (PDO $_pdo): string => $actor,
            'es',
            $this->draft()
        );
        $this->filesystem->remove(
            $this->root . '/storage/liquidstack/blog/sitemap-cache/'
                . PrivateBlogSitemapCacheStorage::MARKER
        );

        try {
            $this->service->publish(
                static fn (PDO $_pdo): string => $actor,
                $variant->postPublicId(),
                'es',
                $variant->lockVersion()
            );
            self::fail('Publication must fail when the durable fence fails.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
        }
        self::assertSame(1, $this->state->current()->publicRevision());
        self::assertSame(
            BlogPostVariant::DRAFT,
            $this->service->loadPost($variant->postPublicId(), 'es')?->status()
        );
    }

    private function draft(): BlogDraft
    {
        return new BlogDraft(
            'Article title',
            'Complete article body.',
            'article-title',
            'Article SEO title',
            'Article meta description.',
            'Article excerpt.'
        );
    }
}
