<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Bootstrap;

use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\EmailAddress;
use App\Core\WebAdmin\Security\InvalidEmailAddress;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Creates the two initial WebAdmin identities after the schema migration.
 *
 * The environment array must already be loaded by the application boundary.
 * This service never reads .env and never returns, logs or embeds either email.
 */
final class WebAdminBootstrapService
{
    /** @var array<string, array{label: string, capabilities: list<string>}> */
    private const ACCOUNT_SPECS = [
        'system_superadmin' => [
            'label' => 'webadmin.roles.system_superadmin',
            'capabilities' => [
                'webadmin.access',
                'webadmin.audit.view',
                'webadmin.profile.manage_self',
                'webadmin.system.diagnose',
                'webadmin.users.capabilities.manage',
                'webadmin.users.invite',
                'webadmin.users.suspend',
                'webadmin.users.view',
            ],
        ],
        'site_admin' => [
            'label' => 'webadmin.roles.site_admin',
            'capabilities' => [
                'webadmin.access',
                'webadmin.audit.view',
                'webadmin.profile.manage_self',
                'webadmin.users.capabilities.manage',
                'webadmin.users.invite',
                'webadmin.users.suspend',
                'webadmin.users.view',
            ],
        ],
    ];

    /** @var array<string, array{label: string, delegable: int}> */
    private const CAPABILITY_SPECS = [
        'webadmin.access' => [
            'label' => 'webadmin.capabilities.access',
            'delegable' => 0,
        ],
        'webadmin.audit.view' => [
            'label' => 'webadmin.capabilities.audit_view',
            'delegable' => 0,
        ],
        'webadmin.profile.manage_self' => [
            'label' => 'webadmin.capabilities.profile_manage_self',
            'delegable' => 0,
        ],
        'webadmin.system.diagnose' => [
            'label' => 'webadmin.capabilities.system_diagnose',
            'delegable' => 0,
        ],
        'webadmin.users.capabilities.manage' => [
            'label' => 'webadmin.capabilities.users_capabilities_manage',
            'delegable' => 0,
        ],
        'webadmin.users.invite' => [
            'label' => 'webadmin.capabilities.users_invite',
            'delegable' => 0,
        ],
        'webadmin.users.suspend' => [
            'label' => 'webadmin.capabilities.users_suspend',
            'delegable' => 0,
        ],
        'webadmin.users.view' => [
            'label' => 'webadmin.capabilities.users_view',
            'delegable' => 1,
        ],
    ];

    /**
     * Additive WebAdmin features may extend protected roles after 0001. They
     * are optional as one complete, exact set so bootstrap remains compatible
     * both before and after the feature migration without accepting arbitrary
     * privilege drift.
     *
     * @var array<string, array{label: string, delegable: int}>
     */
    private const OPTIONAL_CAPABILITY_SPECS = [
        'webadmin.media.upload' => [
            'label' => 'webadmin.capabilities.media_upload',
            'delegable' => 1,
        ],
        'webadmin.media.view' => [
            'label' => 'webadmin.capabilities.media_view',
            'delegable' => 1,
        ],
    ];

    private readonly ClockInterface $clock;
    private readonly UuidGeneratorInterface $uuidGenerator;

    public function __construct(
        private readonly PDO $pdo,
        private readonly WebAdminConfig $config,
        ?ClockInterface $clock = null,
        ?UuidGeneratorInterface $uuidGenerator = null
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->uuidGenerator = $uuidGenerator ?? new RandomUuidV4Generator();
    }

