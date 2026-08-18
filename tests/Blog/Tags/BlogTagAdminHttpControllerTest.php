<?php

declare(strict_types=1);

namespace Tests\Blog\Tags;

use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Http\BlogAdminHttpRuntime;
use App\Core\Blog\Http\BlogTagAdminHttpController;
use App\Core\Blog\Http\BlogTagAdminRequestPolicy;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\Tags\BlogTagService;
use App\Core\Blog\Tags\Persistence\PdoBlogTagRepository;
use App\Core\Http\Request;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
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
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class TagAdminControllerClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-01 10:00:00 UTC');
    }
}

final class BlogTagAdminHttpControllerTest extends TestCase
{
    private const ACTOR = '10000000-0000-4000-8000-000000000001';
    private const POST = '90000000-0000-4000-8000-000000000001';

    private PDO $pdo;
    private BlogTagAdminHttpController $controller;
    private string $sessionToken;
    private string $csrfToken;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $webAdminScope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        $blogScope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $webAdminMigration = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        $this->executeMigration($webAdminMigration, $webAdminScope);
        foreach (BlogMigrationProvider::migrations() as $migration) {
            $this->executeMigration(
                $migration,
                $migration->targetScopeModuleId() === 'webadmin'
                    ? $webAdminScope
                    : $blogScope
            );
        }

        $clock = new TagAdminControllerClock();
        $config = new WebAdminConfig(
            '/admin',
            'ls_webadmin_',
            'LS_WEBADMIN_SID',
            300,
            3600,
            'test'
        );
        $securityKey = SecurityKey::fromRawBytes(str_repeat('K', 32));
        $tokens = new SecureTokenGenerator();
        $this->sessionToken = rtrim(strtr(
            base64_encode(str_repeat('S', 32)),
            '+/',
            '-_'
        ), '=');
        $this->csrfToken = $securityKey->deriveToken(
            'csrf.session',
            $this->sessionToken
        );
        $this->seedActor($tokens);
        $this->insertPost();

