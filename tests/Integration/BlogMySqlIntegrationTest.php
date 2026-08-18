<?php

declare(strict_types=1);

use App\Core\Blog\Audit\WebAdminBlogMutationAuditAdapter;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\Persistence\PdoBlogCategoryRepository;
use App\Core\Blog\EditorialWorkflow\Persistence\BlogEditorialWorkflowPersistenceException;
use App\Core\Blog\EditorialWorkflow\Persistence\PdoBlogEditorialWorkspaceRepository;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Http\BlogAdminHttpRuntime;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\PublicFeed\BlogPublicArchivePeriodsQuery;
use App\Core\Blog\PublicFeed\BlogPublicArchiveQuery;
use App\Core\Blog\PublicFeed\BlogPublicRelatedQuery;
use App\Core\Blog\PublicFeed\PdoBlogPublicCatalogRepository;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Media\PdoWebAdminMediaAvailabilityAdapter;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Database\SharedPdoConnectionFactory;
use App\Core\Modules\Blog\BlogCapabilitySeedPostcondition;
use App\Core\Modules\Blog\BlogCategoryCapabilitySeedPostcondition;
use App\Core\Modules\Blog\BlogCategoryHttpSchemaGate;
use App\Core\Modules\Blog\BlogCategoryMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogCopyOperationMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogHttpSchemaGate;
use App\Core\Modules\Blog\BlogInitialSchemaContract;
use App\Core\Modules\Blog\BlogStructuredContentMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogStructuredContentSchemaGate;
use App\Core\Modules\Blog\BlogTagCapabilitySeedPostcondition;
use App\Core\Modules\Blog\BlogTagSchemaGate;
use App\Core\Modules\Blog\BlogTagSchemaMigrationPostconditionVerifier;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationApplyOptions;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationException;
use App\Core\Modules\Migrations\MigrationRegistry;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\WebAdmin\WebAdminInitialSchemaContract;
use App\Core\Modules\WebAdmin\WebAdminMediaHttpSchemaGate;
use App\Core\Modules\WebAdmin\WebAdminMediaMigrationPostconditionVerifier;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogMySqlMutableClockFixture implements ClockInterface
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

/**
 * Opt-in Blog contract against the same disposable MySQL/MariaDB DB used by
 * the WebAdmin integration harness.
 *
 * No .env file is loaded. The strict database-name guard runs before PDO is
 * built, and every table created by this test has a fresh validated prefix.
 */
#[Group('mysql-integration')]
final class BlogMySqlIntegrationTest extends TestCase
{
    private const OPT_IN_ENV = 'LIQUIDSTACK_TEST_MYSQL_INTEGRATION';
    private const ACTOR_PUBLIC_ID =
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const UNKNOWN_ACTOR_PUBLIC_ID =
        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const ACTOR_EMAIL = 'blog-integration@example.test';
    private const ACTOR_PASSWORD =
        'LiquidStack Blog integration password 2026!';
    private const CATEGORY_PUBLIC_ID =
        'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const CATEGORY_LOCALE_PUBLIC_ID =
        'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const POST_CATEGORY_PUBLIC_ID =
        'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const MEDIA_ASSET_PUBLIC_ID =
        '11111111-1111-4111-8111-111111111111';
    private const COVER_BLOCK_PUBLIC_ID =
        '22222222-2222-4222-8222-222222222222';
    private const FIRST_PARAGRAPH_PUBLIC_ID =
        '33333333-3333-4333-8333-333333333333';
    private const SECOND_PARAGRAPH_PUBLIC_ID =
        '44444444-4444-4444-8444-444444444444';
    private const DOCUMENT_PUBLIC_ID =
        '55555555-5555-4555-8555-555555555555';
    private const REVISION_PUBLIC_ID =
        '66666666-6666-4666-8666-666666666666';
    private const DUPLICATE_OPERATION_PUBLIC_ID =
        '77777777-7777-4777-8777-777777777777';
    private const LOCALE_COPY_OPERATION_PUBLIC_ID =
        '88888888-8888-4888-8888-888888888888';
    private const CONCURRENT_DUPLICATE_OPERATION_PUBLIC_ID =
        '99999999-9999-4999-8999-999999999999';
    private const CONCURRENT_LOCALE_OPERATION_PUBLIC_ID =
        '12121212-1212-4212-8212-121212121212';
    private const TAG_RACE_POST_A =
        'abababab-abab-4bab-8bab-abababababab';
    private const TAG_RACE_POST_B =
        'cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdcd';
    private const TAG_RACE_LOCALIZATION_A =
        '10101010-1010-4010-8010-101010101010';
    private const TAG_RACE_LOCALIZATION_B =
        '20202020-2020-4020-8020-202020202020';

