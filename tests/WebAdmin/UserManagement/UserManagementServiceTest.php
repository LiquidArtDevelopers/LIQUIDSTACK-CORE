<?php

declare(strict_types=1);

namespace Tests\WebAdmin\UserManagement;

use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use App\Core\WebAdmin\UserManagement\ActiveModuleSet;
use App\Core\WebAdmin\UserManagement\EditorInviteResult;
use App\Core\WebAdmin\UserManagement\EditorMutationResult;
use App\Core\WebAdmin\UserManagement\UserManagementRepository;
use App\Core\WebAdmin\UserManagement\UserManagementService;
use App\Core\WebAdmin\UserManagement\UserManagementStorageException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UserManagementServiceTest extends TestCase
{
    private const NOW = '2026-08-01 09:00:00.000000';

    private PDO $pdo;
    private UserManagementTestClock $clock;
    private UserManagementTestUuids $uuids;
    private PasswordHasher $hasher;
    private SecureTokenGenerator $tokens;
    private SecurityKey $securityKey;
    private int $actorId;
    private string $sessionToken;
    private string $csrfToken;
    private string $previousExceptionTraceSetting;
    private int $seedUuid = 1;

    protected function setUp(): void
    {
        $this->previousExceptionTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->applySchema();
        $this->clock = new UserManagementTestClock(self::NOW);
        $this->uuids = new UserManagementTestUuids();
        $this->hasher = PasswordHasher::productive();
        $this->tokens = new SecureTokenGenerator();
        $this->securityKey = SecurityKey::fromRawBytes(str_repeat('U', 32));
        [$this->actorId, $this->sessionToken, $this->csrfToken] =
            $this->seedActor('site_admin');
    }

    protected function tearDown(): void
    {
        ini_set(
            'zend.exception_ignore_args',
            $this->previousExceptionTraceSetting
        );
    }

    public function testListsDetailsAndCatalogWithSignedCursorAndActiveScope(): void
    {
        $first = $this->seedEditor('active', 'Editor Uno');
        $second = $this->seedEditor('invited', null);
        $third = $this->seedEditor('active', 'Editor Tres');
        $protected = $this->seedEditor('active', 'No visible');
        $this->assignRole($protected['id'], 'site_admin');
        $blogId = $this->seedCapability(
            'blog',
            'blog.posts.publish',
            true
        );
        $inactiveId = $this->seedCapability(
            'shop',
            'shop.catalog.manage',
            true
        );
        $this->grantCapability($this->actorId, $blogId);
        $this->grantCapability($this->actorId, $inactiveId);
        $service = $this->service(['webadmin', 'blog']);

        $page = $service->listEditors($this->sessionToken, 2);
        self::assertNotNull($page);
        self::assertCount(2, $page->editors());
        self::assertSame(
            [$first['public_id'], $second['public_id']],
            array_map(
                static fn ($editor): string => $editor->publicId(),
                $page->editors()
            )
        );
        self::assertNotNull($page->nextCursor());
        $next = $service->listEditors(
            $this->sessionToken,
            2,
            $page->nextCursor()
        );
        self::assertNotNull($next);
        self::assertSame([$third['public_id']], array_map(
            static fn ($editor): string => $editor->publicId(),
            $next->editors()
        ));
        self::assertNull($next->nextCursor());
        self::assertNull($service->listEditors(
            $this->sessionToken,
            2,
            $page->nextCursor() . 'x'
        ));

        $detail = $service->editorDetail(
            $this->sessionToken,
            $second['public_id']
        );
        self::assertNotNull($detail);
        self::assertNull($detail->displayName());
        self::assertSame('invited', $detail->status());
        self::assertSame(
            ['webadmin.access', 'webadmin.profile.manage_self'],
            $detail->effectiveCapabilities()
        );
        self::assertNull($service->editorDetail(
            $this->sessionToken,
            $protected['public_id']
        ));

        $catalog = $service->delegableCapabilities($this->sessionToken);
        self::assertNotNull($catalog);
        self::assertSame([
            'blog.posts.publish',
            'webadmin.users.view',
        ], $catalog->codes());
    }

    public function testCursorContainsOnlySignedPublicUuidAndRejectsInvalidReferences(): void
    {
        $first = $this->seedEditor('active', 'Cursor one');
        $second = $this->seedEditor('active', 'Cursor two');
        $this->seedEditor('active', 'Cursor three');
        $service = $this->service();
        $page = $service->listEditors($this->sessionToken, 2);
        self::assertNotNull($page);
        $cursor = $page->nextCursor();
        self::assertNotNull($cursor);
        self::assertSame(92, strlen($cursor));
        [$encodedPayload, $signature] = explode('.', $cursor, 2);
        $payload = base64_decode(
            strtr($encodedPayload, '-_', '+/'),
            true
        );

        self::assertSame($second['public_id'], $payload);
        self::assertNotSame((string) $second['id'], $payload);
        self::assertDoesNotMatchRegularExpression('/\A[0-9]+\z/', $payload);
        self::assertSame(
            $this->securityKey->deriveToken('cursor.editors', $payload),
            $signature
        );
        self::assertFalse(method_exists($page, 'afterId'));
        self::assertFalse(method_exists($page, 'internalId'));
        self::assertFalse(method_exists($page->editors()[0], 'id'));
        self::assertStringNotContainsString(
            'afterId',
            var_export($page, true)
        );
        $url = '/admin/users?after=' . rawurlencode($cursor);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame($cursor, $query['after'] ?? null);
        self::assertSame($second['public_id'], $this->cursorPayload($cursor));

        $ghost = 'cc000000-0000-4000-8000-000000000099';
        self::assertNull($service->listEditors(
            $this->sessionToken,
            2,
            $this->signedCursor($ghost)
        ));
        self::assertNull($service->listEditors(
            $this->sessionToken,
            2,
            'malformed'
        ));
        $last = substr($cursor, -1);
        $tampered = substr($cursor, 0, -1) . ($last === 'A' ? 'B' : 'A');
        self::assertNull($service->listEditors(
            $this->sessionToken,
            2,
            $tampered
        ));
        self::assertSame($first['public_id'], $page->editors()[0]->publicId());
    }

    public function testInvitationCreatesAtomicAggregateAndKeepsPiiOpaque(): void
    {
        $service = $this->service();
        $result = $service->inviteEditor(
            $this->sessionToken,
            $this->csrfToken,
            'Nombre Privado',
            ' New.Editor@Example.Test ',
            ['webadmin.users.view'],
            'es-es'
        );

        self::assertSame(EditorInviteResult::INVITED, $result->status());
        self::assertTrue($result->changed());
        $editor = $result->editor();
        self::assertNotNull($editor);
        self::assertSame('new.editor@example.test', $editor->emailCanonical());
        self::assertSame('Nombre Privado', $editor->displayName());
        self::assertSame(['webadmin.users.view'], $editor->directCapabilities());
        $row = $this->pdo->query(
            "SELECT u.id, u.status, u.display_name, c.password_hash, "
            . "c.password_set_at FROM ls_webadmin_users u JOIN "
            . "ls_webadmin_credentials c ON c.user_id = u.id "
            . "WHERE u.email_canonical = 'new.editor@example.test'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('invited', $row['status']);
        self::assertSame('Nombre Privado', $row['display_name']);
        self::assertNull($row['password_hash']);
        self::assertNull($row['password_set_at']);
        self::assertSame('editor', $this->roleCode((int) $row['id']));
        self::assertSame([
            'kind' => 'invite',
            'locale' => 'es-ES',
            'status' => 'pending',
            'attempts' => 0,
            'action_token_id' => null,
        ], $this->pdo->query(
            'SELECT kind, locale, status, attempts, action_token_id '
            . 'FROM ls_webadmin_outbox WHERE user_id = ' . (int) $row['id']
        )->fetch(PDO::FETCH_ASSOC));

        $audit = json_encode($this->auditRows(), JSON_THROW_ON_ERROR);
        foreach ([
            'new.editor@example.test',
            'Nombre Privado',
            $this->sessionToken,
            $this->csrfToken,
        ] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $audit);
            self::assertStringNotContainsString(
                $sensitive,
                var_export($result, true)
            );
        }
        self::assertStringContainsString('[redacted]', print_r($editor, true));
    }

    public function testInvitationAcceptsEmptyOptionalNameAndRejectsBadInputOrConflict(): void
    {
        $service = $this->service();
        $invited = $service->inviteEditor(
            $this->sessionToken,
            $this->csrfToken,
            '   ',
            'unnamed@example.test'
        );
        self::assertSame(EditorInviteResult::INVITED, $invited->status());
        self::assertNull($invited->editor()?->displayName());

        $invalid = $service->inviteEditor(
            $this->sessionToken,
            $this->csrfToken,
            "Bad\nName",
            'another@example.test'
        );
        self::assertSame(EditorInviteResult::INVALID, $invalid->status());
        self::assertSame(2, $this->tableCount('users'));

        $conflict = $service->inviteEditor(
            $this->sessionToken,
            $this->csrfToken,
            'Duplicate',
            'UNNAMED@example.test'
        );
        self::assertSame(EditorInviteResult::CONFLICT, $conflict->status());
        self::assertSame(2, $this->tableCount('users'));
    }

    public function testReplaceTouchesOnlyActorOwnedActiveDelegableSubset(): void
    {
        $target = $this->seedEditor('active', 'Scoped');
        $viewId = $this->capabilityId('webadmin.users.view');
        $actorOwnedInactive = $this->seedCapability(
            'shop',
            'shop.products.edit',
            true
        );
        $foreignActive = $this->seedCapability(
            'blog',
            'blog.posts.publish',
            true
        );
        $nonDelegable = $this->seedCapability(
            'webadmin',
            'webadmin.internal.flag',
            false
        );
        $this->grantCapability($this->actorId, $actorOwnedInactive);
        foreach ([$viewId, $actorOwnedInactive, $foreignActive, $nonDelegable] as $id) {
            $this->grantCapability($target['id'], $id);
        }
        $service = $this->service(['webadmin', 'blog']);

        $result = $service->replaceCapabilities(
            $this->sessionToken,
            $this->csrfToken,
            $target['public_id'],
            []
        );

        self::assertSame(EditorMutationResult::APPLIED, $result->status());
        self::assertSame(1, $result->affectedCapabilities());
        self::assertSame([
            'blog.posts.publish',
            'shop.products.edit',
            'webadmin.internal.flag',
        ], $this->directCapabilityCodes($target['id']));
        $unchanged = $service->replaceCapabilities(
            $this->sessionToken,
            $this->csrfToken,
            $target['public_id'],
            []
        );
        self::assertSame(EditorMutationResult::UNCHANGED, $unchanged->status());
        $denied = $service->replaceCapabilities(
            $this->sessionToken,
            $this->csrfToken,
            $target['public_id'],
            ['blog.posts.publish']
        );
        self::assertSame(EditorMutationResult::DENIED, $denied->status());
        self::assertSame([
            'blog.posts.publish',
            'shop.products.edit',
            'webadmin.internal.flag',
        ], $this->directCapabilityCodes($target['id']));
    }

    public function testUnauthorizedTargetMutationsDoNotEnumerateTargets(): void
    {
        [$limitedId, $sid, $csrf] = $this->seedActor('editor');
        self::assertGreaterThan(0, $limitedId);
        $target = $this->seedEditor('active', 'Existing');
        $service = $this->service();
        $statuses = [];
        foreach ([
            $target['public_id'],
            'f0000000-0000-4000-8000-0000000000ff',
            'not-a-uuid',
        ] as $publicId) {
            $result = $service->suspendEditor($sid, $csrf, $publicId);
            $statuses[] = [
                $result->status(),
                $result->targetPublicId(),
                $result->changed(),
            ];
        }
        self::assertSame([
            [EditorMutationResult::DENIED, null, false],
            [EditorMutationResult::DENIED, null, false],
            [EditorMutationResult::DENIED, null, false],
        ], $statuses);
        $deniedAudit = $this->pdo->query(
            "SELECT target_public_id FROM ls_webadmin_audit_log "
            . "WHERE outcome = 'denied' ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([null, null, null], $deniedAudit);
    }

    public function testSelfAndProtectedTargetsAreDenied(): void
    {
        $this->assignRole($this->actorId, 'editor');
        $protected = $this->seedEditor('active', 'Protected');
        $this->assignRole($protected['id'], 'site_admin');
        $service = $this->service();

        self::assertSame(
            EditorMutationResult::DENIED,
            $service->suspendEditor(
                $this->sessionToken,
                $this->csrfToken,
                $this->userPublicId($this->actorId)
            )->status()
        );
        self::assertSame(
            EditorMutationResult::DENIED,
            $service->suspendEditor(
                $this->sessionToken,
                $this->csrfToken,
                $protected['public_id']
            )->status()
        );
        self::assertSame('active', $this->userStatus($protected['id']));
    }

    public function testResendIsIdempotentAndRevokesAllOldActionSessions(): void
    {
        $target = $this->seedEditor('invited', 'Pending');
        $live = $this->seedAction($target['id'], 'invite');
        $used = $this->seedAction($target['id'], 'invite', true, false);
        $liveSession = $this->seedActionSession($live);
        $usedSession = $this->seedActionSession($used);
        $service = $this->service();

        $result = $service->resendInvitation(
            $this->sessionToken,
            $this->csrfToken,
            $target['public_id'],
            'eu'
        );
        self::assertSame(EditorMutationResult::APPLIED, $result->status());
        self::assertNotNull($this->actionRow($live)['revoked_at']);
        self::assertNotNull($this->sessionRow($liveSession)['revoked_at']);
        self::assertNotNull($this->sessionRow($usedSession)['revoked_at']);
        self::assertSame(1, $this->openOutboxCount($target['id']));
        self::assertSame('eu', $this->outboxLocale($target['id']));

        $again = $service->resendInvitation(
            $this->sessionToken,
            $this->csrfToken,
            $target['public_id'],
            'es'
        );
        self::assertSame(
            EditorMutationResult::ALREADY_QUEUED,
            $again->status()
        );
        self::assertSame(1, $this->openOutboxCount($target['id']));
        self::assertSame('eu', $this->outboxLocale($target['id']));
    }

    public function testSuspendRevokesAllSessionsTokensAndTerminalizesOpenOutbox(): void
    {
        $target = $this->seedEditor('active', 'Suspend');
        $authSession = $this->seedAuthenticatedSession($target['id']);
        $live = $this->seedAction($target['id'], 'password_reset');
        $terminal = $this->seedAction(
            $target['id'],
            'password_reset',
            false,
            true
        );
        $liveActionSession = $this->seedActionSession($live);
        $terminalActionSession = $this->seedActionSession($terminal);
        $pending = $this->seedOutbox($target['id'], 'password_reset', 'pending');
        $processing = $this->seedOutbox(
            $target['id'],
            'password_reset',
            'processing',
            $live
        );
        $service = $this->service();

        $result = $service->suspendEditor(
            $this->sessionToken,
            $this->csrfToken,
            $target['public_id']
        );
        self::assertSame(EditorMutationResult::APPLIED, $result->status());
        $user = $this->userRow($target['id']);
        self::assertSame('suspended', $user['status']);
        self::assertSame(2, $user['auth_version']);
        self::assertNotNull($user['suspended_at']);
        foreach ([$authSession, $liveActionSession, $terminalActionSession] as $id) {
            self::assertNotNull($this->sessionRow($id)['revoked_at']);
        }
        self::assertNotNull($this->actionRow($live)['revoked_at']);
        foreach ([$pending, $processing] as $id) {
            $outbox = $this->outboxRow($id);
            self::assertSame('failed', $outbox['status']);
            self::assertNull($outbox['locked_at']);
            self::assertNull($outbox['lock_token_hash']);
            self::assertNull($outbox['action_token_id']);
            self::assertSame(
                'outbox.recipient_unavailable',
                $outbox['last_error_code']
            );
        }
        self::assertSame(
            EditorMutationResult::UNCHANGED,
            $service->suspendEditor(
                $this->sessionToken,
                $this->csrfToken,
                $target['public_id']
            )->status()
        );
    }

    public function testResumeRestoresActivatedLegacyCredentialOrQueuesFreshInvite(): void
    {
        $activated = $this->seedEditor(
            'suspended',
            'Legacy',
            true,
            password_hash('legacy password', PASSWORD_BCRYPT, ['cost' => 10])
        );
        $neverActivated = $this->seedEditor('suspended', 'New', false);
        $service = $this->service();

        $activeResult = $service->resumeEditor(
            $this->sessionToken,
            $this->csrfToken,
            $activated['public_id']
        );
        self::assertSame(EditorMutationResult::APPLIED, $activeResult->status());
        self::assertSame('active', $this->userStatus($activated['id']));
        self::assertSame(0, $this->openOutboxCount($activated['id']));

        $inviteResult = $service->resumeEditor(
            $this->sessionToken,
            $this->csrfToken,
            $neverActivated['public_id'],
            'es'
        );
        self::assertSame(EditorMutationResult::APPLIED, $inviteResult->status());
        self::assertSame('invited', $this->userStatus($neverActivated['id']));
        self::assertSame(1, $this->openOutboxCount($neverActivated['id']));
        self::assertSame(
            EditorMutationResult::UNCHANGED,
            $service->resumeEditor(
                $this->sessionToken,
                $this->csrfToken,
                $neverActivated['public_id']
            )->status()
        );
        self::assertSame(1, $this->openOutboxCount($neverActivated['id']));
    }

    public function testRepeatedSuspendReconcilesDriftBeforeBecomingNoop(): void
    {
        $target = $this->seedEditor('suspended', 'Drift', false);
        $token = $this->seedAction($target['id'], 'invite');
        $session = $this->seedActionSession($token);
        $outbox = $this->seedOutbox(
            $target['id'],
            'invite',
            'processing',
            $token
        );
        $service = $this->service();

        self::assertSame(
            EditorMutationResult::APPLIED,
            $service->suspendEditor(
                $this->sessionToken,
                $this->csrfToken,
                $target['public_id']
            )->status()
        );
        self::assertNotNull($this->actionRow($token)['revoked_at']);
        self::assertNotNull($this->sessionRow($session)['revoked_at']);
        self::assertSame('failed', $this->outboxRow($outbox)['status']);
        self::assertSame(
            EditorMutationResult::UNCHANGED,
            $service->suspendEditor(
                $this->sessionToken,
                $this->csrfToken,
                $target['public_id']
            )->status()
        );
    }

    public function testRepeatedSuspendRepairsSuspensionTimestampBeforeResume(): void
    {
        $target = $this->seedEditor('suspended', 'Timestamp drift', false);
        $this->pdo->exec(
            'UPDATE ls_webadmin_users SET suspended_at = NULL WHERE id = '
            . $target['id']
        );
        $service = $this->service();

        $reconciled = $service->suspendEditor(
            $this->sessionToken,
            $this->csrfToken,
            $target['public_id']
        );
        self::assertSame(EditorMutationResult::APPLIED, $reconciled->status());
        $user = $this->userRow($target['id']);
        self::assertNotNull($user['suspended_at']);
        self::assertSame(2, $user['auth_version']);
        self::assertSame(
            EditorMutationResult::APPLIED,
            $service->resumeEditor(
                $this->sessionToken,
                $this->csrfToken,
                $target['public_id']
            )->status()
        );
        self::assertSame('invited', $this->userStatus($target['id']));
    }

    public function testInvalidCsrfAndStaleActorVersionFailClosedWithoutWrites(): void
    {
        $target = $this->seedEditor('active', 'Safe');
        $service = $this->service();
        $badCsrf = $this->tokens->generate();

        self::assertSame(
            EditorMutationResult::DENIED,
            $service->suspendEditor(
                $this->sessionToken,
                $badCsrf,
                $target['public_id']
            )->status()
        );
        self::assertSame('active', $this->userStatus($target['id']));
        self::assertSame(0, $this->tableCount('audit_log'));

        $this->pdo->exec(
            'UPDATE ls_webadmin_users SET auth_version = auth_version + 1 '
            . 'WHERE id = ' . $this->actorId
        );
        self::assertSame(
            EditorMutationResult::DENIED,
            $service->suspendEditor(
                $this->sessionToken,
                $this->csrfToken,
                $target['public_id']
            )->status()
        );
        self::assertSame('active', $this->userStatus($target['id']));
        self::assertSame(0, $this->tableCount('audit_log'));
    }

    public function testActorLifecycleDriftFailsClosed(): void
    {
        $target = $this->seedEditor('active', 'Safe');
        $this->pdo->exec(
            "UPDATE ls_webadmin_users SET suspended_at = '" . self::NOW
            . "' WHERE id = " . $this->actorId
        );
        $service = $this->service();

        self::assertNull($service->listEditors($this->sessionToken));
        self::assertSame(
            EditorMutationResult::DENIED,
            $service->suspendEditor(
                $this->sessionToken,
                $this->csrfToken,
                $target['public_id']
            )->status()
        );
        self::assertSame('active', $this->userStatus($target['id']));
        self::assertSame(0, $this->tableCount('audit_log'));
    }

    public function testResumeRejectsCorruptActivationTimestamp(): void
    {
        $target = $this->seedEditor('suspended', 'Corrupt', true);
        $this->pdo->exec(
            "UPDATE ls_webadmin_users SET activated_at = 'invalid' WHERE id = "
            . $target['id']
        );
        $service = $this->service();

        $result = $service->resumeEditor(
            $this->sessionToken,
            $this->csrfToken,
            $target['public_id']
        );
        self::assertSame(
            EditorMutationResult::STATE_CONFLICT,
            $result->status()
        );
        self::assertSame('suspended', $this->userStatus($target['id']));
        self::assertSame(0, $this->openOutboxCount($target['id']));
    }

    public function testSuspendContainsTargetLifecycleDrift(): void
    {
        $target = $this->seedEditor('active', 'Contain drift');
        $session = $this->seedAuthenticatedSession($target['id']);
        $token = $this->seedAction($target['id'], 'password_reset');
        $actionSession = $this->seedActionSession($token);
        $outbox = $this->seedOutbox(
            $target['id'],
            'password_reset',
            'processing',
            $token
        );
        $this->pdo->exec(
            "UPDATE ls_webadmin_users SET activated_at = 'invalid' WHERE id = "
            . $target['id']
        );
        $service = $this->service();

        $result = $service->suspendEditor(
            $this->sessionToken,
            $this->csrfToken,
            $target['public_id']
        );
        self::assertSame(EditorMutationResult::APPLIED, $result->status());
        self::assertSame('suspended', $this->userStatus($target['id']));
        self::assertNotNull($this->sessionRow($session)['revoked_at']);
        self::assertNotNull($this->sessionRow($actionSession)['revoked_at']);
        self::assertNotNull($this->actionRow($token)['revoked_at']);
        self::assertSame('failed', $this->outboxRow($outbox)['status']);
        self::assertSame(2, $this->userRow($target['id'])['auth_version']);
    }

    public function testAuditFailureRollsBackWholeInvitation(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER fail_editor_audit BEFORE INSERT ON "
            . "ls_webadmin_audit_log WHEN NEW.event_code = "
            . "'webadmin.editor.invited' BEGIN SELECT RAISE(ABORT, "
            . "'forced audit failure'); END"
        );
        $service = $this->service();
        try {
            $service->inviteEditor(
                $this->sessionToken,
                $this->csrfToken,
                'Rollback',
                'rollback@example.test'
            );
            self::fail('Expected a generic storage failure.');
        } catch (UserManagementStorageException $exception) {
            self::assertSame(
                'WebAdmin user management is unavailable.',
                $exception->getMessage()
            );
        }

        self::assertSame(1, $this->tableCount('users'));
        self::assertSame(1, $this->tableCount('credentials'));
        self::assertSame(1, $this->tableCount('user_roles'));
        self::assertSame(0, $this->tableCount('outbox'));
        self::assertSame(0, $this->tableCount('audit_log'));
    }

    public function testMissingCredentialAndNonDelegableExtraRoleFailClosed(): void
    {
        $missingCredential = $this->seedEditor('invited', 'Drift');
        $this->pdo->exec(
            'DELETE FROM ls_webadmin_credentials WHERE user_id = '
            . $missingCredential['id']
        );
        $otherRole = $this->seedEditor('active', 'Role drift');
        $this->pdo->exec(
            "INSERT INTO ls_webadmin_roles (code, label_key, is_protected, "
            . "is_delegable) VALUES ('operator', 'operator', 0, 0)"
        );
        $roleId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            'INSERT INTO ls_webadmin_user_roles '
            . '(user_id, role_id, source) VALUES (' . $otherRole['id']
            . ', ' . $roleId . ", 'manual')"
        );
        $service = $this->service();

        self::assertSame(
            EditorMutationResult::STATE_CONFLICT,
            $service->resendInvitation(
                $this->sessionToken,
                $this->csrfToken,
                $missingCredential['public_id']
            )->status()
        );
        self::assertSame(
            EditorMutationResult::DENIED,
            $service->suspendEditor(
                $this->sessionToken,
                $this->csrfToken,
                $otherRole['public_id']
            )->status()
        );
        self::assertSame('active', $this->userStatus($otherRole['id']));
        $listed = $service->listEditors($this->sessionToken);
        self::assertNotNull($listed);
        self::assertNotContains(
            $otherRole['public_id'],
            array_map(
                static fn ($editor): string => $editor->publicId(),
                $listed->editors()
            )
        );
    }

    public function testRepositoryRetriesMySqlDuplicateOnceForEmailRecheck(): void
    {
        $pdo = new UserManagementRetryPdo();
        $repository = new UserManagementRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        );
        $attempts = 0;
        $result = $repository->transaction(function () use (&$attempts): string {
            ++$attempts;
            if ($attempts === 1) {
                $exception = new PDOException('duplicate', 23000);
                $exception->errorInfo = ['23000', 1062, 'duplicate'];
                throw $exception;
            }

            return 'conflict_rechecked';
        });

        self::assertSame('conflict_rechecked', $result);
        self::assertSame(2, $attempts);
        self::assertSame(2, $pdo->beginCalls);
        self::assertSame(1, $pdo->rollbackCalls);
        self::assertSame(1, $pdo->commitCalls);
    }

    /** @param list<string> $activeModules */
    private function service(array $activeModules = ['webadmin']): UserManagementService
    {
        return new UserManagementService(
            new UserManagementRepository(
                $this->pdo,
                WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_')
            ),
            new ActiveModuleSet($activeModules),
            WebAdminConfig::defaults(),
            $this->securityKey,
            $this->clock,
            $this->uuids,
            $this->hasher,
            $this->tokens
        );
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function seedActor(string $role): array
    {
        $id = $this->insertUser('active', 'Admin', true);
        $this->assignRole($id, $role);
        $session = $this->tokens->generate();
        $csrf = $this->securityKey->deriveToken('csrf.session', $session);
        $this->insertSession(
            $id,
            'authenticated',
            $session,
            hash('sha256', $csrf),
            1,
            null
        );

        return [$id, $session, $csrf];
    }

    /** @return array{id: int, public_id: string} */
    private function seedEditor(
        string $status,
        ?string $displayName,
        ?bool $activated = null,
        ?string $passwordHash = null
    ): array {
        $isActivated = $activated ?? $status === 'active';
        $id = $this->insertUser(
            $status,
            $displayName,
            $isActivated,
            $passwordHash
        );
        $this->assignRole($id, 'editor');

        return ['id' => $id, 'public_id' => $this->userPublicId($id)];
    }

    private function insertUser(
        string $status,
        ?string $displayName,
        bool $activated,
        ?string $passwordHash = null
    ): int {
        $publicId = $this->nextSeedUuid();
        $email = 'user' . $this->seedUuid . '@example.test';
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, display_name, status, '
            . 'auth_version, invited_at, activated_at, suspended_at, '
            . 'created_at, updated_at) VALUES (:public_id, :email, '
            . ':display_name, :status, 1, :invited_at, :activated_at, '
            . ':suspended_at, :created_at, :updated_at)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'email' => $email,
            'display_name' => $displayName,
            'status' => $status,
            'invited_at' => self::NOW,
            'activated_at' => $activated ? self::NOW : null,
            'suspended_at' => $status === 'suspended' ? self::NOW : null,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at, created_at, '
            . 'updated_at) VALUES (:user_id, :password_hash, '
            . ':password_set_at, :created_at, :updated_at)'
        );
        $hash = $activated
            ? ($passwordHash ?? $this->hasher->verificationDummyHash())
            : null;
        $credential->execute([
            'user_id' => $id,
            'password_hash' => $hash,
            'password_set_at' => $activated ? self::NOW : null,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return $id;
    }

    private function assignRole(int $userId, string $code): void
    {
        $roleId = (int) $this->pdo->query(
            "SELECT id FROM ls_webadmin_roles WHERE code = "
            . $this->pdo->quote($code)
        )->fetchColumn();
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO ls_webadmin_user_roles '
            . '(user_id, role_id, source) VALUES (:user_id, :role_id, '
            . "'manual')"
        );
        $statement->execute(['user_id' => $userId, 'role_id' => $roleId]);
    }

    private function roleCode(int $userId): string
    {
        return (string) $this->pdo->query(
            'SELECT r.code FROM ls_webadmin_user_roles ur JOIN '
            . 'ls_webadmin_roles r ON r.id = ur.role_id WHERE ur.user_id = '
            . $userId . ' ORDER BY r.code LIMIT 1'
        )->fetchColumn();
    }

    private function seedCapability(
        string $module,
        string $code,
        bool $delegable
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_capabilities '
            . '(module_id, code, label_key, is_delegable) VALUES '
            . '(:module, :code, :label, :delegable)'
        );
        $statement->execute([
            'module' => $module,
            'code' => $code,
            'label' => $code . '.label',
            'delegable' => $delegable ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function capabilityId(string $code): int
    {
        return (int) $this->pdo->query(
            'SELECT id FROM ls_webadmin_capabilities WHERE code = '
            . $this->pdo->quote($code)
        )->fetchColumn();
    }

    private function grantCapability(int $userId, int $capabilityId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO ls_webadmin_user_capabilities '
            . '(user_id, capability_id, assigned_by_user_id, created_at) '
            . 'VALUES (:user_id, :capability_id, :actor_id, :created_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'capability_id' => $capabilityId,
            'actor_id' => $this->actorId,
            'created_at' => self::NOW,
        ]);
    }

    /** @return list<string> */
    private function directCapabilityCodes(int $userId): array
    {
        return array_values($this->pdo->query(
            'SELECT c.code FROM ls_webadmin_user_capabilities uc JOIN '
            . 'ls_webadmin_capabilities c ON c.id = uc.capability_id '
            . 'WHERE uc.user_id = ' . $userId . ' ORDER BY c.code'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    private function seedAuthenticatedSession(int $userId): int
    {
        $token = $this->tokens->generate();
        $csrf = $this->securityKey->deriveToken('csrf.session', $token);

        return $this->insertSession(
            $userId,
            'authenticated',
            $token,
            hash('sha256', $csrf),
            1,
            null
        );
    }

    private function seedAction(
        int $userId,
        string $purpose,
        bool $used = false,
        bool $revoked = false
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_action_tokens '
            . '(user_id, purpose, token_hash, auth_version, created_at, '
            . 'expires_at, delivered_at, used_at, revoked_at) VALUES '
            . '(:user_id, :purpose, :hash, 1, :created_at, :expires_at, '
            . ':delivered_at, :used_at, :revoked_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'hash' => hash('sha256', $this->tokens->generate()),
            'created_at' => self::NOW,
            'expires_at' => '2026-08-04 09:00:00.000000',
            'delivered_at' => self::NOW,
            'used_at' => $used ? self::NOW : null,
            'revoked_at' => $revoked ? self::NOW : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedActionSession(int $actionId): int
    {
        return $this->insertSession(
            null,
            'preauth',
            $this->tokens->generate(),
            hash('sha256', $this->tokens->generate()),
            null,
            $actionId
        );
    }

    private function insertSession(
        ?int $userId,
        string $type,
        string $token,
        string $csrfHash,
        ?int $authVersion,
        ?int $actionId
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at, revoked_at) VALUES '
            . '(:public_id, :user_id, :type, :token_hash, :csrf_hash, '
            . ':auth_version, :action_id, :created_at, :last_seen, '
            . ':idle, :absolute, NULL)'
        );
        $statement->execute([
            'public_id' => $this->nextSeedUuid(),
            'user_id' => $userId,
            'type' => $type,
            'token_hash' => hash('sha256', $token),
            'csrf_hash' => $csrfHash,
            'auth_version' => $authVersion,
            'action_id' => $actionId,
            'created_at' => self::NOW,
            'last_seen' => self::NOW,
            'idle' => '2026-08-01 09:30:00.000000',
            'absolute' => '2026-08-01 17:00:00.000000',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedOutbox(
        int $userId,
        string $kind,
        string $status,
        ?int $actionId = null
    ): int {
        $processing = $status === 'processing';
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_outbox '
            . '(kind, user_id, locale, status, attempts, available_at, '
            . 'locked_at, lock_token_hash, action_token_id, '
            . 'last_error_code, created_at, sent_at) VALUES '
            . '(:kind, :user_id, :locale, :status, :attempts, '
            . ':available_at, :locked_at, :lock_hash, :action_id, NULL, '
            . ':created_at, NULL)'
        );
        $statement->execute([
            'kind' => $kind,
            'user_id' => $userId,
            'locale' => 'und',
            'status' => $status,
            'attempts' => $processing ? 1 : 0,
            'available_at' => self::NOW,
            'locked_at' => $processing ? self::NOW : null,
            'lock_hash' => $processing ? str_repeat('a', 64) : null,
            'action_id' => $actionId,
            'created_at' => self::NOW,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function userRow(int $id): array
    {
        $row = $this->pdo->query(
            'SELECT * FROM ls_webadmin_users WHERE id = ' . $id
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function userPublicId(int $id): string
    {
        return (string) $this->userRow($id)['public_id'];
    }

    private function userStatus(int $id): string
    {
        return (string) $this->userRow($id)['status'];
    }

    /** @return array<string, mixed> */
    private function actionRow(int $id): array
    {
        $row = $this->pdo->query(
            'SELECT * FROM ls_webadmin_action_tokens WHERE id = ' . $id
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function sessionRow(int $id): array
    {
        $row = $this->pdo->query(
            'SELECT * FROM ls_webadmin_sessions WHERE id = ' . $id
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function outboxRow(int $id): array
    {
        $row = $this->pdo->query(
            'SELECT * FROM ls_webadmin_outbox WHERE id = ' . $id
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function openOutboxCount(int $userId): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_outbox WHERE user_id = "
            . $userId . " AND status IN ('pending', 'processing')"
        )->fetchColumn();
    }

    private function outboxLocale(int $userId): string
    {
        return (string) $this->pdo->query(
            'SELECT locale FROM ls_webadmin_outbox WHERE user_id = '
            . $userId . " AND status IN ('pending', 'processing')"
        )->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(): array
    {
        return $this->pdo->query(
            'SELECT * FROM ls_webadmin_audit_log ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function tableCount(string $suffix): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM "ls_webadmin_' . $suffix . '"'
        )->fetchColumn();
    }

    private function nextSeedUuid(): string
    {
        return sprintf(
            'aa000000-0000-4000-8000-%012x',
            $this->seedUuid++
        );
    }

    private function signedCursor(string $publicId): string
    {
        $payload = rtrim(strtr(
            base64_encode($publicId),
            '+/',
            '-_'
        ), '=');

        return $payload . '.' . $this->securityKey->deriveToken(
            'cursor.editors',
            $publicId
        );
    }

    private function cursorPayload(string $cursor): string
    {
        $encoded = explode('.', $cursor, 2)[0];
        $padding = (4 - (strlen($encoded) % 4)) % 4;
        $decoded = base64_decode(
            strtr($encoded, '-_', '+/') . str_repeat('=', $padding),
            true
        );
        self::assertIsString($decoded);

        return $decoded;
    }

    private function applySchema(): void
    {
        $migration = null;
        foreach (WebAdminMigrationProvider::migrations() as $candidate) {
            if ($candidate->id() === '0001_webadmin_identity_and_access') {
                $migration = $candidate;
                break;
            }
        }
        self::assertNotNull($migration);
        $scope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($this->pdo->exec($sql));
        }
    }
}

final class UserManagementTestClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $timestamp)
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $timestamp,
            new DateTimeZone('UTC')
        );
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('Invalid test clock.');
        }
        $this->now = $parsed;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class UserManagementTestUuids implements UuidGeneratorInterface
{
    private int $counter = 1;

    public function generateV4(): string
    {
        return sprintf(
            'bb000000-0000-4000-8000-%012x',
            $this->counter++
        );
    }
}

final class UserManagementRetryPdo extends PDO
{
    public int $beginCalls = 0;
    public int $rollbackCalls = 0;
    public int $commitCalls = 0;
    private bool $active = false;

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            default => null,
        };
    }

    public function beginTransaction(): bool
    {
        ++$this->beginCalls;
        $this->active = true;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function commit(): bool
    {
        ++$this->commitCalls;
        $this->active = false;

        return true;
    }

    public function rollBack(): bool
    {
        ++$this->rollbackCalls;
        $this->active = false;

        return true;
    }
}
