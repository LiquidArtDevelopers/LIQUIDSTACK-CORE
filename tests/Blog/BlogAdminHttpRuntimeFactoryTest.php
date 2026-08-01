<?php

declare(strict_types=1);

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditStorageException;
use App\Core\Blog\Audit\WebAdminBlogMutationAuditAdapter;
use App\Core\Blog\Http\BlogAdminHttpRuntime;
use App\Core\Blog\Http\BlogAdminHttpRuntimeException;
use App\Core\Blog\Http\BlogAdminHttpRuntimeFactory;
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
    private PDO $pdo;
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

        $this->pdo = new PDO('sqlite::memory:');
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
        self::assertSame($this->projectRoot, $runtime->projectRoot());
        self::assertSame(['es', 'en'], $runtime->languages());
        self::assertSame('/blog', $runtime->blogConfig()->publicPath('es'));
        self::assertSame(
            WebAdminConfig::DEFAULT_BASE_PATH,
            $runtime->webAdminConfig()->basePath()
        );
        self::assertInstanceOf(BlogService::class, $runtime->service());
        self::assertInstanceOf(
            WebAdminAuthenticationService::class,
            $runtime->authentication()
        );
        self::assertInstanceOf(
            WebAdminAuthorizationService::class,
            $runtime->authorization()
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
            BlogMutationAuditEvent::PUBLISH,
            BlogMutationAuditEvent::UNPUBLISH,
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
            'blog.article.published',
            'blog.article.unpublished',
        ], array_column($rows, 'event_code'));
        self::assertSame(
            [null, null, null, null, null],
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

    private function factory(): BlogAdminHttpRuntimeFactory
    {
        return new BlogAdminHttpRuntimeFactory(
            coreRoot: dirname(__DIR__, 2),
            connectionFactoryResolver: function (): BlogAdminRuntimePdoFactory {
                ++$this->connectionCount;

                return new BlogAdminRuntimePdoFactory($this->pdo);
            },
            clock: $this->clock,
            uuidGenerator: new BlogAdminRuntimeUuidSequence([
                self::POST_PUBLIC_ID,
                self::LOCALIZATION_PUBLIC_ID,
                self::REQUEST_PUBLIC_ID,
            ])
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
            new MigrationApplyOptions(expectedPlanHash: $preview->hash())
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