    public function testRealMySqlBlogLifecycle(): void
    {
        if (getenv(self::OPT_IN_ENV) !== '1') {
            self::markTestSkipped(sprintf(
                'Opt-in MySQL/MariaDB test; set %s=1 explicitly.',
                self::OPT_IN_ENV
            ));
        }

        $configuration = BlogMySqlTestConfiguration::fromProcess();
        [$webAdminPrefix, $blogPrefix] = $this->ephemeralPrefixes();
        $previousTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');

        $guardConnection = null;
        $connection = null;
        $secondConnection = null;
        $guardLockName = null;
        $projectRoot = null;
        $cleanupArmed = false;

        try {
            $guardConnection = $this->connect($configuration);
            $this->assertSelectedDatabase(
                $guardConnection,
                $configuration->database()
            );
            $guardLockName = $this->acquireGuardLock(
                $guardConnection,
                $configuration->database()
            );

            $connection = $this->connect($configuration);
            $this->assertSelectedDatabase(
                $connection,
                $configuration->database()
            );
            $this->assertNamespacesAreUnused(
                $connection,
                $configuration->database(),
                $webAdminPrefix,
                $blogPrefix
            );

            $projectRoot = $this->createModuleProject();
            $registry = ModuleRegistry::forProject(
                $projectRoot,
                dirname(__DIR__, 2)
            );
            $catalog = MigrationCatalog::fromRegistry($registry);
            $scopes = MigrationScopeCollection::fromTablePrefixes([
                'webadmin' => $webAdminPrefix,
                'blog' => $blogPrefix,
            ]);
            $webAdminScope = $scopes->get('webadmin');
            $blogScope = $scopes->get('blog');
            self::assertInstanceOf(MigrationScope::class, $webAdminScope);
            self::assertInstanceOf(MigrationScope::class, $blogScope);

            $cleanupArmed = true;
            $runner = new MigrationRunner();
            $preview = (new MigrationDatabasePlanner())->plan(
                $connection,
                $catalog,
                $scopes
            );
            try {
                $firstRun = $runner->apply(
                    $connection,
                    $catalog,
                    $scopes,
                    new MigrationApplyOptions(
                        expectedPlanHash: $preview->hash(),
                        allowDestructive: true,
                        backupConfirmed: true
                    )
                );
            } catch (MigrationException $exception) {
                self::fail(sprintf(
                    'Migration failed at %s:%s (%s).',
                    $exception->moduleId() ?? 'unknown',
                    $exception->migrationId() ?? 'unknown',
                    $exception->issueCode()
                ));
            }
            self::assertTrue($firstRun->changed());
            $applied = array_map(
                static fn (array $entry): string =>
                    $entry['module'] . ':' . $entry['id'],
                $firstRun->applied()
            );
            sort($applied, SORT_STRING);
            self::assertSame([
                'blog:0001_blog_posts',
                'blog:0002_blog_capabilities',
                'blog:0003_blog_categories',
                'blog:0004_blog_category_capabilities',
                'blog:0005_blog_structured_content',
                'blog:0006_blog_sitemap_publication_state',
                'blog:0007_blog_post_tombstones',
                'blog:0008_blog_article_delete_capability',
                'blog:0009_blog_analytics',
                'blog:0010_blog_analytics_view_capability',
                'blog:0011_blog_layout_editor_v2',
                'blog:0012_blog_editor_preferences',
                'blog:0013_blog_settings_manage_capability',
                'blog:0014_blog_private_draft_publication',
                'blog:0015_blog_robots_preferences',
                'blog:0016_blog_url_history',
                'blog:0017_blog_dummy_category',
                'blog:0018_blog_dummy_category_normalization',
                'blog:0019_blog_copy_operation_idempotency',
                'blog:0020_blog_tags',
                'blog:0021_blog_localization_tags',
                'blog:0022_blog_tag_assignment_heads',
                'blog:0023_blog_tag_assignment_workspaces',
                'blog:0024_blog_tag_assignment_workspace_items',
                'blog:0025_blog_tag_capabilities',
                'webadmin:0001_webadmin_identity_and_access',
                'webadmin:0002_webadmin_media_library',
                'webadmin:0003_webadmin_media_avif_source',
                'webadmin:0004_webadmin_profile_preferences',
                'webadmin:0005_webadmin_media_quarantine',
            ], $applied);

            $this->assertAllExpectedTablesExist(
                $connection,
                $configuration->database(),
                $webAdminPrefix,
                $blogPrefix
            );
            $this->assertMigrationScopes(
                $connection,
                $blogScope,
                $webAdminScope
            );
            self::assertTrue(
                (new WebAdminMediaMigrationPostconditionVerifier(
                    acceptAvifSource: true
                ))->verify(
                    $connection,
                    $webAdminScope
                )
            );
            self::assertFalse(
                (new BlogCopyOperationMigrationPostconditionVerifier())
                    ->verify($connection, $blogScope),
                'La frontera exacta 0019 debe quedar supersedida por tags.'
            );
            self::assertTrue(
                (new BlogTagSchemaMigrationPostconditionVerifier(5))
                    ->verify($connection, $blogScope)
            );
            self::assertTrue(
                (new BlogTagCapabilitySeedPostcondition())
                    ->verify($connection, $webAdminScope)
            );
            self::assertTrue(
                (new BlogTagSchemaGate())->isAdministrationReady(
                    $connection,
                    $registry,
                    $scopes
                )
            );
            self::assertTrue(
                (new BlogCapabilitySeedPostcondition())->verify(
                    $connection,
                    $webAdminScope
                )
            );
            self::assertTrue(
                (new BlogCategoryCapabilitySeedPostcondition())->verify(
                    $connection,
                    $webAdminScope
                )
            );
            self::assertTrue(
                (new WebAdminMediaHttpSchemaGate())->isReady(
                    $connection,
                    $registry,
                    $webAdminScope
                )
            );
            self::assertTrue(
                (new BlogHttpSchemaGate())->isReady(
                    $connection,
                    $registry,
                    $scopes
                )
            );
            self::assertTrue(
                (new BlogCategoryHttpSchemaGate())->isAdministrationReady(
                    $connection,
                    $registry,
                    $scopes
                )
            );
            self::assertTrue(
                (new BlogStructuredContentSchemaGate())->isReady(
                    $connection,
                    $registry,
                    $scopes
                )
            );

            $secondRun = $runner->apply($connection, $catalog, $scopes);
            self::assertFalse($secondRun->changed());
            self::assertSame([], $secondRun->applied());
            $this->assertAllExpectedTablesExist(
                $connection,
                $configuration->database(),
                $webAdminPrefix,
                $blogPrefix
            );
            $this->assertMigrationScopes(
                $connection,
                $blogScope,
                $webAdminScope
            );

            $clock = new BlogMySqlMutableClockFixture(
                new DateTimeImmutable(
                    '2032-04-05 10:11:12.123456',
                    new DateTimeZone('UTC')
                )
            );
            $securityKey = SecurityKey::fromRawBytes(str_repeat('B', 32));
            $webAdminConfig = new WebAdminConfig(
                '/admin',
                $webAdminPrefix,
                'LS_BLOG_MYSQL_IT',
                1800,
                28800,
                'mysql-integration'
            );
            $blogConfig = new BlogConfig(
                [
                    'es' => '/noticias',
                    'eu' => '/eu/albisteak',
                ],
                '/blog-sitemap.xml',
                $blogPrefix,
                'mysql-integration'
            );
            $this->seedActiveActor(
                $connection,
                $webAdminPrefix,
                $clock->now()
            );
            $runtime = $this->runtime(
                $connection,
                $projectRoot,
                $blogScope,
                $blogConfig,
                $webAdminConfig,
                $securityKey,
                $clock
            );
            $preAuthentication = $runtime->authentication()
                ->openPreAuthenticationSession(null, '127.0.0.1');
            $attempt = $runtime->authentication()->authenticate(
                $preAuthentication->sessionToken(),
                $preAuthentication->csrfToken(),
                self::ACTOR_EMAIL,
                self::ACTOR_PASSWORD,
                '127.0.0.1',
                'LiquidStack Blog MySQL integration'
            );
            self::assertTrue($attempt->isSuccessful());
            $authenticated = $attempt->nextSession();
            self::assertTrue($authenticated->isAuthenticated());
            $sessionToken = $authenticated->sessionToken();
            $csrfToken = $authenticated->csrfToken();

            $editGate = $runtime->mutationGate(
                $sessionToken,
                $csrfToken,
                'blog.articles.edit'
            );
            $publishGate = $runtime->mutationGate(
                $sessionToken,
                $csrfToken,
                'blog.articles.publish'
            );
            $created = $runtime->service()->createPost(
                $editGate,
                'es',
                $this->draft('matrix-mysql', 'Matrix en MySQL')
            );
            self::assertSame(BlogPostVariant::DRAFT, $created->status());
            self::assertSame(1, $created->lockVersion());
            self::assertSame(
                self::ACTOR_PUBLIC_ID,
                $created->createdByUserPublicId()
            );

            $basque = $runtime->service()->addLocalization(
                $editGate,
                $created->postPublicId(),
                'eu',
                $this->draft('matrix-mysql-eu', 'Matrix MySQL euskaraz')
            );
            self::assertSame(
                $created->postPublicId(),
                $basque->postPublicId()
            );
            self::assertNotSame(
                $created->localizationPublicId(),
                $basque->localizationPublicId()
            );

            $saved = $runtime->service()->saveDraft(
                $editGate,
                $created->postPublicId(),
                'es',
                $created->lockVersion(),
                $this->draft('matrix-mysql', 'Matrix MySQL revisada')
            );
            self::assertSame(2, $saved->lockVersion());
            $published = $runtime->service()->publish(
                $publishGate,
                $created->postPublicId(),
                'es',
                $saved->lockVersion()
            );
            self::assertSame(BlogPostVariant::PUBLISHED, $published->status());
            self::assertSame(
                $created->postPublicId(),
                $runtime->service()->resolvePublished(
                    'es',
                    'matrix-mysql'
                )?->postPublicId()
            );
            self::assertCount(1, $runtime->service()->sitemapEntries());
            $equivalents = $runtime->service()
                ->publishedSitemapEntriesForPost($created->postPublicId());
            self::assertCount(1, $equivalents);
            self::assertSame(
                $created->postPublicId(),
                $equivalents[0]->postPublicId()
            );
            self::assertSame('es', $equivalents[0]->locale());
            self::assertNull(
                $runtime->service()->resolvePublished('eu', 'matrix-mysql-eu')
            );

            // Exercise the production MySQL/MariaDB SQL branches used by
            // related content and the year/month archive projections.
            $publicCatalog = new PdoBlogPublicCatalogRepository(
                $connection,
                $blogScope
            );
            self::assertSame([], $publicCatalog->relatedPosts(
                new BlogPublicRelatedQuery('es', 'matrix-mysql')
            ));
            $archiveCards = $publicCatalog->archivePosts(
                new BlogPublicArchiveQuery('es', 2032, 4)
            );
            self::assertCount(1, $archiveCards);
            self::assertSame('matrix-mysql', $archiveCards[0]->slug());
            $archivePeriods = $publicCatalog->archivePeriods(
                new BlogPublicArchivePeriodsQuery('es')
            );
            self::assertCount(1, $archivePeriods);
            self::assertSame(2032, $archivePeriods[0]->year());
            self::assertSame(4, $archivePeriods[0]->month());
            self::assertSame(1, $archivePeriods[0]->count());

            $withdrawn = $runtime->service()->unpublish(
                $publishGate,
                $created->postPublicId(),
                'es',
                $published->lockVersion()
            );
            self::assertSame(BlogPostVariant::DRAFT, $withdrawn->status());
            self::assertNull($withdrawn->publishedAt());
            self::assertSame(
                $published->draft()->bodyText(),
                $withdrawn->draft()->bodyText()
            );
            self::assertNull(
                $runtime->service()->resolvePublished('es', 'matrix-mysql')
            );
            self::assertSame([], $runtime->service()->sitemapEntries());
            self::assertSame(
                [],
                $runtime->service()->publishedSitemapEntriesForPost(
                    $created->postPublicId()
                )
            );
            self::assertCount(2, $runtime->service()->listPosts(10));

            $secondConnection = $this->connect($configuration);
            $this->assertSelectedDatabase(
                $secondConnection,
                $configuration->database()
            );
            $secondRuntime = $this->runtime(
                $secondConnection,
                $projectRoot,
                $blogScope,
                $blogConfig,
                $webAdminConfig,
                $securityKey,
                $clock
            );
            $firstView = $runtime->service()->loadPost(
                $created->postPublicId(),
                'es'
            );
            $secondView = $secondRuntime->service()->loadPost(
                $created->postPublicId(),
                'es'
            );
            self::assertSame(
                $firstView->lockVersion(),
                $secondView->lockVersion()
            );
            $clock->set($clock->now()->modify('+1 second'));
            $winner = $runtime->service()->saveDraft(
                $editGate,
                $created->postPublicId(),
                'es',
                $firstView->lockVersion(),
                $this->draft('matrix-mysql', 'Primera escritura')
            );
            try {
                $secondRuntime->service()->saveDraft(
                    $secondRuntime->mutationGate(
                        $sessionToken,
                        $csrfToken,
                        'blog.articles.edit'
                    ),
                    $created->postPublicId(),
                    'es',
                    $secondView->lockVersion(),
                    $this->draft('matrix-mysql', 'Escritura obsoleta')
                );
                self::fail('A stale MySQL write must never win.');
            } catch (BlogException $exception) {
                self::assertSame(
                    BlogException::LOCK_CONFLICT,
                    $exception->issueCode()
                );
            }
            self::assertSame(
                'Primera escritura',
                $secondRuntime->service()->loadPost(
                    $created->postPublicId(),
                    'es'
                )->draft()->h1()
            );
            self::assertSame(
                $winner->lockVersion(),
                $secondRuntime->service()->loadPost(
                    $created->postPublicId(),
                    'es'
                )->lockVersion()
            );

            $postCount = $this->tableCount(
                $connection,
                $blogPrefix . 'posts'
            );
            $localizationCount = $this->tableCount(
                $connection,
                $blogPrefix . 'post_localizations'
            );
            try {
                $runtime->service()->createPost(
                    static fn (PDO $pdo): string =>
                        self::UNKNOWN_ACTOR_PUBLIC_ID,
                    'en',
                    $this->draft('audit-must-rollback', 'Audit rollback')
                );
                self::fail('A failed WebAdmin audit append must roll back Blog.');
            } catch (BlogException $exception) {
                self::assertSame(
                    BlogException::STORAGE_UNAVAILABLE,
                    $exception->issueCode()
                );
            }
            self::assertSame(
                $postCount,
                $this->tableCount($connection, $blogPrefix . 'posts')
            );
            self::assertSame(
                $localizationCount,
                $this->tableCount(
                    $connection,
                    $blogPrefix . 'post_localizations'
                )
            );

            $this->seedCategoryAndStructuredContentFixtures(
                $connection,
                $blogScope,
                $webAdminPrefix,
                $created->postPublicId(),
                $basque,
                $clock->now()
            );
            $categoryRepository = new PdoBlogCategoryRepository(
                $connection,
                $blogScope
            );
            self::assertSame(
                ['es'],
                $categoryRepository->categoryLocales(
                    self::CATEGORY_PUBLIC_ID
                )
            );
            self::assertNull($categoryRepository->categoryLocales(
                '99999999-9999-4999-8999-999999999999'
            ));
            $this->assertExtendedFixtureRows(
                $connection,
                $webAdminPrefix,
                $blogPrefix
            );
            $this->assertStructuredCopyActionsAgainstRealMySql(
                $editGate,
                $connection,
                $blogScope,
                $webAdminScope,
                $created->postPublicId(),
                $basque,
                $clock
            );
            $this->assertConcurrentCopyIdempotencyAgainstRealMySql(
                $connection,
                $configuration,
                $blogScope,
                $webAdminScope,
                $created->postPublicId(),
                $basque
            );
            $this->assertPrivateWorkflowCasAcrossConnections(
                $connection,
                $secondConnection,
                $blogScope,
                $created->postPublicId(),
                $winner,
                $clock->now()
            );
            $this->seedTagRaceVariants($connection, $blogScope);
            $this->assertConcurrentTagIdentityAgainstRealMySql(
                $connection,
                $configuration,
                $blogScope
            );
            self::assertTrue(
                (new WebAdminMediaMigrationPostconditionVerifier(
                    acceptAvifSource: true
                ))->verify(
                    $connection,
                    $webAdminScope
                )
            );
            self::assertTrue(
                (new BlogTagSchemaMigrationPostconditionVerifier(5))
                    ->verify($connection, $blogScope)
            );
            $this->assertBlogAudit(
                $connection,
                $webAdminPrefix,
                $created->postPublicId()
            );
        } finally {
            $secondConnection = null;
            try {
                if ($cleanupArmed && $connection instanceof PDO) {
                    $this->dropOnlyOwnedTables(
                        $connection,
                        $configuration->database(),
                        $webAdminPrefix,
                        $blogPrefix
                    );
                }
            } finally {
                try {
                    if (
                        $guardConnection instanceof PDO
                        && is_string($guardLockName)
                    ) {
                        $this->releaseGuardLock(
                            $guardConnection,
                            $guardLockName
                        );
                    }
                } finally {
                    if (is_string($projectRoot)) {
                        $this->removeModuleProject($projectRoot);
                    }
                    ini_set(
                        'zend.exception_ignore_args',
                        $previousTraceSetting
                    );
                }
            }
        }
    }