    /**
     * @param array<string, mixed> $loadedEnvironment
     */
    public function bootstrap(array $loadedEnvironment): BootstrapResult
    {
        try {
            $tables = WebAdminTableNames::fromPdo(
                $this->pdo,
                $this->config->tablePrefix()
            );
        } catch (Throwable) {
            throw new BootstrapException('bootstrap.persistence_unavailable');
        }

        $repository = new PdoBootstrapRepository($this->pdo, $tables);

        return $repository->transaction(function (
            PdoBootstrapRepository $repository
        ) use ($loadedEnvironment): BootstrapResult {
            $state = $repository->lockInitialAccountsState();

            // Database truth wins over future environment changes. This branch
            // deliberately precedes every access to the email array.
            if ($state === PdoBootstrapRepository::STATE_COMPLETED) {
                $this->assertCompletedState($repository);

                return BootstrapResult::alreadyCompleted();
            }

            $emails = $this->bootstrapEmails($loadedEnvironment);
            $timestamp = $this->utcTimestamp();
            $requestId = $this->uuidV4();
            $plans = [];

            foreach (self::ACCOUNT_SPECS as $roleCode => $spec) {
                $plans[$roleCode] = $this->planAccount(
                    $repository,
                    $roleCode,
                    $spec,
                    $emails[$roleCode],
                    $timestamp
                );
            }

            $created = 0;
            $reconciled = 0;
            $queued = 0;

            foreach ($plans as $plan) {
                if ($plan['user_id'] === null) {
                    $publicId = $this->uuidV4();
                    $userId = $repository->insertInvitedUser(
                        $publicId,
                        $plan['email'],
                        $timestamp
                    );
                    $repository->insertNullCredential($userId, $timestamp);
                    $repository->insertBootstrapRole(
                        $userId,
                        $plan['role_id'],
                        $timestamp
                    );
                    $repository->insertPendingInvite($userId, $timestamp);
                    $repository->insertAuditEvent(
                        $requestId,
                        'webadmin.bootstrap.identity_created',
                        $publicId,
                        $this->metadata(['role' => $plan['role_code']]),
                        $timestamp
                    );
                    $repository->insertAuditEvent(
                        $requestId,
                        'webadmin.bootstrap.role_assigned',
                        $publicId,
                        $this->metadata(['role' => $plan['role_code']]),
                        $timestamp
                    );
                    $repository->insertAuditEvent(
                        $requestId,
                        'webadmin.bootstrap.invite_queued',
                        $publicId,
                        null,
                        $timestamp
                    );
                    ++$created;
                    ++$queued;

                    continue;
                }

                $userId = $plan['user_id'];
                if ($plan['needs_credential']) {
                    $repository->insertNullCredential($userId, $timestamp);
                }
                if ($plan['needs_invite']) {
                    $repository->insertPendingInvite($userId, $timestamp);
                    ++$queued;
                }
                $repository->insertAuditEvent(
                    $requestId,
                    'webadmin.bootstrap.identity_reconciled',
                    $plan['public_id'],
                    $this->metadata(['role' => $plan['role_code']]),
                    $timestamp
                );
                if ($plan['needs_invite']) {
                    $repository->insertAuditEvent(
                        $requestId,
                        'webadmin.bootstrap.invite_queued',
                        $plan['public_id'],
                        null,
                        $timestamp
                    );
                }
                ++$reconciled;
            }

            $repository->insertAuditEvent(
                $requestId,
                'webadmin.bootstrap.completed',
                null,
                $this->metadata([
                    'created_accounts' => $created,
                    'reconciled_accounts' => $reconciled,
                    'queued_invites' => $queued,
                ]),
                $timestamp
            );

            // This is intentionally the final data mutation in the unit of
            // work. A failure before it leaves the state pending by rollback.
            $repository->markInitialAccountsCompleted($timestamp);

            return BootstrapResult::completed($created, $reconciled, $queued);
        });
    }

