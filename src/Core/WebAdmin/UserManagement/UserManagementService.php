<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\UserManagement;

use App\Core\WebAdmin\Authentication\SessionSecrets;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Security\ConstantTime;
use App\Core\WebAdmin\Security\EmailAddress;
use App\Core\WebAdmin\Security\ExceptionTraceGuard;
use App\Core\WebAdmin\Security\InvalidEmailAddress;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use App\Core\WebAdmin\Support\WebAdminLocale;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Application service for the bounded editor-management surface.
 *
 * Every mutation authenticates SID + CSRF again while holding the actor user
 * and session locks. Target identities, roles, capabilities and lifecycle are
 * then re-read under the same transaction before any write.
 */
final class UserManagementService
{
    private const ACCESS = 'webadmin.access';
    private const VIEW = 'webadmin.users.view';
    private const INVITE = 'webadmin.users.invite';
    private const SUSPEND = 'webadmin.users.suspend';
    private const MANAGE_CAPABILITIES =
        'webadmin.users.capabilities.manage';
    private const CSRF_PURPOSE = 'csrf.session';
    private const CURSOR_PURPOSE = 'cursor.editors';
    private const MAX_PAGE_SIZE = 100;
    private const MAX_CAPABILITIES_PER_EDITOR = 128;

    private readonly PasswordHasher $passwordHasher;

    public function __construct(
        private readonly UserManagementRepository $repository,
        private readonly ActiveModuleSet $activeModules,
        private readonly WebAdminConfig $config,
        private readonly SecurityKey $securityKey,
        private readonly ClockInterface $clock,
        private readonly UuidGeneratorInterface $uuidGenerator,
        ?PasswordHasher $passwordHasher = null,
        private readonly SecureTokenGenerator $tokenGenerator =
            new SecureTokenGenerator()
    ) {
        ExceptionTraceGuard::assertEnabled();
        $this->passwordHasher = $passwordHasher
            ?? PasswordHasher::productive();
    }

    public function listEditors(
        #[\SensitiveParameter] string $sessionToken,
        int $limit = 25,
        ?string $cursor = null
    ): ?EditorPage {
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            return null;
        }
        $decodedCursor = $this->decodeCursor($cursor);
        $reference = $this->actorReference($sessionToken);
        if ($reference === null) {
            return null;
        }
        $now = $this->now();

