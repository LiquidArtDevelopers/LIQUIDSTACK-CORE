<?php

declare(strict_types=1);

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\BlogSitemapEntry;
use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\Persistence\BlogRepositoryInterface;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use PHPUnit\Framework\TestCase;

final class BlogSequenceUuidGeneratorFixture implements UuidGeneratorInterface
{
    /** @param list<string> $values */
    public function __construct(private array $values)
    {
    }

    public function generateV4(): string
    {
        $value = array_shift($this->values);
        if (!is_string($value)) {
            throw new RuntimeException('Fixture UUID sequence exhausted.');
        }

        return $value;
    }
}

final class BlogMutableClockFixture implements ClockInterface
{
    public function __construct(private DateTimeImmutable $value)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }

    public function set(DateTimeImmutable $value): void
    {
        $this->value = $value;
    }
}

final class BlogRecordingAuditPortFixture implements BlogMutationAuditPortInterface
{
    /** @var list<BlogMutationAuditEvent> */
    public array $events = [];

    /** @param null|Closure(PDO, BlogMutationAuditEvent): void $beforeRecord */
    public function __construct(private readonly ?Closure $beforeRecord = null)
    {
    }

    public function record(
        PDO $pdo,
        BlogMutationAuditEvent $event
    ): void {
        if ($this->beforeRecord !== null) {
            ($this->beforeRecord)($pdo, $event);
        }
        $this->events[] = $event;
    }
}

final class BlogServicePersistenceTest extends TestCase
{
    private const ACTOR_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ACTOR_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const POST_A = '10000000-0000-4000-8000-000000000001';
    private const POST_B = '20000000-0000-4000-8000-000000000002';
    private const POST_C = '30000000-0000-4000-8000-000000000003';
    private const LOCAL_A_ES = '40000000-0000-4000-8000-000000000004';
    private const LOCAL_A_EU = '50000000-0000-4000-8000-000000000005';
    private const LOCAL_B_ES = '60000000-0000-4000-8000-000000000006';
    private const LOCAL_C_ES = '70000000-0000-4000-8000-000000000007';