    /**
     * Explicit recovery for initial identities whose invitation was already
     * sent or ended in a terminal failure but which remain unactivated.
     * Pending/processing deliveries are never duplicated.
     */
    public function resendInvitations(): BootstrapInvitationResendResult
    {
        try {
            $tables = WebAdminTableNames::fromPdo(
                $this->pdo,
                $this->config->tablePrefix()
            );
        } catch (Throwable) {
            throw new BootstrapException('bootstrap.persistence_unavailable');
        }

        $repository = new PdoBootstrapRepository($this->pdo, $tables);

        return $repository->transaction(function (
            PdoBootstrapRepository $repository
        ): BootstrapInvitationResendResult {
            if (
                $repository->lockInitialAccountsState()
                    !== PdoBootstrapRepository::STATE_COMPLETED
            ) {
                throw new BootstrapException(
                    'bootstrap.resend_requires_completed'
                );
            }

            $this->assertCompletedState($repository);
            $timestamp = $this->utcTimestamp();
            $requestId = $this->uuidV4();
            $queued = 0;
            $skipped = 0;

            foreach (self::ACCOUNT_SPECS as $roleCode => $spec) {
                $roleId = $this->protectedRoleId(
                    $repository,
                    $roleCode,
                    $spec
                );
                $owners = $repository->roleOwners($roleId);
                if (
                    count($owners) !== 1
                    || !$this->positiveInteger(
                        $owners[0]['user_id'] ?? null
                    )
                ) {
                    throw new BootstrapException(
                        'bootstrap.completed_state_incompatible'
                    );
                }

                $userId = (int) $owners[0]['user_id'];
                $user = $repository->userByIdForUpdate($userId);
                if (!$this->resendEligibleUser(
                    $repository,
                    $userId,
                    $user
                )) {
                    ++$skipped;
                    continue;
                }

                $outbox = $repository->inviteOutboxForUser($userId);
                $hasOpenDelivery = false;
                foreach ($outbox as $row) {
                    if (in_array(
                        $row['status'] ?? null,
                        ['pending', 'processing'],
                        true
                    )) {
                        $hasOpenDelivery = true;
                        break;
                    }
                }
                if ($hasOpenDelivery) {
                    ++$skipped;
                    continue;
                }

                $repository->revokeLiveInvitationTokens(
                    $userId,
                    $timestamp
                );
                $repository->insertPendingInvite($userId, $timestamp);
                $repository->insertAuditEvent(
                    $requestId,
                    'webadmin.bootstrap.invite_requeued',
                    (string) $user['public_id'],
                    $this->metadata(['role' => $roleCode]),
                    $timestamp
                );
                ++$queued;
            }

            if ($queued > 0) {
                $repository->insertAuditEvent(
                    $requestId,
                    'webadmin.bootstrap.invites_requeued',
                    null,
                    $this->metadata([
                        'queued_invites' => $queued,
                        'skipped_identities' => $skipped,
                    ]),
                    $timestamp
                );
            }

            return new BootstrapInvitationResendResult($queued, $skipped);
        });
    }

    /** @param array<string, mixed>|null $user */
    private function resendEligibleUser(
        PdoBootstrapRepository $repository,
        int $userId,
        ?array $user
    ): bool {
        if (
            $user === null
            || !$this->compatibleCompletedUser($user)
            || ($user['status'] ?? null) !== 'invited'
            || (int) ($user['auth_version'] ?? 0) >= PHP_INT_MAX
        ) {
            return false;
        }

        $credential = $repository->credentialForUser($userId);

        return $credential !== null
            && $this->integerEquals(
                $credential['user_id'] ?? null,
                $userId
            )
            && ($credential['password_hash'] ?? null) === null
            && ($credential['password_set_at'] ?? null) === null;
    }

    /**
     * @param array<string, mixed> $environment
     * @return array{system_superadmin: string, site_admin: string}
     */
    private function bootstrapEmails(array $environment): array
    {
        $emails = [];
        foreach (WebAdminConfig::BOOTSTRAP_EMAIL_ENV as $role => $name) {
            if (
                !array_key_exists($name, $environment)
                || $environment[$name] === ''
                || $environment[$name] === null
            ) {
                throw new BootstrapException(
                    'bootstrap.environment_missing'
                );
            }
            if (!is_string($environment[$name])) {
                throw new BootstrapException(
                    'bootstrap.environment_invalid'
                );
            }

            try {
                $emails[$role] = EmailAddress::fromString(
                    $environment[$name]
                );
            } catch (InvalidEmailAddress) {
                throw new BootstrapException(
                    'bootstrap.environment_invalid'
                );
            }
        }

        if ($emails['system_superadmin']->equals($emails['site_admin'])) {
            throw new BootstrapException('bootstrap.identities_not_distinct');
        }

        return [
            'system_superadmin' => $emails['system_superadmin']->value(),
            'site_admin' => $emails['site_admin']->value(),
        ];
    }

