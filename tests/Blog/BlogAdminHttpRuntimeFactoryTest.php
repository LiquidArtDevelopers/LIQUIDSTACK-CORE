<?php

declare(strict_types=1);

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditStorageException;
use App\Core\Blog\Audit\WebAdminBlogMutationAuditAdapter;
use App\Core\Blog\Http\BlogAdminHttpRuntime;
use App\Core\Blog\Http\BlogSeoCatalogHttpRuntimeInterface;
use App\Core\Blog\Http\BlogAdminHttpRuntimeException;
use App\Core\Blog\Http\BlogAdminHttpRuntimeFactory;
use App\Core\Blog\Http\BlogAnalyticsAdminHttpRuntimeInterface;
use App\Core\Blog\Http\BlogStructuredEditorCategoryHttpRuntimeInterface;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationApplyOptions;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlogAdminRuntimePdoFactory implements
    PdoConnectionFactoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function connect(): PDO
    {
        return $this->pdo;
    }
}

final class BlogAdminRuntimeCountingPdo extends PDO
{
    /** @var list<string> */
    private array $sql = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function exec(string $statement): int|false
    {
        $this->sql[] = $statement;

        return parent::exec($statement);
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        $this->sql[] = $query;

        return parent::prepare($query, $options);
    }

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        $this->sql[] = $query;

        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function clearSqlLog(): void
    {
        $this->sql = [];
    }

    /** @return list<string> */
    public function sqlLog(): array
    {
        return $this->sql;
    }
}

final class BlogAdminHttpRuntimeFactoryTest extends TestCase
{
    private const ACTOR_PUBLIC_ID =
        '10000000-0000-4000-8000-000000000001';
    private const SESSION_PUBLIC_ID =
        '20000000-0000-4000-8000-000000000002';
    private const POST_PUBLIC_ID =
        '30000000-0000-4000-8000-000000000003';
    private const LOCALIZATION_PUBLIC_ID =
        '40000000-0000-4000-8000-000000000004';
    private const REQUEST_PUBLIC_ID =
        '50000000-0000-4000-8000-000000000005';
    private const NOW = '2030-01-01 00:10:00.000000';

    private string $projectRoot;
    private Filesystem $filesystem;
    private BlogAdminRuntimeCountingPdo $pdo;
    private string $sessionToken;
    private string $csrfToken;
    private int $actorUserId;
    private BlogAdminRuntimeTestClock $clock;
    private string $previousTraceSetting;
    private int $connectionCount = 0;

