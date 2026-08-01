<?php

declare(strict_types=1);

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpController;
use App\Core\Blog\Http\BlogPublicHttpRuntime;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use PHPUnit\Framework\TestCase;

final class BlogPublicHttpControllerTest extends TestCase
{
    private PDO $pdo;
    private BlogService $service;
    private BlogPublicHttpController $controller;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $migration = null;
        foreach (BlogMigrationProvider::migrations() as $candidate) {
            if ($candidate->id() === '0001_blog_posts') {
                $migration = $candidate;
                break;
            }
        }
        self::assertInstanceOf(MigrationDefinition::class, $migration);
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            $this->pdo->exec($sql);
        }

        $uuidGenerator = new class implements UuidGeneratorInterface {
            /** @var list<string> */
            private array $values = [
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
            ];

            public function generateV4(): string
            {
                return array_shift($this->values)
                    ?? throw new RuntimeException('UUID fixture exhausted.');
            }
        };
        $this->service = new BlogService(
            new PdoBlogRepository($this->pdo, $scope),
            $uuidGenerator
        );
        $config = new BlogConfig(
            ['es' => '/noticias'],
            '/blog-sitemap.xml',
            'ls_blog_',
            'fixture'
        );
        $this->controller = new BlogPublicHttpController(
            new BlogPublicHttpRuntime(
                $config,
                BlogPublicOrigin::fromEnvironment([
                    BlogPublicOrigin::ENV => 'https://example.test',
                ]),
                $this->service
            )
        );
    }

    public function testUnknownAndDraftVariantsFallThrough(): void
    {
        self::assertNull($this->controller->article('es', 'unknown'));
        $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft()
        );

        self::assertNull($this->controller->article('es', 'matrix'));
    }

    public function testPublishedArticleAndSitemapAreDbBacked(): void
    {
        $created = $this->service->createPost(
            $this->actorGate(),
            'es',
            $this->draft()
        );
        $published = $this->service->publish(
            $this->actorGate(),
            $created->postPublicId(),
            'es',
            $created->lockVersion()
        );

        $article = $this->controller->article('es', 'matrix');
        self::assertNotNull($article);
        self::assertSame(200, $article->status());
        self::assertSame(
            'text/html; charset=utf-8',
            $article->headers()['Content-Type']
        );
        self::assertStringContainsString(
            '<link rel="canonical" href="https://example.test/noticias/matrix">',
            $article->body()
        );
        self::assertStringContainsString(
            '<h1>Matrix &amp; sistemas</h1>',
            $article->body()
        );
        self::assertStringNotContainsString('<script', $article->body());

        $sitemap = $this->controller->sitemap();
        self::assertSame(200, $sitemap->status());
        self::assertSame(
            'application/xml; charset=utf-8',
            $sitemap->headers()['Content-Type']
        );
        self::assertStringContainsString(
            '<loc>https://example.test/noticias/matrix</loc>',
            $sitemap->body()
        );

        $this->service->unpublish(
            $this->actorGate(),
            $published->postPublicId(),
            'es',
            $published->lockVersion()
        );
        self::assertNull($this->controller->article('es', 'matrix'));
        self::assertStringNotContainsString(
            '/noticias/matrix',
            $this->controller->sitemap()->body()
        );
    }

    private function draft(): BlogDraft
    {
        return new BlogDraft(
            'Matrix & sistemas',
            "Primer párrafo.\n\nSegundo párrafo.",
            'matrix',
            'Matrix | Noticias',
            'Descripción de Matrix.',
            'Extracto de Matrix.'
        );
    }

    /** @return Closure(PDO): string */
    private function actorGate(): Closure
    {
        return static fn (PDO $pdo): string =>
            '33333333-3333-4333-8333-333333333333';
    }
}
