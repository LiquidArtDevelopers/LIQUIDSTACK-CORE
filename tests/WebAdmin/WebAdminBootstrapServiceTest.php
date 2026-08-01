<?php

declare(strict_types=1);

namespace Tests\WebAdmin;

use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Bootstrap\BootstrapException;
use App\Core\WebAdmin\Bootstrap\BootstrapInvitationResendResult;
use App\Core\WebAdmin\Bootstrap\BootstrapResult;
use App\Core\WebAdmin\Bootstrap\PdoBootstrapRepository;
use App\Core\WebAdmin\Bootstrap\WebAdminBootstrapService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebAdminBootstrapServiceTest extends TestCase
{
    private const REQUEST_UUID = '10000000-0000-4000-8000-000000000001';
    private const SYSTEM_UUID = '20000000-0000-4000-8000-000000000002';
    private const SITE_UUID = '30000000-0000-4000-8000-000000000003';
    private const OTHER_UUID = '40000000-0000-4000-8000-000000000004';
    private const UTC_TIMESTAMP = '2026-08-01 01:04:05.123456';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporaryFiles = [];
    }

    public function testCreatesExactlyTwoInvitedAccountsAndAuditsWithoutSecrets(): void
    {
        $pdo = $this->sqliteWithSchema();
        $result = $this->service($pdo)->bootstrap($this->environment(
            'SYSTEM@Example.Test',
            ' client@example.test '
        ));

        self::assertSame(BootstrapResult::COMPLETED, $result->status());
        self::assertTrue($result->changed());
        self::assertSame([
            'status' => 'completed',
            'changed' => true,
            'created_accounts' => 2,
            'reconciled_accounts' => 0,
            'queued_invites' => 2,
        ], $result->toSafeArray());

        self::assertSame([
            [
                'public_id' => self::SITE_UUID,
                'email_canonical' => 'client@example.test',
                'status' => 'invited',
                'auth_version' => 1,
                'invited_at' => self::UTC_TIMESTAMP,
                'created_at' => self::UTC_TIMESTAMP,
                'updated_at' => self::UTC_TIMESTAMP,
            ],
            [
                'public_id' => self::SYSTEM_UUID,
                'email_canonical' => 'system@example.test',
                'status' => 'invited',
                'auth_version' => 1,
                'invited_at' => self::UTC_TIMESTAMP,
                'created_at' => self::UTC_TIMESTAMP,
                'updated_at' => self::UTC_TIMESTAMP,
            ],
        ], $pdo->query(
            'SELECT public_id, email_canonical, status, auth_version, '
            . 'invited_at, created_at, updated_at FROM ls_webadmin_users '
            . 'ORDER BY email_canonical'
        )->fetchAll(PDO::FETCH_ASSOC));

        self::assertSame([
            [
                'email_canonical' => 'client@example.test',
                'password_hash' => null,
                'password_set_at' => null,
                'role_code' => 'site_admin',
                'source' => 'bootstrap',
            ],
            [
                'email_canonical' => 'system@example.test',
                'password_hash' => null,
                'password_set_at' => null,
                'role_code' => 'system_superadmin',
                'source' => 'bootstrap',
            ],
        ], $pdo->query(
            'SELECT u.email_canonical, cr.password_hash, cr.password_set_at, '
            . 'r.code AS role_code, ur.source '
            . 'FROM ls_webadmin_users u '
            . 'JOIN ls_webadmin_credentials cr ON cr.user_id = u.id '
            . 'JOIN ls_webadmin_user_roles ur ON ur.user_id = u.id '
            . 'JOIN ls_webadmin_roles r ON r.id = ur.role_id '
            . "WHERE r.code IN ('system_superadmin', 'site_admin') "
            . 'ORDER BY u.email_canonical'
        )->fetchAll(PDO::FETCH_ASSOC));

        self::assertSame([
            'client@example.test' => 7,
            'system@example.test' => 8,
        ], array_map('intval', $pdo->query(
            'SELECT u.email_canonical, COUNT(rc.capability_id) AS total '
            . 'FROM ls_webadmin_users u '
            . 'JOIN ls_webadmin_user_roles ur ON ur.user_id = u.id '
            . 'JOIN ls_webadmin_role_capabilities rc ON rc.role_id = ur.role_id '
            . 'GROUP BY u.email_canonical ORDER BY u.email_canonical'
        )->fetchAll(PDO::FETCH_KEY_PAIR)));

        $outbox = $pdo->query(
            'SELECT kind, locale, status, attempts, locked_at, '
            . 'lock_token_hash, action_token_id, last_error_code, sent_at '
            . 'FROM ls_webadmin_outbox ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $outbox);
        foreach ($outbox as $row) {
            self::assertSame('invite', $row['kind']);
            self::assertSame('und', $row['locale']);
            self::assertSame('pending', $row['status']);
            self::assertSame(0, $row['attempts']);
            self::assertNull($row['locked_at']);
            self::assertNull($row['lock_token_hash']);
            self::assertNull($row['action_token_id']);
            self::assertNull($row['last_error_code']);
            self::assertNull($row['sent_at']);
        }
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_action_tokens'
        )->fetchColumn());

        $auditRows = $pdo->query(
            'SELECT request_id, actor_user_id, actor_session_public_id, '
            . 'event_code, outcome, target_type, target_public_id, '
            . 'metadata_json, ip_hash, user_agent_hash, occurred_at '
            . 'FROM ls_webadmin_audit_log ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(7, $auditRows);
        self::assertSame([
            'webadmin.bootstrap.completed' => 1,
            'webadmin.bootstrap.identity_created' => 2,
            'webadmin.bootstrap.invite_queued' => 2,
            'webadmin.bootstrap.role_assigned' => 2,
        ], $this->eventCounts($auditRows));
        foreach ($auditRows as $row) {
            self::assertSame(self::REQUEST_UUID, $row['request_id']);
            self::assertNull($row['actor_user_id']);
            self::assertNull($row['actor_session_public_id']);
            self::assertSame('success', $row['outcome']);
            self::assertNull($row['ip_hash']);
            self::assertNull($row['user_agent_hash']);
            self::assertSame(self::UTC_TIMESTAMP, $row['occurred_at']);
        }

        $serializedAudit = json_encode($auditRows, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('system@example.test', $serializedAudit);
        self::assertStringNotContainsString('client@example.test', $serializedAudit);
        self::assertStringNotContainsString('token', strtolower($serializedAudit));
        self::assertSame('completed', $pdo->query(
            "SELECT value_text FROM ls_webadmin_state WHERE state_key = 'bootstrap.initial_accounts'"
        )->fetchColumn());
        self::assertSame(self::UTC_TIMESTAMP, $pdo->query(
            "SELECT updated_at FROM ls_webadmin_state WHERE state_key = 'bootstrap.initial_accounts'"
        )->fetchColumn());
    }

    public function testFreshBlogCapabilitiesSurviveBootstrapAndReachProtectedAccounts(): void
    {
        $pdo = $this->sqliteWithSchema();
        $this->applyBlogCapabilities($pdo);

        $result = $this->service($pdo)->bootstrap($this->environment());

        self::assertSame(BootstrapResult::COMPLETED, $result->status());
        self::assertSame([
            'site@example.test' => 10,
            'system@example.test' => 11,
        ], array_map('intval', $pdo->query(
            'SELECT u.email_canonical, COUNT(rc.capability_id) AS total '
            . 'FROM ls_webadmin_users u '
            . 'JOIN ls_webadmin_user_roles ur ON ur.user_id = u.id '
            . 'JOIN ls_webadmin_role_capabilities rc ON rc.role_id = ur.role_id '
            . 'GROUP BY u.email_canonical ORDER BY u.email_canonical'
        )->fetchAll(PDO::FETCH_KEY_PAIR)));
        self::assertSame([
            'blog.articles.edit',
            'blog.articles.publish',
            'blog.articles.view',
        ], $pdo->query(
            "SELECT DISTINCT c.code FROM ls_webadmin_users u "
            . "JOIN ls_webadmin_user_roles ur ON ur.user_id = u.id "
            . "JOIN ls_webadmin_role_capabilities rc ON rc.role_id = ur.role_id "
            . "JOIN ls_webadmin_capabilities c ON c.id = rc.capability_id "
            . "WHERE u.email_canonical = 'site@example.test' "
            . "AND c.module_id = 'blog' ORDER BY c.code"
        )->fetchAll(PDO::FETCH_COLUMN));

        $second = $this->service(
            $pdo,
            [self::REQUEST_UUID]
        )->bootstrap($this->environment());
        self::assertFalse($second->changed());
    }

    public function testAddingBlogAfterCompletedBootstrapGrantsProtectedAccounts(): void
    {
        $pdo = $this->sqliteWithSchema();
        $this->service($pdo)->bootstrap($this->environment());

        $this->applyBlogCapabilities($pdo);

        self::assertSame(6, (int) $pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_user_roles ur "
            . "JOIN ls_webadmin_role_capabilities rc ON rc.role_id = ur.role_id "
            . "JOIN ls_webadmin_capabilities c ON c.id = rc.capability_id "
            . "WHERE c.module_id = 'blog'"
        )->fetchColumn());
        $second = $this->service(
            $pdo,
            [self::REQUEST_UUID]
        )->bootstrap($this->environment());
        self::assertFalse($second->changed());
    }

    public function testInvitationResendResultExposesOnlySafeCounters(): void
    {
        $unchanged = new BootstrapInvitationResendResult(0, 2);
        self::assertFalse($unchanged->changed());
        self::assertSame(0, $unchanged->queuedInvites());
        self::assertSame(2, $unchanged->skippedIdentities());
        self::assertSame([
            'changed' => false,
            'queued_invites' => 0,
            'skipped_identities' => 2,
        ], $unchanged->toSafeArray());

        $changed = new BootstrapInvitationResendResult(2, 0);
        self::assertTrue($changed->changed());
        self::assertSame([
            'changed' => true,
            'queued_invites' => 2,
            'skipped_identities' => 0,
        ], $changed->toSafeArray());

        foreach ([[-1, 0], [0, -1]] as [$queued, $skipped]) {
            try {
                new BootstrapInvitationResendResult($queued, $skipped);
                self::fail('Negative resend counters must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'Invalid bootstrap invitation resend result.',
                    $exception->getMessage()
                );
            }
        }
    }

    public function testInvitationResendRequiresCompletedBootstrapState(): void
    {
        $pdo = $this->sqliteWithSchema();
        $before = $this->mutableCounts($pdo);

        $this->expectBootstrapFailure(
            fn (): BootstrapInvitationResendResult =>
                $this->service($pdo)->resendInvitations(),
            'bootstrap.resend_requires_completed'
        );

        self::assertSame('pending', $this->bootstrapState($pdo));
        self::assertSame($before, $this->mutableCounts($pdo));
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_action_tokens'
        )->fetchColumn());
    }

    public function testInvitationResendSkipsActiveAndSuspendedIdentities(): void
    {
        $pdo = $this->sqliteWithSchema();
        $this->service($pdo)->bootstrap($this->environment());
        $owners = $this->protectedOwnerIds($pdo);
        $activeId = $owners['system_superadmin'];
        $suspendedId = $owners['site_admin'];
        $lifecycle = '2026-08-01 01:02:03.000000';

        $active = $pdo->prepare(
            "UPDATE ls_webadmin_users SET status = 'active', "
            . 'activated_at = :time, updated_at = :time WHERE id = :id'
        );
        $active->execute(['time' => $lifecycle, 'id' => $activeId]);
        $suspended = $pdo->prepare(
            "UPDATE ls_webadmin_users SET status = 'suspended', "
            . 'activated_at = :time, suspended_at = :time, '
            . 'updated_at = :time WHERE id = :id'
        );
        $suspended->execute([
            'time' => $lifecycle,
            'id' => $suspendedId,
        ]);
        $credentials = $pdo->prepare(
            'UPDATE ls_webadmin_credentials SET password_hash = :hash, '
            . 'password_set_at = :time, updated_at = :time '
            . 'WHERE user_id = :id'
        );
        foreach ([$activeId, $suspendedId] as $id) {
            $credentials->execute([
                'hash' => '$private-test-hash-' . $id,
                'time' => $lifecycle,
                'id' => $id,
            ]);
        }
        $pdo->exec(
            "UPDATE ls_webadmin_outbox SET status = 'failed', attempts = 5, "
            . "last_error_code = 'mail.delivery_failed'"
        );
        $activeToken = $this->insertLiveInvitationToken(
            $pdo,
            $activeId,
            'active-secret-token'
        );
        $suspendedToken = $this->insertLiveInvitationToken(
            $pdo,
            $suspendedId,
            'suspended-secret-token'
        );
        $beforeAudit = (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log'
        )->fetchColumn();

        $result = $this->service(
            $pdo,
            [self::OTHER_UUID]
        )->resendInvitations();

        self::assertFalse($result->changed());
        self::assertSame(0, $result->queuedInvites());
        self::assertSame(2, $result->skippedIdentities());
        self::assertSame(2, (int) $pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_outbox WHERE kind = 'invite'"
        )->fetchColumn());
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_outbox "
            . "WHERE kind = 'invite' AND status = 'pending'"
        )->fetchColumn());
        self::assertSame($beforeAudit, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log'
        )->fetchColumn());
        foreach ([$activeToken, $suspendedToken] as $tokenId) {
            self::assertNull($pdo->query(
                'SELECT revoked_at FROM ls_webadmin_action_tokens WHERE id = '
                . $tokenId
            )->fetchColumn());
        }
    }

    public function testInvitationResendNeverDuplicatesPendingOrProcessingWork(): void
    {
        $pdo = $this->sqliteWithSchema();
        $this->service($pdo)->bootstrap($this->environment());
        $owners = $this->protectedOwnerIds($pdo);
        $processing = $pdo->prepare(
            "UPDATE ls_webadmin_outbox SET status = 'processing', attempts = 1, "
            . 'locked_at = :locked_at, lock_token_hash = :lock_hash '
            . 'WHERE user_id = :user_id'
        );
        $processing->execute([
            'locked_at' => '2026-08-01 01:03:00.000000',
            'lock_hash' => str_repeat('a', 64),
            'user_id' => $owners['site_admin'],
        ]);
        $beforeOutbox = $pdo->query(
            'SELECT * FROM ls_webadmin_outbox ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $beforeAudit = (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log'
        )->fetchColumn();
        $service = $this->service($pdo, [
            self::OTHER_UUID,
            self::REQUEST_UUID,
        ]);

        $first = $service->resendInvitations();
        $second = $service->resendInvitations();

        foreach ([$first, $second] as $result) {
            self::assertFalse($result->changed());
            self::assertSame(0, $result->queuedInvites());
            self::assertSame(2, $result->skippedIdentities());
        }
        self::assertSame($beforeOutbox, $pdo->query(
            'SELECT * FROM ls_webadmin_outbox ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($beforeAudit, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log'
        )->fetchColumn());
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_action_tokens'
        )->fetchColumn());
    }

    public function testInvitationResendRequeuesTerminalWorkRevokesTokensAndIsIdempotent(): void
    {
        $pdo = $this->sqliteWithSchema();
        $this->service($pdo)->bootstrap($this->environment());
        $owners = $this->protectedOwnerIds($pdo);
        $systemToken = $this->insertLiveInvitationToken(
            $pdo,
            $owners['system_superadmin'],
            'system-raw-invitation-secret'
        );
        $siteToken = $this->insertLiveInvitationToken(
            $pdo,
            $owners['site_admin'],
            'site-raw-invitation-secret'
        );
        $this->insertActionSession(
            $pdo,
            $systemToken,
            '00000000-0000-4000-8000-000000000901'
        );
        $this->insertActionSession(
            $pdo,
            $siteToken,
            '00000000-0000-4000-8000-000000000902'
        );
        $sent = $pdo->prepare(
            "UPDATE ls_webadmin_outbox SET status = 'sent', attempts = 1, "
            . 'action_token_id = :token_id, sent_at = :sent_at '
            . 'WHERE user_id = :user_id'
        );
        $sent->execute([
            'token_id' => $systemToken,
            'sent_at' => '2026-08-01 01:03:00.000000',
            'user_id' => $owners['system_superadmin'],
        ]);
        $failed = $pdo->prepare(
            "UPDATE ls_webadmin_outbox SET status = 'failed', attempts = 5, "
            . 'action_token_id = :token_id, '
            . "last_error_code = 'mail.delivery_failed' "
            . 'WHERE user_id = :user_id'
        );
        $failed->execute([
            'token_id' => $siteToken,
            'user_id' => $owners['site_admin'],
        ]);
        $auditBefore = (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log'
        )->fetchColumn();
        $service = $this->service($pdo, [
            self::OTHER_UUID,
            self::REQUEST_UUID,
        ]);

        $first = $service->resendInvitations();

        self::assertTrue($first->changed());
        self::assertSame([
            'changed' => true,
            'queued_invites' => 2,
            'skipped_identities' => 0,
        ], $first->toSafeArray());
        self::assertSame(4, (int) $pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_outbox WHERE kind = 'invite'"
        )->fetchColumn());
        self::assertSame(2, (int) $pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_outbox "
            . "WHERE kind = 'invite' AND status = 'pending'"
        )->fetchColumn());
        foreach ([$systemToken, $siteToken] as $tokenId) {
            self::assertSame(self::UTC_TIMESTAMP, $pdo->query(
                'SELECT revoked_at FROM ls_webadmin_action_tokens WHERE id = '
                . $tokenId
            )->fetchColumn());
        }
        self::assertSame(2, (int) $pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_sessions "
            . "WHERE revoked_at = '" . self::UTC_TIMESTAMP . "'"
        )->fetchColumn());

        $newOutbox = $pdo->query(
            'SELECT o.kind, o.locale, o.status, o.attempts, o.locked_at, '
            . 'o.lock_token_hash, o.action_token_id, o.last_error_code, '
            . 'o.available_at, o.created_at, o.sent_at '
            . 'FROM ls_webadmin_outbox o WHERE o.id > 2 ORDER BY o.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $newOutbox);
        foreach ($newOutbox as $row) {
            self::assertSame('invite', $row['kind']);
            self::assertSame('und', $row['locale']);
            self::assertSame('pending', $row['status']);
            self::assertSame(0, $row['attempts']);
            self::assertNull($row['locked_at']);
            self::assertNull($row['lock_token_hash']);
            self::assertNull($row['action_token_id']);
            self::assertNull($row['last_error_code']);
            self::assertSame(self::UTC_TIMESTAMP, $row['available_at']);
            self::assertSame(self::UTC_TIMESTAMP, $row['created_at']);
            self::assertNull($row['sent_at']);
        }

        $resendAudit = $pdo->query(
            'SELECT request_id, event_code, target_public_id, metadata_json, '
            . 'ip_hash, user_agent_hash, occurred_at '
            . 'FROM ls_webadmin_audit_log WHERE id > ' . $auditBefore
            . ' ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(3, $resendAudit);
        self::assertSame([
            'webadmin.bootstrap.invite_requeued',
            'webadmin.bootstrap.invite_requeued',
            'webadmin.bootstrap.invites_requeued',
        ], array_column($resendAudit, 'event_code'));
        foreach ($resendAudit as $row) {
            self::assertSame(self::OTHER_UUID, $row['request_id']);
            self::assertNull($row['ip_hash']);
            self::assertNull($row['user_agent_hash']);
            self::assertSame(self::UTC_TIMESTAMP, $row['occurred_at']);
        }
        $serializedAudit = json_encode($resendAudit, JSON_THROW_ON_ERROR);
        foreach ([
            'system@example.test',
            'site@example.test',
            'system-raw-invitation-secret',
            'site-raw-invitation-secret',
            hash('sha256', 'system-raw-invitation-secret'),
            hash('sha256', 'site-raw-invitation-secret'),
        ] as $secret) {
            self::assertStringNotContainsString($secret, $serializedAudit);
        }

        $outboxAfterFirst = $pdo->query(
            'SELECT * FROM ls_webadmin_outbox ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $auditAfterFirst = $pdo->query(
            'SELECT * FROM ls_webadmin_audit_log ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $second = $service->resendInvitations();
        self::assertFalse($second->changed());
        self::assertSame(0, $second->queuedInvites());
        self::assertSame(2, $second->skippedIdentities());
        self::assertSame($outboxAfterFirst, $pdo->query(
            'SELECT * FROM ls_webadmin_outbox ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($auditAfterFirst, $pdo->query(
            'SELECT * FROM ls_webadmin_audit_log ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testCompletedStateIsANoOpBeforeEnvironmentClockOrUuid(): void
    {
        $pdo = $this->sqliteWithSchema();
        $this->service($pdo)->bootstrap($this->environment());
        $countsBefore = $this->mutableCounts($pdo);

        $service = new WebAdminBootstrapService(
            $pdo,
            WebAdminConfig::defaults(),
            new FailingBootstrapClock(),
            new FailingUuidGenerator()
        );
        $result = $service->bootstrap([]);

        self::assertSame(BootstrapResult::ALREADY_COMPLETED, $result->status());
        self::assertFalse($result->changed());
        self::assertSame($countsBefore, $this->mutableCounts($pdo));
    }

    public function testCompletedStateIsVerifiedBeforeReturningANoOp(): void
    {
        $pdo = $this->sqliteWithSchema();
        $this->service($pdo)->bootstrap($this->environment());
        $pdo->exec(
            'DELETE FROM ls_webadmin_user_roles WHERE role_id = '
            . "(SELECT id FROM ls_webadmin_roles WHERE code = 'site_admin')"
        );

        $service = new WebAdminBootstrapService(
            $pdo,
            WebAdminConfig::defaults(),
            new FailingBootstrapClock(),
            new FailingUuidGenerator()
        );
        $this->expectBootstrapFailure(
            static fn (): BootstrapResult => $service->bootstrap([]),
            'bootstrap.completed_state_incompatible'
        );
    }

    public function testCompletedStateRequiresDistinctProtectedOwners(): void
    {
        $pdo = $this->sqliteWithSchema();
        $this->service($pdo)->bootstrap($this->environment());
        $pdo->exec(
            'UPDATE ls_webadmin_user_roles SET user_id = '
            . '(SELECT ur.user_id FROM ls_webadmin_user_roles ur '
            . 'INNER JOIN ls_webadmin_roles r ON r.id = ur.role_id '
            . "WHERE r.code = 'system_superadmin') "
            . 'WHERE role_id = '
            . '(SELECT id FROM ls_webadmin_roles '
            . "WHERE code = 'site_admin')"
        );

        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap([]),
            'bootstrap.completed_state_incompatible'
        );
    }

    public function testCompletedStateRejectsImpossibleLifecycleTimestamps(): void
    {
        $pdo = $this->sqliteWithSchema();
        $this->service($pdo)->bootstrap($this->environment());
        $pdo->exec(
            "UPDATE ls_webadmin_users SET invited_at = "
            . "'2026-99-99 00:00:00.000000' WHERE id = "
            . '(SELECT MIN(id) FROM ls_webadmin_users)'
        );
        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap([]),
            'bootstrap.completed_state_incompatible'
        );

        $pdo = $this->sqliteWithSchema();
        $this->service($pdo)->bootstrap($this->environment());
        $pdo->exec(
            "UPDATE ls_webadmin_users SET status = 'active', "
            . 'activated_at = NULL WHERE id = '
            . '(SELECT MIN(id) FROM ls_webadmin_users)'
        );
        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap([]),
            'bootstrap.completed_state_incompatible'
        );
    }

    public function testPendingRecoveryRejectsDelayedInviteAndIdentityResidues(): void
    {
        $pdo = $this->sqliteWithSchema();
        $userId = $this->insertManagedBootstrapUser(
            $pdo,
            self::SYSTEM_UUID,
            'system@example.test',
            'system_superadmin',
            true,
            true
        );
        $pdo->exec(
            "UPDATE ls_webadmin_outbox SET available_at = "
            . "'2027-01-01 00:00:00.000000' WHERE user_id = {$userId}"
        );

        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.outbox_collision'
        );

        $pdo->exec(
            "UPDATE ls_webadmin_outbox SET available_at = "
            . "'2026-01-01 00:00:00.000000' WHERE user_id = {$userId}"
        );
        $pdo->exec(
            'INSERT INTO ls_webadmin_user_capabilities '
            . '(user_id, capability_id, created_at) '
            . "SELECT {$userId}, id, '2026-01-01 00:00:00.000000' "
            . 'FROM ls_webadmin_capabilities LIMIT 1'
        );

        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.identity_collision'
        );
    }

    #[DataProvider('invalidEnvironmentProvider')]
    public function testRejectsMissingInvalidAndEqualEmailsWithoutWrites(
        array $environment,
        string $expectedCode
    ): void {
        $pdo = $this->sqliteWithSchema();

        try {
            $this->service($pdo)->bootstrap($environment);
            self::fail('The invalid bootstrap environment should fail.');
        } catch (BootstrapException $exception) {
            self::assertSame($expectedCode, $exception->issueCode());
            self::assertSame(
                'No se pudo completar el bootstrap de WebAdmin de forma segura.',
                $exception->getMessage()
            );
        }

        self::assertSame([
            'users' => 0,
            'credentials' => 0,
            'user_roles' => 0,
            'outbox' => 0,
            'audit_log' => 0,
        ], $this->mutableCounts($pdo));
        self::assertSame('pending', $this->bootstrapState($pdo));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidEnvironmentProvider(): iterable
    {
        $system = WebAdminConfig::BOOTSTRAP_EMAIL_ENV['system_superadmin'];
        $site = WebAdminConfig::BOOTSTRAP_EMAIL_ENV['site_admin'];

        yield 'missing both' => [[], 'bootstrap.environment_missing'];
        yield 'blank system' => [[
            $system => '',
            $site => 'site@example.test',
        ], 'bootstrap.environment_missing'];
        yield 'non-string site' => [[
            $system => 'system@example.test',
            $site => ['site@example.test'],
        ], 'bootstrap.environment_invalid'];
        yield 'invalid system' => [[
            $system => "system@example.test\r\nBcc: victim@example.test",
            $site => 'site@example.test',
        ], 'bootstrap.environment_invalid'];
        yield 'same after canonicalization' => [[
            $system => 'Admin@Example.Test',
            $site => ' admin@example.test ',
        ], 'bootstrap.identities_not_distinct'];
    }

    public function testCannotRunBeforeTheSchemaMigration(): void
    {
        $pdo = $this->sqlite();

        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap([]),
            'bootstrap.persistence_failed'
        );
        self::assertSame([], $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table'"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testRejectsMissingOrUnexpectedBootstrapState(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec(
            "DELETE FROM ls_webadmin_state WHERE state_key = 'bootstrap.initial_accounts'"
        );
        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.schema_not_ready'
        );

        $pdo = $this->sqliteWithSchema();
        $pdo->exec(
            "UPDATE ls_webadmin_state SET value_text = 'running' "
            . "WHERE state_key = 'bootstrap.initial_accounts'"
        );
        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.state_invalid'
        );
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_users'
        )->fetchColumn());
    }

    public function testClockAndUuidFailuresAreSanitizedAndRolledBack(): void
    {
        $pdo = $this->sqliteWithSchema();
        $service = new WebAdminBootstrapService(
            $pdo,
            WebAdminConfig::defaults(),
            new FailingBootstrapClock(),
            new SequenceUuidGenerator([self::REQUEST_UUID])
        );
        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $service->bootstrap(
                $this->environment()
            ),
            'bootstrap.clock_failed'
        );
        self::assertSame('pending', $this->bootstrapState($pdo));

        $service = new WebAdminBootstrapService(
            $pdo,
            WebAdminConfig::defaults(),
            new FixedBootstrapClock(),
            new SequenceUuidGenerator(['not-a-uuid'])
        );
        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $service->bootstrap(
                $this->environment()
            ),
            'bootstrap.uuid_failed'
        );
        self::assertSame([
            'users' => 0,
            'credentials' => 0,
            'user_roles' => 0,
            'outbox' => 0,
            'audit_log' => 0,
        ], $this->mutableCounts($pdo));
    }

    public function testDoesNotTakeOwnershipOfAnExistingPdoTransaction(): void
    {
        $pdo = $this->sqliteWithSchema();
        self::assertTrue($pdo->beginTransaction());

        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.transaction_already_active'
        );
        self::assertTrue($pdo->inTransaction());
        self::assertTrue($pdo->rollBack());
        self::assertSame('pending', $this->bootstrapState($pdo));
    }

    public function testRequiresPdoExceptionModeBeforeAnyMutation(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.pdo_configuration_invalid'
        );

        self::assertSame([
            'users' => 0,
            'credentials' => 0,
            'user_roles' => 0,
            'outbox' => 0,
            'audit_log' => 0,
        ], $this->mutableCounts($pdo));
        self::assertSame('pending', $this->bootstrapState($pdo));
    }

    public function testLateFailureRollsBackIdentityAuditOutboxAndState(): void
    {
        $pdo = $this->sqliteWithSchema();
        $service = $this->service($pdo, [
            self::REQUEST_UUID,
            self::SYSTEM_UUID,
            self::SYSTEM_UUID,
        ]);

        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $service->bootstrap($this->environment()),
            'bootstrap.persistence_failed'
        );

        self::assertSame([
            'users' => 0,
            'credentials' => 0,
            'user_roles' => 0,
            'outbox' => 0,
            'audit_log' => 0,
        ], $this->mutableCounts($pdo));
        self::assertSame('pending', $this->bootstrapState($pdo));

        $recovery = $this->service($pdo)->bootstrap($this->environment());
        self::assertTrue($recovery->changed());
        self::assertSame(2, $recovery->createdAccounts());
    }

    public function testReconcilesOnlyPreviouslyBootstrapOwnedInvitesWithoutDegradingExtras(): void
    {
        $pdo = $this->sqliteWithSchema();
        $systemId = $this->insertManagedBootstrapUser(
            $pdo,
            self::SYSTEM_UUID,
            'system@example.test',
            'system_superadmin',
            true,
            true
        );
        $siteId = $this->insertManagedBootstrapUser(
            $pdo,
            self::SITE_UUID,
            'site@example.test',
            'site_admin',
            false,
            false
        );
        $pdo->exec(
            'INSERT INTO ls_webadmin_user_roles '
            . '(user_id, role_id, source, created_at) '
            . "SELECT {$systemId}, id, 'manual', '2026-01-01 00:00:00.000000' "
            . "FROM ls_webadmin_roles WHERE code = 'editor'"
        );
        $editorRoleCountBefore = (int) $pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_user_roles ur "
            . 'JOIN ls_webadmin_roles r ON r.id = ur.role_id '
            . "WHERE ur.user_id = {$systemId} AND r.code = 'editor'"
        )->fetchColumn();

        $result = $this->service($pdo)->bootstrap($this->environment());

        self::assertSame(0, $result->createdAccounts());
        self::assertSame(2, $result->reconciledAccounts());
        self::assertSame(1, $result->queuedInvites());
        self::assertSame(2, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_credentials'
        )->fetchColumn());
        self::assertSame(2, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_outbox'
        )->fetchColumn());
        self::assertSame($editorRoleCountBefore, (int) $pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_user_roles ur "
            . 'JOIN ls_webadmin_roles r ON r.id = ur.role_id '
            . "WHERE ur.user_id = {$systemId} AND r.code = 'editor'"
        )->fetchColumn());
        self::assertSame([
            'webadmin.bootstrap.completed' => 1,
            'webadmin.bootstrap.identity_reconciled' => 2,
            'webadmin.bootstrap.invite_queued' => 1,
        ], $this->eventCounts($pdo->query(
            'SELECT event_code FROM ls_webadmin_audit_log ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC)));
        self::assertSame('completed', $this->bootstrapState($pdo));
        self::assertGreaterThan(0, $siteId);
    }

    public function testRefusesToAppropriateAnExistingUser(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, status, auth_version, invited_at, '
            . 'created_at, updated_at) VALUES '
            . "('" . self::SYSTEM_UUID . "', 'system@example.test', "
            . "'invited', 1, '2026-01-01 00:00:00.000000', "
            . "'2026-01-01 00:00:00.000000', '2026-01-01 00:00:00.000000')"
        );

        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.identity_collision'
        );
        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_users'
        )->fetchColumn());
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_user_roles'
        )->fetchColumn());
        self::assertSame('pending', $this->bootstrapState($pdo));
    }

    public function testRefusesAReservedRoleOwnedByAnotherIdentity(): void
    {
        $pdo = $this->sqliteWithSchema();
        $this->insertManagedBootstrapUser(
            $pdo,
            self::OTHER_UUID,
            'other@example.test',
            'system_superadmin',
            true,
            true
        );

        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.role_already_owned'
        );
        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_users'
        )->fetchColumn());
        self::assertSame('pending', $this->bootstrapState($pdo));
    }

    public function testRefusesIncompatibleRoleAndCapabilitySeeds(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec(
            "UPDATE ls_webadmin_roles SET is_protected = 0 "
            . "WHERE code = 'system_superadmin'"
        );
        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.role_incompatible'
        );

        $pdo = $this->sqliteWithSchema();
        $pdo->exec(
            'DELETE FROM ls_webadmin_role_capabilities '
            . 'WHERE role_id = (SELECT id FROM ls_webadmin_roles '
            . "WHERE code = 'system_superadmin') "
            . 'AND capability_id = (SELECT id FROM ls_webadmin_capabilities '
            . "WHERE code = 'webadmin.system.diagnose')"
        );
        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.capability_incompatible'
        );

        $pdo = $this->sqliteWithSchema();
        $pdo->exec(
            'INSERT INTO ls_webadmin_role_capabilities '
            . '(role_id, capability_id) SELECT r.id, c.id '
            . 'FROM ls_webadmin_roles r CROSS JOIN ls_webadmin_capabilities c '
            . "WHERE r.code = 'site_admin' "
            . "AND c.code = 'webadmin.system.diagnose'"
        );
        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.capability_incompatible'
        );
    }

    public function testPreservesCapabilitiesContributedByAnotherModule(): void
    {
        $pdo = $this->sqliteWithSchema();
        $pdo->exec(
            'INSERT INTO ls_webadmin_capabilities '
            . '(module_id, code, label_key, is_delegable) VALUES '
            . "('blog', 'blog.posts.manage', 'blog.capabilities.posts_manage', 0)"
        );
        $pdo->exec(
            'INSERT INTO ls_webadmin_role_capabilities '
            . '(role_id, capability_id) SELECT r.id, c.id '
            . 'FROM ls_webadmin_roles r CROSS JOIN ls_webadmin_capabilities c '
            . "WHERE r.code = 'system_superadmin' "
            . "AND c.code = 'blog.posts.manage'"
        );

        self::assertTrue($this->service($pdo)->bootstrap(
            $this->environment()
        )->changed());
        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_role_capabilities rc '
            . 'JOIN ls_webadmin_roles r ON r.id = rc.role_id '
            . 'JOIN ls_webadmin_capabilities c ON c.id = rc.capability_id '
            . "WHERE r.code = 'system_superadmin' "
            . "AND c.code = 'blog.posts.manage'"
        )->fetchColumn());
    }

    public function testRefusesCredentialAndOutboxCollisions(): void
    {
        $pdo = $this->sqliteWithSchema();
        $systemId = $this->insertManagedBootstrapUser(
            $pdo,
            self::SYSTEM_UUID,
            'system@example.test',
            'system_superadmin',
            true,
            true
        );
        $pdo->exec(
            "UPDATE ls_webadmin_credentials SET password_hash = 'hash', "
            . "password_set_at = '2026-01-01 00:00:00.000000' "
            . "WHERE user_id = {$systemId}"
        );
        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.credential_collision'
        );

        $pdo = $this->sqliteWithSchema();
        $this->insertManagedBootstrapUser(
            $pdo,
            self::SYSTEM_UUID,
            'system@example.test',
            'system_superadmin',
            true,
            true
        );
        $pdo->exec(
            "UPDATE ls_webadmin_outbox SET status = 'failed' "
            . "WHERE user_id = (SELECT id FROM ls_webadmin_users "
            . "WHERE email_canonical = 'system@example.test')"
        );
        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($pdo)->bootstrap(
                $this->environment()
            ),
            'bootstrap.outbox_collision'
        );
    }

    public function testCustomTableScopeIsUsedWithoutTouchingTheDefaultScope(): void
    {
        $pdo = $this->sqlite();
        $this->applySchema($pdo, 'client_admin_');
        $config = new WebAdminConfig(
            '/admin',
            'client_admin_',
            'LS_WEBADMIN_SID',
            1800,
            28800,
            'test'
        );
        $result = $this->service($pdo, null, $config)->bootstrap(
            $this->environment()
        );

        self::assertTrue($result->changed());
        self::assertSame(2, (int) $pdo->query(
            'SELECT COUNT(*) FROM client_admin_users'
        )->fetchColumn());
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' "
            . "AND name LIKE 'ls_webadmin_%'"
        )->fetchColumn());
    }

    public function testSqliteLockContentionFailsClosedAndCanBeRetried(): void
    {
        $path = $this->temporaryDatabasePath();
        $schemaConnection = $this->sqlite('sqlite:' . $path);
        $this->applySchema($schemaConnection);
        unset($schemaConnection);

        $locker = $this->sqlite('sqlite:' . $path);
        $worker = $this->sqlite('sqlite:' . $path);
        $worker->exec('PRAGMA busy_timeout = 0');
        $locker->exec('BEGIN IMMEDIATE');

        $this->expectBootstrapFailure(
            fn (): BootstrapResult => $this->service($worker)->bootstrap(
                $this->environment()
            ),
            'bootstrap.persistence_failed'
        );
        $locker->exec('ROLLBACK');

        self::assertSame('pending', $this->bootstrapState($worker));
        self::assertSame(0, (int) $worker->query(
            'SELECT COUNT(*) FROM ls_webadmin_users'
        )->fetchColumn());
        self::assertTrue($this->service($worker)->bootstrap(
            $this->environment()
        )->changed());
    }

    public function testSqliteCommitFailureRollsBackAuditStateAndReleasesTheLock(): void
    {
        $path = $this->temporaryDatabasePath();
        $pdo = $this->sqlite('sqlite:' . $path);
        $this->applySchema($pdo);
        $pdo->exec('CREATE TABLE deferred_parent (id INTEGER PRIMARY KEY)');
        $pdo->exec(
            'CREATE TABLE deferred_child ('
            . 'parent_id INTEGER NOT NULL, '
            . 'FOREIGN KEY (parent_id) REFERENCES deferred_parent(id) '
            . 'DEFERRABLE INITIALLY DEFERRED)'
        );
        $repository = new PdoBootstrapRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        );

        $this->expectBootstrapFailure(
            function () use ($repository, $pdo): mixed {
                return $repository->transaction(function (
                    PdoBootstrapRepository $repository
                ) use ($pdo): void {
                    self::assertSame(
                        'pending',
                        $repository->lockInitialAccountsState()
                    );
                    $repository->insertAuditEvent(
                        self::REQUEST_UUID,
                        'webadmin.bootstrap.completed',
                        null,
                        null,
                        self::UTC_TIMESTAMP
                    );
                    $repository->markInitialAccountsCompleted(
                        self::UTC_TIMESTAMP
                    );
                    $pdo->exec(
                        'INSERT INTO deferred_child (parent_id) VALUES (999)'
                    );
                });
            },
            'bootstrap.persistence_failed'
        );

        self::assertSame('pending', $this->bootstrapState($pdo));
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_audit_log'
        )->fetchColumn());
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM deferred_child'
        )->fetchColumn());

        $secondConnection = $this->sqlite('sqlite:' . $path);
        $secondConnection->exec('PRAGMA busy_timeout = 0');
        self::assertSame(0, $secondConnection->exec('BEGIN IMMEDIATE'));
        self::assertSame(0, $secondConnection->exec('ROLLBACK'));
    }

    private function service(
        PDO $pdo,
        ?array $uuids = null,
        ?WebAdminConfig $config = null
    ): WebAdminBootstrapService {
        return new WebAdminBootstrapService(
            $pdo,
            $config ?? WebAdminConfig::defaults(),
            new FixedBootstrapClock(),
            new SequenceUuidGenerator($uuids ?? [
                self::REQUEST_UUID,
                self::SYSTEM_UUID,
                self::SITE_UUID,
            ])
        );
    }

    /** @return array<string, string> */
    private function environment(
        string $system = 'system@example.test',
        string $site = 'site@example.test'
    ): array {
        return [
            WebAdminConfig::BOOTSTRAP_EMAIL_ENV['system_superadmin'] => $system,
            WebAdminConfig::BOOTSTRAP_EMAIL_ENV['site_admin'] => $site,
        ];
    }

    private function sqliteWithSchema(): PDO
    {
        $pdo = $this->sqlite();
        $this->applySchema($pdo);

        return $pdo;
    }

    private function sqlite(string $dsn = 'sqlite::memory:'): PDO
    {
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function applySchema(PDO $pdo, string $prefix = 'ls_webadmin_'): void
    {
        $migrations = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        );
        self::assertCount(1, $migrations);
        $scope = MigrationScope::forTablePrefix('webadmin', $prefix);
        foreach ($migrations[0]->statementsFor('sqlite', $scope) as $sql) {
            $pdo->exec($sql);
        }
    }

    private function applyBlogCapabilities(PDO $pdo): void
    {
        $scope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if ($migration->id() !== '0002_blog_capabilities') {
                continue;
            }
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                $pdo->exec($sql);
            }

            return;
        }

        throw new RuntimeException('Blog capability migration is missing.');
    }

    private function bootstrapState(PDO $pdo): string
    {
        return (string) $pdo->query(
            "SELECT value_text FROM ls_webadmin_state "
            . "WHERE state_key = 'bootstrap.initial_accounts'"
        )->fetchColumn();
    }

    /** @return array<string, int> */
    private function mutableCounts(PDO $pdo): array
    {
        $counts = [];
        foreach ([
            'users',
            'credentials',
            'user_roles',
            'outbox',
            'audit_log',
        ] as $suffix) {
            $counts[$suffix] = (int) $pdo->query(
                'SELECT COUNT(*) FROM ls_webadmin_' . $suffix
            )->fetchColumn();
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function eventCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $event = (string) $row['event_code'];
            $counts[$event] = ($counts[$event] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    private function insertManagedBootstrapUser(
        PDO $pdo,
        string $publicId,
        string $email,
        string $roleCode,
        bool $withCredential,
        bool $withOutbox
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, status, auth_version, invited_at, '
            . 'created_at, updated_at) VALUES '
            . "(:public_id, :email, 'invited', 1, :time, :time, :time)"
        );
        $statement->execute([
            'public_id' => $publicId,
            'email' => $email,
            'time' => '2026-01-01 00:00:00.000000',
        ]);
        $userId = (int) $pdo->lastInsertId();
        if ($withCredential) {
            $pdo->exec(
                'INSERT INTO ls_webadmin_credentials '
                . '(user_id, created_at, updated_at) VALUES '
                . "({$userId}, '2026-01-01 00:00:00.000000', "
                . "'2026-01-01 00:00:00.000000')"
            );
        }
        $pdo->exec(
            'INSERT INTO ls_webadmin_user_roles '
            . '(user_id, role_id, source, created_at) '
            . "SELECT {$userId}, id, 'bootstrap', "
            . "'2026-01-01 00:00:00.000000' FROM ls_webadmin_roles "
            . "WHERE code = '" . $roleCode . "'"
        );
        if ($withOutbox) {
            $pdo->exec(
                'INSERT INTO ls_webadmin_outbox '
                . '(kind, user_id, locale, status, attempts, available_at, '
                . "created_at) VALUES ('invite', {$userId}, 'und', 'pending', "
                . "0, '2026-01-01 00:00:00.000000', "
                . "'2026-01-01 00:00:00.000000')"
            );
        }

        return $userId;
    }

    /** @return array{system_superadmin: int, site_admin: int} */
    private function protectedOwnerIds(PDO $pdo): array
    {
        $owners = array_map('intval', $pdo->query(
            'SELECT r.code, ur.user_id FROM ls_webadmin_roles r '
            . 'JOIN ls_webadmin_user_roles ur ON ur.role_id = r.id '
            . "WHERE r.code IN ('system_superadmin', 'site_admin') "
            . 'ORDER BY r.code'
        )->fetchAll(PDO::FETCH_KEY_PAIR));
        self::assertSame(
            ['site_admin', 'system_superadmin'],
            array_keys($owners)
        );

        return [
            'system_superadmin' => $owners['system_superadmin'],
            'site_admin' => $owners['site_admin'],
        ];
    }

    private function insertLiveInvitationToken(
        PDO $pdo,
        int $userId,
        string $rawToken
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO ls_webadmin_action_tokens '
            . '(user_id, purpose, token_hash, auth_version, created_at, '
            . 'expires_at, delivered_at) VALUES '
            . "(:user_id, 'invite', :token_hash, 1, :created_at, "
            . ':expires_at, :delivered_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $rawToken),
            'created_at' => '2026-08-01 00:30:00.000000',
            'expires_at' => '2026-08-04 00:30:00.000000',
            'delivered_at' => '2026-08-01 00:31:00.000000',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertActionSession(
        PDO $pdo,
        int $actionTokenId,
        string $publicId
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at, revoked_at) VALUES '
            . "(:public_id, NULL, 'preauth', :token_hash, :csrf_hash, NULL, "
            . ':action_token_id, :created_at, :last_seen_at, '
            . ':idle_expires_at, :absolute_expires_at, NULL)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'token_hash' => hash('sha256', $publicId . ':session'),
            'csrf_hash' => hash('sha256', $publicId . ':csrf'),
            'action_token_id' => $actionTokenId,
            'created_at' => '2026-08-01 00:32:00.000000',
            'last_seen_at' => '2026-08-01 00:32:00.000000',
            'idle_expires_at' => '2026-08-01 00:47:00.000000',
            'absolute_expires_at' => '2026-08-01 01:02:00.000000',
        ]);
    }

    /** @param callable(): mixed $operation */
    private function expectBootstrapFailure(
        callable $operation,
        string $expectedCode
    ): void {
        try {
            $operation();
            self::fail('The bootstrap operation should fail.');
        } catch (BootstrapException $exception) {
            self::assertSame($expectedCode, $exception->issueCode());
            self::assertSame(
                'No se pudo completar el bootstrap de WebAdmin de forma segura.',
                $exception->getMessage()
            );
        }
    }

    private function temporaryDatabasePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ls-webadmin-bootstrap-');
        if (!is_string($path)) {
            self::fail('Could not create a temporary SQLite database.');
        }
        $this->temporaryFiles[] = $path;

        return $path;
    }
}

final class FixedBootstrapClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-08-01 03:04:05.123456',
            new DateTimeZone('Europe/Madrid')
        );
    }
}

final class FailingBootstrapClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        throw new RuntimeException('Clock must not be read.');
    }
}

final class SequenceUuidGenerator implements UuidGeneratorInterface
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

final class FailingUuidGenerator implements UuidGeneratorInterface
{
    public function generateV4(): string
    {
        throw new RuntimeException('UUID generator must not be read.');
    }
}
