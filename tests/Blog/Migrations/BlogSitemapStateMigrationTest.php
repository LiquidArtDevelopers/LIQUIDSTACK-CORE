<?php

declare(strict_types=1);

namespace Tests\Blog\Migrations;

use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use App\Core\Blog\Sitemap\Persistence\PdoBlogSitemapStateRepository;
use App\Core\Composer\BlogSitemapCacheInitCommandRuntime;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Blog\BlogSitemapStateMigrationPostconditionVerifier;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogSitemapStateMigrationTest extends TestCase
{
    private PDO $pdo;
    private MigrationScope $scope;
    private Filesystem $filesystem;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/ls-blog-sitemap-state-' . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectRoot);
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        foreach (['0001_blog_posts', '0003_blog_categories',
            '0005_blog_structured_content',
            '0006_blog_sitemap_publication_state'] as $id) {
            $migration = $this->migration($id);
            foreach ($migration->statementsFor('sqlite', $this->scope) as $sql) {
                $this->pdo->exec($sql);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectRoot);
    }

    public function testMigrationIsExactIdempotentAndSeedsOneInactiveState(): void
    {
        $verifier = new BlogSitemapStateMigrationPostconditionVerifier();
        self::assertTrue($verifier->verify($this->pdo, $this->scope));

        $migration = $this->migration('0006_blog_sitemap_publication_state');
        foreach ($migration->statementsFor('sqlite', $this->scope) as $sql) {
            $this->pdo->exec($sql);
        }
        self::assertTrue($verifier->verify($this->pdo, $this->scope));
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_sitemap_state'
        )->fetchColumn());
        $row = $this->pdo->query(
            'SELECT public_revision, cache_generation '
            . 'FROM ls_blog_sitemap_state'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $row['public_revision']);
        self::assertNull($row['cache_generation']);
    }

    public function testVerifierRejectsACompatibleLookingTableWithoutChecks(): void
    {
        $this->pdo->exec('DROP TABLE ls_blog_sitemap_state');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE ls_blog_sitemap_state (
    state_key TEXT COLLATE BINARY NOT NULL PRIMARY KEY,
    public_revision INTEGER NOT NULL DEFAULT 1,
    cache_generation TEXT COLLATE BINARY NULL,
    updated_at TEXT NOT NULL
        DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
) WITHOUT ROWID
SQL);
        $this->pdo->exec(
            "INSERT INTO ls_blog_sitemap_state "
            . "(state_key, public_revision, cache_generation) "
            . "VALUES ('sitemap', 1, NULL)"
        );

        self::assertFalse(
            (new BlogSitemapStateMigrationPostconditionVerifier())->verify(
                $this->pdo,
                $this->scope
            )
        );
    }

    public function testRepositoryLocksActivatesAndRollsBackRevisionAtomically(): void
    {
        $repository = new PdoBlogSitemapStateRepository(
            $this->pdo,
            $this->scope
        );
        $generation = '123e4567-e89b-42d3-a456-426614174000';
        $now = new DateTimeImmutable('2026-08-03 12:00:00.000000', new DateTimeZone('UTC'));

        self::assertTrue($this->pdo->beginTransaction());
        self::assertSame(1, $repository->lock()->publicRevision());
        $repository->activateGeneration($generation, $now);
        $repository->incrementRevision(1, $now);
        self::assertTrue($this->pdo->rollBack());
        self::assertSame(1, $repository->current()->publicRevision());
        self::assertNull($repository->current()->cacheGeneration());

        self::assertTrue($this->pdo->beginTransaction());
        $repository->lock();
        $repository->activateGeneration($generation, $now);
        $state = $repository->incrementRevision(1, $now);
        self::assertSame(2, $state->publicRevision());
        self::assertTrue($this->pdo->commit());
        self::assertSame(2, $repository->current()->publicRevision());
        self::assertSame($generation, $repository->current()->cacheGeneration());
    }

    public function testInitReportsActivationAfterRecoveringFromMarkerOnlyCrash(): void
    {
        $storage = PrivateBlogSitemapCacheStorage::forProject(
            $this->projectRoot,
            [
                'RAIZ' => 'http://localhost:1309',
                'DEV_MODE' => '1',
            ]
        );
        $marker = $storage->initialize();
        self::assertTrue($marker->changed());

        $repository = new PdoBlogSitemapStateRepository(
            $this->pdo,
            $this->scope
        );
        self::assertNull($repository->current()->cacheGeneration());
        $runtime = new BlogSitemapCacheInitCommandRuntime(
            $this->pdo,
            $repository,
            $storage
        );

        $recovered = $runtime->initialize();
        self::assertTrue($recovered->changed());
        self::assertSame(
            $marker->generation(),
            $repository->current()->cacheGeneration()
        );

        self::assertFalse($runtime->initialize()->changed());
    }

    private function migration(string $id): MigrationDefinition
    {
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if ($migration->id() === $id) { return $migration; }
        }
        self::fail('Migration not found: ' . $id);
    }
}