    private function runtime(
        PDO $connection,
        string $projectRoot,
        MigrationScope $blogScope,
        BlogConfig $blogConfig,
        WebAdminConfig $webAdminConfig,
        SecurityKey $securityKey,
        ClockInterface $clock
    ): BlogAdminHttpRuntime {
        $tables = WebAdminTableNames::fromPdo(
            $connection,
            $webAdminConfig->tablePrefix()
        );
        $passwordHasher = PasswordHasher::productive();
        $tokenGenerator = new SecureTokenGenerator();
        $authentication = new WebAdminAuthenticationService(
            new WebAdminAuthenticationRepository($connection, $tables),
            $webAdminConfig,
            $securityKey,
            $clock,
            new RandomUuidV4Generator(),
            $passwordHasher,
            $tokenGenerator
        );

        return new BlogAdminHttpRuntime(
            $projectRoot,
            ['es', 'eu'],
            $blogConfig,
            $webAdminConfig,
            new BlogService(
                new PdoBlogRepository($connection, $blogScope),
                new RandomUuidV4Generator(),
                $clock,
                new WebAdminBlogMutationAuditAdapter(
                    $connection,
                    $tables,
                    new RandomUuidV4Generator()
                )
            ),
            $authentication,
            new WebAdminAuthorizationService(
                $connection,
                $tables,
                $clock,
                $tokenGenerator,
                $passwordHasher
            ),
            $connection,
            new WebAdminMutationActorGate(
                $connection,
                $tables,
                $webAdminConfig,
                $securityKey,
                $clock,
                $tokenGenerator,
                $passwordHasher
            )
        );
    }