    private PDO $pdo;
    private MigrationScope $scope;
    private PdoBlogRepository $repository;
    private BlogMutableClockFixture $clock;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Blog tests.');
        }

        $this->scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $this->pdo = $this->sqlite();
        $this->installSchema($this->pdo);
        $this->repository = new PdoBlogRepository($this->pdo, $this->scope);
        $this->clock = new BlogMutableClockFixture(
            new DateTimeImmutable('2030-01-01 10:00:00.123456 +02:00')
        );
    }

    public function testCreateIsAtomicAndRunsActorGateInsideTransactionFirst(): void
    {
        $gateRanInsideTransaction = false;
        $service = $this->service([
            self::POST_A,
            self::LOCAL_A_ES,
        ]);

        $variant = $service->createPost(
            function (PDO $pdo) use (&$gateRanInsideTransaction): string {
                self::assertSame($this->pdo, $pdo);
                self::assertTrue($pdo->inTransaction());
                self::assertSame(0, $this->tableCount('ls_blog_posts'));
                self::assertSame(0, $this->tableCount('ls_blog_post_localizations'));
                try {
                    $pdo->exec('BEGIN IMMEDIATE');
                    self::fail('The actor gate must run after BEGIN IMMEDIATE.');
                } catch (PDOException) {
                    $gateRanInsideTransaction = true;
                }

                return self::ACTOR_A;
            },
            'es',
            $this->draft('matrix-resurrections', 'Matrix Resurrections')
        );

        self::assertTrue($gateRanInsideTransaction);
        self::assertSame(self::POST_A, $variant->postPublicId());
        self::assertSame(
            self::LOCAL_A_ES,
            $variant->localizationPublicId()
        );
        self::assertSame(self::ACTOR_A, $variant->createdByUserPublicId());
        self::assertSame(self::ACTOR_A, $variant->updatedByUserPublicId());
        self::assertSame(BlogPostVariant::DRAFT, $variant->status());
        self::assertSame(1, $variant->lockVersion());
        self::assertNull($variant->publishedAt());
        self::assertSame(
            '2030-01-01 08:00:00.123456',
            $variant->createdAt()->format('Y-m-d H:i:s.u')
        );
        self::assertSame(1, $this->tableCount('ls_blog_posts'));
        self::assertSame(1, $this->tableCount('ls_blog_post_localizations'));
    }

    public function testActorGateFailureRollsBackAndRepositoryRemainsReusable(): void
    {
        $service = $this->service([
            self::POST_A,
            self::LOCAL_A_ES,
            self::POST_B,
            self::LOCAL_B_ES,
        ]);

        try {
            $service->createPost(
                static function (PDO $pdo): string {
                    $pdo->query('SELECT COUNT(*) FROM ls_blog_posts');
                    throw new RuntimeException('secret@example.test');
                },
                'es',
                $this->draft('failed-gate', 'Failed gate')
            );
            self::fail('A failed actor gate must abort the mutation.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::ACTOR_GATE_FAILED,
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'secret@example.test',
                $exception->getMessage()
            );
        }

        self::assertSame(0, $this->tableCount('ls_blog_posts'));
        self::assertSame(0, $this->tableCount('ls_blog_post_localizations'));

        $created = $service->createPost(
            $this->gate(self::ACTOR_A),
            'es',
            $this->draft('working-after-rollback', 'Working')
        );
        self::assertSame(self::POST_B, $created->postPublicId());
        self::assertSame(1, $this->tableCount('ls_blog_posts'));
    }

    public function testSecondInsertFailureRollsBackTheNewAggregate(): void
    {
        $this->service([self::POST_A, self::LOCAL_A_ES])->createPost(
            $this->gate(self::ACTOR_A),
            'es',
            $this->draft('first-post', 'First post')
        );
        $service = $this->service([
            self::POST_B,
            self::LOCAL_A_ES,
        ]);

        $this->expectBlogIssue(
            BlogException::STORAGE_UNAVAILABLE,
            fn () => $service->createPost(
                $this->gate(self::ACTOR_A),
                'eu',
                $this->draft('second-post', 'Second post')
            )
        );

        self::assertSame(1, $this->tableCount('ls_blog_posts'));
        self::assertSame(1, $this->tableCount('ls_blog_post_localizations'));
        self::assertSame(0, $this->scalar(
            'SELECT COUNT(*) FROM ls_blog_posts WHERE public_id = :public_id',
            ['public_id' => self::POST_B]
        ));
    }

    public function testLocalesAreIndependentAndDuplicateLocaleIsRejected(): void
    {
        $service = $this->service([
            self::POST_A,
            self::LOCAL_A_ES,
            self::LOCAL_A_EU,
            self::LOCAL_C_ES,
        ]);
        $spanish = $service->createPost(
            $this->gate(self::ACTOR_A),
            'es',
            $this->draft('matrix', 'Matrix original')
        );
        $basque = $service->addLocalization(
            $this->gate(self::ACTOR_B),
            $spanish->postPublicId(),
            'eu',
            $this->draft('matrix-euskaraz', 'Matrix euskaraz')
        );

        self::assertSame($spanish->postPublicId(), $basque->postPublicId());
        self::assertNotSame(
            $spanish->localizationPublicId(),
            $basque->localizationPublicId()
        );
        self::assertSame(self::ACTOR_B, $basque->createdByUserPublicId());

        $updatedBasque = $service->saveDraft(
            $this->gate(self::ACTOR_B),
            self::POST_A,
            'eu',
            1,
            $this->draft('matrix-euskaraz', 'Matrix berria')
        );
        self::assertSame(2, $updatedBasque->lockVersion());
        self::assertSame(
            'Matrix original',
            $service->loadPost(self::POST_A, 'es')->draft()->h1()
        );
        self::assertSame(
            'Matrix berria',
            $service->loadPost(self::POST_A, 'eu')->draft()->h1()
        );

        $this->expectBlogIssue(
            BlogException::LOCALE_CONFLICT,
            fn () => $service->addLocalization(
                $this->gate(self::ACTOR_A),
                self::POST_A,
                'es',
                $this->draft('another-spanish', 'Duplicate locale')
            )
        );
        self::assertSame(2, $this->tableCount('ls_blog_post_localizations'));
    }

    public function testAddingLocaleToUnknownAggregateFailsWithoutOrphanRows(): void
    {
        $service = $this->service([self::LOCAL_A_EU]);

        $this->expectBlogIssue(
            BlogException::POST_NOT_FOUND,
            fn () => $service->addLocalization(
                $this->gate(self::ACTOR_A),
                self::POST_A,
                'eu',
                $this->draft('orphan', 'Orphan')
            )
        );

        self::assertSame(0, $this->tableCount('ls_blog_posts'));
        self::assertSame(0, $this->tableCount('ls_blog_post_localizations'));
    }

    public function testListIsDeterministicBoundedAndContainsNoInternalIds(): void
    {
        $this->service([self::POST_B, self::LOCAL_B_ES])->createPost(
            $this->gate(self::ACTOR_A),
            'es',
            $this->draft('second', 'Second')
        );
        $service = $this->service([
            self::POST_A,
            self::LOCAL_A_ES,
            self::LOCAL_A_EU,
        ]);
        $service->createPost(
            $this->gate(self::ACTOR_A),
            'es',
            $this->draft('first', 'First')
        );
        $service->addLocalization(
            $this->gate(self::ACTOR_A),
            self::POST_A,
            'eu',
            $this->draft('first-eu', 'First EU')
        );

        $summaries = $service->listPosts();

        self::assertSame([
            self::POST_A . ':es',
            self::POST_A . ':eu',
            self::POST_B . ':es',
        ], array_map(
            static fn ($summary): string =>
                $summary->postPublicId() . ':' . $summary->locale(),
            $summaries
        ));
        foreach ($summaries as $summary) {
            $payload = $summary->toArray();
            self::assertArrayNotHasKey('id', $payload);
            self::assertArrayNotHasKey('post_id', $payload);
            self::assertArrayNotHasKey('body_text', $payload);
            self::assertArrayNotHasKey('excerpt', $payload);
            self::assertArrayNotHasKey('meta_description', $payload);
        }

        self::assertSame([
            self::POST_A . ':eu',
            self::POST_B . ':es',
        ], array_map(
            static fn ($summary): string =>
                $summary->postPublicId() . ':' . $summary->locale(),
            $service->listPosts(2, 1)
        ));
        foreach ([[0, 0], [101, 0], [1, -1], [1, 1_000_001]] as $page) {
            $this->expectBlogIssue(
                BlogException::INVALID_INPUT,
                fn () => $service->listPosts($page[0], $page[1])
            );
        }
    }

    public function testLoadUsesOnlyPublicPostUuidAndCanonicalLocale(): void
    {
        $service = $this->service([self::POST_A, self::LOCAL_A_ES]);
        $service->createPost(
            $this->gate(self::ACTOR_A),
            'es',
            $this->draft('loadable', 'Loadable')
        );

        self::assertSame(
            self::LOCAL_A_ES,
            $service->loadPost(self::POST_A, 'es')->localizationPublicId()
        );
        $this->expectBlogIssue(
            BlogException::VARIANT_NOT_FOUND,
            fn () => $service->loadPost(self::POST_A, 'eu')
        );
        $this->expectBlogIssue(
            BlogException::INVALID_INPUT,
            fn () => $service->loadPost('1', 'es')
        );
        $this->expectBlogIssue(
            BlogException::INVALID_INPUT,
            fn () => $service->loadPost(self::POST_A, 'ES')
        );
    }

    public function testOptimisticLockPreventsLostUpdatesAcrossTwoConnections(): void
    {
        $path = sys_get_temp_dir()
            . '/liquidstack-blog-lock-' . bin2hex(random_bytes(8)) . '.sqlite';
        $first = $this->sqlite($path);
        $second = $this->sqlite($path);

        try {
            $this->installSchema($first);
            $firstService = $this->serviceFor(
                $first,
                [self::POST_A, self::LOCAL_A_ES]
            );
            $secondService = $this->serviceFor($second, []);
            $firstService->createPost(
                $this->gate(self::ACTOR_A),
                'es',
                $this->draft('concurrent', 'Original')
            );

            self::assertSame(
                1,
                $firstService->loadPost(self::POST_A, 'es')->lockVersion()
            );
            self::assertSame(
                1,
                $secondService->loadPost(self::POST_A, 'es')->lockVersion()
            );

            $saved = $firstService->saveDraft(
                $this->gate(self::ACTOR_A),
                self::POST_A,
                'es',
                1,
                $this->draft('concurrent', 'First writer wins')
            );
            self::assertSame(2, $saved->lockVersion());

            $this->expectBlogIssue(
                BlogException::LOCK_CONFLICT,
                fn () => $secondService->saveDraft(
                    $this->gate(self::ACTOR_B),
                    self::POST_A,
                    'es',
                    1,
                    $this->draft('concurrent', 'Stale writer')
                )
            );

            $current = $secondService->loadPost(self::POST_A, 'es');
            self::assertSame('First writer wins', $current->draft()->h1());
            self::assertSame(2, $current->lockVersion());
            self::assertSame(self::ACTOR_A, $current->updatedByUserPublicId());
        } finally {
            $first = null;
            $second = null;
            @unlink($path);
        }
    }

    public function testPublishAndUnpublishEnforceCompletenessAndPreserveContent(): void
    {
        $service = $this->service([self::POST_A, self::LOCAL_A_ES]);
        $created = $service->createPost(
            $this->gate(self::ACTOR_A),
            'es',
            new BlogDraft('Draft H1', '')
        );

        $this->expectBlogIssue(
            BlogException::PUBLISH_INCOMPLETE,
            fn () => $service->publish(
                $this->gate(self::ACTOR_A),
                self::POST_A,
                'es',
                $created->lockVersion()
            )
        );
        self::assertSame(
            1,
            $service->loadPost(self::POST_A, 'es')->lockVersion()
        );

        $saved = $service->saveDraft(
            $this->gate(self::ACTOR_A),
            self::POST_A,
            'es',
            1,
            $this->draft('published-post', 'Published H1')
        );
        $this->clock->set(
            new DateTimeImmutable('2030-01-02 12:00:00.654321 UTC')
        );
        $published = $service->publish(
            $this->gate(self::ACTOR_B),
            self::POST_A,
            'es',
            $saved->lockVersion()
        );

        self::assertSame(BlogPostVariant::PUBLISHED, $published->status());
        self::assertSame(3, $published->lockVersion());
        self::assertSame(self::ACTOR_B, $published->updatedByUserPublicId());
        self::assertSame(
            '2030-01-02 12:00:00.654321',
            $published->publishedAt()?->format('Y-m-d H:i:s.u')
        );
        self::assertSame(
            self::POST_A,
            $service->resolvePublished('es', 'published-post')?->postPublicId()
        );
        self::assertNull($service->resolvePublished('eu', 'published-post'));
        self::assertCount(1, $service->sitemapEntries());
        $cards = $service->listPublishedCards('es');
        self::assertCount(1, $cards);
        self::assertSame('es', $cards[0]->locale());
        self::assertSame('published-post', $cards[0]->slug());
        self::assertSame('Published H1', $cards[0]->h1());
        self::assertSame('Published H1 excerpt.', $cards[0]->excerpt());
        self::assertSame(
            '2030-01-02 12:00:00.654321',
            $cards[0]->publishedAt()->format('Y-m-d H:i:s.u')
        );
        self::assertSame([], $service->listPublishedCards('eu'));
        self::assertArrayNotHasKey('body_text', $cards[0]->toArray());
        self::assertArrayNotHasKey('post_public_id', $cards[0]->toArray());

        $this->expectBlogIssue(
            BlogException::INVALID_STATE,
            fn () => $service->saveDraft(
                $this->gate(self::ACTOR_A),
                self::POST_A,
                'es',
                3,
                $this->draft('published-post', 'Unsafe live edit')
            )
        );

        $before = $published->draft();
        $withdrawn = $service->unpublish(
            $this->gate(self::ACTOR_A),
            self::POST_A,
            'es',
            3
        );
        self::assertSame(BlogPostVariant::DRAFT, $withdrawn->status());
        self::assertSame(4, $withdrawn->lockVersion());
        self::assertNull($withdrawn->publishedAt());
        self::assertSame($before->slug(), $withdrawn->draft()->slug());
        self::assertSame($before->h1(), $withdrawn->draft()->h1());
        self::assertSame($before->bodyText(), $withdrawn->draft()->bodyText());
        self::assertNull($service->resolvePublished('es', 'published-post'));
        self::assertSame([], $service->sitemapEntries());
        self::assertSame([], $service->listPublishedCards('es'));
    }

    public function testSlugUniquenessIsPerLocaleAndConflictsRollBack(): void
    {
        $this->service([self::POST_A, self::LOCAL_A_ES])->createPost(
            $this->gate(self::ACTOR_A),
            'es',
            $this->draft('same-slug', 'First')
        );
        $service = $this->service([
            self::POST_B,
            self::LOCAL_B_ES,
            self::POST_C,
            self::LOCAL_C_ES,
            self::LOCAL_A_EU,
        ]);

        $this->expectBlogIssue(
            BlogException::SLUG_CONFLICT,
            fn () => $service->createPost(
                $this->gate(self::ACTOR_A),
                'es',
                $this->draft('same-slug', 'Second')
            )
        );
        self::assertSame(1, $this->tableCount('ls_blog_posts'));

        $second = $service->createPost(
            $this->gate(self::ACTOR_A),
            'es',
            new BlogDraft('Second', 'Draft body')
        );
        $this->expectBlogIssue(
            BlogException::SLUG_CONFLICT,
            fn () => $service->saveDraft(
                $this->gate(self::ACTOR_A),
                $second->postPublicId(),
                'es',
                1,
                $this->draft('same-slug', 'Second changed')
            )
        );
        self::assertSame(
            1,
            $service->loadPost($second->postPublicId(), 'es')->lockVersion()
        );

        $basque = $service->addLocalization(
            $this->gate(self::ACTOR_A),
            self::POST_A,
            'eu',
            $this->draft('same-slug', 'Basque variant')
        );
        self::assertSame('same-slug', $basque->draft()->slug());
    }

    public function testSitemapContainsOnlyPublishedVariantsInStableOrder(): void
    {
        $service = $this->service([
            self::POST_A,
            self::LOCAL_A_ES,
            self::LOCAL_A_EU,
            self::POST_B,
            self::LOCAL_B_ES,
        ]);
        $a = $service->createPost(
            $this->gate(self::ACTOR_A),
            'es',
            $this->draft('zeta', 'Zeta')
        );
        $eu = $service->addLocalization(
            $this->gate(self::ACTOR_A),
            self::POST_A,
            'eu',
            $this->draft('alpha', 'Alpha EU')
        );
        $service->createPost(
            $this->gate(self::ACTOR_A),
            'es',
            $this->draft('draft-only', 'Draft only')
        );
        $service->publish(
            $this->gate(self::ACTOR_A),
            self::POST_A,
            'es',
            $a->lockVersion()
        );
        $service->publish(
            $this->gate(self::ACTOR_A),
            self::POST_A,
            'eu',
            $eu->lockVersion()
        );

        $sitemapEntries = $service->sitemapEntries();
        self::assertSame([
            'es:zeta',
            'eu:alpha',
        ], array_map(
            static fn ($entry): string =>
                $entry->locale() . ':' . $entry->slug(),
            $sitemapEntries
        ));
        self::assertSame(
            [self::POST_A, self::POST_A],
            array_map(
                static fn (BlogSitemapEntry $entry): ?string =>
                    $entry->postPublicId(),
                $sitemapEntries
            )
        );
        self::assertSame(
            ['es:zeta', 'eu:alpha'],
            array_map(
                static fn (BlogSitemapEntry $entry): string =>
                    $entry->locale() . ':' . $entry->slug(),
                $service->publishedSitemapEntriesForPost(self::POST_A)
            )
        );
        self::assertSame(
            [],
            $service->publishedSitemapEntriesForPost(self::POST_B)
        );
        self::assertNull($service->resolvePublished('es', 'draft-only'));
    }

    public function testSitemapFailsClosedAtTheStandardDocumentLimit(): void
    {
        $entry = new BlogSitemapEntry(
            'es',
            'matrix',
            new DateTimeImmutable('2030-01-01 08:00:00 UTC'),
            new DateTimeImmutable('2030-01-01 08:00:00 UTC')
        );
        $repository = $this->createMock(BlogRepositoryInterface::class);
        $repository->expects(self::exactly(2))
            ->method('sitemapEntries')
            ->with(BlogSitemapEntry::OVERFLOW_QUERY_LIMIT)
            ->willReturn(
                array_fill(
                    0,
                    BlogSitemapEntry::MAX_DOCUMENT_ENTRIES,
                    $entry
                ),
                array_fill(
                    0,
                    BlogSitemapEntry::OVERFLOW_QUERY_LIMIT,
                    $entry
                )
            );
        $service = new BlogService($repository);

        self::assertSame(
            [],
            $service->publishedSitemapEntriesForPost(self::POST_A)
        );
        self::assertCount(50_000, $service->sitemapEntries());
        $this->expectBlogIssue(
            BlogException::SITEMAP_OVERFLOW,
            static fn () => $service->sitemapEntries()
        );
        self::assertSame(50_000, BlogService::MAX_SITEMAP_ENTRIES);
    }

    public function testEverySuccessfulMutationIsAuditedAfterItsWrites(): void
    {
        $auditCall = 0;
        $audit = new BlogRecordingAuditPortFixture(
            function (
                PDO $pdo,
                BlogMutationAuditEvent $event
            ) use (&$auditCall): void {
                self::assertSame($this->pdo, $pdo);
                self::assertTrue($pdo->inTransaction());
                $auditCall++;

                if ($auditCall === 1) {
                    try {
                        $pdo->exec('BEGIN IMMEDIATE');
                        self::fail('Audit must execute before transaction commit.');
                    } catch (PDOException) {
                        // Expected: the Blog write transaction is still active.
                    }
                }

                self::assertSame(1, $this->scalar(
                    'SELECT COUNT(*) FROM ls_blog_posts WHERE public_id = :id',
                    ['id' => $event->postPublicId()]
                ));
                self::assertSame(
                    [1, 2, 2, 2, 2][$auditCall - 1],
                    $this->tableCount('ls_blog_post_localizations')
                );

                $spanish = $pdo->prepare(
                    'SELECT l.status, l.lock_version '
                    . 'FROM ls_blog_post_localizations l '
                    . 'INNER JOIN ls_blog_posts p ON p.id = l.post_id '
                    . 'WHERE p.public_id = :post_id AND l.locale = :locale'
                );
                $spanish->execute([
                    'post_id' => $event->postPublicId(),
                    'locale' => 'es',
                ]);
                $row = $spanish->fetch();
                self::assertIsArray($row);
                self::assertSame(
                    [1, 1, 2, 3, 4][$auditCall - 1],
                    (int) $row['lock_version']
                );
                self::assertSame(
                    ['draft', 'draft', 'draft', 'published', 'draft'][
                        $auditCall - 1
                    ],
                    $row['status']
                );
            }
        );
        $service = $this->service([
            self::POST_A,
            self::LOCAL_A_ES,
            self::LOCAL_A_EU,
        ], $audit);
        $gateAtState = function (
            string $actorPublicId,
            int $localizationCount,
            ?int $spanishLockVersion,
            ?string $spanishStatus
        ): Closure {
            return function (PDO $pdo) use (
                $actorPublicId,
                $localizationCount,
                $spanishLockVersion,
                $spanishStatus
            ): string {
                self::assertTrue($pdo->inTransaction());
                self::assertSame(
                    $localizationCount,
                    $this->tableCount('ls_blog_post_localizations')
                );
                if ($spanishLockVersion !== null) {
                    $statement = $pdo->prepare(
                        'SELECT lock_version, status FROM '
                        . 'ls_blog_post_localizations WHERE locale = :locale'
                    );
                    $statement->execute(['locale' => 'es']);
                    $row = $statement->fetch();
                    self::assertIsArray($row);
                    self::assertSame(
                        $spanishLockVersion,
                        (int) $row['lock_version']
                    );
                    self::assertSame($spanishStatus, $row['status']);
                }

                return $actorPublicId;
            };
        };

        $created = $service->createPost(
            $gateAtState(self::ACTOR_A, 0, null, null),
            'es',
            $this->draft('audited-post', 'Audited post')
        );
        $service->addLocalization(
            $gateAtState(self::ACTOR_B, 1, 1, BlogPostVariant::DRAFT),
            self::POST_A,
            'eu',
            $this->draft('audited-post-eu', 'Audited post EU')
        );
        $saved = $service->saveDraft(
            $gateAtState(self::ACTOR_B, 2, 1, BlogPostVariant::DRAFT),
            self::POST_A,
            'es',
            $created->lockVersion(),
            $this->draft('audited-post', 'Audited post saved')
        );
        $published = $service->publish(
            $gateAtState(self::ACTOR_A, 2, 2, BlogPostVariant::DRAFT),
            self::POST_A,
            'es',
            $saved->lockVersion()
        );
        $service->unpublish(
            $gateAtState(self::ACTOR_B, 2, 3, BlogPostVariant::PUBLISHED),
            self::POST_A,
            'es',
            $published->lockVersion()
        );

        self::assertSame(5, $auditCall);
        self::assertSame([
            BlogMutationAuditEvent::CREATE,
            BlogMutationAuditEvent::ADD_LOCALE,
            BlogMutationAuditEvent::SAVE,
            BlogMutationAuditEvent::PUBLISH,
            BlogMutationAuditEvent::UNPUBLISH,
        ], array_map(
            static fn (BlogMutationAuditEvent $event): string =>
                $event->operation(),
            $audit->events
        ));
        self::assertSame([
            self::ACTOR_A,
            self::ACTOR_B,
            self::ACTOR_B,
            self::ACTOR_A,
            self::ACTOR_B,
        ], array_map(
            static fn (BlogMutationAuditEvent $event): string =>
                $event->actorPublicId(),
            $audit->events
        ));
        foreach ($audit->events as $event) {
            self::assertSame(self::POST_A, $event->postPublicId());
            self::assertSame(
                ['operation', 'actor_public_id', 'post_public_id', 'occurred_at'],
                array_keys($event->toArray())
            );
            self::assertSame(
                '2030-01-01 08:00:00.123456',
                $event->occurredAt()->format('Y-m-d H:i:s.u')
            );
        }
    }

    public function testAuditFailureRollsBackBlogAndAuditAtomically(): void
    {
        $this->pdo->exec(
            'CREATE TABLE audit_probe ('
            . 'operation TEXT NOT NULL, post_public_id TEXT NOT NULL)'
        );
        $audit = new BlogRecordingAuditPortFixture(
            function (PDO $pdo, BlogMutationAuditEvent $event): void {
                $statement = $pdo->prepare(
                    'INSERT INTO audit_probe (operation, post_public_id) '
                    . 'VALUES (:operation, :post_public_id)'
                );
                $statement->execute([
                    'operation' => $event->operation(),
                    'post_public_id' => $event->postPublicId(),
                ]);
                self::assertSame(1, $this->tableCount('ls_blog_posts'));
                self::assertSame(
                    1,
                    (int) $pdo->query(
                        'SELECT COUNT(*) FROM audit_probe'
                    )->fetchColumn()
                );

                throw new RuntimeException('private-audit-detail');
            }
        );

        try {
            $this->service([
                self::POST_A,
                self::LOCAL_A_ES,
            ], $audit)->createPost(
                $this->gate(self::ACTOR_A),
                'es',
                $this->draft('audit-failure', 'Audit failure')
            );
            self::fail('A failed audit append must abort the mutation.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'private-audit-detail',
                $exception->getMessage()
            );
        }

        self::assertSame(0, $this->tableCount('ls_blog_posts'));
        self::assertSame(0, $this->tableCount('ls_blog_post_localizations'));
        self::assertSame(
            0,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM audit_probe'
            )->fetchColumn()
        );
    }

    public function testStorageErrorsAreStableAndDoNotLeakSqlOrPrefix(): void
    {
        $this->pdo->exec('DROP TABLE ls_blog_post_localizations');

        try {
            $this->service([])->listPosts();
            self::fail('Broken storage must fail closed.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'ls_blog_',
                $exception->getMessage()
            );
            self::assertStringNotContainsString(
                'SELECT',
                $exception->getMessage()
            );
        }
    }

    public function testInvalidActorAndGeneratedUuidFailBeforeAnyWrite(): void
    {
        $this->expectBlogIssue(
            BlogException::ACTOR_GATE_FAILED,
            fn () => $this->service([
                self::POST_A,
                self::LOCAL_A_ES,
            ])->createPost(
                static fn (PDO $pdo): string => 'NOT-A-UUID',
                'es',
                $this->draft('invalid-actor', 'Invalid actor')
            )
        );
        self::assertSame(0, $this->tableCount('ls_blog_posts'));

        $gateCalled = false;
        $this->expectBlogIssue(
            BlogException::STORAGE_UNAVAILABLE,
            fn () => $this->service(['not-a-v4'])->createPost(
                function (PDO $pdo) use (&$gateCalled): string {
                    $gateCalled = true;

                    return self::ACTOR_A;
                },
                'es',
                $this->draft('invalid-generated', 'Invalid generated')
            )
        );
        self::assertFalse($gateCalled);
        self::assertSame(0, $this->tableCount('ls_blog_posts'));
    }

    public function testRepositoryRejectsWritesOutsideItsTransaction(): void
    {
        $this->expectException(BlogPersistenceException::class);
        $this->repository->insertPost(
            self::POST_A,
            self::ACTOR_A,
            $this->clock->now()
        );
    }

    public function testRepositoryUsesTheValidatedMigrationScopePrefix(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'client_blog_');
        $this->installSchema($pdo, $scope);
        $service = new BlogService(
            new PdoBlogRepository($pdo, $scope),
            new BlogSequenceUuidGeneratorFixture([
                self::POST_A,
                self::LOCAL_A_ES,
            ]),
            $this->clock
        );

        $service->createPost(
            $this->gate(self::ACTOR_A),
            'es',
            $this->draft('custom-prefix', 'Custom prefix')
        );

        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM client_blog_posts'
        )->fetchColumn());
        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM client_blog_post_localizations'
        )->fetchColumn());

        $this->expectException(BlogPersistenceException::class);
        new PdoBlogRepository(
            $pdo,
            MigrationScope::forTablePrefix('webadmin', 'client_admin_')
        );
    }

    public function testMvpExposesNoDeleteOperation(): void
    {
        foreach ([
            BlogService::class,
            BlogRepositoryInterface::class,
            PdoBlogRepository::class,
        ] as $class) {
            $methods = array_map(
                static fn (ReflectionMethod $method): string =>
                    strtolower($method->getName()),
                (new ReflectionClass($class))->getMethods(
                    ReflectionMethod::IS_PUBLIC
                )
            );
            foreach ($methods as $method) {
                self::assertStringNotContainsString('delete', $method);
                self::assertStringNotContainsString('remove', $method);
            }
        }
    }

    /** @param list<string> $uuids */
    private function service(
        array $uuids,
        ?BlogMutationAuditPortInterface $auditPort = null
    ): BlogService
    {
        return new BlogService(
            $this->repository,
            new BlogSequenceUuidGeneratorFixture($uuids),
            $this->clock,
            $auditPort
        );
    }

    /** @param list<string> $uuids */
    private function serviceFor(PDO $pdo, array $uuids): BlogService
    {
        return new BlogService(
            new PdoBlogRepository($pdo, $this->scope),
            new BlogSequenceUuidGeneratorFixture($uuids),
            $this->clock
        );
    }

    /** @return Closure(PDO): string */
    private function gate(string $actorPublicId): Closure
    {
        return static fn (PDO $pdo): string => $actorPublicId;
    }

    private function draft(string $slug, string $h1): BlogDraft
    {
        return new BlogDraft(
            h1: $h1,
            bodyText: "First paragraph.\n\nSecond paragraph.",
            slug: $slug,
            seoTitle: $h1 . ' SEO',
            metaDescription: $h1 . ' meta description.',
            excerpt: $h1 . ' excerpt.'
        );
    }

    private function sqlite(?string $path = null): PDO
    {
        $pdo = new PDO($path === null ? 'sqlite::memory:' : 'sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 1000');

        return $pdo;
    }

    private function installSchema(
        PDO $pdo,
        ?MigrationScope $scope = null
    ): void
    {
        $scope ??= $this->scope;
        $definition = null;
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if ($migration->id() === '0001_blog_posts') {
                $definition = $migration;
                break;
            }
        }
        if (!$definition instanceof MigrationDefinition) {
            throw new RuntimeException('Blog schema migration is unavailable.');
        }
        foreach ($definition->statementsFor('sqlite', $scope) as $sql) {
            $pdo->exec($sql);
        }
    }

    private function tableCount(string $table): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM "' . $table . '"'
        )->fetchColumn();
    }

    /** @param array<string, mixed> $parameters */
    private function scalar(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** @param callable(): mixed $operation */
    private function expectBlogIssue(string $issueCode, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected Blog issue ' . $issueCode . '.');
        } catch (BlogException $exception) {
            self::assertSame($issueCode, $exception->issueCode());
        }
    }
}