    /**
     * @param array{label: string, capabilities: list<string>} $spec
     * @return array{
     *   email: string,
     *   role_code: string,
     *   role_id: int,
     *   user_id: ?int,
     *   public_id: ?string,
     *   needs_credential: bool,
     *   needs_invite: bool
     * }
     */
    private function planAccount(
        PdoBootstrapRepository $repository,
        string $roleCode,
        array $spec,
        string $email,
        string $timestamp
    ): array {
        $roleId = $this->protectedRoleId(
            $repository,
            $roleCode,
            $spec
        );

        $owners = $repository->roleOwners($roleId);
        if (count($owners) > 1) {
            throw new BootstrapException('bootstrap.role_already_owned');
        }

        $user = $repository->userByEmail($email);
        if ($user === null) {
            if ($owners !== []) {
                throw new BootstrapException(
                    'bootstrap.role_already_owned'
                );
            }

            return [
                'email' => $email,
                'role_code' => $roleCode,
                'role_id' => $roleId,
                'user_id' => null,
                'public_id' => null,
                'needs_credential' => false,
                'needs_invite' => false,
            ];
        }

        if (!$this->compatibleInvitedUser($user, $email)) {
            throw new BootstrapException('bootstrap.identity_collision');
        }
        $userId = (int) $user['id'];
        $assignment = $repository->roleAssignment($userId, $roleId);
        if (
            count($owners) !== 1
            || $assignment === null
            || !$this->integerEquals($assignment['user_id'] ?? null, $userId)
            || !$this->integerEquals($assignment['role_id'] ?? null, $roleId)
            || ($assignment['source'] ?? null) !== 'bootstrap'
            || ($assignment['assigned_by_user_id'] ?? null) !== null
            || ($owners[0]['email_canonical'] ?? null) !== $email
            || ($owners[0]['source'] ?? null) !== 'bootstrap'
        ) {
            throw new BootstrapException('bootstrap.identity_collision');
        }

        $credential = $repository->credentialForUser($userId);
        if (
            $credential !== null
            && (
                ($credential['password_hash'] ?? null) !== null
                || ($credential['password_set_at'] ?? null) !== null
            )
        ) {
            throw new BootstrapException('bootstrap.credential_collision');
        }

        $outbox = $repository->inviteOutboxForUser($userId);
        if (count($outbox) > 1) {
            throw new BootstrapException('bootstrap.outbox_collision');
        }
        if (
            $outbox !== []
            && !$this->compatiblePendingInvite($outbox[0], $timestamp)
        ) {
            throw new BootstrapException('bootstrap.outbox_collision');
        }
        if (
            $repository->directCapabilityCount($userId) !== 0
            || $repository->sessionCount($userId) !== 0
            || $repository->actionTokenCount($userId) !== 0
        ) {
            throw new BootstrapException('bootstrap.identity_collision');
        }

        return [
            'email' => $email,
            'role_code' => $roleCode,
            'role_id' => $roleId,
            'user_id' => $userId,
            'public_id' => $user['public_id'],
            'needs_credential' => $credential === null,
            'needs_invite' => $outbox === [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $requiredCodes
     */
    private function assertRoleCapabilities(
        array $rows,
        array $requiredCodes,
        bool $mediaFeatureApplied
    ): void {
        $actual = [];
        $optionalPresent = [];
        foreach ($rows as $row) {
            $code = $row['code'] ?? null;
            if (!is_string($code) || isset($actual[$code])) {
                throw new BootstrapException(
                    'bootstrap.capability_incompatible'
                );
            }
            $actual[$code] = $row;

            // Capabilities contributed by a dependent module are preserved.
            // An unexpected WebAdmin capability on a protected role is a
            // privilege-contract mismatch and must never be accepted silently.
            if (
                ($row['module_id'] ?? null) === 'webadmin'
                && !in_array($code, $requiredCodes, true)
            ) {
                $expected = self::OPTIONAL_CAPABILITY_SPECS[$code] ?? null;
                if (
                    $expected === null
                    || ($row['label_key'] ?? null) !== $expected['label']
                    || !$this->integerEquals(
                        $row['is_delegable'] ?? null,
                        $expected['delegable']
                    )
                ) {
                    throw new BootstrapException(
                        'bootstrap.capability_incompatible'
                    );
                }
                $optionalPresent[$code] = true;
            }
        }

        $actualOptional = array_keys($optionalPresent);
        $expectedOptional = $mediaFeatureApplied
            ? array_keys(self::OPTIONAL_CAPABILITY_SPECS)
            : [];
        sort($actualOptional, SORT_STRING);
        sort($expectedOptional, SORT_STRING);
        if ($actualOptional !== $expectedOptional) {
            throw new BootstrapException(
                'bootstrap.capability_incompatible'
            );
        }

        foreach ($requiredCodes as $code) {
            $expected = self::CAPABILITY_SPECS[$code] ?? null;
            $row = $actual[$code] ?? null;
            if (
                $expected === null
                || $row === null
                || ($row['module_id'] ?? null) !== 'webadmin'
                || ($row['label_key'] ?? null) !== $expected['label']
                || !$this->integerEquals(
                    $row['is_delegable'] ?? null,
                    $expected['delegable']
                )
            ) {
                throw new BootstrapException(
                    'bootstrap.capability_incompatible'
                );
            }
        }
    }

    /** @param array<string, mixed> $user */
    private function compatibleInvitedUser(array $user, string $email): bool
    {
        return $this->positiveInteger($user['id'] ?? null)
            && is_string($user['public_id'] ?? null)
            && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $user['public_id']
            ) === 1
            && ($user['email_canonical'] ?? null) === $email
            && ($user['status'] ?? null) === 'invited'
            && $this->integerEquals($user['auth_version'] ?? null, 1)
            && ($user['created_by_user_id'] ?? null) === null
            && $this->validUtcTimestamp($user['invited_at'] ?? null)
            && ($user['activated_at'] ?? null) === null
            && ($user['suspended_at'] ?? null) === null;
    }

    /** @param array<string, mixed> $row */
    private function compatiblePendingInvite(
        array $row,
        string $currentTimestamp
    ): bool
    {
        $locale = $row['locale'] ?? null;
        $availableAt = $row['available_at'] ?? null;
        $createdAt = $row['created_at'] ?? null;

        return is_string($locale)
            && strlen($locale) <= 16
            && preg_match('/\A[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/', $locale) === 1
            && ($row['status'] ?? null) === 'pending'
            && $this->integerEquals($row['attempts'] ?? null, 0)
            && ($row['locked_at'] ?? null) === null
            && ($row['lock_token_hash'] ?? null) === null
            && ($row['action_token_id'] ?? null) === null
            && ($row['last_error_code'] ?? null) === null
            && ($row['sent_at'] ?? null) === null
            && $this->validUtcTimestamp($createdAt)
            && $this->validUtcTimestamp($availableAt)
            && $createdAt <= $availableAt
            && $availableAt <= $currentTimestamp;
    }

    /**
     * @param array{label: string, capabilities: list<string>} $spec
     */
    private function protectedRoleId(
        PdoBootstrapRepository $repository,
        string $roleCode,
        array $spec
    ): int {
        $role = $repository->roleByCode($roleCode);
        if (
            $role === null
            || !$this->positiveInteger($role['id'] ?? null)
            || ($role['code'] ?? null) !== $roleCode
            || ($role['label_key'] ?? null) !== $spec['label']
            || !$this->integerEquals($role['is_protected'] ?? null, 1)
            || !$this->integerEquals($role['is_delegable'] ?? null, 0)
        ) {
            throw new BootstrapException('bootstrap.role_incompatible');
        }

        $roleId = (int) $role['id'];
        $this->assertRoleCapabilities(
            $repository->roleCapabilities($roleId),
            $spec['capabilities'],
            $repository->mediaFeatureIsApplied()
        );

        return $roleId;
    }

    private function assertCompletedState(
        PdoBootstrapRepository $repository
    ): void {
        $protectedOwners = [];
        foreach (self::ACCOUNT_SPECS as $roleCode => $spec) {
            $roleId = $this->protectedRoleId(
                $repository,
                $roleCode,
                $spec
            );
            $owners = $repository->roleOwners($roleId);
            if (
                count($owners) !== 1
                || ($owners[0]['source'] ?? null) !== 'bootstrap'
                || !$this->positiveInteger($owners[0]['user_id'] ?? null)
            ) {
                throw new BootstrapException(
                    'bootstrap.completed_state_incompatible'
                );
            }

            $userId = (int) $owners[0]['user_id'];
            $user = $repository->userById($userId);
            $assignment = $repository->roleAssignment($userId, $roleId);
            $credential = $repository->credentialForUser($userId);
            if (
                !$this->compatibleCompletedUser($user)
                || ($owners[0]['email_canonical'] ?? null)
                    !== ($user['email_canonical'] ?? null)
                || $assignment === null
                || ($assignment['source'] ?? null) !== 'bootstrap'
                || ($assignment['assigned_by_user_id'] ?? null) !== null
                || $credential === null
            ) {
                throw new BootstrapException(
                    'bootstrap.completed_state_incompatible'
                );
            }

            $protectedOwners[] = $userId;
        }

        if (count(array_unique($protectedOwners)) !== count($protectedOwners)) {
            throw new BootstrapException(
                'bootstrap.completed_state_incompatible'
            );
        }
    }

    /** @param array<string, mixed>|null $user */
    private function compatibleCompletedUser(?array $user): bool
    {
        if (
            $user === null
            || !$this->positiveInteger($user['id'] ?? null)
            || !is_string($user['public_id'] ?? null)
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $user['public_id']
            ) !== 1
            || !is_string($user['email_canonical'] ?? null)
            || !$this->positiveInteger($user['auth_version'] ?? null)
            || ($user['created_by_user_id'] ?? null) !== null
            || !in_array(
                $user['status'] ?? null,
                ['invited', 'active', 'suspended'],
                true
            )
        ) {
            return false;
        }

        try {
            $email = EmailAddress::fromString($user['email_canonical']);
        } catch (InvalidEmailAddress) {
            return false;
        }

        if ($email->value() !== $user['email_canonical']) {
            return false;
        }

        $status = $user['status'];
        if (!$this->validUtcTimestamp($user['invited_at'] ?? null)) {
            return false;
        }
        if ($status === 'invited') {
            return ($user['activated_at'] ?? null) === null
                && ($user['suspended_at'] ?? null) === null;
        }
        if (!$this->validUtcTimestamp($user['activated_at'] ?? null)) {
            return false;
        }

        return $status === 'active'
            ? ($user['suspended_at'] ?? null) === null
            : $this->validUtcTimestamp($user['suspended_at'] ?? null);
    }

    private function validUtcTimestamp(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed instanceof DateTimeImmutable
            && ($errors === false || (
                $errors['warning_count'] === 0
                && $errors['error_count'] === 0
            ))
            && $parsed->format('Y-m-d H:i:s.u') === $value;
    }

    private function utcTimestamp(): string
    {
        try {
            return $this->clock->now()
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            throw new BootstrapException('bootstrap.clock_failed');
        }
    }

    private function uuidV4(): string
    {
        try {
            $uuid = $this->uuidGenerator->generateV4();
        } catch (Throwable) {
            throw new BootstrapException('bootstrap.uuid_failed');
        }

        if (preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $uuid
        ) !== 1) {
            throw new BootstrapException('bootstrap.uuid_failed');
        }

        return $uuid;
    }

    /** @param array<string, int|string> $values */
    private function metadata(array $values): string
    {
        try {
            return json_encode(
                $values,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (Throwable) {
            throw new BootstrapException('bootstrap.audit_failed');
        }
    }

    private function positiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_INT) !== false
            && (int) $value > 0;
    }

    private function integerEquals(mixed $value, int $expected): bool
    {
        return $value === $expected || $value === (string) $expected;
    }
}
