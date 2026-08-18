<?php

declare(strict_types=1);

namespace Tests\WebAdmin;

use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Bootstrap\BootstrapException;
use App\Core\WebAdmin\Bootstrap\BootstrapInvitationReadiness;
use App\Core\WebAdmin\Bootstrap\BootstrapOnboardingResult;
use App\Core\WebAdmin\Bootstrap\BootstrapResult;
use App\Core\WebAdmin\Bootstrap\WebAdminBootstrapInvitationAudience;
use App\Core\WebAdmin\Bootstrap\WebAdminBootstrapService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatchReport;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\UnsupportedPasswordPolicy;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebAdminBootstrapInvitationAudienceTest extends TestCase
{
    private const NOW = '2026-08-01 03:00:00.000000';

    private PDO $pdo;
    private WebAdminBootstrapInvitationAudience $audience;

    /** @var array{site_admin: int, system_superadmin: int} */
    private array $owners;

    /** @var array<string, int> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->applySchema();

        $bootstrapClock = new InvitationAudienceFixedClock(
            '2026-08-01 01:00:00.000000'
        );
        $clock = new InvitationAudienceFixedClock(self::NOW);
        $service = new WebAdminBootstrapService(
            $this->pdo,
            WebAdminConfig::defaults(),
            $bootstrapClock,
            new InvitationAudienceUuidSequence([
                '10000000-0000-4000-8000-000000000001',
                '20000000-0000-4000-8000-000000000002',
                '30000000-0000-4000-8000-000000000003',
            ])
        );
        $service->bootstrap([
            WebAdminConfig::BOOTSTRAP_EMAIL_ENV['system_superadmin'] =>
                'operator-one@example.test',
            WebAdminConfig::BOOTSTRAP_EMAIL_ENV['site_admin'] =>
                'operator-two@example.test',
        ]);

        /** @var array<string, int|string> $owners */
        $owners = $this->pdo->query(
            'SELECT r.code, ur.user_id FROM ls_webadmin_roles r '
            . 'JOIN ls_webadmin_user_roles ur ON ur.role_id = r.id '
            . "WHERE r.code IN ('site_admin', 'system_superadmin') "
            . 'ORDER BY r.code'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->owners = [
            'site_admin' => (int) $owners['site_admin'],
            'system_superadmin' => (int) $owners['system_superadmin'],
        ];
        $this->audience = new WebAdminBootstrapInvitationAudience(
            $this->pdo,
            WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_'),
            $clock
        );
    }

    public function testFreshBootstrapIsIncompleteUntilSmtpDelivery(): void
    {
        self::assertSame(
            array_values($this->owners),
            $this->audience->recipientIds()
        );
        self::assertSame(
            array_values($this->owners),
            $this->audience->dispatchableRecipientIds()
        );
        self::assertSame([
            'ready' => false,
            'expected_identities' => 2,
            'active_identities' => 0,
            'delivered_invitations' => 0,
            'incomplete_identities' => 2,
        ], $this->audience->readiness()->toSafeArray());
    }

    public function testTwoLiveDeliveredInvitationsAreReady(): void
    {
        $this->deliverBoth();

        self::assertSame([
            'ready' => true,
            'expected_identities' => 2,
            'active_identities' => 0,
            'delivered_invitations' => 2,
            'incomplete_identities' => 0,
        ], $this->audience->readiness()->toSafeArray());
        self::assertSame([], $this->audience->dispatchableRecipientIds());
    }

    public function testPartialDeliveryTargetsOnlyTheRemainingOpenInvite(): void
    {
        $this->tokens['site_admin'] = $this->insertDeliveredInvitation(
            $this->owners['site_admin'],
            'site_admin',
            true
        );

        self::assertSame([
            $this->owners['system_superadmin'],
        ], $this->audience->dispatchableRecipientIds());
        self::assertSame([
            'ready' => false,
            'expected_identities' => 2,
            'active_identities' => 0,
            'delivered_invitations' => 1,
            'incomplete_identities' => 1,
        ], $this->audience->readiness()->toSafeArray());
    }

    #[DataProvider('openInvitationStateProvider')]
    public function testBackoffAndProcessingInvitesRemainDispatchable(
        string $openState
    ): void {
        $siteId = $this->owners['site_admin'];
        if ($openState === 'backoff') {
            $this->pdo->exec(
                'UPDATE ls_webadmin_outbox SET attempts = 1, '
                . "available_at = '2026-08-01 04:00:00.000000', "
                . "last_error_code = 'outbox.delivery_failed' "
                . "WHERE user_id = {$siteId}"
            );
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO ls_webadmin_action_tokens '
                . '(user_id, purpose, token_hash, auth_version, '
                . 'created_by_user_id, created_at, expires_at) VALUES '
                . "(:user_id, 'invite', :token_hash, 1, NULL, :created_at, "
                . ':expires_at)'
            );
            $statement->execute([
                'user_id' => $siteId,
                'token_hash' => hash('sha256', 'processing-site-invite'),
                'created_at' => '2026-08-01 02:00:00.000000',
                'expires_at' => '2026-08-04 02:00:00.000000',
            ]);
            $tokenId = (int) $this->pdo->lastInsertId();
            $this->pdo->exec(
                "UPDATE ls_webadmin_outbox SET status = 'processing', "
                . "attempts = 1, locked_at = '2026-08-01 02:55:00.000000', "
                . "lock_token_hash = '" . hash('sha256', 'processing-lease')
                . "', action_token_id = {$tokenId} WHERE user_id = {$siteId}"
            );
        }

        self::assertSame(
            array_values($this->owners),
            $this->audience->dispatchableRecipientIds()
        );
    }

    /** @return iterable<string, array{string}> */
    public static function openInvitationStateProvider(): iterable
    {
        yield 'pending in backoff' => ['backoff'];
        yield 'processing under lease' => ['processing'];
    }

    public function testActiveAndDeliveredIdentityCombinationIsReady(): void
    {
        $this->deliverBoth();
        $this->activate('site_admin');

        self::assertSame([
            'ready' => true,
            'expected_identities' => 2,
            'active_identities' => 1,
            'delivered_invitations' => 1,
            'incomplete_identities' => 0,
        ], $this->audience->readiness()->toSafeArray());
        self::assertSame([], $this->audience->dispatchableRecipientIds());
    }

    public function testSuspendedProtectedIdentityIsNeverReady(): void
    {
        $this->deliverBoth();
        $this->activate('site_admin');
        $this->pdo->exec(
            "UPDATE ls_webadmin_users SET status = 'suspended', "
            . "suspended_at = '2026-08-01 03:00:00.000000' WHERE id = "
            . $this->owners['site_admin']
        );

        self::assertSame([
            'ready' => false,
            'expected_identities' => 2,
            'active_identities' => 0,
            'delivered_invitations' => 1,
            'incomplete_identities' => 1,
        ], $this->audience->readiness()->toSafeArray());
    }

    #[DataProvider('invalidActiveHashProvider')]
    public function testActiveIdentityRequiresTheProductiveLoginHash(
        string $hashKind
    ): void {
        $this->deliverBoth();
        $this->activate('site_admin');
        $invalidHash = $hashKind === 'legacy'
            ? password_hash('ValidAccess1!', PASSWORD_BCRYPT, ['cost' => 4])
            : 'not-a-password-hash';
        self::assertIsString($invalidHash);
        $statement = $this->pdo->prepare(
            'UPDATE ls_webadmin_credentials SET password_hash = :hash '
            . 'WHERE user_id = :user_id'
        );
        $statement->execute([
            'hash' => $invalidHash,
            'user_id' => $this->owners['site_admin'],
        ]);

        self::assertSame([
            'ready' => false,
            'expected_identities' => 2,
            'active_identities' => 0,
            'delivered_invitations' => 1,
            'incomplete_identities' => 1,
        ], $this->audience->readiness()->toSafeArray());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidActiveHashProvider(): iterable
    {
        yield 'corrupt hash' => ['corrupt'];
        yield 'legacy bcrypt hash' => ['legacy'];
    }

    public function testNonProductiveInjectedHasherFailsClosed(): void
    {
        $this->expectException(UnsupportedPasswordPolicy::class);

        new WebAdminBootstrapInvitationAudience(
            $this->pdo,
            WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_'),
            new InvitationAudienceFixedClock(self::NOW),
            PasswordHasher::bcryptFallback()
        );
    }

    #[DataProvider('invalidInvitationProvider')]
    public function testInvalidInvitationCannotSatisfyReadiness(
        string $corruption
    ): void {
        $this->deliverBoth();
        $siteToken = $this->tokens['site_admin'];

        if ($corruption === 'expired') {
            $this->pdo->exec(
                "UPDATE ls_webadmin_action_tokens SET expires_at = "
                . "'2026-08-01 02:59:59.000000' WHERE id = {$siteToken}"
            );
        } elseif ($corruption === 'revoked') {
            $this->pdo->exec(
                "UPDATE ls_webadmin_action_tokens SET revoked_at = "
                . "'2026-08-01 02:50:00.000000' WHERE id = {$siteToken}"
            );
        } elseif ($corruption === 'mismatched_token_user') {
            $this->pdo->exec(
                'UPDATE ls_webadmin_action_tokens SET user_id = '
                . $this->owners['system_superadmin']
                . " WHERE id = {$siteToken}"
            );
        } elseif ($corruption === 'token_created_by_user') {
            $this->pdo->exec(
                'UPDATE ls_webadmin_action_tokens SET created_by_user_id = '
                . $this->owners['system_superadmin']
                . " WHERE id = {$siteToken}"
            );
        } elseif ($corruption === 'future_delivery') {
            $this->pdo->exec(
                "UPDATE ls_webadmin_action_tokens SET delivered_at = "
                . "'2026-08-02 02:10:00.000000' WHERE id = {$siteToken}"
            );
        } else {
            self::fail('Unknown invitation corruption fixture.');
        }

        self::assertSame([
            'ready' => false,
            'expected_identities' => 2,
            'active_identities' => 0,
            'delivered_invitations' => 1,
            'incomplete_identities' => 1,
        ], $this->audience->readiness()->toSafeArray());
        self::assertSame([], $this->audience->dispatchableRecipientIds());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInvitationProvider(): iterable
    {
        yield 'expired' => ['expired'];
        yield 'revoked' => ['revoked'];
        yield 'token belongs to another identity' => [
            'mismatched_token_user',
        ];
        yield 'token has a creating user' => ['token_created_by_user'];
        yield 'delivery timestamp is in the future' => ['future_delivery'];
    }

    public function testFailedOrExpiredInviteRequiresExplicitRecovery(): void
    {
        $this->deliverBoth();
        $siteToken = $this->tokens['site_admin'];
        $this->pdo->exec(
            "UPDATE ls_webadmin_action_tokens SET revoked_at = "
            . "'2026-08-01 02:50:00.000000' WHERE id = {$siteToken}"
        );
        $this->pdo->exec(
            "UPDATE ls_webadmin_outbox SET status = 'failed', "
            . "last_error_code = 'outbox.delivery_failed', sent_at = NULL "
            . "WHERE action_token_id = {$siteToken}"
        );

        self::assertSame([], $this->audience->dispatchableRecipientIds());
        self::assertSame(1, $this->audience->readiness()
            ->incompleteIdentities());
    }

    #[DataProvider('outboxCollisionProvider')]
    public function testImpossibleOpenAndLiveCombinationsFailClosed(
        string $collision
    ): void {
        if ($collision === 'multiple_open') {
            $this->insertOpenInvitation($this->owners['site_admin']);
        } elseif ($collision === 'multiple_live') {
            $this->deliverBoth();
            $this->insertDeliveredInvitation(
                $this->owners['site_admin'],
                'second-site-invite',
                false
            );
        } elseif ($collision === 'live_and_open') {
            $this->deliverBoth();
            $this->insertOpenInvitation($this->owners['site_admin']);
        } elseif ($collision === 'active_and_open') {
            $this->activate('site_admin');
        } else {
            self::fail('Unknown outbox collision fixture.');
        }

        foreach (['readiness', 'dispatchableRecipientIds'] as $method) {
            try {
                $this->audience->{$method}();
                self::fail('An impossible outbox state must fail closed.');
            } catch (BootstrapException $exception) {
                self::assertSame(
                    'bootstrap.outbox_collision',
                    $exception->issueCode()
                );
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function outboxCollisionProvider(): iterable
    {
        yield 'more than one open invite' => ['multiple_open'];
        yield 'more than one live invite' => ['multiple_live'];
        yield 'live and open invite' => ['live_and_open'];
        yield 'active identity and open invite' => ['active_and_open'];
    }

    public function testNonBootstrapProtectedRoleAssignmentFailsClosed(): void
    {
        $this->pdo->exec(
            "UPDATE ls_webadmin_user_roles SET source = 'manual' "
            . 'WHERE user_id = ' . $this->owners['site_admin']
        );

        try {
            $this->audience->readiness();
            self::fail('A non-bootstrap owner must fail closed.');
        } catch (BootstrapException $exception) {
            self::assertSame(
                'bootstrap.completed_state_incompatible',
                $exception->issueCode()
            );
            self::assertStringNotContainsString(
                'operator-two@example.test',
                $exception->getMessage()
            );
        }
    }

    #[DataProvider('invalidOwnerProvenanceProvider')]
    public function testInvalidProtectedOwnerProvenanceFailsClosed(
        string $provenance
    ): void {
        if ($provenance === 'assigned_by') {
            $this->pdo->exec(
                'UPDATE ls_webadmin_user_roles SET assigned_by_user_id = '
                . $this->owners['system_superadmin']
                . ' WHERE user_id = ' . $this->owners['site_admin']
            );
        } else {
            $this->pdo->exec(
                'UPDATE ls_webadmin_users SET created_by_user_id = '
                . $this->owners['system_superadmin']
                . ' WHERE id = ' . $this->owners['site_admin']
            );
        }

        try {
            $this->audience->recipientIds();
            self::fail('Invalid bootstrap provenance must fail closed.');
        } catch (BootstrapException $exception) {
            self::assertSame(
                'bootstrap.completed_state_incompatible',
                $exception->issueCode()
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOwnerProvenanceProvider(): iterable
    {
        yield 'role assignment has an actor' => ['assigned_by'];
        yield 'identity has a creator' => ['created_by'];
    }

    public function testClockFailureIsReportedWithSafeIssueCode(): void
    {
        $audience = new WebAdminBootstrapInvitationAudience(
            $this->pdo,
            WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_'),
            new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    throw new RuntimeException('secret clock detail');
                }
            }
        );

        try {
            $audience->readiness();
            self::fail('A clock failure must fail closed.');
        } catch (BootstrapException $exception) {
            self::assertSame('bootstrap.clock_failed', $exception->issueCode());
            self::assertStringNotContainsString(
                'secret clock detail',
                $exception->getMessage()
            );
        }
    }

    public function testOnboardingResultIsSafeAndReadyOnlyWithoutFailures(): void
    {
        $this->deliverBoth();
        $readiness = $this->audience->readiness();
        $successful = new BootstrapOnboardingResult(
            BootstrapResult::completed(2, 0, 2),
            new WebAdminOutboxDispatchReport(2, 2, 2, 0, 0, 0),
            $readiness
        );

        self::assertTrue($successful->isReady());
        self::assertSame([
            'bootstrap' => [
                'status' => 'completed',
                'changed' => true,
                'created_accounts' => 2,
                'reconciled_accounts' => 0,
                'queued_invites' => 2,
            ],
            'dispatch' => [
                'examined' => 2,
                'claimed' => 2,
                'sent' => 2,
                'retry_scheduled' => 0,
                'permanently_failed' => 0,
                'fenced' => 0,
            ],
            'readiness' => $readiness->toSafeArray(),
        ], $successful->toSafeArray());

        $safeJson = json_encode(
            $successful->toSafeArray(),
            JSON_THROW_ON_ERROR
        );
        self::assertStringNotContainsString('@example.test', $safeJson);
        self::assertStringNotContainsString('second-site-invite', $safeJson);
        self::assertDoesNotMatchRegularExpression(
            '/"(?:user|recipient|token|action_token)_?id"/i',
            $safeJson
        );

        foreach ([
            new WebAdminOutboxDispatchReport(1, 1, 0, 1, 0, 0),
            new WebAdminOutboxDispatchReport(1, 1, 0, 0, 1, 0),
            new WebAdminOutboxDispatchReport(1, 1, 0, 0, 0, 1),
        ] as $failedDispatch) {
            self::assertFalse((new BootstrapOnboardingResult(
                BootstrapResult::alreadyCompleted(),
                $failedDispatch,
                $readiness
            ))->isReady());
        }

        self::assertFalse((new BootstrapOnboardingResult(
            BootstrapResult::alreadyCompleted(),
            new WebAdminOutboxDispatchReport(0, 0, 0, 0, 0, 0),
            new BootstrapInvitationReadiness(1, 0, 1)
        ))->isReady());
    }

    private function deliverBoth(): void
    {
        foreach ($this->owners as $roleCode => $userId) {
            $this->tokens[$roleCode] = $this->insertDeliveredInvitation(
                $userId,
                $roleCode,
                true
            );
        }
    }

    private function activate(string $roleCode): void
    {
        $userId = $this->owners[$roleCode];
        $statement = $this->pdo->prepare(
            'UPDATE ls_webadmin_credentials SET password_hash = :hash, '
            . 'password_set_at = :time, updated_at = :time '
            . 'WHERE user_id = :user_id'
        );
        $statement->execute([
            'hash' => PasswordHasher::productive()->hash('ValidAccess1!'),
            'time' => self::NOW,
            'user_id' => $userId,
        ]);
        $statement = $this->pdo->prepare(
            "UPDATE ls_webadmin_users SET status = 'active', "
            . 'auth_version = auth_version + 1, activated_at = :time, '
            . 'suspended_at = NULL, updated_at = :time WHERE id = :user_id'
        );
        $statement->execute([
            'time' => self::NOW,
            'user_id' => $userId,
        ]);
        if (isset($this->tokens[$roleCode])) {
            $this->pdo->exec(
                "UPDATE ls_webadmin_action_tokens SET used_at = "
                . "'2026-08-01 03:00:00.000000' WHERE id = "
                . $this->tokens[$roleCode]
            );
        }
    }

    private function insertDeliveredInvitation(
        int $userId,
        string $fixtureKey,
        bool $usePendingOutbox
    ): int {
        $statement = $this->pdo->prepare(
            'SELECT auth_version FROM ls_webadmin_users WHERE id = ?'
        );
        $statement->execute([$userId]);
        $authVersion = (int) $statement->fetchColumn();
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_action_tokens '
            . '(user_id, purpose, token_hash, auth_version, '
            . 'created_by_user_id, created_at, expires_at, delivered_at, '
            . 'used_at, revoked_at) VALUES '
            . "(:user_id, 'invite', :token_hash, :auth_version, NULL, "
            . ':created_at, :expires_at, :delivered_at, NULL, NULL)'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $fixtureKey),
            'auth_version' => $authVersion,
            'created_at' => '2026-08-01 02:00:00.000000',
            'expires_at' => '2026-08-04 02:00:00.000000',
            'delivered_at' => '2026-08-01 02:10:00.000000',
        ]);
        $tokenId = (int) $this->pdo->lastInsertId();

        if ($usePendingOutbox) {
            $statement = $this->pdo->prepare(
                "UPDATE ls_webadmin_outbox SET status = 'sent', attempts = 1, "
                . 'action_token_id = :token_id, sent_at = :sent_at '
                . "WHERE user_id = :user_id AND kind = 'invite' "
                . "AND status = 'pending'"
            );
            $statement->execute([
                'token_id' => $tokenId,
                'sent_at' => '2026-08-01 02:10:00.000000',
                'user_id' => $userId,
            ]);
            self::assertSame(1, $statement->rowCount());
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO ls_webadmin_outbox '
                . '(kind, user_id, locale, status, attempts, available_at, '
                . 'locked_at, lock_token_hash, action_token_id, '
                . 'last_error_code, created_at, sent_at) VALUES '
                . "('invite', :user_id, 'und', 'sent', 1, :created_at, "
                . 'NULL, NULL, :token_id, NULL, :created_at, :sent_at)'
            );
            $statement->execute([
                'user_id' => $userId,
                'created_at' => '2026-08-01 02:00:00.000000',
                'token_id' => $tokenId,
                'sent_at' => '2026-08-01 02:10:00.000000',
            ]);
        }

        return $tokenId;
    }

    private function insertOpenInvitation(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_outbox '
            . '(kind, user_id, locale, status, attempts, available_at, '
            . 'created_at) VALUES '
            . "('invite', :user_id, 'und', 'pending', 0, :time, :time)"
        );
        $statement->execute([
            'user_id' => $userId,
            'time' => '2026-08-01 02:00:00.000000',
        ]);
    }

    private function applySchema(): void
    {
        $scope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        $found = false;
        foreach (WebAdminMigrationProvider::migrations() as $migration) {
            if ($migration->id() !== '0001_webadmin_identity_and_access') {
                continue;
            }
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                $this->pdo->exec($sql);
            }
            $found = true;
            break;
        }
        if (!$found) {
            throw new RuntimeException('WebAdmin base migration is missing.');
        }
    }
}

final class InvitationAudienceFixedClock implements ClockInterface
{
    private readonly DateTimeImmutable $now;

    public function __construct(string $now)
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class InvitationAudienceUuidSequence implements UuidGeneratorInterface
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