        $tables = WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_');
        $hasher = PasswordHasher::productive();
        $uuids = new RandomUuidV4Generator();
        $authentication = new WebAdminAuthenticationService(
            new WebAdminAuthenticationRepository($this->pdo, $tables),
            $config,
            $securityKey,
            $clock,
            $uuids,
            $hasher,
            $tokens
        );
        $authorization = new WebAdminAuthorizationService(
            $this->pdo,
            $tables,
            $clock,
            $tokens,
            $hasher
        );
        $blogService = new BlogService(
            new PdoBlogRepository($this->pdo, $blogScope),
            $uuids,
            $clock
        );
        $tagService = new BlogTagService(
            new PdoBlogTagRepository($this->pdo, $blogScope),
            $uuids,
            $clock
        );
        $runtime = new BlogAdminHttpRuntime(
            projectRoot: sys_get_temp_dir(),
            languages: ['es', 'en'],
            blogConfig: BlogConfig::defaults(['es', 'en']),
            webAdminConfig: $config,
            service: $blogService,
            authentication: $authentication,
            authorization: $authorization,
            pdo: $this->pdo,
            actorGate: new WebAdminMutationActorGate(
                $this->pdo,
                $tables,
                $config,
                $securityKey,
                $clock,
                $tokens,
                $hasher
            ),
            optionalTagService: $tagService
        );
        $this->controller = new BlogTagAdminHttpController($runtime);
    }

    public function testAsyncAssignmentReturnsCanonicalIdFreePayload(): void
    {
        $response = $this->controller->saveAssignment($this->post([
            'csrf' => $this->csrfToken,
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '1',
            'tag_workspace_version' => '0',
            'tags' => 'Fiscalidad, Ahorro',
        ], true));

        self::assertSame(200, $response->status());
        $payload = json_decode(
            $response->body(),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        self::assertSame(true, $payload['ok']);
        self::assertSame(1, $payload['lock_version']);
        self::assertSame(1, $payload['tag_workspace_version']);
        self::assertSame([
            ['name' => 'Ahorro', 'slug' => 'ahorro'],
            ['name' => 'Fiscalidad', 'slug' => 'fiscalidad'],
        ], $payload['tags']);
        self::assertArrayNotHasKey('public_id', $payload['tags'][0]);
        self::assertArrayNotHasKey('normalized_sha256', $payload['tags'][0]);
        self::assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->headers()['Cache-Control']
        );
    }

    public function testConflictKeepsSubmittedCsvInSsrRecovery(): void
    {
        $first = $this->controller->saveAssignment($this->post([
            'csrf' => $this->csrfToken,
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '1',
            'tag_workspace_version' => '0',
            'tags' => 'Ahorro',
        ], true));
        self::assertSame(200, $first->status());

        $conflict = $this->controller->saveAssignment($this->post([
            'csrf' => $this->csrfToken,
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '1',
            'tag_workspace_version' => '0',
            'tags' => 'Fiscalidad & ahorro',
        ]));
        self::assertSame(409, $conflict->status());
        self::assertStringContainsString(
            'value="Fiscalidad &amp; ahorro"',
            $conflict->body()
        );
        self::assertStringNotContainsString(
            'action="/admin/blog/tags/assign"',
            $conflict->body()
        );
        self::assertStringContainsString(
            '#blog-editor-tags-title',
            $conflict->body()
        );
    }

    public function testNativeMultibyteCsvWithinMaxlengthGets422Recovery(): void
    {
        $names = [];
        for ($index = 1; $index <= 30; $index++) {
            $names[] = str_repeat("\u{1F4BC}", 62)
                . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        }
        $csv = implode(', ', $names);
        $utf16 = mb_convert_encoding($csv, 'UTF-16LE', 'UTF-8');
        self::assertLessThanOrEqual(4096, intdiv(strlen($utf16), 2));
        self::assertGreaterThan(BlogTagService::MAX_CSV_BYTES, strlen($csv));
        self::assertLessThanOrEqual(
            BlogTagAdminRequestPolicy::MAX_TRANSPORT_CSV_BYTES,
            strlen($csv)
        );

        $response = $this->controller->saveAssignment($this->post([
            'csrf' => $this->csrfToken,
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '1',
            'tag_workspace_version' => '0',
            'tags' => $csv,
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString(
            'action="/admin/blog/tags/assign"',
            $response->body()
        );
        self::assertStringContainsString(
            'name="tags" value="' . $csv . '"',
            $response->body()
        );
        self::assertStringContainsString(
            'maxlength="' . BlogTagService::MAX_CSV_BYTES . '"',
            $response->body()
        );
        $this->assertTagStorageEmpty();
    }

    public function testCsvAboveTransportCeilingGets400WithoutWrites(): void
    {
        $csv = str_repeat("\u{1F4BC}", 4097);
        self::assertGreaterThan(
            BlogTagAdminRequestPolicy::MAX_TRANSPORT_CSV_BYTES,
            strlen($csv)
        );

        $response = $this->controller->saveAssignment($this->post([
            'csrf' => $this->csrfToken,
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '1',
            'tag_workspace_version' => '0',
            'tags' => $csv,
        ]));

        self::assertSame(400, $response->status());
        self::assertSame('Bad request', $response->body());
        self::assertStringNotContainsString($csv, $response->body());
        $this->assertTagStorageEmpty();
    }

    public function testSsrSuccessRedirectAndAuthorizationAreClosed(): void
    {
        $wrongCsrf = $this->controller->saveAssignment($this->post([
            'csrf' => str_repeat('X', 43),
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '1',
            'tag_workspace_version' => '0',
            'tags' => 'Ahorro',
        ]));
        self::assertSame(403, $wrongCsrf->status());

        $saved = $this->controller->saveAssignment($this->post([
            'csrf' => $this->csrfToken,
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '1',
            'tag_workspace_version' => '0',
            'tags' => 'Ahorro',
        ]));
        self::assertSame(303, $saved->status());
        self::assertSame(
            '/admin/blog/editor?post=' . self::POST
                . '&locale=es#blog-editor-tags-title',
            $saved->headers()['Location']
        );

        $extra = $this->controller->saveAssignment($this->post([
            'csrf' => $this->csrfToken,
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '1',
            'tag_workspace_version' => '1',
            'tags' => 'Ahorro',
            'internal_id' => '12',
        ], true));
        self::assertSame(400, $extra->status());
        self::assertSame(
            ['ok' => false, 'error' => 'bad_request'],
            json_decode($extra->body(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function testMissingEditCapabilityFailsBeforeAnyTagWrite(): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM ls_webadmin_user_capabilities WHERE capability_id = '
                . '(SELECT id FROM ls_webadmin_capabilities WHERE code = :code)'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'code' => BlogTagAdminHttpController::EDIT_CAPABILITY,
        ]));
        self::assertSame(1, $statement->rowCount());

        $response = $this->controller->saveAssignment($this->post([
            'csrf' => $this->csrfToken,
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '1',
            'tag_workspace_version' => '0',
            'tags' => 'Ahorro',
        ], true));

        self::assertSame(403, $response->status());
        self::assertSame(
            ['ok' => false, 'error' => 'forbidden'],
            json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR)
        );
        foreach ([
            'ls_blog_tags',
            'ls_blog_tag_assignment_heads',
            'ls_blog_tag_assignment_workspaces',
            'ls_blog_tag_assignment_workspace_items',
        ] as $table) {
            self::assertSame(0, (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn(), $table);
        }
    }

    public function testEditOnlyAndNoTagCapabilitiesCannotMutate(): void
    {
        foreach ([
            'edit_only' => [BlogTagAdminHttpController::VIEW_CAPABILITY],
            'neither' => [
                BlogTagAdminHttpController::VIEW_CAPABILITY,
                BlogTagAdminHttpController::EDIT_CAPABILITY,
            ],
        ] as $case => $removed) {
            $this->addCapability(BlogTagAdminHttpController::VIEW_CAPABILITY);
            $this->addCapability(BlogTagAdminHttpController::EDIT_CAPABILITY);
            foreach ($removed as $capability) {
                $this->removeCapability($capability);
            }
            $response = $this->controller->saveAssignment($this->post([
                'csrf' => $this->csrfToken,
                'post' => self::POST,
                'locale' => 'es',
                'lock_version' => '1',
                'tag_workspace_version' => '0',
                'tags' => 'Ahorro',
            ], true));

            self::assertSame(403, $response->status(), $case);
            self::assertSame(0, (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ls_blog_tags'
            )->fetchColumn(), $case);
        }
    }

    public function testProtectedAdminRolesCanMutateWithBothTagCapabilities(): void
    {
        $this->removeCapability(BlogTagAdminHttpController::VIEW_CAPABILITY);
        $this->removeCapability(BlogTagAdminHttpController::EDIT_CAPABILITY);
        $workspaceVersion = 0;
        foreach (['site_admin', 'system_superadmin'] as $index => $role) {
            self::assertNotFalse($this->pdo->exec(
                'DELETE FROM ls_webadmin_user_roles'
            ));
            $this->assignRole($role);
            $response = $this->controller->saveAssignment($this->post([
                'csrf' => $this->csrfToken,
                'post' => self::POST,
                'locale' => 'es',
                'lock_version' => '1',
                'tag_workspace_version' => (string) $workspaceVersion,
                'tags' => $index === 0 ? 'Ahorro' : 'Fiscalidad',
            ], true));

            self::assertSame(200, $response->status(), $role);
            $payload = json_decode(
                $response->body(),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            $workspaceVersion += 1;
            self::assertSame(
                $workspaceVersion,
                $payload['tag_workspace_version'],
                $role
            );
        }
    }

    private function seedActor(SecureTokenGenerator $tokens): void
    {
        $this->pdo->exec(
            "INSERT INTO ls_webadmin_users "
            . "(public_id, email_canonical, status, auth_version, activated_at) "
            . "VALUES ('" . self::ACTOR . "', 'editor@example.test', "
            . "'active', 1, '2030-01-01 09:00:00.000000')"
        );
        $userId = (int) $this->pdo->lastInsertId();
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at) VALUES (?, ?, ?)'
        );
        self::assertTrue($credential->execute([
            $userId,
            PasswordHasher::productive()->hash(
                'Correct horse battery staple 1!'
            ),
            '2030-01-01 09:00:00.000000',
        ]));
        foreach ([
            'webadmin.access',
            BlogTagAdminHttpController::VIEW_CAPABILITY,
            BlogTagAdminHttpController::EDIT_CAPABILITY,
        ] as $capability) {
            $statement = $this->pdo->prepare(
                'INSERT INTO ls_webadmin_user_capabilities '
                . '(user_id, capability_id) SELECT :user, id FROM '
                . 'ls_webadmin_capabilities WHERE code = :code'
            );
            self::assertTrue($statement->execute([
                'user' => $userId,
                'code' => $capability,
            ]));
            self::assertSame(1, $statement->rowCount(), $capability);
        }
        $session = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, created_at, last_seen_at, '
            . 'idle_expires_at, absolute_expires_at) VALUES '
            . '(:public_id, :user, :type, :token, :csrf, 1, :created, '
            . ':seen, :idle, :absolute)'
        );
        self::assertTrue($session->execute([
            'public_id' => '20000000-0000-4000-8000-000000000002',
            'user' => $userId,
            'type' => 'authenticated',
            'token' => $tokens->hashForStorage($this->sessionToken),
            'csrf' => $tokens->hashForStorage($this->csrfToken),
            'created' => '2030-01-01 09:55:00.000000',
            'seen' => '2030-01-01 09:59:00.000000',
            'idle' => '2030-01-01 10:05:00.000000',
            'absolute' => '2030-01-01 11:00:00.000000',
        ]));
    }

    private function insertPost(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_posts '
            . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        );
        self::assertTrue($statement->execute([self::POST, self::ACTOR]));
        $postId = (int) $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_post_localizations '
            . '(public_id, post_id, locale, slug, h1, body_text, '
            . 'created_by_user_public_id, updated_by_user_public_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        self::assertTrue($statement->execute([
            '91000000-0000-4000-8000-000000000001',
            $postId,
            'es',
            'matrix',
            'Matrix',
            'Contenido Matrix',
            self::ACTOR,
            self::ACTOR,
        ]));
    }

    /** @param array<string, mixed> $form */
    private function post(array $form, bool $async = false): Request
    {
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'Tag admin test browser',
        ];
        if ($async) {
            $headers += [
                'Accept' => 'application/json',
                'X-LiquidStack-Tag-Editor' => 'async',
            ];
        }

        return Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/blog/tags/assign',
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '192.0.2.55',
        ], form: $form, cookies: [
            'LS_WEBADMIN_SID' => $this->sessionToken,
        ], headers: $headers);
    }

    private function addCapability(string $capability): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO ls_webadmin_user_capabilities '
                . '(user_id, capability_id) SELECT u.id, c.id FROM '
                . 'ls_webadmin_users u CROSS JOIN ls_webadmin_capabilities c '
                . 'WHERE u.public_id = :user AND c.code = :code'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'user' => self::ACTOR,
            'code' => $capability,
        ]));
    }

    private function assertTagStorageEmpty(): void
    {
        foreach ([
            'ls_blog_tags',
            'ls_blog_tag_assignment_heads',
            'ls_blog_tag_assignment_workspaces',
            'ls_blog_tag_assignment_workspace_items',
        ] as $table) {
            self::assertSame(0, (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn(), $table);
        }
    }

    private function removeCapability(string $capability): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM ls_webadmin_user_capabilities WHERE user_id = '
                . '(SELECT id FROM ls_webadmin_users WHERE public_id = :user) '
                . 'AND capability_id = (SELECT id FROM '
                . 'ls_webadmin_capabilities WHERE code = :code)'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'user' => self::ACTOR,
            'code' => $capability,
        ]));
    }

    private function assignRole(string $role): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_user_roles (user_id, role_id, source) '
                . 'SELECT u.id, r.id, :source FROM ls_webadmin_users u '
                . 'CROSS JOIN ls_webadmin_roles r '
                . 'WHERE u.public_id = :user AND r.code = :role'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'source' => 'system',
            'user' => self::ACTOR,
            'role' => $role,
        ]));
        self::assertSame(1, $statement->rowCount(), $role);
    }

    private function executeMigration(
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($this->pdo->exec($sql));
        }
    }
}
