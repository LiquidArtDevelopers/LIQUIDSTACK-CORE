<?php

declare(strict_types=1);

namespace Tests\Blog\Seo;

use App\Core\Blog\Seo\BlogSeoAnalysisService;
use App\Core\Blog\Seo\BlogSeoAnalyzer;
use App\Core\Blog\Seo\BlogSeoCandidateRepositoryInterface;
use App\Core\Blog\Seo\BlogSeoCandidateScan;
use App\Core\Blog\Seo\BlogSeoCompetingPage;
use App\Core\Blog\Seo\BlogSeoStaticPageInventory;
use App\Core\Blog\Seo\PdoBlogSeoCandidateRepository;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlogSeoInfrastructureTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-seo-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir(
            $this->projectRoot . '/App/config/seo',
            0700,
            true
        ));
    }

    protected function tearDown(): void
    {
        $path = $this->projectRoot . '/App/config/seo/canonical-pages.json';
        if (is_file($path)) {
            unlink($path);
        }
        @rmdir($this->projectRoot . '/App/config/seo');
        @rmdir($this->projectRoot . '/App/config');
        @rmdir($this->projectRoot . '/App');
        @rmdir($this->projectRoot);
    }

    public function testPdoInventoryUsesOnlyPublishedSameLocaleAndExcludesCurrent(): void
    {
        $pdo = $this->pdo();
        $pdo->exec(
            'CREATE TABLE ls_blog_posts ('
            . 'id INTEGER PRIMARY KEY, public_id TEXT NOT NULL)'
        );
        $pdo->exec(
            'CREATE TABLE ls_blog_post_localizations ('
            . 'id INTEGER PRIMARY KEY, public_id TEXT, post_id INTEGER NOT NULL, locale TEXT NOT NULL, '
            . 'slug TEXT, h1 TEXT NOT NULL, seo_title TEXT, status TEXT NOT NULL, '
            . 'published_at TEXT, updated_at TEXT NOT NULL)'
        );
        $insertPost = $pdo->prepare(
            'INSERT INTO ls_blog_posts (id, public_id) VALUES (?, ?)'
        );
        $insertLocale = $pdo->prepare(
            'INSERT INTO ls_blog_post_localizations '
            . '(id, public_id, post_id, locale, slug, h1, seo_title, status, '
            . 'published_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (range(1, 5) as $id) {
            self::assertTrue($insertPost->execute([$id, $this->id($id)]));
        }
        $rows = [
            [1, $this->id(101), 1, 'es', 'actual', 'Actual', 'Actual', 'published', '2030-01-01', '2030-01-05'],
            [2, $this->id(102), 2, 'es', 'publicado', 'Publicado', 'Title', 'published', '2030-01-01', '2030-01-04'],
            [3, $this->id(103), 3, 'es', 'borrador', 'Borrador', 'Title', 'draft', null, '2030-01-03'],
            [4, $this->id(104), 4, 'en', 'english', 'English', 'Title', 'published', '2030-01-01', '2030-01-02'],
            [5, $this->id(105), 5, 'es', 'sin-fecha', 'Sin fecha', 'Title', 'published', null, '2030-01-01'],
        ];
        foreach ($rows as $row) {
            self::assertTrue($insertLocale->execute($row));
        }

        $repository = new PdoBlogSeoCandidateRepository(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_'),
            ['es' => '/noticias']
        );
        $scan = $repository->publishedCandidates(
            'es',
            $this->id(1),
            5
        );
        $candidates = $scan->candidates();

        self::assertTrue($scan->complete());
        self::assertCount(1, $candidates);
        self::assertSame('/noticias/publicado', $candidates[0]->url());
        self::assertSame('Publicado', $candidates[0]->h1());
    }

    public function testPdoInventoryReportsOverflowInsteadOfFalseCompleteness(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE ls_blog_posts (id INTEGER PRIMARY KEY, public_id TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE ls_blog_post_localizations ('
            . 'id INTEGER PRIMARY KEY, public_id TEXT, post_id INTEGER NOT NULL, '
            . 'locale TEXT NOT NULL, slug TEXT, h1 TEXT NOT NULL, seo_title TEXT, '
            . 'status TEXT NOT NULL, published_at TEXT, updated_at TEXT NOT NULL)');
        for ($id = 1; $id <= 4; ++$id) {
            $pdo->prepare('INSERT INTO ls_blog_posts (id, public_id) VALUES (?, ?)')
                ->execute([$id, $this->id($id)]);
            $pdo->prepare('INSERT INTO ls_blog_post_localizations '
                . '(id, public_id, post_id, locale, slug, h1, seo_title, status, '
                . 'published_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $id, $this->id(100 + $id), $id, 'es', 'slug-' . $id,
                    'Heading ' . $id, 'Title ' . $id, 'published',
                    '2030-01-01', '2030-01-0' . $id,
                ]);
        }
        $scan = (new PdoBlogSeoCandidateRepository(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_')
        ))->publishedCandidates('es', $this->id(1), 2);

        self::assertFalse($scan->complete());
        self::assertCount(2, $scan->candidates());
    }

    public function testOptionalStaticInventoryIsStrictAndLocaleScoped(): void
    {
        $path = $this->projectRoot . '/App/config/seo/canonical-pages.json';
        file_put_contents($path, json_encode([
            'schema' => BlogSeoStaticPageInventory::SCHEMA,
            'version' => 1,
            'pages' => [[
                'locale' => 'es',
                'url' => '/asesoria-fiscal',
                'h1' => 'Asesoría fiscal',
                'seo_title' => 'Asesoría fiscal en Bilbao',
            ], [
                'locale' => 'en',
                'url' => '/en/tax-advice',
                'h1' => 'Tax advice',
                'seo_title' => null,
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $inventory = new BlogSeoStaticPageInventory($this->projectRoot);
        $spanish = $inventory->candidates('es');

        self::assertCount(1, $spanish);
        self::assertSame(
            BlogSeoCompetingPage::STATIC_PAGE,
            $spanish[0]->source()
        );
        self::assertSame('/asesoria-fiscal', $spanish[0]->url());
        self::assertCount(1, $inventory->candidates('en'));
        self::assertSame([], $inventory->candidates('eu'));
    }

    public function testMalformedStaticInventoryFailsClosed(): void
    {
        file_put_contents(
            $this->projectRoot . '/App/config/seo/canonical-pages.json',
            '{"schema":"wrong","version":1,"pages":[]}'
        );

        $this->expectException(RuntimeException::class);
        (new BlogSeoStaticPageInventory($this->projectRoot))
            ->candidates('es');
    }

    public function testCandidateFailureLeavesOnlyCompetitionPending(): void
    {
        $repository = new class implements BlogSeoCandidateRepositoryInterface {
            public function publishedCandidates(
                string $locale,
                string $exceptPostPublicId,
                int $limit
            ): BlogSeoCandidateScan {
                throw new RuntimeException('database unavailable');
            }
        };
        $analysis = (new BlogSeoAnalysisService(
            new BlogSeoAnalyzer(),
            $repository,
            new BlogSeoStaticPageInventory($this->projectRoot)
        ))->analyze(
            $this->draft(),
            $this->id(1),
            'es',
            '/noticias'
        )->toArray();
        $competition = array_values(array_filter(
            $analysis['checks'],
            static fn (array $check): bool =>
                $check['key'] === 'competition.cannibalization'
        ));

        self::assertCount(1, $competition);
        self::assertSame('pending', $competition[0]['status']);
        self::assertSame([], $analysis['competing_pages']);
        self::assertTrue($analysis['advisory']);
    }

    public function testIncompleteCandidateScanCannotProduceFalseGreen(): void
    {
        $repository = new class implements BlogSeoCandidateRepositoryInterface {
            public function publishedCandidates(
                string $locale,
                string $exceptPostPublicId,
                int $limit
            ): BlogSeoCandidateScan {
                return new BlogSeoCandidateScan([
                    new BlogSeoCompetingPage(
                        BlogSeoCompetingPage::BLOG,
                        $locale,
                        '/known-page',
                        'Known page'
                    ),
                ], false);
            }
        };
        $analysis = (new BlogSeoAnalysisService(
            new BlogSeoAnalyzer(),
            $repository,
            new BlogSeoStaticPageInventory($this->projectRoot)
        ))->analyze(
            $this->draft(),
            $this->id(1),
            'es',
            '/noticias'
        )->toArray();
        $competition = array_values(array_filter(
            $analysis['checks'],
            static fn (array $check): bool =>
                $check['key'] === 'competition.cannibalization'
        ));

        self::assertSame('pending', $competition[0]['status']);
        self::assertSame([], $analysis['competing_pages']);
    }

    private function draft(): BlogStructuredDraft
    {
        return new BlogStructuredDraft(
            'Guía segura para entender Matrix',
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => 'article-basic-01',
                'blocks' => [[
                    'id' => $this->id(100),
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Una guía práctica para entender Matrix con contexto.',
                        'marks' => [],
                    ]],
                ]],
            ]),
            'guia-segura-matrix',
            'Guía segura para entender Matrix con contexto',
            'Una descripción editorial suficientemente clara para explicar el contenido de la guía Matrix y orientar a quien necesita comprender su contexto.',
            'Una guía práctica sobre Matrix.'
        );
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }
}