    protected function setUp(): void
    {
        $this->previousTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $this->filesystem = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . '/liquidstack-blog-admin-runtime-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir(
            $this->projectRoot . '/App/config'
        );
        $this->writeComposer(['liquidstack/blog' => '*']);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/langs.php',
            "<?php\n\nreturn ['es', 'en'];\n"
        );

        $this->pdo = new BlogAdminRuntimeCountingPdo();
        $this->pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );
        $this->pdo->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->clock = new BlogAdminRuntimeTestClock(
            new DateTimeImmutable(self::NOW . ' UTC')
        );

        $securityKey = SecurityKey::fromRawBytes(str_repeat('R', 32));
        $this->sessionToken = self::token('A');
        $this->csrfToken = $securityKey->deriveToken(
            'csrf.session',
            $this->sessionToken
        );
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        ini_set(
            'zend.exception_ignore_args',
            $this->previousTraceSetting
        );
        $this->filesystem->remove($this->projectRoot);
    }

    public function testFactoryComposesOneSharedPdoAndAtomicWebAdminAudit(): void
    {
        $this->applyMigrations();
        $this->seedAuthorizedActor();

        $runtime = $this->factory()->create(
            $this->context(),
            WebAdminConfig::defaults()
        );

        self::assertInstanceOf(BlogAdminHttpRuntime::class, $runtime);
        self::assertInstanceOf(
            BlogSeoCatalogHttpRuntimeInterface::class,
            $runtime
        );
        self::assertSame([], $runtime->seoCatalog()->scoresFor([], []));
        self::assertInstanceOf(
            BlogAnalyticsAdminHttpRuntimeInterface::class,
            $runtime
        );
        self::assertSame([], $runtime->analyticsReport()
            ->summariesForLocalizations(
                [],
                new DateTimeImmutable('2029-01-01T00:00:00Z'),
                new DateTimeImmutable('2030-01-01T00:00:00Z')
            ));
        self::assertInstanceOf(
            BlogStructuredEditorCategoryHttpRuntimeInterface::class,
            $runtime
        );
        self::assertNotNull($runtime->editorCategoryCatalog());
        self::assertSame($this->projectRoot, $runtime->projectRoot());
        self::assertSame(['es', 'en'], $runtime->languages());
        self::assertSame('/blog', $runtime->blogConfig()->publicPath('es'));
        self::assertSame(
            WebAdminConfig::DEFAULT_BASE_PATH,
            $runtime->webAdminConfig()->basePath()
        );
        self::assertInstanceOf(BlogService::class, $runtime->service());
        self::assertTrue($runtime->tagsReady());
        self::assertNotNull($runtime->tagService());
        self::assertInstanceOf(
            WebAdminAuthenticationService::class,
            $runtime->authentication()
        );
        self::assertInstanceOf(
            WebAdminAuthorizationService::class,
            $runtime->authorization()
        );
        self::assertSame(
            [
                '/blog',
                '/blog/categories',
                '/blog/settings/presentation',
                '/media',
            ],
            array_map(
                static fn ($item): string => $item->suffix(),
                $runtime->navigation()->items()
            )
        );
        self::assertSame(1, $this->connectionCount);

        $variant = $runtime->service()->createPost(
            $runtime->mutationGate(
                $this->sessionToken,
                $this->csrfToken,
                'blog.articles.edit'
            ),
            'es',
            $this->draft()
        );
        self::assertSame(self::POST_PUBLIC_ID, $variant->postPublicId());
        self::assertSame(1, $this->tableCount('ls_blog_posts'));
        self::assertSame(
            1,
            $this->tableCount('ls_blog_post_localizations')
        );

        $audit = $this->pdo->query(
            'SELECT request_id, actor_user_id, '
            . 'actor_session_public_id, event_code, outcome, reason_code, '
            . 'target_type, target_public_id, metadata_json, ip_hash, '
            . 'user_agent_hash, occurred_at FROM ls_webadmin_audit_log'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($audit);
        self::assertSame(self::REQUEST_PUBLIC_ID, $audit['request_id']);
        self::assertSame($this->actorUserId, (int) $audit['actor_user_id']);
        self::assertNull($audit['actor_session_public_id']);
        self::assertSame('blog.article.created', $audit['event_code']);
        self::assertSame('success', $audit['outcome']);
        self::assertNull($audit['reason_code']);
        self::assertSame('blog_article', $audit['target_type']);
        self::assertSame(self::POST_PUBLIC_ID, $audit['target_public_id']);
        self::assertNull($audit['metadata_json']);
        self::assertNull($audit['ip_hash']);
        self::assertNull($audit['user_agent_hash']);
        self::assertSame(self::NOW, $audit['occurred_at']);
        self::assertSame(self::NOW, $this->sessionTimes()['last_seen_at']);
    }

    public function testEditorMediaCatalogExcludesQuarantinedAssets(): void
    {
        $this->applyMigrations();
        $this->seedAuthorizedActor();

        $activePublicId = '61000000-0000-4000-8000-000000000001';
        $quarantinedPublicId = '61000000-0000-4000-8000-000000000002';
        $insertAsset = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_assets '
            . '(public_id, label, source_mime, source_width, source_height, '
            . 'source_bytes, source_sha256, created_by_user_id, created_at) '
            . "VALUES (:public_id, :label, 'image/avif', 1800, 1200, 1000, "
            . ':source_sha256, :actor, :created_at)'
        );
        $insertVariant = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_variants '
            . '(asset_id, width, height, bytes, sha256, storage_key, mime, '
            . 'created_at) VALUES (:asset_id, 480, 320, 100, :sha256, '
            . ":storage_key, 'image/avif', :created_at)"
        );
        foreach ([
            [$activePublicId, 'Portada activa', '2030-01-01 00:00:00.000000'],
            [$quarantinedPublicId, 'Portada en cuarentena', '2030-01-02 00:00:00.000000'],
        ] as $index => [$publicId, $label, $createdAt]) {
            $sourceHash = str_repeat((string) ($index + 1), 64);
            self::assertTrue($insertAsset->execute([
                'public_id' => $publicId,
                'label' => $label,
                'source_sha256' => $sourceHash,
                'actor' => $this->actorUserId,
                'created_at' => $createdAt,
            ]));
            $assetId = (int) $this->pdo->lastInsertId();
            self::assertTrue($insertVariant->execute([
                'asset_id' => $assetId,
                'sha256' => str_repeat((string) ($index + 3), 64),
                'storage_key' => substr($publicId, 0, 2) . '/'
                    . $publicId . '/480.avif',
                'created_at' => $createdAt,
            ]));
        }

        $quarantinedAssetId = (int) $this->pdo->query(
            "SELECT id FROM ls_webadmin_media_assets WHERE public_id = '"
            . $quarantinedPublicId . "'"
        )->fetchColumn();
        $requestId = '62000000-0000-4000-8000-000000000002';
        $manifest = '{}';
        $insertQuarantine = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_quarantines '
            . '(asset_id, public_id, state, asset_version, '
            . 'original_storage_prefix, quarantine_storage_prefix, '
            . 'manifest_storage_key, manifest_json, manifest_sha256, '
            . 'request_id, quarantined_by_user_id, quarantined_at) VALUES '
            . "(:asset_id, :public_id, 'quarantined', :asset_version, "
            . ':original_prefix, :quarantine_prefix, :manifest_key, '
            . ':manifest_json, :manifest_sha256, :request_id, :actor, '
            . ':quarantined_at)'
        );
        self::assertTrue($insertQuarantine->execute([
            'asset_id' => $quarantinedAssetId,
            'public_id' => $quarantinedPublicId,
            'asset_version' => str_repeat('a', 64),
            'original_prefix' => 'media/' . $quarantinedPublicId,
            'quarantine_prefix' => 'quarantine/' . $quarantinedPublicId,
            'manifest_key' => 'quarantine/' . $quarantinedPublicId
                . '/manifest.json',
            'manifest_json' => $manifest,
            'manifest_sha256' => hash('sha256', $manifest),
            'request_id' => $requestId,
            'actor' => $this->actorUserId,
            'quarantined_at' => '2030-01-02 00:10:00.000000',
        ]));

        $items = $this->factory()->create(
            $this->context(),
            WebAdminConfig::defaults()
        )->editorMediaCatalog()->recent(48);

        self::assertSame([$activePublicId], array_map(
            static fn ($asset): string => $asset->publicId(),
            $items
        ));
    }

    public function testCategoryProjectionIsOptionalAtTheLegacyBoundary(): void
    {
        $this->applyMigrations();
        $capabilityId = $this->pdo->query(
            "SELECT id FROM ls_webadmin_capabilities "
                . "WHERE code = 'blog.categories.edit'"
        )->fetchColumn();
        self::assertNotFalse($capabilityId);
        foreach ([
            'ls_webadmin_user_capabilities',
            'ls_webadmin_role_capabilities',
        ] as $table) {
            $statement = $this->pdo->prepare(
                'DELETE FROM ' . $table . ' WHERE capability_id = ?'
            );
            self::assertTrue($statement->execute([(int) $capabilityId]));
        }
        $statement = $this->pdo->prepare(
            'DELETE FROM ls_webadmin_capabilities WHERE id = ?'
        );
        self::assertTrue($statement->execute([(int) $capabilityId]));

        $runtime = $this->factory()->create(
            $this->context(),
            WebAdminConfig::defaults()
        );

        self::assertInstanceOf(BlogAdminHttpRuntime::class, $runtime);
        self::assertNull($runtime->editorCategoryCatalog());
        self::assertInstanceOf(BlogService::class, $runtime->service());
        self::assertSame(1, $this->connectionCount);
    }

    public function testAuditFailureRollsBackBlogWriteAndSessionSlide(): void
    {
        $this->applyMigrations();
        $this->seedAuthorizedActor();
        $runtime = $this->factory()->create(
            $this->context(),
            WebAdminConfig::defaults()
        );
        $before = $this->sessionTimes();
        $this->pdo->exec('DROP TABLE ls_webadmin_audit_log');

        try {
            $runtime->service()->createPost(
                $runtime->mutationGate(
                    $this->sessionToken,
                    $this->csrfToken,
                    'blog.articles.edit'
                ),
                'es',
                $this->draft()
            );
            self::fail('Audit failure must roll the aggregate back.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
        }

        self::assertSame(0, $this->tableCount('ls_blog_posts'));
        self::assertSame(
            0,
            $this->tableCount('ls_blog_post_localizations')
        );
        self::assertSame($before, $this->sessionTimes());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testMutationGateRejectsDifferentPdoAndHidesSecrets(): void
    {
        $this->applyMigrations();
        $this->seedAuthorizedActor();
        $runtime = $this->factory()->create(
            $this->context(),
            WebAdminConfig::defaults()
        );
        $gate = $runtime->mutationGate(
            $this->sessionToken,
            $this->csrfToken,
            'blog.articles.edit'
        );
        $debug = print_r($gate, true);
        self::assertStringNotContainsString($this->sessionToken, $debug);
        self::assertStringNotContainsString($this->csrfToken, $debug);
        self::assertStringNotContainsString(
            'private-editor@example.test',
            $debug
        );
        $other = new PDO('sqlite::memory:');
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->exec('PRAGMA foreign_keys = ON');

        try {
            $gate($other);
            self::fail('The actor gate must reject a different PDO.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::ACTOR_GATE_FAILED,
                $exception->issueCode()
            );
        }
        self::assertSame(0, $this->tableCount('ls_blog_posts'));
    }

    public function testAuditAdapterRejectsCallsOutsideOrAcrossTransactions(): void
    {
        $this->applyMigrations();
        $this->seedAuthorizedActor();
        $adapter = new WebAdminBlogMutationAuditAdapter(
            $this->pdo,
            WebAdminTableNames::fromPdo(
                $this->pdo,
                WebAdminConfig::DEFAULT_TABLE_PREFIX
            ),
            new BlogAdminRuntimeUuidSequence([
                self::REQUEST_PUBLIC_ID,
            ])
        );
        $event = new BlogMutationAuditEvent(
            BlogMutationAuditEvent::CREATE,
            self::ACTOR_PUBLIC_ID,
            self::POST_PUBLIC_ID,
            $this->clock->now()
        );

        try {
            $adapter->record($this->pdo, $event);
            self::fail('Audit outside a transaction must fail closed.');
        } catch (BlogMutationAuditStorageException $exception) {
            self::assertSame(
                'Blog mutation audit is unavailable.',
                $exception->getMessage()
            );
        }

        $other = new PDO('sqlite::memory:');
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->exec('PRAGMA foreign_keys = ON');
        self::assertTrue($other->beginTransaction());
        try {
            $adapter->record($other, $event);
            self::fail('Audit on a different PDO must fail closed.');
        } catch (BlogMutationAuditStorageException $exception) {
            self::assertSame(
                'Blog mutation audit is unavailable.',
                $exception->getMessage()
            );
        } finally {
            self::assertTrue($other->rollBack());
        }

        self::assertSame(0, $this->tableCount('ls_webadmin_audit_log'));
    }

    public function testAuditAdapterMapsEveryMutationWithoutContentMetadata(): void
    {
        $this->applyMigrations();
        $this->seedAuthorizedActor();
        $requests = [
            '51000000-0000-4000-8000-000000000005',
            '52000000-0000-4000-8000-000000000005',
            '53000000-0000-4000-8000-000000000005',
            '54000000-0000-4000-8000-000000000005',
            '55000000-0000-4000-8000-000000000005',
            '56000000-0000-4000-8000-000000000005',
            '57000000-0000-4000-8000-000000000005',
            '58000000-0000-4000-8000-000000000005',
            '59000000-0000-4000-8000-000000000005',
            '5a000000-0000-4000-8000-000000000005',
            '5b000000-0000-4000-8000-000000000005',
        ];
        $adapter = new WebAdminBlogMutationAuditAdapter(
            $this->pdo,
            WebAdminTableNames::fromPdo(
                $this->pdo,
                WebAdminConfig::DEFAULT_TABLE_PREFIX
            ),
            new BlogAdminRuntimeUuidSequence($requests)
        );
        $operations = [
            BlogMutationAuditEvent::CREATE,
            BlogMutationAuditEvent::ADD_LOCALE,
            BlogMutationAuditEvent::SAVE,
            BlogMutationAuditEvent::RESTORE,
            BlogMutationAuditEvent::PUBLISH,
            BlogMutationAuditEvent::UNPUBLISH,
            BlogMutationAuditEvent::DUPLICATE,
            BlogMutationAuditEvent::TRASH,
            BlogMutationAuditEvent::RESTORE_FROM_TRASH,
            BlogMutationAuditEvent::URL_GONE,
            BlogMutationAuditEvent::URL_REDIRECT,
        ];
        self::assertTrue($this->pdo->beginTransaction());
        foreach ($operations as $operation) {
            $adapter->record($this->pdo, new BlogMutationAuditEvent(
                $operation,
                self::ACTOR_PUBLIC_ID,
                self::POST_PUBLIC_ID,
                $this->clock->now()
            ));
        }
        self::assertTrue($this->pdo->commit());

        $rows = $this->pdo->query(
            'SELECT request_id, event_code, metadata_json '
            . 'FROM ls_webadmin_audit_log ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame($requests, array_column($rows, 'request_id'));
        self::assertSame([
            'blog.article.created',
            'blog.article.locale_added',
            'blog.article.saved',
            'blog.article.restored',
            'blog.article.published',
            'blog.article.unpublished',
            'blog.article.duplicated',
            'blog.article.trashed',
            'blog.article.restored_from_trash',
            'blog.article.url_gone',
            'blog.article.url_redirect',
        ], array_column($rows, 'event_code'));
        self::assertSame(
            [null, null, null, null, null, null, null, null, null, null, null],
            array_column($rows, 'metadata_json')
        );
    }

    public function testCsrfAndCapabilityDenialsNeverWriteOrSlide(): void
    {
        $this->applyMigrations();
        $this->seedAuthorizedActor();
        $before = $this->sessionTimes();
        $attempts = [
            [self::token('B'), 'blog.articles.edit'],
            [$this->csrfToken, 'blog.articles.unknown'],
        ];

        foreach ($attempts as [$csrf, $capability]) {
            $runtime = $this->factory()->create(
                $this->context(),
                WebAdminConfig::defaults()
            );
            try {
                $runtime->service()->createPost(
                    $runtime->mutationGate(
                        $this->sessionToken,
                        $csrf,
                        $capability
                    ),
                    'es',
                    $this->draft()
                );
                self::fail('The unauthorized actor must be rejected.');
            } catch (BlogException $exception) {
                self::assertSame(
                    BlogException::ACTOR_GATE_FAILED,
                    $exception->issueCode()
                );
            }
        }

        self::assertSame(0, $this->tableCount('ls_blog_posts'));
        self::assertSame($before, $this->sessionTimes());
    }

    public function testReadyFactoryPathNeverAuditsDatabaseMetadata(): void
    {
        $this->applyMigrations();
        $this->pdo->clearSqlLog();

        $this->factory()->create(
            $this->context(),
            WebAdminConfig::defaults()
        );

        $sql = strtolower(implode("\n", $this->pdo->sqlLog()));
        foreach ([
            'information_schema',
            'sqlite_master',
            'pragma table_info',
            'pragma index_list',
            'pragma index_info',
            'pragma foreign_key_list',
            'pragma foreign_key_check',
            'pragma integrity_check',
            'show create table',
            'show columns',
            'show index',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $sql);
        }
        self::assertStringContainsString('where 1 = 0', $sql);
    }

    public function testPendingSchemaFailsClosedWithStableIssue(): void
    {
        try {
            $this->factory()->create(
                $this->context(),
                WebAdminConfig::defaults()
            );
            self::fail('Pending migrations must block the runtime.');
        } catch (BlogAdminHttpRuntimeException $exception) {
            self::assertSame(
                'blog.webadmin_schema_not_ready',
                $exception->issueCode()
            );
            self::assertSame(
                'Blog admin runtime is unavailable.',
                $exception->getMessage()
            );
        }
        self::assertSame(1, $this->connectionCount);
    }

    public function testPendingTagMigrationsRemainAdditive(): void
    {
        $this->applyMigrations();
        $this->pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
                . "AND migration_id LIKE '002%'"
        );

        $runtime = $this->factory()->create(
            $this->context(),
            WebAdminConfig::defaults()
        );

        self::assertFalse($runtime->tagsReady());
        self::assertNull($runtime->tagService());
        self::assertInstanceOf(BlogService::class, $runtime->service());
    }

    public function testAppliedTagSchemaDriftFailsClosedWithStableIssue(): void
    {
        $this->applyMigrations();
        $this->pdo->exec('DROP TABLE ls_blog_localization_tags');

        try {
            $this->factory()->create(
                $this->context(),
                WebAdminConfig::defaults()
            );
            self::fail('Applied corrupt tag schema must fail closed.');
        } catch (BlogAdminHttpRuntimeException $exception) {
            self::assertSame(
                'blog.tags_schema_not_ready',
                $exception->issueCode()
            );
        }
    }

    public function testAppliedTagCapabilityDriftFailsClosedWithStableIssue(): void
    {
        $this->applyMigrations();
        $this->pdo->exec(
            'DELETE FROM ls_webadmin_role_capabilities WHERE capability_id = '
                . '(SELECT id FROM ls_webadmin_capabilities WHERE code = '
                . "'blog.tags.edit') AND role_id = (SELECT id FROM "
                . "ls_webadmin_roles WHERE code = 'site_admin')"
        );

        try {
            $this->factory()->create(
                $this->context(),
                WebAdminConfig::defaults()
            );
            self::fail('Applied corrupt tag capabilities must fail closed.');
        } catch (BlogAdminHttpRuntimeException $exception) {
            self::assertSame(
                'blog.tags_administration_not_ready',
                $exception->issueCode()
            );
        }
    }

    public function testPendingPreNormalizationCatalogBlocksAdminRuntime(): void
    {
        $this->applyMigrations();
        $this->seedAuthorizedActor();
        $this->pdo->exec(
            "DELETE FROM ls_module_migrations WHERE module_id = 'blog' "
                . "AND migration_id IN ("
                . "'0008_blog_article_delete_capability', "
                . "'0009_blog_analytics', "
                . "'0010_blog_analytics_view_capability', "
                . "'0011_blog_layout_editor_v2', "
                . "'0012_blog_editor_preferences', "
                . "'0013_blog_settings_manage_capability', "
                . "'0014_blog_private_draft_publication', "
                . "'0015_blog_robots_preferences', "
                . "'0016_blog_url_history', "
                . "'0017_blog_dummy_category', "
                . "'0018_blog_dummy_category_normalization')"
        );
        $this->expectException(BlogAdminHttpRuntimeException::class);
        $this->expectExceptionMessage('Blog admin runtime is unavailable.');
        $this->factory()->create(
            $this->context(),
            WebAdminConfig::defaults()
        );
    }

    public function testInvalidSecurityKeyFailsBeforeConnectAndNeverLeaks(): void
    {
        $context = new ModuleRuntimeContext(
            $this->projectRoot,
            [BlogAdminHttpRuntimeFactory::SECURITY_KEY_ENV =>
                'invalid-private-key-must-not-leak']
        );

        try {
            $this->factory()->create(
                $context,
                WebAdminConfig::defaults()
            );
            self::fail('Invalid security material must fail closed.');
        } catch (BlogAdminHttpRuntimeException $exception) {
            self::assertSame(
                'blog.security_key_invalid',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'invalid-private-key-must-not-leak',
                $exception->getMessage()
            );
        }
        self::assertSame(0, $this->connectionCount);
    }

    public function testFactoryPropagatesLiquidStackAsSecondResolverArgument(): void
    {
        $this->writeModuleDatabaseConfig('webadmin', 'liquidstack');
        $this->writeModuleDatabaseConfig('blog', 'liquidstack');
        $receivedConnection = null;
        $factory = new BlogAdminHttpRuntimeFactory(
            coreRoot: dirname(__DIR__, 2),
            connectionFactoryResolver: function (
                array $_environment,
                string $connection
            ) use (&$receivedConnection): BlogAdminRuntimePdoFactory {
                ++$this->connectionCount;
                $receivedConnection = $connection;

                return new BlogAdminRuntimePdoFactory($this->pdo);
            }
        );

        try {
            $factory->create(
                $this->context(),
                $this->liquidStackWebAdminConfig()
            );
            self::fail('El esquema pendiente debía bloquear Blog admin.');
        } catch (BlogAdminHttpRuntimeException $exception) {
            self::assertSame(
                'blog.webadmin_schema_not_ready',
                $exception->issueCode()
            );
        }
        self::assertSame(1, $this->connectionCount);
        self::assertSame('liquidstack', $receivedConnection);
    }

    public function testDatabaseConnectionMismatchFailsBeforeResolverAndConnector(): void
    {
        $this->writeModuleDatabaseConfig('webadmin', 'liquidstack');
        $this->writeModuleDatabaseConfig('blog', 'shared');

        try {
            $this->factory()->create(
                $this->context(),
                $this->liquidStackWebAdminConfig()
            );
            self::fail('El mismatch debía fallar antes del resolver PDO.');
        } catch (BlogAdminHttpRuntimeException $exception) {
            self::assertSame(
                'blog.database_connection_mismatch',
                $exception->issueCode()
            );
        }
        self::assertSame(0, $this->connectionCount);
    }

    public function testRegistryAndWebAdminConfigMismatchFailBeforeConnect(): void
    {
        $this->writeComposer(['liquidstack/core' => '^1.9']);
        try {
            $this->factory()->create(
                $this->context(),
                WebAdminConfig::defaults()
            );
            self::fail('A disabled Blog module must fail closed.');
        } catch (BlogAdminHttpRuntimeException $exception) {
            self::assertSame(
                'blog.module_not_enabled',
                $exception->issueCode()
            );
        }
        self::assertSame(0, $this->connectionCount);

        $this->writeComposer(['liquidstack/blog' => '*']);
        $mismatch = new WebAdminConfig(
            WebAdminConfig::DEFAULT_BASE_PATH,
            WebAdminConfig::DEFAULT_TABLE_PREFIX,
            'DIFFERENT_WEBADMIN_COOKIE',
            WebAdminConfig::DEFAULT_IDLE_TTL_SECONDS,
            WebAdminConfig::DEFAULT_ABSOLUTE_TTL_SECONDS,
            'test'
        );
        try {
            $this->factory()->create($this->context(), $mismatch);
            self::fail('A stale WebAdmin config must fail closed.');
        } catch (BlogAdminHttpRuntimeException $exception) {
            self::assertSame(
                'blog.webadmin_config_mismatch',
                $exception->issueCode()
            );
        }
        self::assertSame(0, $this->connectionCount);
    }

    public function testEffectiveWebAdminRoutePathIsAcceptedAndPreserved(): void
    {
        $this->applyMigrations();
        $effective = new WebAdminConfig(
            '/effective-admin',
            WebAdminConfig::DEFAULT_TABLE_PREFIX,
            WebAdminConfig::DEFAULT_COOKIE_NAME,
            WebAdminConfig::DEFAULT_IDLE_TTL_SECONDS,
            WebAdminConfig::DEFAULT_ABSOLUTE_TTL_SECONDS,
            'effective-route'
        );

        $runtime = $this->factory()->create($this->context(), $effective);

        self::assertSame(
            '/effective-admin',
            $runtime->webAdminConfig()->basePath()
        );
        self::assertSame(
            '/effective-admin',
            $runtime->webAdminConfig()->cookiePath()
        );
    }

    public function testExceptionTraceGuardFailsBeforeReadingSecretsOrConnecting(): void
    {
        ini_set('zend.exception_ignore_args', '0');

        try {
            $this->factory()->create(
                new ModuleRuntimeContext($this->projectRoot, [
                    BlogAdminHttpRuntimeFactory::SECURITY_KEY_ENV =>
                        'trace-secret-must-not-leak',
                ]),
                WebAdminConfig::defaults()
            );
            self::fail('Unsafe exception traces must block the runtime.');
        } catch (BlogAdminHttpRuntimeException $exception) {
            self::assertSame(
                'blog.exception_trace_guard_failed',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'trace-secret-must-not-leak',
                $exception->getMessage()
            );
        }
        self::assertSame(0, $this->connectionCount);
    }

    /** @param list<string>|null $uuidSequence */
    private function factory(?array $uuidSequence = null): BlogAdminHttpRuntimeFactory
    {
        return new BlogAdminHttpRuntimeFactory(
            coreRoot: dirname(__DIR__, 2),
            connectionFactoryResolver: function (): BlogAdminRuntimePdoFactory {
                ++$this->connectionCount;

                return new BlogAdminRuntimePdoFactory($this->pdo);
            },
            clock: $this->clock,
            uuidGenerator: new BlogAdminRuntimeUuidSequence(
                $uuidSequence ?? [
                    self::POST_PUBLIC_ID,
                    self::LOCALIZATION_PUBLIC_ID,
                    self::REQUEST_PUBLIC_ID,
                ]
            )
        );
    }

    private function context(): ModuleRuntimeContext
    {
        return new ModuleRuntimeContext(
            $this->projectRoot,
            $this->environment()
        );
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return [BlogAdminHttpRuntimeFactory::SECURITY_KEY_ENV => rtrim(
            strtr(base64_encode(str_repeat('R', 32)), '+/', '-_'),
            '='
        )];
    }

    private function liquidStackWebAdminConfig(): WebAdminConfig
    {
        return new WebAdminConfig(
            WebAdminConfig::DEFAULT_BASE_PATH,
            WebAdminConfig::DEFAULT_TABLE_PREFIX,
            WebAdminConfig::DEFAULT_COOKIE_NAME,
            WebAdminConfig::DEFAULT_IDLE_TTL_SECONDS,
            WebAdminConfig::DEFAULT_ABSOLUTE_TTL_SECONDS,
            'test',
            'liquidstack'
        );
    }

    private function writeModuleDatabaseConfig(
        string $module,
        string $connection
    ): void {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/modules/' . $module . '.php',
            "<?php\nreturn ['database' => ["
                . "'connection' => '" . $connection . "']];\n"
        );
    }

    private function applyMigrations(): void
    {
        $registry = ModuleRegistry::forProject(
            $this->projectRoot,
            dirname(__DIR__, 2)
        );
        $scopes = (new ConfiguredMigrationScopeFactory())->create(
            $registry,
            $this->projectRoot
        );
        $catalog = MigrationCatalog::fromRegistry($registry);
        $planner = new MigrationDatabasePlanner();
        $preview = $planner->plan($this->pdo, $catalog, $scopes);
        (new MigrationRunner())->apply(
            $this->pdo,
            $catalog,
            $scopes,
            new MigrationApplyOptions(
                expectedPlanHash: $preview->hash(),
                allowDestructive: true,
                backupConfirmed: true
            )
        );
    }

    private function seedAuthorizedActor(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, display_name, status, '
            . 'auth_version, activated_at) VALUES '
            . '(:public_id, :email, :display_name, :status, '
            . ':auth_version, :activated_at)'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'public_id' => self::ACTOR_PUBLIC_ID,
            'email' => 'private-editor@example.test',
            'display_name' => 'Private Editor',
            'status' => 'active',
            'auth_version' => 1,
            'activated_at' => '2030-01-01 00:00:00.000000',
        ]));
        $this->actorUserId = (int) $this->pdo->lastInsertId();

        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at) '
            . 'VALUES (:user_id, :password_hash, :password_set_at)'
        );
        self::assertNotFalse($credential);
        self::assertTrue($credential->execute([
            'user_id' => $this->actorUserId,
            'password_hash' => PasswordHasher::productive()
                ->verificationDummyHash(),
            'password_set_at' => '2030-01-01 00:00:00.000000',
        ]));

        $role = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_user_roles '
            . '(user_id, role_id, source) '
            . "SELECT :user_id, id, 'manual' FROM ls_webadmin_roles "
            . "WHERE code = 'site_admin'"
        );
        self::assertNotFalse($role);
        self::assertTrue($role->execute([
            'user_id' => $this->actorUserId,
        ]));

        $session = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at, revoked_at) VALUES '
            . '(:public_id, :user_id, :session_type, :token_hash, '
            . ':csrf_token_hash, :auth_version, NULL, :created_at, '
            . ':last_seen_at, :idle_expires_at, :absolute_expires_at, NULL)'
        );
        self::assertNotFalse($session);
        self::assertTrue($session->execute([
            'public_id' => self::SESSION_PUBLIC_ID,
            'user_id' => $this->actorUserId,
            'session_type' => 'authenticated',
            'token_hash' => hash('sha256', $this->sessionToken),
            'csrf_token_hash' => hash('sha256', $this->csrfToken),
            'auth_version' => 1,
            'created_at' => '2030-01-01 00:00:00.000000',
            'last_seen_at' => '2030-01-01 00:05:00.000000',
            'idle_expires_at' => '2030-01-01 00:20:00.000000',
            'absolute_expires_at' => '2030-01-01 01:00:00.000000',
        ]));
    }

    private function draft(): BlogDraft
    {
        return new BlogDraft(
            h1: 'Matrix runtime article',
            bodyText: "Wake up, Neo.\n\nThe Matrix has you.",
            slug: 'matrix-runtime-article',
            seoTitle: 'Matrix runtime article title',
            metaDescription: 'Matrix runtime article description.',
            excerpt: 'Matrix runtime article excerpt.'
        );
    }

    /** @return array{last_seen_at: string, idle_expires_at: string} */
    private function sessionTimes(): array
    {
        $row = $this->pdo->query(
            'SELECT last_seen_at, idle_expires_at '
            . 'FROM ls_webadmin_sessions'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [
            'last_seen_at' => (string) $row['last_seen_at'],
            'idle_expires_at' => (string) $row['idle_expires_at'],
        ];
    }

    private function tableCount(string $table): int
    {
        self::assertMatchesRegularExpression(
            '/\A[a-z][a-z0-9_]*\z/',
            $table
        );

        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM "' . $table . '"'
        )->fetchColumn();
    }

    /** @param array<string, string> $requirements */
    private function writeComposer(array $requirements): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode(
                ['require' => $requirements],
                JSON_THROW_ON_ERROR
            )
        );
    }

    private static function token(string $byte): string
    {
        return rtrim(strtr(
            base64_encode(str_repeat($byte, 32)),
            '+/',
            '-_'
        ), '=');
    }
}

final class BlogAdminRuntimeTestClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class BlogAdminRuntimeUuidSequence implements UuidGeneratorInterface
{
    /** @param list<string> $values */
    public function __construct(private array $values)
    {
    }

    public function generateV4(): string
    {
        $value = array_shift($this->values);
        if (!is_string($value)) {
            throw new RuntimeException('UUID sequence exhausted.');
        }

        return $value;
    }
}
