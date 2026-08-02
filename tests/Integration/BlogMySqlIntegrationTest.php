<?php

declare(strict_types=1);

use App\Core\Blog\Audit\WebAdminBlogMutationAuditAdapter;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Http\BlogAdminHttpRuntime;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Database\SharedPdoConnectionFactory;
use App\Core\Modules\Blog\BlogCapabilitySeedPostcondition;
use App\Core\Modules\Blog\BlogCategoryCapabilitySeedPostcondition;
use App\Core\Modules\Blog\BlogCategoryHttpSchemaGate;
use App\Core\Modules\Blog\BlogCategoryMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogHttpSchemaGate;
use App\Core\Modules\Blog\BlogInitialSchemaContract;
use App\Core\Modules\Blog\BlogStructuredContentMigrationPostconditionVerifier;
use App\Core\Modules\Blog\BlogStructuredContentSchemaGate;
use App\Core\Modules\Migrations\MigrationCatalog;
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
            $firstRun = $runner->apply($connection, $catalog, $scopes);
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
                'webadmin:0001_webadmin_identity_and_access',
                'webadmin:0002_webadmin_media_library',
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
                (new WebAdminMediaMigrationPostconditionVerifier())->verify(
                    $connection,
                    $webAdminScope
                )
            );
            $structuredContentVerifier =
                new BlogStructuredContentMigrationPostconditionVerifier();
            self::assertTrue(
                $structuredContentVerifier->verify(
                    $connection,
                    $blogScope
                )
            );
            $categoryVerifier = new BlogCategoryMigrationPostconditionVerifier(
                expectStructuredContentExtension: true
            );
            self::assertTrue(
                $categoryVerifier->verify($connection, $blogScope)
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
            self::assertNull(
                $runtime->service()->resolvePublished('eu', 'matrix-mysql-eu')
            );

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
            $this->assertExtendedFixtureRows(
                $connection,
                $webAdminPrefix,
                $blogPrefix
            );
            self::assertTrue(
                (new WebAdminMediaMigrationPostconditionVerifier())->verify(
                    $connection,
                    $webAdminScope
                )
            );
            self::assertTrue(
                $categoryVerifier->verify($connection, $blogScope)
            );
            self::assertTrue(
                $structuredContentVerifier->verify(
                    $connection,
                    $blogScope
                )
            );
            $this->assertStructuredDataDriftIsDetectedAndRestored(
                $connection,
                $blogScope,
                $structuredContentVerifier
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
            $webAdminPrefix . 'media_assets',
            $webAdminPrefix . 'media_variants',
            $blogPrefix . 'categories',
            $blogPrefix . 'category_locales',
            $blogPrefix . 'post_categories',
            $blogPrefix . 'content_docs',
            $blogPrefix . 'content_revisions',
            $blogPrefix . 'content_media',
            $blogPrefix . 'revision_media',
        ] as $table) {
            self::assertSame(1, $this->tableCount($connection, $table));
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
                'module_id' => 'webadmin',
                'migration_id' => '0001_webadmin_identity_and_access',
                'scope_hash' => $webAdminScope->hash(),
            ],
            [
                'module_id' => 'webadmin',
                'migration_id' => '0002_webadmin_media_library',
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
        foreach (['media_assets', 'media_variants'] as $suffix) {
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
