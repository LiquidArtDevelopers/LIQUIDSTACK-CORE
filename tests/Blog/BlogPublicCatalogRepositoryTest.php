<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\PublicFeed\BlogPublicArchivePeriodsQuery;
use App\Core\Blog\PublicFeed\BlogPublicArchiveQuery;
use App\Core\Blog\PublicFeed\BlogPublicCatalogQuery;
use App\Core\Blog\PublicFeed\BlogPublicCatalogRepositoryInterface;
use App\Core\Blog\PublicFeed\BlogPublicRelatedQuery;
use App\Core\Blog\PublicFeed\PdoBlogPublicCatalogRepository;
use App\Core\Blog\PublishedPostCard;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PHPUnit\Framework\TestCase;

final class BlogPublicCatalogRepositoryTest extends TestCase
{
    private const ACTOR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private PDO $pdo;
    private MigrationScope $scope;
    private BlogPublicCatalogRepositoryInterface $repository;
    private PdoBlogPublicCatalogRepository $discoveryRepository;
    /** @var array<string, int> */
    private array $categoryIds = [];

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = $this->sqlite();
        $this->scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $this->installSchema();
        $this->seedCatalog();
        $this->discoveryRepository = new PdoBlogPublicCatalogRepository(
            $this->pdo,
            $this->scope
        );
        $this->repository = $this->discoveryRepository;
    }

    public function testReturnsOnlyPublishedCardsForTheRequestedLocale(): void
    {
        $cards = $this->repository->search(
            new BlogPublicCatalogQuery('es', null, [], 'any', 20)
        );

        self::assertContainsOnlyInstancesOf(PublishedPostCard::class, $cards);
        self::assertSame(
            [
                'primer-empate',
                'matrix-reloaded',
                'porcentaje-literal',
                'animatrix',
                'sin-categoria',
            ],
            $this->slugs($cards)
        );
        self::assertSame(
            ['matrix-birkargatuta'],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery('eu')
            ))
        );
        self::assertSame(
            ['matrix-english'],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery('en')
            ))
        );
        self::assertSame('es', $cards[0]->locale());
        self::assertSame(
            '2030-04-04 10:00:00.000000',
            $cards[0]->publishedAt()->format('Y-m-d H:i:s.u')
        );
        self::assertArrayNotHasKey('body_text', $cards[0]->toArray());
    }

    public function testSearchIsCaseInsensitiveAcrossEveryAllowedField(): void
    {
        self::assertSame(
            ['matrix-reloaded'],
            $this->searchSlugs('mAtRiX rElOaDeD')
        );
        self::assertSame(
            ['animatrix', 'sin-categoria'],
            $this->searchSlugs('needle')
        );
        self::assertSame(
            ['porcentaje-literal'],
            $this->searchSlugs('COBERTURA 100% REAL')
        );
        self::assertSame(
            ['matrix-reloaded'],
            $this->searchSlugs('elegido despierta')
        );
        self::assertSame(
            ['animatrix'],
            $this->searchSlugs('árbol junto al oráculo')
        );
    }

    public function testLikeWildcardsAndInjectionTextStayLiteral(): void
    {
        self::assertSame(
            ['porcentaje-literal'],
            $this->searchSlugs('100%')
        );
        self::assertSame(
            ['porcentaje-literal'],
            $this->searchSlugs('literal_')
        );
        self::assertSame(
            ['porcentaje-literal'],
            $this->searchSlugs('! tambien')
        );
        self::assertSame([], $this->searchSlugs("' OR 1=1 --"));
    }

    public function testCategoryAnyAndAllUseLocalizedUniqueSlugs(): void
    {
        self::assertSame(
            [
                'primer-empate',
                'matrix-reloaded',
                'porcentaje-literal',
                'animatrix',
            ],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery(
                    'es',
                    null,
                    ['noticias', 'cine', 'noticias'],
                    BlogPublicCatalogQuery::MODE_ANY,
                    20
                )
            ))
        );
        self::assertSame(
            ['matrix-reloaded'],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery(
                    'es',
                    null,
                    ['noticias', 'cine'],
                    BlogPublicCatalogQuery::MODE_ALL
                )
            ))
        );
        self::assertSame(
            ['matrix-birkargatuta'],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery('eu', null, ['albisteak'])
            ))
        );
        self::assertSame(
            [],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery('eu', null, ['noticias'])
            ))
        );
        self::assertSame(
            [],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery('es', null, ['desconocida'])
            ))
        );
    }

    public function testThePublicCategoryCeilingRemainsExecutable(): void
    {
        $categories = ['noticias'];
        for (
            $index = 2;
            $index <= BlogPublicCatalogQuery::MAX_CATEGORIES;
            ++$index
        ) {
            $categories[] = 'categoria-' . $index;
        }

        self::assertSame(
            ['primer-empate', 'matrix-reloaded', 'porcentaje-literal'],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery(
                    'es',
                    null,
                    $categories,
                    BlogPublicCatalogQuery::MODE_ANY,
                    20
                )
            ))
        );
        self::assertSame(
            [],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery(
                    'es',
                    null,
                    $categories,
                    BlogPublicCatalogQuery::MODE_ALL,
                    20
                )
            ))
        );
    }

    public function testSearchCategoriesAndExclusionComposeSafely(): void
    {
        self::assertSame(
            ['animatrix'],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery(
                    'es',
                    'needle',
                    ['cine'],
                    BlogPublicCatalogQuery::MODE_ANY
                )
            ))
        );
        self::assertSame(
            ['primer-empate', 'porcentaje-literal'],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery(
                    'es',
                    null,
                    ['noticias'],
                    BlogPublicCatalogQuery::MODE_ANY,
                    20,
                    0,
                    'matrix-reloaded'
                )
            ))
        );
    }

    public function testPaginationUsesPublishedDateAndStablePublicIdOrder(): void
    {
        self::assertSame(
            ['matrix-reloaded', 'porcentaje-literal'],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery('es', null, [], 'any', 2, 1)
            ))
        );
        self::assertSame(
            [],
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery('es', null, [], 'any', 2, 999)
            ))
        );
    }

    public function testRelatedPostsRequireAPublishedSourceAndRankSharedCategories(
    ): void {
        $this->insertPost(8, [
            $this->published(
                801,
                'es',
                'matrix-doble-categoria',
                'Otra mirada a Matrix',
                'Una entrada anterior que comparte las dos categorias.',
                'Cuerpo relacionado.',
                '2029-12-12 10:00:00.000000'
            ),
        ], ['news', 'cinema']);

        self::assertSame(
            [
                'matrix-doble-categoria',
                'primer-empate',
                'porcentaje-literal',
                'animatrix',
            ],
            $this->slugs($this->discoveryRepository->relatedPosts(
                new BlogPublicRelatedQuery('es', 'matrix-reloaded', 4)
            ))
        );
        self::assertSame([], $this->discoveryRepository->relatedPosts(
            new BlogPublicRelatedQuery('es', 'desconocida')
        ));
        self::assertSame([], $this->discoveryRepository->relatedPosts(
            new BlogPublicRelatedQuery('es', 'matrix-draft')
        ));
    }

    public function testMonthlyArchivePeriodsAreLocalizedCountedAndOrdered(): void
    {
        $periods = $this->discoveryRepository->archivePeriods(
            new BlogPublicArchivePeriodsQuery('es', 10)
        );

        self::assertSame([
            ['locale' => 'es', 'year' => 2030, 'month' => 4, 'count' => 2],
            ['locale' => 'es', 'year' => 2030, 'month' => 3, 'count' => 1],
            ['locale' => 'es', 'year' => 2030, 'month' => 2, 'count' => 1],
            ['locale' => 'es', 'year' => 2030, 'month' => 1, 'count' => 1],
        ], array_map(
            static fn ($period): array => $period->toResourceData(),
            $periods
        ));
        self::assertSame([
            ['locale' => 'en', 'year' => 2030, 'month' => 6, 'count' => 1],
        ], array_map(
            static fn ($period): array => $period->toResourceData(),
            $this->discoveryRepository->archivePeriods(
                new BlogPublicArchivePeriodsQuery('en')
            )
        ));
    }

    public function testArchiveCardsSupportYearMonthAndStablePagination(): void
    {
        self::assertSame(
            ['primer-empate', 'matrix-reloaded'],
            $this->slugs($this->discoveryRepository->archivePosts(
                new BlogPublicArchiveQuery('es', 2030, 4)
            ))
        );
        self::assertSame(
            ['porcentaje-literal', 'animatrix'],
            $this->slugs($this->discoveryRepository->archivePosts(
                new BlogPublicArchiveQuery('es', 2030, null, 2, 2)
            ))
        );
        self::assertSame([], $this->discoveryRepository->archivePosts(
            new BlogPublicArchiveQuery('es', 2029)
        ));
        self::assertNotContains(
            'matrix-draft',
            $this->slugs($this->discoveryRepository->archivePosts(
                new BlogPublicArchiveQuery('es', 2030)
            ))
        );
    }

    public function testDraftsNeverLeakEvenWhenEveryFilterMatches(): void
    {
        self::assertSame([], $this->searchSlugs('unpublished'));
        self::assertNotContains(
            'matrix-draft',
            $this->slugs($this->repository->search(
                new BlogPublicCatalogQuery('es', null, ['noticias'])
            ))
        );
    }

    public function testRepositoryRejectsWrongScopeAndUnsafeSqliteConnection(): void
    {
        try {
            new PdoBlogPublicCatalogRepository(
                $this->pdo,
                MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_')
            );
            self::fail('A non-Blog scope must be rejected.');
        } catch (BlogPersistenceException $exception) {
            self::assertSame(
                'Blog persistence is unavailable.',
                $exception->getMessage()
            );
        }

        $unsafe = new PDO('sqlite::memory:');
        $unsafe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            new PdoBlogPublicCatalogRepository($unsafe, $this->scope);
            self::fail('SQLite without foreign keys must be rejected.');
        } catch (BlogPersistenceException $exception) {
            self::assertSame(
                'Blog persistence is unavailable.',
                $exception->getMessage()
            );
        }
    }

    public function testDatabaseFailuresAndInvalidRowsAreRedacted(): void
    {
        $empty = $this->sqlite();
        $repository = new PdoBlogPublicCatalogRepository($empty, $this->scope);
        try {
            $repository->search(new BlogPublicCatalogQuery('es'));
            self::fail('Missing schema must fail closed.');
        } catch (BlogPersistenceException $exception) {
            self::assertSame(
                'Blog persistence is unavailable.',
                $exception->getMessage()
            );
            self::assertNull($exception->getPrevious());
        }
        foreach ([
            static fn (): array => $repository->relatedPosts(
                new BlogPublicRelatedQuery('es', 'matrix')
            ),
            static fn (): array => $repository->archivePosts(
                new BlogPublicArchiveQuery('es', 2030)
            ),
            static fn (): array => $repository->archivePeriods(
                new BlogPublicArchivePeriodsQuery('es')
            ),
        ] as $operation) {
            try {
                $operation();
                self::fail('Missing schema must fail closed.');
            } catch (BlogPersistenceException $exception) {
                self::assertSame(
                    'Blog persistence is unavailable.',
                    $exception->getMessage()
                );
                self::assertNull($exception->getPrevious());
            }
        }

        $this->pdo->exec(
            "UPDATE ls_blog_post_localizations SET updated_at = 'not-a-date' "
            . "WHERE slug = 'matrix-reloaded'"
        );
        try {
            $this->repository->search(
                new BlogPublicCatalogQuery('es', 'matrix')
            );
            self::fail('An invalid persisted row must fail closed.');
        } catch (BlogPersistenceException $exception) {
            self::assertSame(
                'Blog persistence is unavailable.',
                $exception->getMessage()
            );
            self::assertNull($exception->getPrevious());
        }
        try {
            $this->discoveryRepository->archivePosts(
                new BlogPublicArchiveQuery('es', 2030)
            );
            self::fail('Invalid archive rows must fail closed.');
        } catch (BlogPersistenceException $exception) {
            self::assertSame(
                'Blog persistence is unavailable.',
                $exception->getMessage()
            );
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return list<string> */
    private function searchSlugs(string $search): array
    {
        return $this->slugs($this->repository->search(
            new BlogPublicCatalogQuery('es', $search, [], 'any', 20)
        ));
    }

    /** @param list<PublishedPostCard> $cards @return list<string> */
    private function slugs(array $cards): array
    {
        return array_map(
            static fn (PublishedPostCard $card): string => $card->slug(),
            $cards
        );
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function installSchema(): void
    {
        $migrations = iterator_to_array(
            BlogMigrationProvider::migrations(),
            false
        );
        foreach ([$migrations[0], $migrations[2]] as $migration) {
            foreach (
                $migration->statementsFor('sqlite', $this->scope) as $sql
            ) {
                $this->pdo->exec($sql);
            }
        }
    }

    private function seedCatalog(): void
    {
        $this->categoryIds['news'] = $this->insertCategory(1, [
            'es' => ['noticias', 'Noticias'],
            'eu' => ['albisteak', 'Albisteak'],
            'en' => ['news', 'News'],
        ]);
        $this->categoryIds['cinema'] = $this->insertCategory(2, [
            'es' => ['cine', 'Cine'],
            'eu' => ['zinema', 'Zinema'],
            'en' => ['cinema', 'Cinema'],
        ]);

        $this->insertPost(1, [
            $this->published(
                101,
                'es',
                'matrix-reloaded',
                'Matrix Reloaded',
                'Neo vuelve a Sion',
                'El elegido despierta en la ciudad.',
                '2030-04-04 10:00:00.000000'
            ),
            $this->published(
                102,
                'eu',
                'matrix-birkargatuta',
                'Matrix Birkargatuta',
                'Neo Sionera itzuli da',
                'Aukeratua hirian esnatzen da.',
                '2030-04-04 10:00:00.000000'
            ),
        ], ['news', 'cinema']);
        $this->insertPost(2, [
            $this->published(
                201,
                'es',
                'porcentaje-literal',
                'Informe especial',
                'Cobertura 100% real y literal_under_score',
                'Un signo ! tambien cuenta.',
                '2030-03-03 10:00:00.000000'
            ),
        ], ['news']);
        $this->insertPost(3, [
            $this->published(
                301,
                'es',
                'animatrix',
                'Animatrix',
                'Historias animadas',
                'A hidden NEEDLE lives under an ÁRBOL junto al ORÁCULO.',
                '2030-02-02 10:00:00.000000'
            ),
        ], ['cinema']);
        $this->insertPost(4, [
            $this->published(
                401,
                'es',
                'sin-categoria',
                'Entrada independiente',
                'The NEEDLE appears in this excerpt',
                'Otro cuerpo.',
                '2030-01-01 10:00:00.000000'
            ),
        ]);
        $this->insertPost(5, [
            $this->draft(
                501,
                'es',
                'matrix-draft',
                'Matrix unpublished',
                'Unpublished excerpt',
                'Unpublished body'
            ),
        ], ['news']);
        $this->insertPost(6, [
            $this->published(
                601,
                'en',
                'matrix-english',
                'Matrix in English',
                'English edition',
                'English body.',
                '2030-06-06 10:00:00.000000'
            ),
        ], ['news']);
        $this->insertPost(7, [
            $this->published(
                100,
                'es',
                'primer-empate',
                'Orden estable',
                'Misma fecha de publicacion',
                'El public id decide el desempate.',
                '2030-04-04 10:00:00.000000'
            ),
        ], ['news']);
    }

    /**
     * @param array<string, array{string, string}> $localizations
     */
    private function insertCategory(int $index, array $localizations): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_categories '
            . '(public_id, created_by_user_public_id, created_at, updated_at) '
            . 'VALUES (:public_id, :actor, :created_at, :updated_at)'
        );
        $statement->execute([
            'public_id' => $this->uuid(10_000 + $index),
            'actor' => self::ACTOR,
            'created_at' => '2029-01-01 00:00:00.000000',
            'updated_at' => '2029-01-01 00:00:00.000000',
        ]);
        $categoryId = (int) $this->pdo->lastInsertId();
        $position = 0;
        foreach ($localizations as $locale => [$slug, $name]) {
            ++$position;
            $localization = $this->pdo->prepare(
                'INSERT INTO ls_blog_category_locales '
                . '(public_id, category_id, locale, slug, name, lock_version, '
                . 'created_by_user_public_id, updated_by_user_public_id, '
                . 'created_at, updated_at) VALUES (:public_id, :category_id, '
                . ':locale, :slug, :name, 1, :created_actor, :updated_actor, '
                . ':created_at, :updated_at)'
            );
            $localization->execute([
                'public_id' => $this->uuid(
                    20_000 + ($index * 10) + $position
                ),
                'category_id' => $categoryId,
                'locale' => $locale,
                'slug' => $slug,
                'name' => $name,
                'created_actor' => self::ACTOR,
                'updated_actor' => self::ACTOR,
                'created_at' => '2029-01-01 00:00:00.000000',
                'updated_at' => '2029-01-01 00:00:00.000000',
            ]);
        }

        return $categoryId;
    }

    /**
     * @param list<array<string, mixed>> $localizations
     * @param list<string> $categoryKeys
     */
    private function insertPost(
        int $index,
        array $localizations,
        array $categoryKeys = []
    ): void {
        $post = $this->pdo->prepare(
            'INSERT INTO ls_blog_posts '
            . '(public_id, created_by_user_public_id, created_at, updated_at) '
            . 'VALUES (:public_id, :actor, :created_at, :updated_at)'
        );
        $post->execute([
            'public_id' => $this->uuid(30_000 + $index),
            'actor' => self::ACTOR,
            'created_at' => '2029-01-01 00:00:00.000000',
            'updated_at' => '2029-01-01 00:00:00.000000',
        ]);
        $postId = (int) $this->pdo->lastInsertId();

        foreach ($localizations as $value) {
            $localization = $this->pdo->prepare(
                'INSERT INTO ls_blog_post_localizations '
                . '(public_id, post_id, locale, slug, h1, seo_title, '
                . 'meta_description, excerpt, body_text, status, '
                . 'published_at, lock_version, created_by_user_public_id, '
                . 'updated_by_user_public_id, created_at, updated_at) VALUES '
                . '(:public_id, :post_id, :locale, :slug, :h1, :seo_title, '
                . ':meta_description, :excerpt, :body_text, :status, '
                . ':published_at, 1, :created_actor, :updated_actor, '
                . ':created_at, :updated_at)'
            );
            $localization->execute(array_replace($value, [
                'post_id' => $postId,
                'created_actor' => self::ACTOR,
                'updated_actor' => self::ACTOR,
                'created_at' => '2029-01-01 00:00:00.000000',
            ]));
        }

        foreach ($categoryKeys as $position => $categoryKey) {
            $relation = $this->pdo->prepare(
                'INSERT INTO ls_blog_post_categories '
                . '(public_id, post_id, category_id, '
                . 'assigned_by_user_public_id, created_at, updated_at) '
                . 'VALUES (:public_id, :post_id, :category_id, :actor, '
                . ':created_at, :updated_at)'
            );
            $relation->execute([
                'public_id' => $this->uuid(
                    40_000 + ($index * 10) + $position
                ),
                'post_id' => $postId,
                'category_id' => $this->categoryIds[$categoryKey],
                'actor' => self::ACTOR,
                'created_at' => '2029-01-01 00:00:00.000000',
                'updated_at' => '2029-01-01 00:00:00.000000',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function published(
        int $id,
        string $locale,
        string $slug,
        string $h1,
        string $excerpt,
        string $body,
        string $publishedAt
    ): array {
        return [
            'public_id' => $this->uuid(50_000 + $id),
            'locale' => $locale,
            'slug' => $slug,
            'h1' => $h1,
            'seo_title' => $h1 . ' SEO',
            'meta_description' => $excerpt . ' description',
            'excerpt' => $excerpt,
            'body_text' => $body,
            'status' => 'published',
            'published_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function draft(
        int $id,
        string $locale,
        string $slug,
        string $h1,
        string $excerpt,
        string $body
    ): array {
        return [
            'public_id' => $this->uuid(50_000 + $id),
            'locale' => $locale,
            'slug' => $slug,
            'h1' => $h1,
            'seo_title' => $h1 . ' SEO',
            'meta_description' => $excerpt . ' description',
            'excerpt' => $excerpt,
            'body_text' => $body,
            'status' => 'draft',
            'published_at' => null,
            'updated_at' => '2030-07-07 10:00:00.000000',
        ];
    }

    private function uuid(int $value): string
    {
        return sprintf(
            '%08x-0000-4000-8000-%012x',
            $value,
            $value
        );
    }
}