    private function seedActiveActor(
        PDO $connection,
        string $webAdminPrefix,
        DateTimeImmutable $now
    ): void {
        $tables = WebAdminTableNames::fromPdo(
            $connection,
            $webAdminPrefix
        );
        $timestamp = self::format($now);
        $passwordHash = PasswordHasher::productive()->hash(
            self::ACTOR_PASSWORD
        );
        $user = $connection->prepare(
            'INSERT INTO ' . $tables->table('users') . ' '
            . '(public_id, email_canonical, display_name, status, '
            . 'auth_version, activated_at, created_at, updated_at) VALUES '
            . '(:public_id, :email, :display_name, :status, 1, '
            . ':activated_at, :created_at, :updated_at)'
        );
        self::assertNotFalse($user);
        self::assertTrue($user->execute([
            'public_id' => self::ACTOR_PUBLIC_ID,
            'email' => self::ACTOR_EMAIL,
            'display_name' => 'Blog integration actor',
            'status' => 'active',
            'activated_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]));
        self::assertSame(1, $user->rowCount());
        $userId = $this->positiveInteger($connection->lastInsertId());

        $credential = $connection->prepare(
            'INSERT INTO ' . $tables->table('credentials') . ' '
            . '(user_id, password_hash, password_set_at, created_at, '
            . 'updated_at) VALUES (:user_id, :password_hash, '
            . ':password_set_at, :created_at, :updated_at)'
        );
        self::assertNotFalse($credential);
        self::assertTrue($credential->execute([
            'user_id' => $userId,
            'password_hash' => $passwordHash,
            'password_set_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]));

        $role = $connection->prepare(
            'INSERT INTO ' . $tables->table('user_roles') . ' '
            . '(user_id, role_id, assigned_by_user_id, source, created_at) '
            . 'SELECT :user_id, id, NULL, :source, :created_at FROM '
            . $tables->table('roles') . ' WHERE code = :role_code'
        );
        self::assertNotFalse($role);
        self::assertTrue($role->execute([
            'user_id' => $userId,
            'source' => 'system',
            'created_at' => $timestamp,
            'role_code' => 'system_superadmin',
        ]));
        self::assertSame(1, $role->rowCount());
    }

    private function seedCategoryAndStructuredContentFixtures(
        PDO $connection,
        MigrationScope $blogScope,
        string $webAdminPrefix,
        string $postPublicId,
        BlogPostVariant $localization,
        DateTimeImmutable $now
    ): void {
        $tables = WebAdminTableNames::fromPdo(
            $connection,
            $webAdminPrefix
        );
        $repository = new PdoBlogStructuredContentRepository(
            $connection,
            $blogScope
        );
        $draft = $this->structuredDraft($localization);

        self::assertTrue($connection->beginTransaction());
        try {
            $this->seedCategoryFixture(
                $connection,
                $blogScope,
                $postPublicId,
                $now
            );
            $this->seedMediaFixture($connection, $tables, $now);

            $repository->upsertCurrent(
                $localization->localizationPublicId(),
                self::DOCUMENT_PUBLIC_ID,
                $draft,
                self::ACTOR_PUBLIC_ID,
                $now
            );
            $repository->replaceCurrentMedia(
                $localization->localizationPublicId(),
                $draft->mediaReferences(),
                $now
            );
            self::assertSame(
                1,
                $repository->appendRevision(
                    $localization->localizationPublicId(),
                    self::REVISION_PUBLIC_ID,
                    $localization->lockVersion(),
                    $draft,
                    self::ACTOR_PUBLIC_ID,
                    $now
                )
            );
            $repository->appendRevisionMedia(
                self::REVISION_PUBLIC_ID,
                $draft->mediaReferences(),
                $now
            );

            self::assertTrue($connection->commit());
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }

        $current = $repository->current(
            $localization->localizationPublicId()
        );
        self::assertNotNull($current);
        self::assertSame(
            self::DOCUMENT_PUBLIC_ID,
            $current->documentPublicId()
        );
        self::assertCount(1, $current->snapshot()->mediaReferences());
        $revision = $repository->revision(self::REVISION_PUBLIC_ID);
        self::assertNotNull($revision);
        self::assertSame(1, $revision->revisionNumber());
        self::assertCount(1, $revision->snapshot()->mediaReferences());
    }

    private function seedCategoryFixture(
        PDO $connection,
        MigrationScope $scope,
        string $postPublicId,
        DateTimeImmutable $now
    ): void {
        $timestamp = self::format($now);
        $postId = $this->rowIdByPublicId(
            $connection,
            $scope->quotedTable('posts', 'mysql'),
            $postPublicId
        );
        $categories = $scope->quotedTable('categories', 'mysql');
        $categoryLocales = $scope->quotedTable(
            'category_locales',
            'mysql'
        );
        $postCategories = $scope->quotedTable(
            'post_categories',
            'mysql'
        );

        $category = $connection->prepare(
            'INSERT INTO ' . $categories . ' '
            . '(public_id, created_by_user_public_id, created_at, updated_at) '
            . 'VALUES (:public_id, :actor, :created_at, :updated_at)'
        );
        self::assertNotFalse($category);
        self::assertTrue($category->execute([
            'public_id' => self::CATEGORY_PUBLIC_ID,
            'actor' => self::ACTOR_PUBLIC_ID,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]));
        self::assertSame(1, $category->rowCount());
        $categoryId = $this->positiveInteger(
            $connection->lastInsertId()
        );

        $locale = $connection->prepare(
            'INSERT INTO ' . $categoryLocales . ' '
            . '(public_id, category_id, locale, slug, name, lock_version, '
            . 'created_by_user_public_id, updated_by_user_public_id, '
            . 'created_at, updated_at) VALUES '
            . '(:public_id, :category_id, :locale, :slug, :name, 1, '
            . ':created_actor, :updated_actor, :created_at, :updated_at)'
        );
        self::assertNotFalse($locale);
        self::assertTrue($locale->execute([
            'public_id' => self::CATEGORY_LOCALE_PUBLIC_ID,
            'category_id' => $categoryId,
            'locale' => 'es',
            'slug' => 'matrix',
            'name' => 'Matrix',
            'created_actor' => self::ACTOR_PUBLIC_ID,
            'updated_actor' => self::ACTOR_PUBLIC_ID,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]));
        self::assertSame(1, $locale->rowCount());

        $assignment = $connection->prepare(
            'INSERT INTO ' . $postCategories . ' '
            . '(public_id, post_id, category_id, '
            . 'assigned_by_user_public_id, created_at, updated_at) VALUES '
            . '(:public_id, :post_id, :category_id, :actor, '
            . ':created_at, :updated_at)'
        );
        self::assertNotFalse($assignment);
        self::assertTrue($assignment->execute([
            'public_id' => self::POST_CATEGORY_PUBLIC_ID,
            'post_id' => $postId,
            'category_id' => $categoryId,
            'actor' => self::ACTOR_PUBLIC_ID,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]));
        self::assertSame(1, $assignment->rowCount());
    }

    private function assertStructuredCopyActionsAgainstRealMySql(
        callable $actorGate,
        PDO $connection,
        MigrationScope $blogScope,
        MigrationScope $webAdminScope,
        string $sourcePostPublicId,
        BlogPostVariant $source,
        ClockInterface $clock
    ): void {
        $content = new PdoBlogStructuredContentRepository(
            $connection,
            $blogScope
        );
        $service = new BlogService(
            new PdoBlogRepository($connection, $blogScope),
            new RandomUuidV4Generator(),
            $clock,
            structuredContentRepository: $content,
            mediaAvailability: new PdoWebAdminMediaAvailabilityAdapter(
                $connection,
                $webAdminScope
            )
        );
        $sourceCurrent = $content->current(
            $source->localizationPublicId()
        );
        self::assertNotNull($sourceCurrent);
        $sourceBeforeCopies = $service->loadPost(
            $sourcePostPublicId,
            $source->locale()
        );
        $sourceCanonicalJson = $sourceCurrent->snapshot()->canonicalJson();
        $sourceRevisionCount = count($content->listRevisions(
            $source->localizationPublicId(),
            10,
            0
        ));
        $postCount = $this->tableCount(
            $connection,
            $blogScope->tableName('posts')
        );
        $localizationCount = $this->tableCount(
            $connection,
            $blogScope->tableName('post_localizations')
        );
        $this->assertBlogCopyIssue(
            BlogException::LOCK_CONFLICT,
            fn (): BlogPostVariant => $service->duplicatePost(
                $actorGate,
                $sourcePostPublicId,
                $source->locale(),
                $sourceBeforeCopies->lockVersion() + 1
            )
        );

        $duplicate = $service->duplicatePost(
            $actorGate,
            $sourcePostPublicId,
            $source->locale(),
            $sourceBeforeCopies->lockVersion(),
            self::DUPLICATE_OPERATION_PUBLIC_ID
        );
        $duplicateReplay = $service->duplicatePost(
            $actorGate,
            $sourcePostPublicId,
            $source->locale(),
            $sourceBeforeCopies->lockVersion(),
            self::DUPLICATE_OPERATION_PUBLIC_ID
        );
        self::assertSame(
            $duplicate->postPublicId(),
            $duplicateReplay->postPublicId()
        );
        self::assertSame(
            $duplicate->localizationPublicId(),
            $duplicateReplay->localizationPublicId()
        );
        $this->assertBlogCopyIssue(
            BlogException::IDEMPOTENCY_CONFLICT,
            fn (): BlogPostVariant => $service->duplicatePost(
                $actorGate,
                $sourcePostPublicId,
                $source->locale(),
                $sourceBeforeCopies->lockVersion() + 1,
                self::DUPLICATE_OPERATION_PUBLIC_ID
            )
        );
        $this->assertInitialStructuredCopy($content, $duplicate);

        $localeCopy = $service->addLocalizationCopy(
            $actorGate,
            $duplicate->postPublicId(),
            $duplicate->locale(),
            'es',
            $duplicate->lockVersion(),
            self::LOCALE_COPY_OPERATION_PUBLIC_ID
        );
        $localeReplay = $service->addLocalizationCopy(
            $actorGate,
            $duplicate->postPublicId(),
            $duplicate->locale(),
            'es',
            $duplicate->lockVersion(),
            self::LOCALE_COPY_OPERATION_PUBLIC_ID
        );
        self::assertSame(
            $localeCopy->localizationPublicId(),
            $localeReplay->localizationPublicId()
        );
        $this->assertInitialStructuredCopy($content, $localeCopy);

        $this->assertBlogCopyIssue(
            BlogException::LOCALE_CONFLICT,
            fn (): BlogPostVariant => $service->addLocalizationCopy(
                $actorGate,
                $duplicate->postPublicId(),
                $duplicate->locale(),
                'es',
                $duplicate->lockVersion()
            )
        );
        self::assertSame(
            $sourceCanonicalJson,
            $content->current(
                $source->localizationPublicId()
            )?->snapshot()->canonicalJson()
        );
        self::assertCount(
            $sourceRevisionCount,
            $content->listRevisions(
                $source->localizationPublicId(),
                10,
                0
            )
        );
        self::assertSame(
            $postCount + 1,
            $this->tableCount(
                $connection,
                $blogScope->tableName('posts')
            )
        );
        self::assertSame(
            $localizationCount + 2,
            $this->tableCount(
                $connection,
                $blogScope->tableName('post_localizations')
            )
        );
        self::assertSame(
            2,
            $this->tableCount(
                $connection,
                $blogScope->tableName('copy_operations')
            )
        );
        $sourceAfterCopies = $service->loadPost(
            $sourcePostPublicId,
            $source->locale()
        );
        self::assertSame(
            $sourceBeforeCopies->lockVersion(),
            $sourceAfterCopies->lockVersion()
        );
        self::assertEquals(
            $sourceBeforeCopies->draft(),
            $sourceAfterCopies->draft()
        );
        self::assertSame(
            $sourceBeforeCopies->status(),
            $sourceAfterCopies->status()
        );
    }

    private function assertConcurrentCopyIdempotencyAgainstRealMySql(
        PDO $connection,
        BlogMySqlTestConfiguration $configuration,
        MigrationScope $blogScope,
        MigrationScope $webAdminScope,
        string $sourcePostPublicId,
        BlogPostVariant $source
    ): void {
        $repository = new PdoBlogRepository($connection, $blogScope);
        $content = new PdoBlogStructuredContentRepository(
            $connection,
            $blogScope
        );
        $sourceBefore = $repository->variant(
            $sourcePostPublicId,
            $source->locale()
        );
        self::assertNotNull($sourceBefore);

        $postsBefore = $this->tableCount(
            $connection,
            $blogScope->tableName('posts')
        );
        $localizationsBefore = $this->tableCount(
            $connection,
            $blogScope->tableName('post_localizations')
        );
        $operationsBefore = $this->tableCount(
            $connection,
            $blogScope->tableName('copy_operations')
        );
        $samePayload = [
            'operation' => 'duplicate_post',
            'operation_id' =>
                self::CONCURRENT_DUPLICATE_OPERATION_PUBLIC_ID,
            'source_post_public_id' => $sourcePostPublicId,
            'source_locale' => $source->locale(),
            'destination_locale' => $source->locale(),
            'expected_lock_version' => $sourceBefore->lockVersion(),
            'actor_public_id' => self::ACTOR_PUBLIC_ID,
        ];
        $sameResults = $this->runConcurrentCopyWorkers(
            $configuration,
            $blogScope,
            $webAdminScope,
            [$samePayload, $samePayload]
        );
        self::assertSame([0, 0], array_column(
            $sameResults,
            'exit_code'
        ));
        $sameDestinationPosts = array_values(array_unique(array_map(
            static fn (array $result): string =>
                (string) $result['payload']['post_public_id'],
            $sameResults
        )));
        $sameDestinationLocalizations = array_values(array_unique(array_map(
            static fn (array $result): string =>
                (string) $result['payload']['localization_public_id'],
            $sameResults
        )));
        self::assertCount(
            1,
            $sameDestinationPosts,
            'Concurrent idempotent replays must return one destination post.'
        );
        self::assertCount(
            1,
            $sameDestinationLocalizations,
            'Concurrent idempotent replays must return one localization.'
        );
        self::assertSame(
            $postsBefore + 1,
            $this->tableCount($connection, $blogScope->tableName('posts'))
        );
        self::assertSame(
            $localizationsBefore + 1,
            $this->tableCount(
                $connection,
                $blogScope->tableName('post_localizations')
            )
        );
        self::assertSame(
            $operationsBefore + 1,
            $this->tableCount(
                $connection,
                $blogScope->tableName('copy_operations')
            )
        );
        $sameCopy = $repository->variant(
            $sameDestinationPosts[0],
            $source->locale()
        );
        self::assertNotNull($sameCopy);
        self::assertSame(
            $sameDestinationLocalizations[0],
            $sameCopy->localizationPublicId()
        );
        $this->assertInitialStructuredCopy($content, $sameCopy);
        $sameOperation = $this->copyOperationRow(
            $connection,
            $blogScope,
            self::CONCURRENT_DUPLICATE_OPERATION_PUBLIC_ID
        );
        self::assertSame(
            $sameDestinationPosts[0],
            $sameOperation['result_post_public_id']
        );
        self::assertSame(
            $source->locale(),
            $sameOperation['result_locale']
        );
        self::assertNotNull($sameOperation['completed_at']);

        $postsAfterReplay = $this->tableCount(
            $connection,
            $blogScope->tableName('posts')
        );
        $localizationsAfterReplay = $this->tableCount(
            $connection,
            $blogScope->tableName('post_localizations')
        );
        $operationsAfterReplay = $this->tableCount(
            $connection,
            $blogScope->tableName('copy_operations')
        );
        $differentPayloadBase = [
            'operation' => 'add_locale',
            'operation_id' => self::CONCURRENT_LOCALE_OPERATION_PUBLIC_ID,
            'source_post_public_id' => $sourcePostPublicId,
            'source_locale' => $source->locale(),
            'expected_lock_version' => $sourceBefore->lockVersion(),
            'actor_public_id' => self::ACTOR_PUBLIC_ID,
        ];
        $differentResults = $this->runConcurrentCopyWorkers(
            $configuration,
            $blogScope,
            $webAdminScope,
            [
                $differentPayloadBase + ['destination_locale' => 'en'],
                $differentPayloadBase + ['destination_locale' => 'fr'],
            ]
        );
        $differentExitCodes = array_column(
            $differentResults,
            'exit_code'
        );
        sort($differentExitCodes, SORT_NUMERIC);
        self::assertSame(
            [0, 4],
            $differentExitCodes,
            'Different concurrent payloads must yield one winner and one '
                . 'idempotency conflict.'
        );
        $winnerResults = array_values(array_filter(
            $differentResults,
            static fn (array $result): bool => $result['exit_code'] === 0
        ));
        $conflictResults = array_values(array_filter(
            $differentResults,
            static fn (array $result): bool => $result['exit_code'] === 4
        ));
        self::assertCount(1, $winnerResults);
        self::assertCount(1, $conflictResults);
        self::assertSame(
            BlogException::IDEMPOTENCY_CONFLICT,
            $conflictResults[0]['payload']['issue'] ?? null
        );
        $winningLocale = (string) (
            $winnerResults[0]['payload']['locale'] ?? ''
        );
        self::assertContains($winningLocale, ['en', 'fr']);
        $losingLocale = $winningLocale === 'en' ? 'fr' : 'en';
        self::assertSame(
            $sourcePostPublicId,
            $winnerResults[0]['payload']['post_public_id'] ?? null
        );
        self::assertSame(
            $postsAfterReplay,
            $this->tableCount($connection, $blogScope->tableName('posts'))
        );
        self::assertSame(
            $localizationsAfterReplay + 1,
            $this->tableCount(
                $connection,
                $blogScope->tableName('post_localizations')
            )
        );
        self::assertSame(
            $operationsAfterReplay + 1,
            $this->tableCount(
                $connection,
                $blogScope->tableName('copy_operations')
            )
        );
        $winningCopy = $repository->variant(
            $sourcePostPublicId,
            $winningLocale
        );
        self::assertNotNull($winningCopy);
        self::assertNull($repository->variant(
            $sourcePostPublicId,
            $losingLocale
        ));
        $this->assertInitialStructuredCopy($content, $winningCopy);
        $differentOperation = $this->copyOperationRow(
            $connection,
            $blogScope,
            self::CONCURRENT_LOCALE_OPERATION_PUBLIC_ID
        );
        self::assertSame(
            $sourcePostPublicId,
            $differentOperation['result_post_public_id']
        );
        self::assertSame(
            $winningLocale,
            $differentOperation['destination_locale']
        );
        self::assertSame(
            $winningLocale,
            $differentOperation['result_locale']
        );
        self::assertNotNull($differentOperation['completed_at']);

        $sourceAfter = $repository->variant(
            $sourcePostPublicId,
            $source->locale()
        );
        self::assertNotNull($sourceAfter);
        self::assertSame(
            $sourceBefore->lockVersion(),
            $sourceAfter->lockVersion()
        );
        self::assertEquals($sourceBefore->draft(), $sourceAfter->draft());
        self::assertSame($sourceBefore->status(), $sourceAfter->status());
    }

    /**
     * @param list<array{
     *   operation: string,
     *   operation_id: string,
     *   source_post_public_id: string,
     *   source_locale: string,
     *   destination_locale: string,
     *   expected_lock_version: int,
     *   actor_public_id: string
     * }> $requests
     * @return list<array{exit_code: int, payload: array<string, mixed>}>
     */
    private function runConcurrentCopyWorkers(
        BlogMySqlTestConfiguration $configuration,
        MigrationScope $blogScope,
        MigrationScope $webAdminScope,
        array $requests
    ): array {
        self::assertCount(2, $requests);
        $worker = dirname(__DIR__)
            . DIRECTORY_SEPARATOR . 'Integration'
            . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'blog_mysql_copy_worker.php';
        self::assertFileExists($worker);
        $markers = [
            $this->unusedCopyWorkerPath('ls-blog-copy-a-'),
            $this->unusedCopyWorkerPath('ls-blog-copy-b-'),
        ];
        $start = $this->unusedCopyWorkerPath('ls-blog-copy-go-');
        $processes = [];
        foreach ($requests as $position => $request) {
            $processes[] = $this->copyWorkerProcess(
                $worker,
                $configuration,
                $blogScope,
                $webAdminScope,
                $markers[$position],
                $start,
                $request
            );
        }

        try {
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($markers as $marker) {
                $this->waitForCopyWorkerMarker($marker, $processes);
            }
            foreach ($processes as $process) {
                self::assertTrue(
                    $process->isRunning(),
                    'Both copy workers must be live at the race barrier.'
                );
            }
            self::assertSame(2, file_put_contents($start, 'go', LOCK_EX));

            $results = [];
            foreach ($processes as $process) {
                $exitCode = $process->wait();
                $output = trim($process->getOutput());
                try {
                    $payload = json_decode(
                        $output,
                        true,
                        32,
                        JSON_THROW_ON_ERROR
                    );
                } catch (JsonException $exception) {
                    self::fail(sprintf(
                        'Copy worker exited %d without valid JSON: %s (%s)',
                        $exitCode,
                        $output,
                        trim($process->getErrorOutput())
                    ));
                }
                self::assertIsArray($payload);
                $results[] = [
                    'exit_code' => $exitCode,
                    'payload' => $payload,
                ];
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1.0);
                }
            }
            foreach (array_merge($markers, [$start]) as $path) {
                $this->removeCopyWorkerPath($path);
            }
        }
    }

    /**
     * @param array{
     *   operation: string,
     *   operation_id: string,
     *   source_post_public_id: string,
     *   source_locale: string,
     *   destination_locale: string,
     *   expected_lock_version: int,
     *   actor_public_id: string
     * } $request
     */
    private function copyWorkerProcess(
        string $worker,
        BlogMySqlTestConfiguration $configuration,
        MigrationScope $blogScope,
        MigrationScope $webAdminScope,
        string $marker,
        string $start,
        array $request
    ): Process {
        $process = new Process(
            [PHP_BINARY, $worker],
            dirname(__DIR__, 2),
            [
                'LIQUIDSTACK_TEST_WORKER_AUTOLOAD' => dirname(__DIR__, 2)
                    . DIRECTORY_SEPARATOR . 'vendor'
                    . DIRECTORY_SEPARATOR . 'autoload.php',
                'LIQUIDSTACK_TEST_WORKER_MARKER' => $marker,
                'LIQUIDSTACK_TEST_WORKER_START' => $start,
                'LIQUIDSTACK_TEST_WORKER_HOST' => $configuration->host()
                    . ':' . $configuration->port(),
                'LIQUIDSTACK_TEST_WORKER_USERNAME' =>
                    $configuration->username(),
                'LIQUIDSTACK_TEST_WORKER_PASSWORD' =>
                    $configuration->password(),
                'LIQUIDSTACK_TEST_WORKER_DATABASE' =>
                    $configuration->database(),
                'LIQUIDSTACK_TEST_WORKER_BLOG_PREFIX' =>
                    $blogScope->tablePrefix(),
                'LIQUIDSTACK_TEST_WORKER_WEBADMIN_PREFIX' =>
                    $webAdminScope->tablePrefix(),
                'LIQUIDSTACK_TEST_WORKER_COPY_ACTION' =>
                    $request['operation'],
                'LIQUIDSTACK_TEST_WORKER_OPERATION_ID' =>
                    $request['operation_id'],
                'LIQUIDSTACK_TEST_WORKER_SOURCE_POST' =>
                    $request['source_post_public_id'],
                'LIQUIDSTACK_TEST_WORKER_SOURCE_LOCALE' =>
                    $request['source_locale'],
                'LIQUIDSTACK_TEST_WORKER_DESTINATION_LOCALE' =>
                    $request['destination_locale'],
                'LIQUIDSTACK_TEST_WORKER_EXPECTED_LOCK' =>
                    (string) $request['expected_lock_version'],
                'LIQUIDSTACK_TEST_WORKER_ACTOR' =>
                    $request['actor_public_id'],
            ]
        );
        $process->setTimeout(20.0);

        return $process;
    }

    private function seedTagRaceVariants(
        PDO $connection,
        MigrationScope $blogScope
    ): void {
        $posts = $blogScope->quotedTable('posts', 'mysql');
        $localizations = $blogScope->quotedTable(
            'post_localizations',
            'mysql'
        );
        $insertPost = $connection->prepare(
            'INSERT INTO ' . $posts
                . ' (public_id, created_by_user_public_id) '
                . 'VALUES (:public, :actor)'
        );
        $insertLocalization = $connection->prepare(
            'INSERT INTO ' . $localizations
                . ' (public_id, post_id, locale, slug, h1, seo_title, '
                . 'meta_description, excerpt, body_text, status, '
                . 'published_at, created_by_user_public_id, '
                . 'updated_by_user_public_id) VALUES (:public, :post, '
                . "'es', NULL, 'Tag race', NULL, NULL, NULL, 'Contenido', "
                . "'draft', NULL, :created_actor, :updated_actor)"
        );
        foreach ([
            [self::TAG_RACE_POST_A, self::TAG_RACE_LOCALIZATION_A],
            [self::TAG_RACE_POST_B, self::TAG_RACE_LOCALIZATION_B],
        ] as [$post, $localization]) {
            $insertPost->execute([
                'public' => $post,
                'actor' => self::ACTOR_PUBLIC_ID,
            ]);
            $postId = (int) $connection->lastInsertId();
            self::assertGreaterThan(0, $postId);
            $insertLocalization->execute([
                'public' => $localization,
                'post' => $postId,
                'created_actor' => self::ACTOR_PUBLIC_ID,
                'updated_actor' => self::ACTOR_PUBLIC_ID,
            ]);
        }
    }

    private function assertConcurrentTagIdentityAgainstRealMySql(
        PDO $connection,
        BlogMySqlTestConfiguration $configuration,
        MigrationScope $blogScope
    ): void {
        $sameIdentity = $this->runConcurrentTagWorkers(
            $configuration,
            $blogScope,
            [
                [
                    'post' => self::TAG_RACE_POST_A,
                    'csv' => 'Fiscal',
                    'workspace_version' => 0,
                ],
                [
                    'post' => self::TAG_RACE_POST_B,
                    'csv' => 'FISCAL',
                    'workspace_version' => 0,
                ],
            ]
        );
        foreach ($sameIdentity as $result) {
            self::assertSame(
                0,
                $result['exit_code'],
                'Same-identity tag worker failed: ' . json_encode(
                    $result['payload'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            );
            self::assertSame('success', $result['payload']['status'] ?? null);
            self::assertSame(
                1,
                $result['payload']['workspace_version'] ?? null
            );
            self::assertCount(
                1,
                $result['payload']['tag_public_ids'] ?? []
            );
        }
        self::assertIsString(
            $sameIdentity[0]['payload']['tag_public_ids'][0] ?? null
        );
        self::assertSame(
            $sameIdentity[0]['payload']['tag_public_ids'][0] ?? null,
            $sameIdentity[1]['payload']['tag_public_ids'][0] ?? null,
            'Both transactions must resolve the same first-writer identity.'
        );

        $slugCollision = $this->runConcurrentTagWorkers(
            $configuration,
            $blogScope,
            [
                [
                    'post' => self::TAG_RACE_POST_A,
                    'csv' => 'C++',
                    'workspace_version' => 1,
                ],
                [
                    'post' => self::TAG_RACE_POST_B,
                    'csv' => 'C#',
                    'workspace_version' => 1,
                ],
            ]
        );
        foreach ($slugCollision as $result) {
            self::assertSame(
                0,
                $result['exit_code'],
                'Slug-collision tag worker failed: ' . json_encode(
                    $result['payload'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            );
            self::assertSame('success', $result['payload']['status'] ?? null);
            self::assertSame(
                2,
                $result['payload']['workspace_version'] ?? null
            );
            self::assertCount(1, $result['payload']['tags'] ?? []);
        }

        $statement = $connection->prepare(
            'SELECT slug, normalized_sha256 FROM '
                . $blogScope->quotedTable('tags', 'mysql')
                . " WHERE locale = 'es' AND normalized_sha256 IN (:cpp, :csharp)"
        );
        $statement->execute([
            'cpp' => hash('sha256', 'c++'),
            'csharp' => hash('sha256', 'c#'),
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        $base = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['slug'] ?? null) === 'c'
        ));
        self::assertCount(1, $base);
        $collision = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['slug'] ?? null) !== 'c'
        ));
        self::assertCount(1, $collision);
        self::assertSame(
            'c-' . substr((string) $collision[0]['normalized_sha256'], 0, 16),
            $collision[0]['slug']
        );
    }

    /**
     * @param list<array{post: string, csv: string, workspace_version: int}> $requests
     * @return list<array{exit_code: int, payload: array<string, mixed>}>
     */
    private function runConcurrentTagWorkers(
        BlogMySqlTestConfiguration $configuration,
        MigrationScope $blogScope,
        array $requests
    ): array {
        self::assertCount(2, $requests);
        $worker = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Integration'
            . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR
            . 'blog_mysql_tag_worker.php';
        self::assertFileExists($worker);
        $ready = [
            $this->unusedTagWorkerPath('ready-a'),
            $this->unusedTagWorkerPath('ready-b'),
        ];
        $insert = [
            $this->unusedTagWorkerPath('insert-a'),
            $this->unusedTagWorkerPath('insert-b'),
        ];
        $start = $this->unusedTagWorkerPath('start');
        $insertStart = $this->unusedTagWorkerPath('insert-go');
        $processes = [];
        foreach ($requests as $position => $request) {
            $processes[] = $this->tagWorkerProcess(
                $worker,
                $configuration,
                $blogScope,
                $ready[$position],
                $start,
                $insert[$position],
                $insertStart,
                $request
            );
        }

        try {
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($ready as $marker) {
                $this->waitForTagWorkerMarker($marker, $processes);
            }
            self::assertSame(2, file_put_contents($start, 'go', LOCK_EX));
            foreach ($insert as $marker) {
                $this->waitForTagWorkerMarker($marker, $processes);
            }
            self::assertSame(
                2,
                file_put_contents($insertStart, 'go', LOCK_EX)
            );

            $results = [];
            foreach ($processes as $process) {
                $exitCode = $process->wait();
                $output = trim($process->getOutput());
                try {
                    $payload = json_decode(
                        $output,
                        true,
                        32,
                        JSON_THROW_ON_ERROR
                    );
                } catch (JsonException) {
                    self::fail(sprintf(
                        'Tag worker exited %d without valid JSON: %s (%s)',
                        $exitCode,
                        $output,
                        trim($process->getErrorOutput())
                    ));
                }
                self::assertIsArray($payload);
                $results[] = [
                    'exit_code' => $exitCode,
                    'payload' => $payload,
                ];
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1.0);
                }
            }
            foreach (
                array_merge($ready, $insert, [$start, $insertStart])
                as $path
            ) {
                $this->removeTagWorkerPath($path);
            }
        }
    }

    /** @param array{post: string, csv: string, workspace_version: int} $request */
    private function tagWorkerProcess(
        string $worker,
        BlogMySqlTestConfiguration $configuration,
        MigrationScope $blogScope,
        string $ready,
        string $start,
        string $insert,
        string $insertStart,
        array $request
    ): Process {
        $process = new Process(
            [PHP_BINARY, $worker],
            dirname(__DIR__, 2),
            [
                'LIQUIDSTACK_TEST_WORKER_AUTOLOAD' => dirname(__DIR__, 2)
                    . DIRECTORY_SEPARATOR . 'vendor'
                    . DIRECTORY_SEPARATOR . 'autoload.php',
                'LIQUIDSTACK_TEST_WORKER_HOST' => $configuration->host()
                    . ':' . $configuration->port(),
                'LIQUIDSTACK_TEST_WORKER_USERNAME' =>
                    $configuration->username(),
                'LIQUIDSTACK_TEST_WORKER_PASSWORD' =>
                    $configuration->password(),
                'LIQUIDSTACK_TEST_WORKER_DATABASE' =>
                    $configuration->database(),
                'LIQUIDSTACK_TEST_WORKER_BLOG_PREFIX' =>
                    $blogScope->tablePrefix(),
                'LIQUIDSTACK_TEST_WORKER_ACTOR' => self::ACTOR_PUBLIC_ID,
                'LIQUIDSTACK_TEST_TAG_READY' => $ready,
                'LIQUIDSTACK_TEST_TAG_START' => $start,
                'LIQUIDSTACK_TEST_TAG_INSERT' => $insert,
                'LIQUIDSTACK_TEST_TAG_INSERT_START' => $insertStart,
                'LIQUIDSTACK_TEST_TAG_POST' => $request['post'],
                'LIQUIDSTACK_TEST_TAG_LOCALE' => 'es',
                'LIQUIDSTACK_TEST_TAG_LOCK' => '1',
                'LIQUIDSTACK_TEST_TAG_WORKSPACE' =>
                    (string) $request['workspace_version'],
                'LIQUIDSTACK_TEST_TAG_CSV' => $request['csv'],
            ]
        );
        $process->setTimeout(25.0);

        return $process;
    }

    /** @param list<Process> $processes */
    private function waitForTagWorkerMarker(
        string $marker,
        array $processes
    ): void {
        $deadline = microtime(true) + 6.0;
        while (!is_file($marker) && microtime(true) < $deadline) {
            foreach ($processes as $process) {
                if (!$process->isRunning()) {
                    break 2;
                }
            }
            usleep(10_000);
        }
        self::assertFileExists(
            $marker,
            'An isolated Blog tag worker did not reach its race barrier.'
        );
    }

    private function unusedTagWorkerPath(string $role): string
    {
        if (!in_array($role, [
            'ready-a', 'ready-b', 'insert-a', 'insert-b', 'start', 'insert-go',
        ], true)) {
            throw new RuntimeException('Unsafe Blog tag worker marker role.');
        }
        $path = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR
            . 'ls-blog-tag-' . $role . '-' . bin2hex(random_bytes(8))
            . '.tmp';
        if (file_exists($path)) {
            throw new RuntimeException('Blog tag worker path already exists.');
        }

        return $path;
    }

    private function removeTagWorkerPath(string $path): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($path));
        if (
            $temporaryRoot === false
            || $parent !== $temporaryRoot
            || preg_match(
                '/\Als-blog-tag-(?:(?:ready|insert)-(?:a|b)|start|insert-go)'
                    . '-[a-f0-9]{16}\.tmp\z/D',
                basename($path)
            ) !== 1
        ) {
            throw new RuntimeException('Refusing unsafe tag worker cleanup.');
        }
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not remove tag worker marker.');
        }
    }

    /** @param list<Process> $processes */
    private function waitForCopyWorkerMarker(
        string $marker,
        array $processes
    ): void {
        $deadline = microtime(true) + 4.0;
        while (!is_file($marker) && microtime(true) < $deadline) {
            foreach ($processes as $process) {
                if (!$process->isRunning()) {
                    break 2;
                }
            }
            usleep(10_000);
        }
        self::assertFileExists(
            $marker,
            'An isolated Blog copy worker did not reach the race barrier.'
        );
    }

    private function unusedCopyWorkerPath(string $prefix): string
    {
        if (!in_array($prefix, [
            'ls-blog-copy-a-',
            'ls-blog-copy-b-',
            'ls-blog-copy-go-',
        ], true)) {
            throw new RuntimeException('Unsafe Blog worker marker prefix.');
        }
        $path = rtrim(sys_get_temp_dir(), '\\/')
            . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8))
            . '.tmp';
        if (file_exists($path)) {
            throw new RuntimeException('Blog worker path already exists.');
        }

        return $path;
    }

    private function removeCopyWorkerPath(string $path): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($path));
        $basename = basename($path);
        if (
            $temporaryRoot === false
            || $parent !== $temporaryRoot
            || preg_match(
                '/\Als-blog-copy-(?:a|b|go)-[a-f0-9]{16}\.tmp\z/D',
                $basename
            ) !== 1
        ) {
            throw new RuntimeException('Unsafe Blog worker file path.');
        }
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not remove a Blog worker file.');
        }
    }

    /** @return array<string, mixed> */
    private function copyOperationRow(
        PDO $connection,
        MigrationScope $scope,
        string $operationPublicId
    ): array {
        $statement = $connection->prepare(
            'SELECT request_public_id, payload_sha256, actor_public_id, '
            . 'operation, source_post_public_id, source_locale, '
            . 'destination_locale, expected_lock_version, '
            . 'result_post_public_id, result_locale, completed_at FROM '
            . $scope->quotedTable('copy_operations', 'mysql')
            . ' WHERE request_public_id = :request_public_id'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'request_public_id' => $operationPublicId,
        ]));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(
            1,
            $rows,
            'Each concurrent operation ID must own exactly one durable row.'
        );

        return $rows[0];
    }

    private function assertInitialStructuredCopy(
        PdoBlogStructuredContentRepository $content,
        BlogPostVariant $copy
    ): void {
        $current = $content->current($copy->localizationPublicId());
        $revisions = $content->listRevisions(
            $copy->localizationPublicId(),
            10,
            0
        );
        self::assertNotNull($current);
        self::assertCount(1, $current->snapshot()->mediaReferences());
        self::assertCount(1, $revisions);
        self::assertSame(1, $revisions[0]->revisionNumber());
        self::assertSame(1, $revisions[0]->variantLockVersion());
        self::assertSame(1, $revisions[0]->mediaCount());
        self::assertSame(
            $current->snapshot()->canonicalJson(),
            $content->revision(
                $revisions[0]->revisionPublicId()
            )?->snapshot()->canonicalJson()
        );
    }

    /** @param callable(): BlogPostVariant $mutation */
    private function assertBlogCopyIssue(
        string $expectedIssue,
        callable $mutation
    ): void {
        try {
            $mutation();
            self::fail('The structured copy mutation should have failed.');
        } catch (BlogException $exception) {
            self::assertSame($expectedIssue, $exception->issueCode());
        }
    }

    private function assertPrivateWorkflowCasAcrossConnections(
        PDO $firstConnection,
        PDO $secondConnection,
        MigrationScope $scope,
        string $postPublicId,
        BlogPostVariant $variant,
        DateTimeImmutable $now
    ): void {
        $first = new PdoBlogEditorialWorkspaceRepository(
            $firstConnection,
            $scope
        );
        $second = new PdoBlogEditorialWorkspaceRepository(
            $secondConnection,
            $scope
        );
        $state = $first->variantState($postPublicId, $variant->locale());
        self::assertNotNull($state);
        self::assertTrue($firstConnection->beginTransaction());
        try {
            self::assertTrue($first->publishSnapshot(
                $state->localizationPublicId(),
                $state->lockVersion(),
                BlogPostVariant::DRAFT,
                $variant->draft(),
                self::ACTOR_PUBLIC_ID,
                $now
            ));
            self::assertTrue($firstConnection->commit());
        } catch (Throwable $exception) {
            if ($firstConnection->inTransaction()) {
                $firstConnection->rollBack();
            }
            throw $exception;
        }

        $contentVersion = $state->lockVersion() + 1;
        self::assertTrue($firstConnection->beginTransaction());
        self::assertTrue($first->advancePrivateLock(
            $state->localizationPublicId(),
            $contentVersion,
            self::ACTOR_PUBLIC_ID
        ));
        self::assertTrue($firstConnection->commit());
        self::assertTrue($secondConnection->beginTransaction());
        self::assertFalse($second->advancePrivateLock(
            $state->localizationPublicId(),
            $contentVersion,
            self::ACTOR_PUBLIC_ID
        ));
        self::assertTrue($secondConnection->commit());

        $staleCategoryVersion = $second->categoryWorkspaceVersion(
            $postPublicId
        );
        self::assertSame(0, $staleCategoryVersion);
        $baseAssignmentVersion = $first->categoryAssignmentVersion(
            $postPublicId
        );
        self::assertTrue($firstConnection->beginTransaction());
        self::assertSame(1, $first->replaceWorkspaceCategories(
            $postPublicId,
            [self::CATEGORY_PUBLIC_ID],
            0,
            $baseAssignmentVersion,
            self::ACTOR_PUBLIC_ID,
            $now
        ));
        self::assertTrue($firstConnection->commit());

        self::assertTrue($secondConnection->beginTransaction());
        try {
            $second->replaceWorkspaceCategories(
                $postPublicId,
                [self::CATEGORY_PUBLIC_ID],
                $staleCategoryVersion,
                $baseAssignmentVersion,
                self::ACTOR_PUBLIC_ID,
                $now
            );
            self::fail('A stale category workspace CAS must never win.');
        } catch (BlogEditorialWorkflowPersistenceException) {
            self::assertTrue($secondConnection->rollBack());
        }
    }

    private function seedMediaFixture(
        PDO $connection,
        WebAdminTableNames $tables,
        DateTimeImmutable $now
    ): void {
        $timestamp = self::format($now);
        $userId = $this->rowIdByPublicId(
            $connection,
            $tables->table('users'),
            self::ACTOR_PUBLIC_ID
        );
        $asset = $connection->prepare(
            'INSERT INTO ' . $tables->table('media_assets') . ' '
            . '(public_id, label, source_mime, source_width, source_height, '
            . 'source_bytes, source_sha256, created_by_user_id, created_at) '
            . 'VALUES (:public_id, :label, :source_mime, :source_width, '
            . ':source_height, :source_bytes, :source_sha256, :author, '
            . ':created_at)'
        );
        self::assertNotFalse($asset);
        self::assertTrue($asset->execute([
            'public_id' => self::MEDIA_ASSET_PUBLIC_ID,
            // Exactly 120 multibyte characters: VARCHAR and the verifier
            // contract count characters, never UTF-8 storage bytes.
            'label' => str_repeat("\u{00E1}", 120),
            'source_mime' => 'image/png',
            'source_width' => 1280,
            'source_height' => 720,
            'source_bytes' => 4096,
            'source_sha256' => hash('sha256', 'matrix-source-fixture'),
            'author' => $userId,
            'created_at' => $timestamp,
        ]));
        self::assertSame(1, $asset->rowCount());
        $assetId = $this->positiveInteger($connection->lastInsertId());

        $variant = $connection->prepare(
            'INSERT INTO ' . $tables->table('media_variants') . ' '
            . '(asset_id, width, height, bytes, sha256, storage_key, mime, '
            . 'created_at) VALUES (:asset_id, :width, :height, :bytes, '
            . ':sha256, :storage_key, :mime, :created_at)'
        );
        self::assertNotFalse($variant);
        self::assertTrue($variant->execute([
            'asset_id' => $assetId,
            'width' => 800,
            'height' => 450,
            'bytes' => 2048,
            'sha256' => hash('sha256', 'matrix-avif-fixture'),
            'storage_key' => '11/' . self::MEDIA_ASSET_PUBLIC_ID
                . '/800.avif',
            'mime' => 'image/avif',
            'created_at' => $timestamp,
        ]));
        self::assertSame(1, $variant->rowCount());
    }

    private function structuredDraft(
        BlogPostVariant $localization
    ): BlogStructuredDraft {
        $plainDraft = $localization->draft();
        $paragraphs = preg_split('/\R{2,}/u', $plainDraft->bodyText());
        self::assertIsArray($paragraphs);
        self::assertCount(2, $paragraphs);
        $paragraphIds = [
            self::FIRST_PARAGRAPH_PUBLIC_ID,
            self::SECOND_PARAGRAPH_PUBLIC_ID,
        ];
        $blocks = [[
            'id' => self::COVER_BLOCK_PUBLIC_ID,
            'type' => 'image',
            'media_asset_public_id' => self::MEDIA_ASSET_PUBLIC_ID,
            'alt' => '',
            'title' => null,
            'caption' => null,
            'decorative' => true,
            'display' => 'cover',
        ]];
        foreach ($paragraphs as $position => $paragraph) {
            $blocks[] = [
                'id' => $paragraphIds[$position],
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => $paragraph,
                    'marks' => [],
                ]],
            ];
        }

        return new BlogStructuredDraft(
            $plainDraft->h1(),
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => BlogDocumentTemplateRegistry::ARTICLE_COVER,
                'blocks' => $blocks,
            ]),
            $plainDraft->slug(),
            $plainDraft->seoTitle(),
            $plainDraft->metaDescription(),
            $plainDraft->excerpt()
        );
    }

    private function rowIdByPublicId(
        PDO $connection,
        string $quotedTable,
        string $publicId
    ): int {
        if (preg_match('/\A`[a-z0-9_]+`\z/', $quotedTable) !== 1) {
            throw new RuntimeException(
                'Unsafe MySQL integration table identifier rejected.'
            );
        }
        $statement = $connection->prepare(
            'SELECT id FROM ' . $quotedTable . ' WHERE public_id = :public_id'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute(['public_id' => $publicId]));

        return $this->positiveInteger($statement->fetchColumn());
    }

    private function assertExtendedFixtureRows(
        PDO $connection,
        string $webAdminPrefix,
        string $blogPrefix
    ): void {
        $this->assertSafePrefixes($webAdminPrefix, $blogPrefix);
        foreach ([
            $webAdminPrefix . 'media_assets' => 1,
            $webAdminPrefix . 'media_variants' => 1,
            $blogPrefix . 'categories' => 2,
            $blogPrefix . 'category_locales' => 2,
            $blogPrefix . 'post_categories' => 1,
            $blogPrefix . 'content_docs' => 1,
            $blogPrefix . 'content_revisions' => 1,
            $blogPrefix . 'content_media' => 1,
            $blogPrefix . 'revision_media' => 1,
        ] as $table => $expected) {
            self::assertSame(
                $expected,
                $this->tableCount($connection, $table),
                'Unexpected fixture row count for ' . $table . '.'
            );
        }
    }

    private function assertStructuredDataDriftIsDetectedAndRestored(
        PDO $connection,
        MigrationScope $scope,
        BlogStructuredContentMigrationPostconditionVerifier $verifier
    ): void {
        $documents = $scope->quotedTable('content_docs', 'mysql');
        $select = $connection->prepare(
            'SELECT document_sha256 FROM ' . $documents
            . ' WHERE public_id = :public_id'
        );
        self::assertNotFalse($select);
        self::assertTrue($select->execute([
            'public_id' => self::DOCUMENT_PUBLIC_ID,
        ]));
        $originalHash = $select->fetchColumn();
        self::assertIsString($originalHash);
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{64}\z/',
            $originalHash
        );
        $driftedHash = $originalHash === str_repeat('0', 64)
            ? str_repeat('f', 64)
            : str_repeat('0', 64);
        self::assertTrue($connection->beginTransaction());
        try {
            $update = $connection->prepare(
                'UPDATE ' . $documents . ' SET document_sha256 = :hash '
                . 'WHERE public_id = :public_id'
            );
            self::assertNotFalse($update);
            self::assertTrue($update->execute([
                'hash' => $driftedHash,
                'public_id' => self::DOCUMENT_PUBLIC_ID,
            ]));
            self::assertSame(1, $update->rowCount());
            self::assertFalse($verifier->verify($connection, $scope));
        } finally {
            if ($connection->inTransaction()) {
                self::assertTrue($connection->rollBack());
            }
        }
        self::assertTrue($select->execute([
            'public_id' => self::DOCUMENT_PUBLIC_ID,
        ]));
        self::assertSame($originalHash, $select->fetchColumn());
        self::assertTrue($verifier->verify($connection, $scope));
    }

    private function assertMigrationScopes(
        PDO $connection,
        MigrationScope $blogScope,
        MigrationScope $webAdminScope
    ): void {
        $statement = $connection->prepare(
            'SELECT module_id, migration_id, scope_hash FROM `'
            . MigrationRegistry::TABLE . '` ORDER BY module_id, migration_id'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute());
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([
            [
                'module_id' => 'blog',
                'migration_id' => '0001_blog_posts',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0002_blog_capabilities',
                'scope_hash' => $webAdminScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0003_blog_categories',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0004_blog_category_capabilities',
                'scope_hash' => $webAdminScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0005_blog_structured_content',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0006_blog_sitemap_publication_state',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0007_blog_post_tombstones',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0008_blog_article_delete_capability',
                'scope_hash' => $webAdminScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0009_blog_analytics',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0010_blog_analytics_view_capability',
                'scope_hash' => $webAdminScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0011_blog_layout_editor_v2',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0012_blog_editor_preferences',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0013_blog_settings_manage_capability',
                'scope_hash' => $webAdminScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0014_blog_private_draft_publication',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0015_blog_robots_preferences',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0016_blog_url_history',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0017_blog_dummy_category',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0018_blog_dummy_category_normalization',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0019_blog_copy_operation_idempotency',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0020_blog_tags',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0021_blog_localization_tags',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0022_blog_tag_assignment_heads',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0023_blog_tag_assignment_workspaces',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' =>
                    '0024_blog_tag_assignment_workspace_items',
                'scope_hash' => $blogScope->hash(),
            ],
            [
                'module_id' => 'blog',
                'migration_id' => '0025_blog_tag_capabilities',
                'scope_hash' => $webAdminScope->hash(),
            ],
            [
                'module_id' => 'webadmin',
                'migration_id' => '0001_webadmin_identity_and_access',
                'scope_hash' => $webAdminScope->hash(),
            ],
            [
                'module_id' => 'webadmin',
                'migration_id' => '0002_webadmin_media_library',
                'scope_hash' => $webAdminScope->hash(),
            ],
            [
                'module_id' => 'webadmin',
                'migration_id' => '0003_webadmin_media_avif_source',
                'scope_hash' => $webAdminScope->hash(),
            ],
            [
                'module_id' => 'webadmin',
                'migration_id' => '0004_webadmin_profile_preferences',
                'scope_hash' => $webAdminScope->hash(),
            ],
            [
                'module_id' => 'webadmin',
                'migration_id' => '0005_webadmin_media_quarantine',
                'scope_hash' => $webAdminScope->hash(),
            ],
        ], $rows);
    }

    private function assertBlogAudit(
        PDO $connection,
        string $webAdminPrefix,
        string $postPublicId
    ): void {
        $tables = WebAdminTableNames::fromPdo(
            $connection,
            $webAdminPrefix
        );
        $statement = $connection->prepare(
            'SELECT a.request_id, a.event_code, a.outcome, a.reason_code, '
            . 'a.target_type, a.target_public_id, a.metadata_json, '
            . 'a.ip_hash, a.user_agent_hash, a.actor_session_public_id, '
            . 'u.public_id AS actor_public_id FROM '
            . $tables->table('audit_log') . ' a INNER JOIN '
            . $tables->table('users') . ' u ON u.id = a.actor_user_id '
            . "WHERE a.event_code LIKE 'blog.article.%' ORDER BY a.id"
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute());
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertIsArray($rows);
        self::assertSame([
            'blog.article.created',
            'blog.article.locale_added',
            'blog.article.saved',
            'blog.article.published',
            'blog.article.unpublished',
            'blog.article.saved',
        ], array_column($rows, 'event_code'));
        foreach ($rows as $row) {
            self::assertMatchesRegularExpression(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                (string) $row['request_id']
            );
            self::assertSame('success', $row['outcome']);
            self::assertNull($row['reason_code']);
            self::assertSame('blog_article', $row['target_type']);
            self::assertSame($postPublicId, $row['target_public_id']);
            self::assertNull($row['metadata_json']);
            self::assertNull($row['ip_hash']);
            self::assertNull($row['user_agent_hash']);
            self::assertNull($row['actor_session_public_id']);
            self::assertSame(
                self::ACTOR_PUBLIC_ID,
                $row['actor_public_id']
            );
        }
    }

    private function draft(string $slug, string $h1): BlogDraft
    {
        return new BlogDraft(
            h1: $h1,
            bodyText: "Primer párrafo sobre Matrix.\n\nSegundo párrafo de prueba.",
            slug: $slug,
            seoTitle: $h1 . ' | LiquidStack',
            metaDescription: $h1 . ' mediante el contrato Blog de LiquidStack.',
            excerpt: $h1 . ' en una variante editorial de prueba.'
        );
    }

    /** @return array{0: string, 1: string} */
    private function ephemeralPrefixes(): array
    {
        $token = bin2hex(random_bytes(8));
        $webAdminPrefix = 'lsit_web_' . $token . '_';
        $blogPrefix = 'lsit_blog_' . $token . '_';
        $this->assertSafePrefixes($webAdminPrefix, $blogPrefix);
        self::assertNotSame(
            WebAdminConfig::DEFAULT_TABLE_PREFIX,
            $webAdminPrefix
        );
        self::assertNotSame(BlogConfig::DEFAULT_TABLE_PREFIX, $blogPrefix);

        return [$webAdminPrefix, $blogPrefix];
    }

    private function assertSafePrefixes(
        string $webAdminPrefix,
        string $blogPrefix
    ): void {
        if (
            preg_match(
                '/\Alsit_web_[a-f0-9]{16}_\z/',
                $webAdminPrefix
            ) !== 1
            || strlen($webAdminPrefix)
                > WebAdminConfig::MAX_TABLE_PREFIX_LENGTH
            || preg_match(
                '/\Alsit_blog_[a-f0-9]{16}_\z/',
                $blogPrefix
            ) !== 1
            || strlen($blogPrefix) > BlogConfig::MAX_TABLE_PREFIX_LENGTH
        ) {
            throw new RuntimeException(
                'Unsafe ephemeral MySQL integration prefixes rejected.'
            );
        }
    }

    private function connect(
        #[\SensitiveParameter] BlogMySqlTestConfiguration $configuration
    ): PDO {
        try {
            $names = WebAdminConfig::SHARED_DATABASE_ENV;
            $connection = (new SharedPdoConnectionFactory([
                $names[0] => $configuration->host()
                    . ':' . $configuration->port(),
                $names[1] => $configuration->username(),
                $names[2] => $configuration->password(),
                $names[3] => $configuration->database(),
            ]))->connect();
            self::assertSame(
                'mysql',
                $connection->getAttribute(PDO::ATTR_DRIVER_NAME)
            );

            return $connection;
        } catch (Throwable) {
            self::fail(
                'Could not connect to the isolated MySQL/MariaDB test DB.'
            );
        }
    }

    private function assertSelectedDatabase(
        PDO $connection,
        string $expectedDatabase
    ): void {
        self::assertSame(
            $expectedDatabase,
            $connection->query('SELECT DATABASE()')->fetchColumn(),
            'The connection must remain scoped to the guarded test DB.'
        );
    }

    private function acquireGuardLock(
        PDO $connection,
        string $database
    ): string {
        $lockName = 'liquidstack:test:mysql:'
            . substr(hash('sha256', $database), 0, 40);
        $statement = $connection->prepare(
            'SELECT GET_LOCK(:lock_name, 0)'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute(['lock_name' => $lockName]));
        self::assertSame(
            '1',
            (string) $statement->fetchColumn(),
            'Another integration run already owns this test DB.'
        );

        return $lockName;
    }

    private function releaseGuardLock(
        PDO $connection,
        string $lockName
    ): void {
        $statement = $connection->prepare(
            'SELECT RELEASE_LOCK(:lock_name)'
        );
        if (
            $statement === false
            || !$statement->execute(['lock_name' => $lockName])
            || (string) $statement->fetchColumn() !== '1'
        ) {
            throw new RuntimeException(
                'The MySQL integration guard lock could not be released.'
            );
        }
    }

    private function assertNamespacesAreUnused(
        PDO $connection,
        string $database,
        string $webAdminPrefix,
        string $blogPrefix
    ): void {
        $this->assertSafePrefixes($webAdminPrefix, $blogPrefix);
        $present = $this->tableNames($connection, $database);
        $collisions = array_values(array_filter(
            $present,
            static fn (string $table): bool =>
                $table === MigrationRegistry::TABLE
                || str_starts_with($table, $webAdminPrefix)
                || str_starts_with($table, $blogPrefix)
        ));
        self::assertSame(
            [],
            $collisions,
            'The test registry and ephemeral namespaces must be unused.'
        );
    }

    private function assertAllExpectedTablesExist(
        PDO $connection,
        string $database,
        string $webAdminPrefix,
        string $blogPrefix
    ): void {
        self::assertSame(
            [],
            array_values(array_diff(
                $this->expectedTables($webAdminPrefix, $blogPrefix),
                $this->tableNames($connection, $database)
            ))
        );
    }

    /** @return list<string> */
    private function expectedTables(
        string $webAdminPrefix,
        string $blogPrefix
    ): array {
        $this->assertSafePrefixes($webAdminPrefix, $blogPrefix);
        $tables = [MigrationRegistry::TABLE];
        foreach (WebAdminInitialSchemaContract::tableSuffixes() as $suffix) {
            $tables[] = $webAdminPrefix . $suffix;
        }
        foreach ([
            'media_assets',
            'media_variants',
            'media_quarantines',
            'user_profiles',
        ] as $suffix) {
            $tables[] = $webAdminPrefix . $suffix;
        }
        foreach (BlogInitialSchemaContract::tableSuffixes() as $suffix) {
            $tables[] = $blogPrefix . $suffix;
        }
        foreach ([
            'categories',
            'category_locales',
            'post_categories',
            'content_docs',
            'content_revisions',
            'content_media',
            'revision_media',
            'sitemap_state',
            'post_tombstones',
            'analytics_sessions',
            'analytics_views',
            'content_layout_docs',
            'content_layout_revisions',
            'editor_preferences',
            'editorial_workspaces',
            'category_assignment_heads',
            'category_assignment_workspaces',
            'category_assignment_workspace_items',
            'publication_heads',
            'robots_settings',
            'revision_robots',
            'url_history',
            'copy_operations',
            'tags',
            'localization_tags',
            'tag_assignment_heads',
            'tag_assignment_workspaces',
            'tag_assignment_workspace_items',
        ] as $suffix) {
            $tables[] = $blogPrefix . $suffix;
        }
        sort($tables, SORT_STRING);

        return $tables;
    }

    /** @return list<string> */
    private function tableNames(PDO $connection, string $database): array
    {
        $statement = $connection->prepare(
            'SELECT table_name FROM information_schema.tables '
            . 'WHERE table_schema = :schema ORDER BY table_name'
        );
        if ($statement === false || !$statement->execute([
            'schema' => $database,
        ])) {
            throw new RuntimeException(
                'The isolated MySQL test DB could not be inspected.'
            );
        }

        return array_values(array_map(
            'strval',
            $statement->fetchAll(PDO::FETCH_COLUMN)
        ));
    }

    private function dropOnlyOwnedTables(
        PDO $connection,
        string $database,
        string $webAdminPrefix,
        string $blogPrefix
    ): void {
        $this->assertSelectedDatabase($connection, $database);
        $expected = $this->expectedTables($webAdminPrefix, $blogPrefix);
        $present = $this->tableNames($connection, $database);
        $targets = array_values(array_intersect($expected, $present));

        if ($targets !== []) {
            if ($connection->exec(
                'SET SESSION FOREIGN_KEY_CHECKS = 0'
            ) === false) {
                throw new RuntimeException(
                    'Could not prepare isolated Blog-table cleanup.'
                );
            }
            try {
                foreach ($targets as $table) {
                    $sql = 'DROP TABLE IF EXISTS '
                        . $this->quoteIdentifier($database)
                        . '.' . $this->quoteIdentifier($table);
                    if ($connection->exec($sql) === false) {
                        throw new RuntimeException(
                            'An owned Blog integration table was not removed.'
                        );
                    }
                }
            } finally {
                if ($connection->exec(
                    'SET SESSION FOREIGN_KEY_CHECKS = 1'
                ) === false) {
                    throw new RuntimeException(
                        'Could not restore foreign-key checks after cleanup.'
                    );
                }
            }
        }

        if (array_intersect(
            $expected,
            $this->tableNames($connection, $database)
        ) !== []) {
            throw new RuntimeException(
                'Owned Blog integration tables were not fully removed.'
            );
        }
    }

    private function tableCount(PDO $connection, string $table): int
    {
        return (int) $connection->query(
            'SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table)
        )->fetchColumn();
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/\A[a-z0-9_]+\z/', $identifier) !== 1) {
            throw new RuntimeException(
                'Unsafe MySQL integration identifier rejected.'
            );
        }

        return '`' . $identifier . '`';
    }

    private function createModuleProject(): string
    {
        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-blog-mysql-project-'
            . bin2hex(random_bytes(12));
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException(
                'Could not create the temporary Blog module project.'
            );
        }
        $composerJson = json_encode([
            'require' => [
                'liquidstack/core' => '^1.9',
                'liquidstack/webadmin' => '*',
                'liquidstack/blog' => '*',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents(
            $path . DIRECTORY_SEPARATOR . 'composer.json',
            $composerJson . PHP_EOL
        ) === false) {
            rmdir($path);
            throw new RuntimeException(
                'Could not prepare the temporary Blog module project.'
            );
        }

        return $path;
    }

    private function removeModuleProject(string $path): void
    {
        $expectedParent = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($path));
        $leaf = basename($path);
        if (
            $expectedParent === false
            || $parent !== $expectedParent
            || preg_match(
                '/\Aliquidstack-blog-mysql-project-[a-f0-9]{24}\z/',
                $leaf
            ) !== 1
        ) {
            throw new RuntimeException(
                'Refusing to clean an unvalidated Blog temporary path.'
            );
        }

        $composerJson = $path . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composerJson) && !unlink($composerJson)) {
            throw new RuntimeException(
                'Could not remove the temporary Blog composer.json.'
            );
        }
        if (is_dir($path) && !rmdir($path)) {
            throw new RuntimeException(
                'Could not remove the temporary Blog module project.'
            );
        }
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        throw new RuntimeException('Invalid MySQL integration row ID.');
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }
}