        return $this->repository->transaction(function () use (
            $sessionToken,
            $limit,
            $decodedCursor,
            $reference,
            $now
        ): ?EditorPage {
            $users = $this->repository->lockUsers([$reference['user_id']]);
            $actor = $this->resolveActor(
                $sessionToken,
                null,
                $reference,
                $users,
                $now
            );
            if ($actor === null || !$this->hasCapabilities($actor, [self::VIEW])) {
                return null;
            }

            if ($decodedCursor === null) {
                return null;
            }
            $afterId = 0;
            $cursorPublicId = $decodedCursor['public_id'];
            if ($cursorPublicId !== null) {
                $cursorReference = $this->repository
                    ->editorCursorReference($cursorPublicId);
                if ($cursorReference === null) {
                    return null;
                }
                $afterId = $this->positiveInteger(
                    $cursorReference['id'] ?? null
                );
            }

            $rows = $this->repository->editorPageRows($afterId, $limit + 1);
            $hasNext = count($rows) > $limit;
            if ($hasNext) {
                array_pop($rows);
            }
            $editors = array_map(
                fn (array $row): EditorSummary => $this->summary($row),
                $rows
            );
            $nextCursor = null;
            if ($hasNext && $rows !== []) {
                $last = $rows[array_key_last($rows)];
                $nextCursor = $this->encodeCursor(
                    $this->string($last['public_id'] ?? null)
                );
            }

            return new EditorPage($editors, $nextCursor);
        });
    }

    public function editorDetail(
        #[\SensitiveParameter] string $sessionToken,
        string $targetPublicId
    ): ?EditorDetail {
        if (!$this->isUuid($targetPublicId)) {
            return null;
        }
        $reference = $this->actorReference($sessionToken);
        if ($reference === null) {
            return null;
        }
        $now = $this->now();

        return $this->repository->transaction(function () use (
            $sessionToken,
            $targetPublicId,
            $reference,
            $now
        ): ?EditorDetail {
            $users = $this->repository->lockUsers([$reference['user_id']]);
            $actor = $this->resolveActor(
                $sessionToken,
                null,
                $reference,
                $users,
                $now
            );
            if ($actor === null || !$this->hasCapabilities($actor, [self::VIEW])) {
                return null;
            }
            $row = $this->repository->editorRowByPublicId($targetPublicId);
            if ($row === null) {
                return null;
            }

            return $this->detail($row);
        });
    }

    public function delegableCapabilities(
        #[\SensitiveParameter] string $sessionToken
    ): ?DelegableCapabilityCatalog {
        $reference = $this->actorReference($sessionToken);
        if ($reference === null) {
            return null;
        }
        $now = $this->now();

        return $this->repository->transaction(function () use (
            $sessionToken,
            $reference,
            $now
        ): ?DelegableCapabilityCatalog {
            $users = $this->repository->lockUsers([$reference['user_id']]);
            $actor = $this->resolveActor(
                $sessionToken,
                null,
                $reference,
                $users,
                $now
            );
            if (
                $actor === null
                || !$this->hasCapabilities($actor, [self::MANAGE_CAPABILITIES])
            ) {
                return null;
            }

            return new DelegableCapabilityCatalog(array_values(array_map(
                static fn (array $row): DelegableCapability =>
                    new DelegableCapability(
                        (string) $row['module_id'],
                        (string) $row['code'],
                        (string) $row['label_key']
                    ),
                $this->manageableCapabilities($actor)
            )));
        });
    }

    /** @param list<string> $capabilityCodes */
    public function inviteEditor(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        #[\SensitiveParameter] string $displayName,
        #[\SensitiveParameter] string $email,
        array $capabilityCodes = [],
        string $locale = WebAdminLocale::UNDETERMINED
    ): EditorInviteResult {
        $normalizedName = $this->displayName($displayName);
        $normalizedEmail = $this->email($email);
        $requested = $this->capabilityCodes($capabilityCodes);
        $normalizedLocale = WebAdminLocale::normalize($locale);
        $reference = $this->actorReference($sessionToken);
        if ($reference === null) {
            return EditorInviteResult::failed(EditorInviteResult::DENIED);
        }
        $existing = $normalizedEmail === null
            ? null
            : $this->repository->userReferenceByEmail($normalizedEmail);
        $userIds = [$reference['user_id']];
        if ($existing !== null) {
            $existingId = $this->nullablePositiveInteger($existing['id'] ?? null);
            if ($existingId !== null) {
                $userIds[] = $existingId;
            }
        }
        $now = $this->now();

        return $this->repository->transaction(function () use (
            $sessionToken,
            $csrfToken,
            $normalizedName,
            $normalizedEmail,
            $requested,
            $normalizedLocale,
            $reference,
            $userIds,
            $now
        ): EditorInviteResult {
            $users = $this->repository->lockUsers($userIds);
            $actor = $this->resolveActor(
                $sessionToken,
                $csrfToken,
                $reference,
                $users,
                $now
            );
            if ($actor === null) {
                return EditorInviteResult::failed(EditorInviteResult::DENIED);
            }
            $required = [self::INVITE];
            if ($requested !== null && $requested !== []) {
                $required[] = self::MANAGE_CAPABILITIES;
            }
            if (!$this->hasCapabilities($actor, $required)) {
                $this->auditDenied($actor, 'webadmin.editor.invite', 'capability_denied', null, $now);

                return EditorInviteResult::failed(EditorInviteResult::DENIED);
            }
            if (!$normalizedName['valid'] || $normalizedEmail === null || $requested === null) {
                $this->auditDenied($actor, 'webadmin.editor.invite', 'input_invalid', null, $now);

                return EditorInviteResult::failed(EditorInviteResult::INVALID);
            }
            $manageable = $this->manageableCapabilities($actor);
            if (!$this->requestedCapabilitiesAllowed($requested, $manageable)) {
                $this->auditDenied($actor, 'webadmin.editor.invite', 'capability_scope', null, $now);

                return EditorInviteResult::failed(EditorInviteResult::DENIED);
            }

            // Re-read under the unique-index lock on every transaction retry.
            if ($this->repository->lockUserByEmail($normalizedEmail) !== null) {
                $this->auditDenied($actor, 'webadmin.editor.invite', 'identity_exists', null, $now);

                return EditorInviteResult::failed(EditorInviteResult::CONFLICT);
            }
            $role = $this->repository->editorRole();
            if (
                $role === null
                || ($role['code'] ?? null) !== 'editor'
                || !$this->isZero($role['is_protected'] ?? null)
                || !$this->isOne($role['is_delegable'] ?? null)
            ) {
                throw new UserManagementStorageException();
            }
            $publicId = $this->uuidGenerator->generateV4();
            if (!$this->isUuid($publicId)) {
                throw new UserManagementStorageException();
            }
            $userId = $this->repository->insertInvitedEditor(
                $publicId,
                $normalizedEmail,
                $normalizedName['value'],
                $actor['user_id'],
                $now
            );
            $this->repository->insertNullCredential($userId, $now);
            $this->repository->assignEditorRole(
                $userId,
                $this->positiveInteger($role['id'] ?? null),
                $actor['user_id'],
                $now
            );
            foreach ($requested as $code) {
                $this->repository->assignDirectCapability(
                    $userId,
                    $this->positiveInteger($manageable[$code]['id'] ?? null),
                    $actor['user_id'],
                    $now
                );
            }
            $this->repository->insertPendingInvitation(
                $userId,
                $normalizedLocale,
                $now
            );
            $this->auditSuccess(
                $actor,
                'webadmin.editor.invited',
                $publicId,
                null,
                $now
            );
            $row = $this->repository->editorRowByPublicId($publicId);
            if ($row === null) {
                throw new UserManagementStorageException();
            }

            return EditorInviteResult::invited($this->detail($row));
        });
    }

    /** @param list<string> $capabilityCodes */
    public function replaceCapabilities(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $targetPublicId,
        array $capabilityCodes
    ): EditorMutationResult {
        $requested = $this->capabilityCodes($capabilityCodes);

        return $this->targetMutation(
            'replace_capabilities',
            $sessionToken,
            $csrfToken,
            $targetPublicId,
            false,
            [self::MANAGE_CAPABILITIES],
            function (array $actor, array $target, array $openOutbox, DateTimeImmutable $now) use (
                $requested
            ): EditorMutationResult {
                $publicId = (string) $target['public_id'];
                if ($requested === null) {
                    $this->auditDenied($actor, 'webadmin.editor.capabilities.replace', 'input_invalid', $publicId, $now);

                    return new EditorMutationResult(
                        'replace_capabilities',
                        EditorMutationResult::INVALID,
                        $publicId
                    );
                }
                if (!$this->hasCapabilities($actor, [self::MANAGE_CAPABILITIES])) {
                    $this->auditDenied($actor, 'webadmin.editor.capabilities.replace', 'capability_denied', $publicId, $now);

                    return new EditorMutationResult(
                        'replace_capabilities',
                        EditorMutationResult::DENIED,
                        $publicId
                    );
                }
                $manageable = $this->manageableCapabilities($actor);
                if (!$this->requestedCapabilitiesAllowed($requested, $manageable)) {
                    $this->auditDenied($actor, 'webadmin.editor.capabilities.replace', 'capability_scope', $publicId, $now);

                    return new EditorMutationResult(
                        'replace_capabilities',
                        EditorMutationResult::DENIED,
                        $publicId
                    );
                }
                $currentRows = $this->repository->lockDirectCapabilityRows(
                    $this->positiveInteger($target['id'] ?? null)
                );
                $currentManageable = [];
                foreach ($currentRows as $row) {
                    $code = $this->capabilityCode($row);
                    if (isset($manageable[$code])) {
                        $currentManageable[$code] = $row;
                    }
                }
                $desired = array_fill_keys($requested, true);
                $removed = array_diff_key($currentManageable, $desired);
                $added = array_diff_key($desired, $currentManageable);
                if ($removed === [] && $added === []) {
                    $this->auditSuccess($actor, 'webadmin.editor.capabilities.replaced', $publicId, 'unchanged', $now);

                    return new EditorMutationResult(
                        'replace_capabilities',
                        EditorMutationResult::UNCHANGED,
                        $publicId
                    );
                }
                $targetId = $this->positiveInteger($target['id'] ?? null);
                foreach ($removed as $row) {
                    $this->repository->removeDirectCapability(
                        $targetId,
                        $this->positiveInteger($row['id'] ?? null)
                    );
                }
                foreach (array_keys($added) as $code) {
                    $this->repository->assignDirectCapability(
                        $targetId,
                        $this->positiveInteger($manageable[$code]['id'] ?? null),
                        $actor['user_id'],
                        $now
                    );
                }
                $affected = count($removed) + count($added);
                $this->auditSuccess($actor, 'webadmin.editor.capabilities.replaced', $publicId, null, $now);

                return new EditorMutationResult(
                    'replace_capabilities',
                    EditorMutationResult::APPLIED,
                    $publicId,
                    $affected
                );
            }
        );
    }

    public function resendInvitation(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $targetPublicId,
        string $locale = WebAdminLocale::UNDETERMINED
    ): EditorMutationResult {
        $normalizedLocale = WebAdminLocale::normalize($locale);

        return $this->targetMutation(
            'resend_invitation',
            $sessionToken,
            $csrfToken,
            $targetPublicId,
            true,
            [self::INVITE],
            function (array $actor, array $target, array $openOutbox, DateTimeImmutable $now) use (
                $normalizedLocale
            ): EditorMutationResult {
                $publicId = (string) $target['public_id'];
                if (!$this->hasCapabilities($actor, [self::INVITE])) {
                    $this->auditDenied($actor, 'webadmin.editor.invitation.resend', 'capability_denied', $publicId, $now);

                    return new EditorMutationResult('resend_invitation', EditorMutationResult::DENIED, $publicId);
                }
                if (!$this->isNeverActivatedInvitation($target)) {
                    $this->auditDenied($actor, 'webadmin.editor.invitation.resend', 'lifecycle_invalid', $publicId, $now);

                    return new EditorMutationResult('resend_invitation', EditorMutationResult::STATE_CONFLICT, $publicId);
                }
                if ($openOutbox !== []) {
                    if (
                        count($openOutbox) === 1
                        && ($openOutbox[0]['kind'] ?? null) === 'invite'
                    ) {
                        $this->auditSuccess($actor, 'webadmin.editor.invitation.resend', $publicId, 'already_queued', $now);

                        return new EditorMutationResult('resend_invitation', EditorMutationResult::ALREADY_QUEUED, $publicId);
                    }
                    $this->auditDenied($actor, 'webadmin.editor.invitation.resend', 'outbox_state_invalid', $publicId, $now);

                    return new EditorMutationResult('resend_invitation', EditorMutationResult::STATE_CONFLICT, $publicId);
                }
                $targetId = $this->positiveInteger($target['id'] ?? null);
                $tokens = $this->repository->lockActionTokensForUser($targetId);
                foreach ($this->liveTokens($tokens) as $token) {
                    if (($token['purpose'] ?? null) !== 'invite') {
                        $this->auditDenied($actor, 'webadmin.editor.invitation.resend', 'action_state_invalid', $publicId, $now);

                        return new EditorMutationResult('resend_invitation', EditorMutationResult::STATE_CONFLICT, $publicId);
                    }
                }
                $sessions = $this->repository->lockTargetSessions($targetId);
                $this->repository->revokeLockedSessions($sessions, $now);
                $this->repository->revokeLiveActionTokens($targetId, $now);
                $this->repository->insertPendingInvitation(
                    $targetId,
                    $normalizedLocale,
                    $now
                );
                $this->auditSuccess($actor, 'webadmin.editor.invitation.resent', $publicId, null, $now);

                return new EditorMutationResult('resend_invitation', EditorMutationResult::APPLIED, $publicId);
            }
        );
    }

    public function suspendEditor(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $targetPublicId
    ): EditorMutationResult {
        return $this->targetMutation(
            'suspend',
            $sessionToken,
            $csrfToken,
            $targetPublicId,
            true,
            [self::SUSPEND],
            function (array $actor, array $target, array $openOutbox, DateTimeImmutable $now): EditorMutationResult {
                $publicId = (string) $target['public_id'];
                if (!$this->hasCapabilities($actor, [self::SUSPEND])) {
                    $this->auditDenied($actor, 'webadmin.editor.suspend', 'capability_denied', $publicId, $now);

                    return new EditorMutationResult('suspend', EditorMutationResult::DENIED, $publicId);
                }
                $status = $target['status'] ?? null;
                if ($status === 'suspended') {
                    $targetId = $this->positiveInteger($target['id'] ?? null);
                    $suspensionDrift = !$this->hasSuspensionRecord($target);
                    $tokens = $this->repository->lockActionTokensForUser(
                        $targetId
                    );
                    $sessions = $this->repository->lockTargetSessions(
                        $targetId
                    );
                    if (
                        $openOutbox !== []
                        || $this->liveTokens($tokens) !== []
                        || $sessions !== []
                        || $suspensionDrift
                    ) {
                        $this->repository->revokeLockedSessions(
                            $sessions,
                            $now
                        );
                        $this->repository->revokeLiveActionTokens(
                            $targetId,
                            $now
                        );
                        $this->repository->terminalizeOpenOutbox(
                            $openOutbox,
                            'outbox.recipient_unavailable'
                        );
                        if ($suspensionDrift) {
                            $this->repository->updateUserStatus(
                                $targetId,
                                $this->positiveInteger(
                                    $target['auth_version'] ?? null
                                ),
                                'suspended',
                                'suspended',
                                $now
                            );
                        }
                        $this->auditSuccess($actor, 'webadmin.editor.suspend', $publicId, 'reconciled', $now);

                        return new EditorMutationResult('suspend', EditorMutationResult::APPLIED, $publicId);
                    }
                    $this->auditSuccess($actor, 'webadmin.editor.suspend', $publicId, 'unchanged', $now);

                    return new EditorMutationResult('suspend', EditorMutationResult::UNCHANGED, $publicId);
                }
                if (!in_array($status, ['active', 'invited'], true)) {
                    $this->auditDenied($actor, 'webadmin.editor.suspend', 'lifecycle_invalid', $publicId, $now);

                    return new EditorMutationResult('suspend', EditorMutationResult::STATE_CONFLICT, $publicId);
                }
                $targetId = $this->positiveInteger($target['id'] ?? null);
                $tokens = $this->repository->lockActionTokensForUser($targetId);
                $sessions = $this->repository->lockTargetSessions($targetId);
                $this->repository->revokeLockedSessions($sessions, $now);
                $this->repository->revokeLiveActionTokens($targetId, $now);
                $this->repository->terminalizeOpenOutbox(
                    $openOutbox,
                    'outbox.recipient_unavailable'
                );
                $this->repository->updateUserStatus(
                    $targetId,
                    $this->positiveInteger($target['auth_version'] ?? null),
                    $status,
                    'suspended',
                    $now
                );
                $this->auditSuccess($actor, 'webadmin.editor.suspended', $publicId, null, $now);

                return new EditorMutationResult('suspend', EditorMutationResult::APPLIED, $publicId);
            }
        );
    }

    public function resumeEditor(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $targetPublicId,
        string $locale = WebAdminLocale::UNDETERMINED
    ): EditorMutationResult {
        $normalizedLocale = WebAdminLocale::normalize($locale);

        return $this->targetMutation(
            'resume',
            $sessionToken,
            $csrfToken,
            $targetPublicId,
            true,
            [self::SUSPEND],
            function (array $actor, array $target, array $openOutbox, DateTimeImmutable $now) use (
                $normalizedLocale
            ): EditorMutationResult {
                $publicId = (string) $target['public_id'];
                if (!$this->hasCapabilities($actor, [self::SUSPEND])) {
                    $this->auditDenied($actor, 'webadmin.editor.resume', 'capability_denied', $publicId, $now);

                    return new EditorMutationResult('resume', EditorMutationResult::DENIED, $publicId);
                }
                if (($target['status'] ?? null) !== 'suspended') {
                    $this->auditSuccess($actor, 'webadmin.editor.resume', $publicId, 'unchanged', $now);

                    return new EditorMutationResult('resume', EditorMutationResult::UNCHANGED, $publicId);
                }
                if (!$this->hasSuspensionRecord($target)) {
                    $this->auditDenied($actor, 'webadmin.editor.resume', 'lifecycle_invalid', $publicId, $now);

                    return new EditorMutationResult('resume', EditorMutationResult::STATE_CONFLICT, $publicId);
                }
                if ($openOutbox !== []) {
                    $this->auditDenied($actor, 'webadmin.editor.resume', 'outbox_state_invalid', $publicId, $now);

                    return new EditorMutationResult('resume', EditorMutationResult::STATE_CONFLICT, $publicId);
                }
                $targetId = $this->positiveInteger($target['id'] ?? null);
                $tokens = $this->repository->lockActionTokensForUser($targetId);
                $sessions = $this->repository->lockTargetSessions($targetId);
                if ($this->liveTokens($tokens) !== [] || $sessions !== []) {
                    $this->auditDenied($actor, 'webadmin.editor.resume', 'action_state_invalid', $publicId, $now);

                    return new EditorMutationResult('resume', EditorMutationResult::STATE_CONFLICT, $publicId);
                }

                $activated = $target['activated_at'] ?? null;
                $nextStatus = 'invited';
                if ($activated !== null) {
                    if (
                        !$this->hasCredentialRecord($target)
                        || !$this->hasActivationRecord($target)
                    ) {
                        $this->auditDenied($actor, 'webadmin.editor.resume', 'credential_state_invalid', $publicId, $now);

                        return new EditorMutationResult('resume', EditorMutationResult::STATE_CONFLICT, $publicId);
                    }
                    $nextStatus = 'active';
                } else {
                    if (
                        !$this->hasCapabilities($actor, [self::INVITE])
                        || $this->nullablePositiveInteger(
                            $target['credential_user_id'] ?? null
                        ) !== $targetId
                        || ($target['password_hash'] ?? null) !== null
                        || ($target['password_set_at'] ?? null) !== null
                    ) {
                        $this->auditDenied($actor, 'webadmin.editor.resume', 'invitation_state_invalid', $publicId, $now);

                        return new EditorMutationResult('resume', EditorMutationResult::STATE_CONFLICT, $publicId);
                    }
                }
                $this->repository->updateUserStatus(
                    $targetId,
                    $this->positiveInteger($target['auth_version'] ?? null),
                    'suspended',
                    $nextStatus,
                    $now
                );
                if ($nextStatus === 'invited') {
                    $this->repository->insertPendingInvitation(
                        $targetId,
                        $normalizedLocale,
                        $now
                    );
                }
                $this->auditSuccess($actor, 'webadmin.editor.resumed', $publicId, null, $now);

                return new EditorMutationResult('resume', EditorMutationResult::APPLIED, $publicId);
            }
        );
    }

    /**
     * @param callable(array<string, mixed>, array<string, mixed>, list<array<string, mixed>>, DateTimeImmutable): EditorMutationResult $operation
     */
    private function targetMutation(
        string $operationName,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $targetPublicId,
        bool $locksOutbox,
        array $requiredCapabilities,
        callable $operation
    ): EditorMutationResult {
        $actorReference = $this->actorReference($sessionToken);
        if ($actorReference === null) {
            return new EditorMutationResult(
                $operationName,
                EditorMutationResult::DENIED
            );
        }
        $validTargetId = $this->isUuid($targetPublicId);
        $targetReference = $validTargetId
            ? $this->repository->userReferenceByPublicId($targetPublicId)
            : null;
        $targetId = $targetReference === null
            ? null
            : $this->nullablePositiveInteger($targetReference['id'] ?? null);
        $now = $this->now();

        return $this->repository->transaction(function () use (
            $operationName,
            $sessionToken,
            $csrfToken,
            $targetPublicId,
            $validTargetId,
            $actorReference,
            $targetId,
            $locksOutbox,
            $requiredCapabilities,
            $operation,
            $now
        ): EditorMutationResult {
            // Dispatcher-compatible order: outbox -> users -> actor session.
            $openOutbox = $locksOutbox && $targetId !== null
                ? $this->repository->lockOpenOutboxForUser($targetId)
                : [];
            $ids = [$actorReference['user_id']];
            if ($targetId !== null) {
                $ids[] = $targetId;
            }
            $users = $this->repository->lockUsers($ids);
            $actor = $this->resolveActor(
                $sessionToken,
                $csrfToken,
                $actorReference,
                $users,
                $now
            );
            if ($actor === null) {
                return new EditorMutationResult(
                    $operationName,
                    EditorMutationResult::DENIED
                );
            }
            if (!$this->hasCapabilities($actor, $requiredCapabilities)) {
                $this->auditDenied(
                    $actor,
                    'webadmin.editor.' . $operationName,
                    'capability_denied',
                    null,
                    $now
                );

                return new EditorMutationResult(
                    $operationName,
                    EditorMutationResult::DENIED
                );
            }
            if (!$validTargetId) {
                $this->auditDenied($actor, 'webadmin.editor.' . $operationName, 'input_invalid', null, $now);

                return new EditorMutationResult(
                    $operationName,
                    EditorMutationResult::INVALID
                );
            }
            if ($targetId === null || !isset($users[$targetId])) {
                $this->auditDenied($actor, 'webadmin.editor.' . $operationName, 'target_not_found', null, $now);

                return new EditorMutationResult(
                    $operationName,
                    EditorMutationResult::NOT_FOUND
                );
            }
            $target = $users[$targetId];
            if (($target['public_id'] ?? null) !== $targetPublicId) {
                throw new UserManagementStorageException();
            }
            $roles = $this->repository->lockRoleAssignments($targetId);
            if (
                $targetId === $actor['user_id']
                || !$this->isEditorRoleSet($roles)
            ) {
                $this->auditDenied($actor, 'webadmin.editor.' . $operationName, 'target_protected', $targetPublicId, $now);

                return new EditorMutationResult(
                    $operationName,
                    EditorMutationResult::DENIED,
                    $targetPublicId
                );
            }

            return $operation($actor, $target, $openOutbox, $now);
        });
    }

    /** @return array{user_id: int, session_public_id: string, capabilities: array<string, array<string, mixed>>}|null */
    private function resolveActor(
        string $sessionToken,
        ?string $submittedCsrf,
        array $reference,
        array $lockedUsers,
        DateTimeImmutable $now
    ): ?array {
        $userId = $this->positiveInteger($reference['user_id'] ?? null);
        $user = $lockedUsers[$userId] ?? null;
        $session = $this->repository->lockSession(
            $this->tokenGenerator->hashForStorage($sessionToken)
        );
        if (!is_array($user) || !is_array($session)) {
            return null;
        }
        $expectedCsrf = $this->securityKey->deriveToken(
            self::CSRF_PURPOSE,
            $sessionToken
        );
        $storedCsrfHash = $session['csrf_token_hash'] ?? null;
        if (
            ($session['session_type'] ?? null) !== SessionSecrets::AUTHENTICATED
            || ($session['revoked_at'] ?? null) !== null
            || ($session['pending_action_token_id'] ?? null) !== null
            || $this->positiveInteger($session['user_id'] ?? null) !== $userId
            || $this->positiveInteger($session['auth_version'] ?? null)
                !== $this->positiveInteger($user['auth_version'] ?? null)
            || !$this->isActiveIdentityLifecycle($user)
            || !is_string($storedCsrfHash)
            || !$this->tokenGenerator->verify($expectedCsrf, $storedCsrfHash)
            || (
                $submittedCsrf !== null
                && (
                    !$this->tokenGenerator->hasValidFormat($submittedCsrf)
                    || !ConstantTime::equals($expectedCsrf, $submittedCsrf)
                )
            )
            || !$this->hasUsableCredential($user)
        ) {
            return null;
        }
        $idle = UserManagementRepository::parseTimestamp(
            $session['idle_expires_at'] ?? null
        );
        $absolute = UserManagementRepository::parseTimestamp(
            $session['absolute_expires_at'] ?? null
        );
        if ($now >= $idle || $now >= $absolute) {
            return null;
        }
        $roles = $this->repository->lockRoleAssignments($userId);
        if ($roles === []) {
            return null;
        }
        $capabilities = [];
        foreach ($this->repository->roleCapabilityRows($userId) as $row) {
            $capabilities[$this->capabilityCode($row)] = $row;
        }
        foreach ($this->repository->lockDirectCapabilityRows($userId) as $row) {
            $capabilities[$this->capabilityCode($row)] = $row;
        }
        if (!isset($capabilities[self::ACCESS])) {
            return null;
        }
        $nextIdle = $now->modify('+' . $this->config->idleTtlSeconds() . ' seconds');
        if ($nextIdle > $absolute) {
            $nextIdle = $absolute;
        }
        $this->repository->touchSession(
            $this->positiveInteger($session['id'] ?? null),
            $now,
            $nextIdle
        );

        return [
            'user_id' => $userId,
            'session_public_id' => $this->string($session['public_id'] ?? null),
            'capabilities' => $capabilities,
        ];
    }

    /** @param array<string, mixed> $actor @param list<string> $required */
    private function hasCapabilities(array $actor, array $required): bool
    {
        foreach ($required as $code) {
            if (!isset($actor['capabilities'][$code])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $actor @return array<string, array<string, mixed>> */
    private function manageableCapabilities(array $actor): array
    {
        $manageable = [];
        foreach ($this->repository->delegableCapabilityRows() as $row) {
            $code = $this->capabilityCode($row);
            $module = $this->string($row['module_id'] ?? null);
            if (
                $this->isOne($row['is_delegable'] ?? null)
                && $this->activeModules->contains($module)
                && isset($actor['capabilities'][$code])
            ) {
                $manageable[$code] = $row;
            }
        }
        ksort($manageable, SORT_STRING);

        return $manageable;
    }

    /** @param list<string> $requested @param array<string, array<string, mixed>> $manageable */
    private function requestedCapabilitiesAllowed(
        array $requested,
        array $manageable
    ): bool {
        foreach ($requested as $code) {
            if (!isset($manageable[$code])) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $roles */
    private function isEditorRoleSet(array $roles): bool
    {
        $editor = false;
        foreach ($roles as $role) {
            if (
                !$this->isZero($role['is_protected'] ?? null)
                || !$this->isOne($role['is_delegable'] ?? null)
            ) {
                return false;
            }
            if (($role['code'] ?? null) === 'editor') {
                $editor = true;
            }
        }

        return $editor;
    }

    /** @param array<string, mixed> $target */
    private function isNeverActivatedInvitation(array $target): bool
    {
        return ($target['status'] ?? null) === 'invited'
            && $this->nullablePositiveInteger(
                $target['credential_user_id'] ?? null
            ) === $this->nullablePositiveInteger($target['id'] ?? null)
            && ($target['activated_at'] ?? null) === null
            && ($target['suspended_at'] ?? null) === null
            && ($target['password_hash'] ?? null) === null
            && ($target['password_set_at'] ?? null) === null;
    }

    /** @param array<string, mixed> $user */
    private function hasUsableCredential(array $user): bool
    {
        if (
            $this->nullablePositiveInteger($user['credential_user_id'] ?? null)
                !== $this->nullablePositiveInteger($user['id'] ?? null)
        ) {
            return false;
        }
        $hash = $user['password_hash'] ?? null;
        $setAt = $user['password_set_at'] ?? null;
        if (!is_string($hash) || $hash === '' || !is_string($setAt)) {
            return false;
        }
        try {
            UserManagementRepository::parseTimestamp($setAt);
        } catch (UserManagementStorageException) {
            return false;
        }

        return $this->passwordHasher->isCurrentHash($hash);
    }

    /** @param array<string, mixed> $user */
    private function hasCredentialRecord(array $user): bool
    {
        if (
            $this->nullablePositiveInteger($user['credential_user_id'] ?? null)
                !== $this->nullablePositiveInteger($user['id'] ?? null)
        ) {
            return false;
        }
        $hash = $user['password_hash'] ?? null;
        $setAt = $user['password_set_at'] ?? null;
        if (!is_string($hash) || $hash === '' || !is_string($setAt)) {
            return false;
        }
        try {
            UserManagementRepository::parseTimestamp($setAt);
        } catch (UserManagementStorageException) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $user */
    private function hasActivationRecord(array $user): bool
    {
        try {
            UserManagementRepository::parseTimestamp(
                $user['activated_at'] ?? null
            );
        } catch (UserManagementStorageException) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $user */
    private function hasSuspensionRecord(array $user): bool
    {
        try {
            UserManagementRepository::parseTimestamp(
                $user['suspended_at'] ?? null
            );
        } catch (UserManagementStorageException) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $user */
    private function isActiveIdentityLifecycle(array $user): bool
    {
        if (
            ($user['status'] ?? null) !== 'active'
            || ($user['suspended_at'] ?? null) !== null
            || !$this->hasActivationRecord($user)
            || !$this->hasUsableCredential($user)
        ) {
            return false;
        }
        return true;
    }

    /** @return array{user_id: int}|null */
    private function actorReference(string $sessionToken): ?array
    {
        if (!$this->tokenGenerator->hasValidFormat($sessionToken)) {
            return null;
        }
        $row = $this->repository->sessionReference(
            $this->tokenGenerator->hashForStorage($sessionToken)
        );
        if (
            $row === null
            || ($row['session_type'] ?? null) !== SessionSecrets::AUTHENTICATED
        ) {
            return null;
        }
        $userId = $this->nullablePositiveInteger($row['user_id'] ?? null);
        if ($userId === null) {
            return null;
        }

        return ['user_id' => $userId];
    }

    /** @param array<string, mixed> $row */
    private function summary(array $row): EditorSummary
    {
        $publicId = $this->string($row['public_id'] ?? null);
        if (!$this->isUuid($publicId)) {
            throw new UserManagementStorageException();
        }
        $storedName = $this->nullableString($row['display_name'] ?? null);
        $name = $this->displayName($storedName ?? '');
        if (!$name['valid']) {
            throw new UserManagementStorageException();
        }

        return new EditorSummary(
            $publicId,
            $this->storedEmail($row['email_canonical'] ?? null),
            $name['value'],
            $this->status($row['status'] ?? null),
            UserManagementRepository::parseTimestamp($row['created_at'] ?? null),
            UserManagementRepository::parseTimestamp($row['updated_at'] ?? null)
        );
    }

    /** @param array<string, mixed> $row */
    private function detail(array $row): EditorDetail
    {
        $userId = $this->positiveInteger($row['id'] ?? null);
        $directRows = $this->repository->directCapabilityRows($userId);
        $effective = [];
        foreach ($this->repository->roleCapabilityRows($userId) as $capability) {
            $effective[$this->capabilityCode($capability)] = true;
        }
        $direct = [];
        foreach ($directRows as $capability) {
            $code = $this->capabilityCode($capability);
            $direct[$code] = true;
            $effective[$code] = true;
        }
        $directCodes = array_keys($direct);
        $effectiveCodes = array_keys($effective);
        sort($directCodes, SORT_STRING);
        sort($effectiveCodes, SORT_STRING);
        $summary = $this->summary($row);

        return new EditorDetail(
            $summary->publicId(),
            $summary->emailCanonical(),
            $summary->displayName(),
            $this->status($row['status'] ?? null),
            UserManagementRepository::parseTimestamp($row['created_at'] ?? null),
            UserManagementRepository::parseTimestamp($row['updated_at'] ?? null),
            $this->nullableTimestamp($row['invited_at'] ?? null),
            $this->nullableTimestamp($row['activated_at'] ?? null),
            $this->nullableTimestamp($row['suspended_at'] ?? null),
            $this->nullableTimestamp($row['last_login_at'] ?? null),
            $directCodes,
            $effectiveCodes
        );
    }

    /** @param array<string, mixed> $actor */
    private function auditDenied(
        array $actor,
        string $eventCode,
        string $reasonCode,
        ?string $targetPublicId,
        DateTimeImmutable $now
    ): void {
        $this->audit($actor, $eventCode, 'denied', $reasonCode, $targetPublicId, $now);
    }

    /** @param array<string, mixed> $actor */
    private function auditSuccess(
        array $actor,
        string $eventCode,
        ?string $targetPublicId,
        ?string $reasonCode,
        DateTimeImmutable $now
    ): void {
        $this->audit($actor, $eventCode, 'success', $reasonCode, $targetPublicId, $now);
    }

    /** @param array<string, mixed> $actor */
    private function audit(
        array $actor,
        string $eventCode,
        string $outcome,
        ?string $reasonCode,
        ?string $targetPublicId,
        DateTimeImmutable $now
    ): void {
        $requestId = $this->uuidGenerator->generateV4();
        if (!$this->isUuid($requestId)) {
            throw new UserManagementStorageException();
        }
        $this->repository->insertAudit(
            $requestId,
            $this->positiveInteger($actor['user_id'] ?? null),
            $this->string($actor['session_public_id'] ?? null),
            $eventCode,
            $outcome,
            $reasonCode,
            $targetPublicId,
            $now
        );
    }

    /** @param list<array<string, mixed>> $tokens @return list<array<string, mixed>> */
    private function liveTokens(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (array $row): bool =>
                ($row['used_at'] ?? null) === null
                && ($row['revoked_at'] ?? null) === null
        ));
    }

    /** @param list<string> $codes @return list<string>|null */
    private function capabilityCodes(array $codes): ?array
    {
        if (!array_is_list($codes) || count($codes) > self::MAX_CAPABILITIES_PER_EDITOR) {
            return null;
        }
        $normalized = [];
        foreach ($codes as $code) {
            if (
                !is_string($code)
                || preg_match('/\A[a-z][a-z0-9_.-]{2,127}\z/', $code) !== 1
            ) {
                return null;
            }
            $normalized[$code] = true;
        }
        $result = array_keys($normalized);
        sort($result, SORT_STRING);

        return $result;
    }

    /** @return array{valid: bool, value: string|null} */
    private function displayName(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['valid' => true, 'value' => null];
        }
        if (
            preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
        ) {
            return ['valid' => false, 'value' => null];
        }
        $count = preg_match_all('/./us', $value, $matches);

        return is_int($count) && $count <= 120
            ? ['valid' => true, 'value' => $value]
            : ['valid' => false, 'value' => null];
    }

    private function email(string $value): ?string
    {
        try {
            return EmailAddress::fromString($value)->value();
        } catch (InvalidEmailAddress) {
            return null;
        }
    }

    private function storedEmail(mixed $value): string
    {
        if (
            !is_string($value)
            || strlen($value) < 1
            || strlen($value) > EmailAddress::MAX_BYTES
            || strtolower($value) !== $value
            || preg_match('/\A[\x20-\x7E]+\z/', $value) !== 1
        ) {
            throw new UserManagementStorageException();
        }

        return $value;
    }

    private function encodeCursor(string $publicId): string
    {
        if (!$this->isUuid($publicId)) {
            throw new UserManagementStorageException();
        }
        $payload = $publicId;
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return $encoded . '.' . $this->securityKey->deriveToken(
            self::CURSOR_PURPOSE,
            $payload
        );
    }

    /** @return array{public_id: string|null}|null */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null) {
            return ['public_id' => null];
        }
        if (
            strlen($cursor) !== 92
            || preg_match('/\A([A-Za-z0-9_-]{48})\.([A-Za-z0-9_-]{43})\z/', $cursor, $matches) !== 1
        ) {
            return null;
        }
        $padding = (4 - (strlen($matches[1]) % 4)) % 4;
        $payload = base64_decode(
            strtr($matches[1], '-_', '+/') . str_repeat('=', $padding),
            true
        );
        if (
            !is_string($payload)
            || !$this->isUuid($payload)
            || rtrim(strtr(base64_encode($payload), '+/', '-_'), '=')
                !== $matches[1]
            || !ConstantTime::equals(
                $this->securityKey->deriveToken(self::CURSOR_PURPOSE, $payload),
                $matches[2]
            )
        ) {
            return null;
        }

        return ['public_id' => $payload];
    }

    /** @param array<string, mixed> $row */
    private function capabilityCode(array $row): string
    {
        $code = $this->string($row['code'] ?? null);
        if (preg_match('/\A[a-z][a-z0-9_.-]{2,127}\z/', $code) !== 1) {
            throw new UserManagementStorageException();
        }

        return $code;
    }

    private function nullableTimestamp(mixed $value): ?DateTimeImmutable
    {
        return $value === null
            ? null
            : UserManagementRepository::parseTimestamp($value);
    }

    private function status(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['invited', 'active', 'suspended'], true)) {
            throw new UserManagementStorageException();
        }

        return $value;
    }

    private function string(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new UserManagementStorageException();
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->string($value);
    }

    private function positiveInteger(mixed $value): int
    {
        $normalized = $this->nullablePositiveInteger($value);
        if ($normalized === null) {
            throw new UserManagementStorageException();
        }

        return $normalized;
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        return null;
    }

    private function isZero(mixed $value): bool
    {
        return in_array($value, [0, '0'], true);
    }

    private function isOne(mixed $value): bool
    {
        return in_array($value, [1, '1'], true);
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $value
        ) === 1;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }
}