final class BlogMySqlTestConfiguration
{
    private const HOST_ENV = 'LIQUIDSTACK_TEST_MYSQL_HOST';
    private const PORT_ENV = 'LIQUIDSTACK_TEST_MYSQL_PORT';
    private const DATABASE_ENV = 'LIQUIDSTACK_TEST_MYSQL_DATABASE';
    private const USERNAME_ENV = 'LIQUIDSTACK_TEST_MYSQL_USERNAME';
    private const PASSWORD_ENV = 'LIQUIDSTACK_TEST_MYSQL_PASSWORD';
    private const DATABASE_PATTERN =
        '/\Aliquidstack_core_test_[a-z0-9_]{1,32}\z/';

    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password
    ) {
    }

    public static function fromProcess(): self
    {
        $values = [];
        foreach ([
            self::HOST_ENV,
            self::PORT_ENV,
            self::DATABASE_ENV,
            self::USERNAME_ENV,
            self::PASSWORD_ENV,
        ] as $name) {
            $value = getenv($name);
            if (
                !is_string($value)
                || ($name !== self::PASSWORD_ENV && $value === '')
            ) {
                throw new RuntimeException(sprintf(
                    'Required test-only environment variable %s is missing.',
                    $name
                ));
            }
            $values[$name] = $value;
        }

        $host = $values[self::HOST_ENV];
        if (
            strlen($host) > 253
            || preg_match('/\A[a-zA-Z0-9.-]+\z/', $host) !== 1
            || str_contains($host, '..')
            || trim($host, '.-') !== $host
        ) {
            throw new RuntimeException(
                'The test-only MySQL host is invalid.'
            );
        }

        $portValue = $values[self::PORT_ENV];
        if (preg_match('/\A[0-9]{1,5}\z/', $portValue) !== 1) {
            throw new RuntimeException(
                'The test-only MySQL port is invalid.'
            );
        }
        $port = (int) $portValue;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException(
                'The test-only MySQL port is invalid.'
            );
        }

        $database = $values[self::DATABASE_ENV];
        if (
            preg_match(self::DATABASE_PATTERN, $database) !== 1
            || strlen($database) > 64
        ) {
            throw new RuntimeException(
                'The database must use the strict '
                . 'liquidstack_core_test_* test prefix.'
            );
        }

        $username = $values[self::USERNAME_ENV];
        if (strlen($username) > 128 || str_contains($username, "\0")) {
            throw new RuntimeException(
                'The test-only MySQL username is invalid.'
            );
        }

        return new self(
            $host,
            $port,
            $database,
            $username,
            $values[self::PASSWORD_ENV]
        );
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    /** @return array<string, string|int> */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => '[redacted]',
            'password' => '[redacted]',
        ];
    }
}
