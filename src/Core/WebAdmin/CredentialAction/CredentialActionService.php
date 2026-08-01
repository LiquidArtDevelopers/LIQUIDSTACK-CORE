<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\CredentialAction;

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
use InvalidArgumentException;

/**
 * Credential recovery/activation domain, isolated from login sessions.
 *
 * Raw link, browser-session, CSRF and password values remain process-local.
 * The persistence boundary receives only hashes or password hashes.
 */
final class CredentialActionService
{
    public const INVITATION = 'invite';
    public const PASSWORD_RESET = 'password_reset';

    private const RESET_IDENTIFIER_ACTION = 'password_reset.identifier';
    private const RESET_IP_ACTION = 'password_reset.ip';
    private const LOGIN_IDENTIFIER_ACTION = 'login.identifier';
    private const ACTION_IDLE_TTL_SECONDS = 900;
    private const ACTION_ABSOLUTE_TTL_SECONDS = 1800;

    private readonly PasswordHasher $passwordHasher;

    public function __construct(
        private readonly CredentialActionRepository $repository,
        private readonly WebAdminConfig $config,
        private readonly SecurityKey $securityKey,
        private readonly ClockInterface $clock,
        private readonly UuidGeneratorInterface $uuidGenerator,
        ?PasswordHasher $passwordHasher = null,
        private readonly SecureTokenGenerator $tokenGenerator =
            new SecureTokenGenerator(),
        private readonly CredentialActionRateLimitPolicy $rateLimitPolicy =
            new CredentialActionRateLimitPolicy()
    ) {
        ExceptionTraceGuard::assertEnabled();
        $this->passwordHasher = $passwordHasher
            ?? PasswordHasher::productive();
    }

    public static function isSupportedPurpose(string $purpose): bool
    {
        return in_array($purpose, [
            self::INVITATION,
            self::PASSWORD_RESET,
        ], true);
    }

    /**
     * Always returns the exact same public result. Only an eligible active
     * identity can gain an outbox entry; invalid, absent, suspended, invited,
     * duplicate and rate-limited requests remain indistinguishable.
     */
    public function requestPasswordReset(
        #[\SensitiveParameter] string $email,
        #[\SensitiveParameter] string $remoteAddress,
        #[\SensitiveParameter] ?string $userAgent = null,
        string $locale = 'und'
    ): PasswordResetRequestResult {
        $now = $this->now();
        $canonicalEmail = $this->canonicalEmailOrNull($email);
        $identifierHash = $this->identifierSubjectHash(
            $email,
            $canonicalEmail,
            self::RESET_IDENTIFIER_ACTION
        );
        $ipHash = $this->ipSubjectHash(
            $remoteAddress,
            self::RESET_IP_ACTION
        );
        $userAgentHash = $this->userAgentSubjectHash($userAgent);
        $safeLocale = $this->safeLocale($locale);

        $this->repository->transaction(function () use (
            $canonicalEmail,
            $identifierHash,
            $ipHash,
            $userAgentHash,
            $safeLocale,
            $now
        ): void {
            $identifierLimit = $this->readRateLimit(
                self::RESET_IDENTIFIER_ACTION,
                $identifierHash,
                $now
            );
            $ipLimit = $this->readRateLimit(
                self::RESET_IP_ACTION,
                $ipHash,
                $now
            );
            $limited = $identifierLimit['blocked'] || $ipLimit['blocked'];

            $this->recordRequest(
                self::RESET_IDENTIFIER_ACTION,
                $identifierHash,
                $identifierLimit,
                $this->rateLimitPolicy->identifierRequestLimit(),
                $now
            );
            $this->recordRequest(
                self::RESET_IP_ACTION,
                $ipHash,
                $ipLimit,
                $this->rateLimitPolicy->ipRequestLimit(),
                $now
            );

            $user = $this->repository->findUserCredentialByEmailForUpdate(
                $canonicalEmail
                    ?? '__invalid__' . hash('sha256', $identifierHash)
            );
            $eligible = !$limited && $this->isResetRequestEligible($user);
            $targetPublicId = null;
            if ($eligible) {
                $userId = $this->positiveInteger($user['user_id'] ?? null);
                $targetPublicId = $this->uuidValue(
                    $user['user_public_id'] ?? null
                );
                if (!$this->repository->hasOpenPasswordResetOutbox($userId)) {
                    $this->repository->insertPasswordResetOutbox(
                        $userId,
                        $safeLocale,
                        $now
                    );
                }
            }

            // Once either fixed bucket is already blocked, suppress further
            // append-only audit writes as well as delivery work. This keeps a
            // single hostile source from causing unbounded persistence.
            if (!$limited) {
                // No email, raw address, reason or metadata is persisted.
                $this->repository->insertAuditEvent(
                    $this->uuidV4(),
                    'webadmin.password_reset.requested',
                    'success',
                    null,
                    null,
                    $targetPublicId,
                    $ipHash,
                    $userAgentHash,
                    $now
                );
            }
        });

        return new PasswordResetRequestResult();
    }

    /**
     * Exchanges a delivered raw action link for an unrelated browser action
     * session. The action remains unused until a CSRF-protected completion.
     */
    public function bindActionToken(
        #[\SensitiveParameter] string $rawActionToken,
        string $expectedPurpose
    ): ?CredentialActionSessionSecrets {
        $this->assertPurpose($expectedPurpose);
        if (!$this->tokenGenerator->hasValidFormat($rawActionToken)) {
            return null;
        }
        $now = $this->now();

        return $this->repository->transaction(function () use (
            $rawActionToken,
            $expectedPurpose,
            $now
        ): ?CredentialActionSessionSecrets {
            $action = $this->lockActionByHash(
                $this->tokenGenerator->hashForStorage($rawActionToken)
            );
            if (!$this->isActionEligible($action, $expectedPurpose, $now)) {
                return null;
            }

            $actionExpiry = CredentialActionRepository::parseTimestamp(
                $action['expires_at'] ?? null
            );
            $absoluteExpiresAt = $this->earliest(
                $this->addSeconds(
                    $now,
                    min(
                        self::ACTION_ABSOLUTE_TTL_SECONDS,
                        $this->config->absoluteTtlSeconds()
                    )
                ),
                $actionExpiry
            );
            $idleExpiresAt = $this->earliest(
                $this->addSeconds(
                    $now,
                    min(
                        self::ACTION_IDLE_TTL_SECONDS,
                        $this->config->idleTtlSeconds()
                    )
                ),
                $absoluteExpiresAt
            );
            $sessionToken = $this->tokenGenerator->generate();
            $csrfToken = $this->csrfTokenForSession($sessionToken);
            $actionTokenId = $this->positiveInteger(
                $action['action_token_id'] ?? null
            );
            $this->repository->insertActionSession(
                $this->uuidV4(),
                $this->tokenGenerator->hashForStorage($sessionToken),
                $this->tokenGenerator->hashForStorage($csrfToken),
                $actionTokenId,
                $now,
                $idleExpiresAt,
                $absoluteExpiresAt
            );
            /*
             * Every action flow now uses user -> action -> session. Keeping
             * insertion and pruning in this transaction preserves the
             * three-session bound without an orphan window or inverse lock.
             */
            $this->repository->pruneActionSessionsForToken(
                $actionTokenId,
                $now,
                3
            );

            return new CredentialActionSessionSecrets(
                $sessionToken,
                $csrfToken,
                $expectedPurpose,
                $absoluteExpiresAt
            );
        });
    }

    /**
     * Resolves a clean-URL action page from its dedicated cookie. No raw link
     * is needed and the action is still not consumed.
     */
    public function resolveBoundAction(
        #[\SensitiveParameter] string $actionSessionToken,
        string $expectedPurpose
    ): ?BoundCredentialAction {
        $this->assertPurpose($expectedPurpose);
        if (!$this->tokenGenerator->hasValidFormat($actionSessionToken)) {
            return null;
        }
        $now = $this->now();

        return $this->repository->transaction(function () use (
            $actionSessionToken,
            $expectedPurpose,
            $now
        ): ?BoundCredentialAction {
            $locked = $this->lockActionSessionAndAction(
                $actionSessionToken
            );
            $session = $locked['session'];
            if (!$this->isActionSessionStructurallyValid($session, $now)) {
                $this->revokeInvalidActionSession($session, $now);

                return null;
            }
            if (!$this->sessionCsrfBindingIsValid(
                $actionSessionToken,
                $session
            )) {
                $this->revokeInvalidActionSession($session, $now);

                return null;
            }

            $action = $locked['action'];
            if (!$this->isActionEligible($action, $expectedPurpose, $now)) {
                $this->repository->revokeSession(
                    $this->positiveInteger($session['id'] ?? null),
                    $now
                );

                return null;
            }

            $absoluteExpiresAt = CredentialActionRepository::parseTimestamp(
                $session['absolute_expires_at'] ?? null
            );
            $idleExpiresAt = $this->earliest(
                $this->addSeconds(
                    $now,
                    min(
                        self::ACTION_IDLE_TTL_SECONDS,
                        $this->config->idleTtlSeconds()
                    )
                ),
                $absoluteExpiresAt
            );
            $this->repository->touchSession(
                $this->positiveInteger($session['id'] ?? null),
                $now,
                $idleExpiresAt
            );

            return new BoundCredentialAction(
                $expectedPurpose,
                $this->earliest(
                    CredentialActionRepository::parseTimestamp(
                        $action['expires_at'] ?? null
                    ),
                    $absoluteExpiresAt
                )
            );
        });
    }

    /**
     * Returns the stable action-bound CSRF value after resolving the exact
     * expected purpose. This is the post-redirect form-rendering primitive.
     */
    public function boundActionCsrfToken(
        #[\SensitiveParameter] string $actionSessionToken,
        string $expectedPurpose
    ): ?CredentialActionCsrfToken {
        $bound = $this->resolveBoundAction(
            $actionSessionToken,
            $expectedPurpose
        );
        if ($bound === null) {
            return null;
        }

        return new CredentialActionCsrfToken(
            $this->csrfTokenForSession($actionSessionToken),
            $bound->purpose(),
            $bound->expiresAt()
        );
    }

    /**
     * CSRF-protected cancellation/cleanup for the separate action cookie.
     * It never accepts a login pre-authentication or authenticated session.
     */
    public function revokeActionSession(
        #[\SensitiveParameter] string $actionSessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $expectedPurpose
    ): bool {
        $this->assertPurpose($expectedPurpose);
        if (
            !$this->tokenGenerator->hasValidFormat($actionSessionToken)
            || !$this->tokenGenerator->hasValidFormat($csrfToken)
        ) {
            return false;
        }
        $now = $this->now();

        return $this->repository->transaction(function () use (
            $actionSessionToken,
            $csrfToken,
            $expectedPurpose,
            $now
        ): bool {
            $locked = $this->lockActionSessionAndAction(
                $actionSessionToken
            );
            $session = $locked['session'];
            if (
                !$this->isActionSessionStructurallyValid($session, $now)
                || !$this->submittedCsrfIsValid(
                    $actionSessionToken,
                    $csrfToken,
                    $session
                )
            ) {
                return false;
            }
            $action = $locked['action'];
            if (($action['purpose'] ?? null) !== $expectedPurpose) {
                return false;
            }

            $this->repository->revokeSession(
                $this->positiveInteger($session['id'] ?? null),
                $now
            );

            return true;
        });
    }

    public function completeInvitation(
        #[\SensitiveParameter] string $actionSessionToken,
        #[\SensitiveParameter] string $csrfToken,
        #[\SensitiveParameter] string $newPassword,
        #[\SensitiveParameter] string $remoteAddress = '',
        #[\SensitiveParameter] ?string $userAgent = null
    ): CredentialActionCompletion {
        return $this->complete(
            $actionSessionToken,
            $csrfToken,
            $newPassword,
            self::INVITATION,
            $remoteAddress,
            $userAgent
        );
    }

    public function completePasswordReset(
        #[\SensitiveParameter] string $actionSessionToken,
        #[\SensitiveParameter] string $csrfToken,
        #[\SensitiveParameter] string $newPassword,
        #[\SensitiveParameter] string $remoteAddress = '',
        #[\SensitiveParameter] ?string $userAgent = null
    ): CredentialActionCompletion {
        return $this->complete(
            $actionSessionToken,
            $csrfToken,
            $newPassword,
            self::PASSWORD_RESET,
            $remoteAddress,
            $userAgent
        );
    }

    private function complete(
        #[\SensitiveParameter] string $actionSessionToken,
        #[\SensitiveParameter] string $csrfToken,
        #[\SensitiveParameter] string $newPassword,
        string $expectedPurpose,
        #[\SensitiveParameter] string $remoteAddress,
        #[\SensitiveParameter] ?string $userAgent
    ): CredentialActionCompletion {
        if (
            !$this->tokenGenerator->hasValidFormat($actionSessionToken)
            || !$this->tokenGenerator->hasValidFormat($csrfToken)
        ) {
            return CredentialActionCompletion::failed();
        }

        /*
         * Reject forged but well-formed session/CSRF pairs before Argon2.
         * This short transaction releases every lock before hashing; the
         * final transaction below repeats all predicates, so the preflight
         * grants no authority and opens no TOCTOU window.
         */
        if (!$this->completionPreflight(
            $actionSessionToken,
            $csrfToken,
            $expectedPurpose
        )) {
            return CredentialActionCompletion::failed();
        }

        // Argon2 work must never hold row locks. The transaction revalidates
        // every mutable predicate after the hash has been computed.
        $passwordHash = $this->passwordHasher->hash($newPassword);
        $now = $this->now();
        $ipHash = $this->ipSubjectHash(
            $remoteAddress,
            'audit.credential_action.ip'
        );
        $userAgentHash = $this->userAgentSubjectHash($userAgent);

        $loginIdentifierHash = null;
        $completion = $this->repository->transaction(function () use (
            $actionSessionToken,
            $csrfToken,
            $passwordHash,
            $expectedPurpose,
            $ipHash,
            $userAgentHash,
            $now,
            &$loginIdentifierHash
        ): CredentialActionCompletion {
            $locked = $this->lockActionSessionAndAction(
                $actionSessionToken
            );
            $session = $locked['session'];
            if (
                !$this->isActionSessionStructurallyValid($session, $now)
                || !$this->submittedCsrfIsValid(
                    $actionSessionToken,
                    $csrfToken,
                    $session
                )
            ) {
                return CredentialActionCompletion::failed();
            }

            $action = $locked['action'];
            if (!$this->isActionEligible($action, $expectedPurpose, $now)) {
                $this->repository->revokeSession(
                    $this->positiveInteger($session['id'] ?? null),
                    $now
                );

                return CredentialActionCompletion::failed();
            }

            $userId = $this->positiveInteger($action['user_id'] ?? null);
            $authVersion = $this->positiveInteger(
                $action['user_auth_version'] ?? null
            );
            $actionTokenId = $this->positiveInteger(
                $action['action_token_id'] ?? null
            );
            $targetPublicId = $this->uuidValue(
                $action['user_public_id'] ?? null
            );

            $this->repository->replaceCredential(
                $userId,
                $passwordHash,
                $now
            );
            $this->repository->advanceUserAuthenticationVersion(
                $userId,
                $authVersion,
                $expectedPurpose === self::INVITATION
                    ? 'invited'
                    : 'active',
                $expectedPurpose === self::INVITATION,
                $now
            );
            $this->repository->markActionTokenUsed($actionTokenId, $now);
            $this->repository->revokeOtherActionTokens(
                $userId,
                $actionTokenId,
                $now
            );
            $this->repository->revokeAllUserAndActionSessions($userId, $now);
            $loginIdentifierHash = $this->identifierSubjectHash(
                $this->stringValue($action['email_canonical'] ?? null),
                $this->stringValue($action['email_canonical'] ?? null),
                self::LOGIN_IDENTIFIER_ACTION
            );
            $this->repository->insertAuditEvent(
                $this->uuidV4(),
                $expectedPurpose === self::INVITATION
                    ? 'webadmin.invitation.completed'
                    : 'webadmin.password_reset.completed',
                'success',
                null,
                null,
                $targetPublicId,
                $ipHash,
                $userAgentHash,
                $now
            );

            return CredentialActionCompletion::succeeded();
        });

        /*
         * Login takes rate-limit locks before the user lock. Cleaning the
         * identifier bucket inside the credential transaction would invert
         * that order and can deadlock with a concurrent login. This cleanup
         * grants no authority, so it runs separately and is best-effort after
         * the password/action transaction has committed.
         */
        if ($completion->isCompleted() && is_string($loginIdentifierHash)) {
            try {
                $this->repository->transaction(function () use (
                    $loginIdentifierHash
                ): void {
                    $this->repository->deleteRateLimit(
                        self::LOGIN_IDENTIFIER_ACTION,
                        $loginIdentifierHash
                    );
                });
            } catch (CredentialActionStorageException) {
                // A bounded login lockout may remain until its normal expiry.
            }
        }

        return $completion;
    }

    private function completionPreflight(
        #[\SensitiveParameter] string $actionSessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $expectedPurpose
    ): bool {
        $now = $this->now();

        return $this->repository->transaction(function () use (
            $actionSessionToken,
            $csrfToken,
            $expectedPurpose,
            $now
        ): bool {
            $locked = $this->lockActionSessionAndAction(
                $actionSessionToken
            );
            $session = $locked['session'];
            if (
                !$this->isActionSessionStructurallyValid($session, $now)
                || !$this->submittedCsrfIsValid(
                    $actionSessionToken,
                    $csrfToken,
                    $session
                )
            ) {
                return false;
            }

            $action = $locked['action'];

            return $this->isActionEligible(
                $action,
                $expectedPurpose,
                $now
            );
        });
    }

    /** @return array<string, mixed>|null */
    private function lockActionByHash(string $tokenHash): ?array
    {
        $reference = $this->repository->actionReferenceByHash($tokenHash);
        if ($reference === null) {
            return null;
        }

        return $this->lockReferencedAction($reference, $tokenHash);
    }

    /**
     * Action flows use user -> action token -> action session. The initial
     * reference is a non-authoritative routing hint; every relationship is
     * re-read under locks before the caller validates it.
     *
     * @return array{session: array<string, mixed>|null, action: array<string, mixed>|null}
     */
    private function lockActionSessionAndAction(
        #[\SensitiveParameter] string $rawSessionToken
    ): array {
        $tokenHash = $this->tokenGenerator->hashForStorage(
            $rawSessionToken
        );
        $reference = $this->repository->actionSessionReference($tokenHash);
        $referencedActionId = $this->nullablePositiveInteger(
            $reference['pending_action_token_id'] ?? null
        );
        $action = $referencedActionId === null
            ? null
            : $this->lockActionById($referencedActionId);
        $session = $this->repository->findSessionForUpdate($tokenHash);

        if (
            $session === null
            || $this->nullablePositiveInteger(
                $session['pending_action_token_id'] ?? null
            ) !== $referencedActionId
        ) {
            $action = null;
        }

        return ['session' => $session, 'action' => $action];
    }

    /** @return array<string, mixed>|null */
    private function lockActionById(int $actionTokenId): ?array
    {
        $reference = $this->repository->actionReferenceById($actionTokenId);
        if ($reference === null) {
            return null;
        }

        return $this->lockReferencedAction($reference, null);
    }

    /**
     * Outbox delivery locks outbox -> user -> action token. Credential-action
     * reads take non-locking references and then lock user -> action token ->
     * action session, so delivery and consumption cannot form an inverse
     * user/token cycle. A reference is never trusted: all authoritative data
     * is re-read and checked under its corresponding locks.
     *
     * @param array{action_token_id: mixed, user_id: mixed} $reference
     * @return array<string, mixed>|null
     */
    private function lockReferencedAction(
        array $reference,
        ?string $expectedTokenHash
    ): ?array {
        $actionTokenId = $this->nullablePositiveInteger(
            $reference['action_token_id'] ?? null
        );
        $userId = $this->nullablePositiveInteger(
            $reference['user_id'] ?? null
        );
        if ($actionTokenId === null || $userId === null) {
            return null;
        }

        $user = $this->repository->findUserCredentialByIdForUpdate($userId);
        if ($user === null) {
            return null;
        }
        $action = $this->repository->findActionByIdForUpdate($actionTokenId);
        if (
            $action === null
            || $this->nullablePositiveInteger($action['user_id'] ?? null)
                !== $userId
            || $this->nullablePositiveInteger(
                $action['action_token_id'] ?? null
            ) !== $actionTokenId
            || (
                $expectedTokenHash !== null
                && ($action['token_hash'] ?? null) !== $expectedTokenHash
            )
        ) {
            return null;
        }

        return $action;
    }

    /** @param array<string, mixed>|null $action */
    private function isActionEligible(
        ?array $action,
        string $expectedPurpose,
        DateTimeImmutable $now
    ): bool {
        if (
            $action === null
            || ($action['purpose'] ?? null) !== $expectedPurpose
            || ($action['used_at'] ?? null) !== null
            || ($action['token_revoked_at'] ?? null) !== null
            || !is_string($action['token_hash'] ?? null)
            || preg_match(
                '/\A[0-9a-f]{64}\z/',
                $action['token_hash']
            ) !== 1
        ) {
            return false;
        }

        try {
            $userId = $this->positiveInteger($action['user_id'] ?? null);
            $credentialUserId = $this->positiveInteger(
                $action['credential_user_id'] ?? null
            );
            $tokenVersion = $this->positiveInteger(
                $action['token_auth_version'] ?? null
            );
            $userVersion = $this->positiveInteger(
                $action['user_auth_version'] ?? null
            );
            $createdAt = CredentialActionRepository::parseTimestamp(
                $action['token_created_at'] ?? null
            );
            $deliveredAt = CredentialActionRepository::parseTimestamp(
                $action['delivered_at'] ?? null
            );
            $expiresAt = CredentialActionRepository::parseTimestamp(
                $action['expires_at'] ?? null
            );
            $email = EmailAddress::fromString(
                $this->stringValue($action['email_canonical'] ?? null)
            );
            $this->uuidValue($action['user_public_id'] ?? null);
        } catch (
            CredentialActionStorageException
            | InvalidEmailAddress
            | InvalidArgumentException
        ) {
            return false;
        }

        if (
            $userId !== $credentialUserId
            || $tokenVersion !== $userVersion
            || $userVersion >= PHP_INT_MAX
            || $email->value() !== ($action['email_canonical'] ?? null)
            || $deliveredAt < $createdAt
            || $deliveredAt > $now
            || $expiresAt <= $now
            || $expiresAt <= $createdAt
        ) {
            return false;
        }

        if ($expectedPurpose === self::INVITATION) {
            return ($action['user_status'] ?? null) === 'invited'
                && ($action['password_hash'] ?? null) === null
                && ($action['password_set_at'] ?? null) === null
                && ($action['activated_at'] ?? null) === null
                && ($action['suspended_at'] ?? null) === null;
        }

        if (
            ($action['user_status'] ?? null) !== 'active'
            || ($action['suspended_at'] ?? null) !== null
            || !is_string($action['password_hash'] ?? null)
            || $action['password_hash'] === ''
        ) {
            return false;
        }

        try {
            CredentialActionRepository::parseTimestamp(
                $action['password_set_at'] ?? null
            );
            CredentialActionRepository::parseTimestamp(
                $action['activated_at'] ?? null
            );
        } catch (CredentialActionStorageException) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed>|null $session */
    private function isActionSessionStructurallyValid(
        ?array $session,
        DateTimeImmutable $now
    ): bool {
        if (
            $session === null
            || ($session['session_type'] ?? null) !== 'preauth'
            || ($session['user_id'] ?? null) !== null
            || ($session['auth_version'] ?? null) !== null
            || ($session['revoked_at'] ?? null) !== null
            || $this->nullablePositiveInteger(
                $session['pending_action_token_id'] ?? null
            ) === null
        ) {
            return false;
        }

        try {
            $createdAt = CredentialActionRepository::parseTimestamp(
                $session['created_at'] ?? null
            );
            $idleExpiresAt = CredentialActionRepository::parseTimestamp(
                $session['idle_expires_at'] ?? null
            );
            $absoluteExpiresAt = CredentialActionRepository::parseTimestamp(
                $session['absolute_expires_at'] ?? null
            );
        } catch (CredentialActionStorageException) {
            return false;
        }

        return $createdAt <= $now
            && $now < $idleExpiresAt
            && $now < $absoluteExpiresAt
            && $idleExpiresAt <= $absoluteExpiresAt;
    }

    /** @param array<string, mixed>|null $session */
    private function revokeInvalidActionSession(
        ?array $session,
        DateTimeImmutable $now
    ): void {
        if (
            $session !== null
            && ($session['session_type'] ?? null) === 'preauth'
            && ($session['revoked_at'] ?? null) === null
            && $this->nullablePositiveInteger(
                $session['pending_action_token_id'] ?? null
            ) !== null
        ) {
            $this->repository->revokeSession(
                $this->positiveInteger($session['id'] ?? null),
                $now
            );
        }
    }

    /** @param array<string, mixed>|null $user */
    private function isResetRequestEligible(?array $user): bool
    {
        if (
            $user === null
            || ($user['user_status'] ?? null) !== 'active'
            || ($user['suspended_at'] ?? null) !== null
            || !is_string($user['password_hash'] ?? null)
            || $user['password_hash'] === ''
        ) {
            return false;
        }

        try {
            return $this->positiveInteger($user['user_id'] ?? null)
                    === $this->positiveInteger(
                        $user['credential_user_id'] ?? null
                    )
                && $this->positiveInteger(
                    $user['user_auth_version'] ?? null
                ) < PHP_INT_MAX
                && CredentialActionRepository::parseTimestamp(
                    $user['password_set_at'] ?? null
                ) instanceof DateTimeImmutable
                && CredentialActionRepository::parseTimestamp(
                    $user['activated_at'] ?? null
                ) instanceof DateTimeImmutable
                && $this->uuidValue(
                    $user['user_public_id'] ?? null
                ) !== '';
        } catch (
            CredentialActionStorageException
            | InvalidArgumentException
        ) {
            return false;
        }
    }

    /**
     * @return array{exists: bool, window_started_at: DateTimeImmutable, attempts: int, blocked_until: DateTimeImmutable|null, blocked: bool}
     */
    private function readRateLimit(
        string $action,
        string $subjectHash,
        DateTimeImmutable $now
    ): array {
        $row = $this->repository->findRateLimitForUpdate(
            $action,
            $subjectHash
        );
        if ($row === null) {
            return [
                'exists' => false,
                'window_started_at' => $now,
                'attempts' => 0,
                'blocked_until' => null,
                'blocked' => false,
            ];
        }

        $windowStartedAt = CredentialActionRepository::parseTimestamp(
            $row['window_started_at'] ?? null
        );
        $blockedUntil = ($row['blocked_until'] ?? null) === null
            ? null
            : CredentialActionRepository::parseTimestamp(
                $row['blocked_until']
            );
        $attempts = $this->nonNegativeInteger($row['attempts'] ?? null);
        if ($blockedUntil !== null && $now < $blockedUntil) {
            return [
                'exists' => true,
                'window_started_at' => $windowStartedAt,
                'attempts' => $attempts,
                'blocked_until' => $blockedUntil,
                'blocked' => true,
            ];
        }

        if (
            $now >= $this->addSeconds(
                $windowStartedAt,
                $this->rateLimitPolicy->windowSeconds()
            )
            || ($blockedUntil !== null && $now >= $blockedUntil)
        ) {
            return [
                'exists' => true,
                'window_started_at' => $now,
                'attempts' => 0,
                'blocked_until' => null,
                'blocked' => false,
            ];
        }

        return [
            'exists' => true,
            'window_started_at' => $windowStartedAt,
            'attempts' => $attempts,
            'blocked_until' => null,
            'blocked' => false,
        ];
    }

    /**
     * @param array{exists: bool, window_started_at: DateTimeImmutable, attempts: int, blocked_until: DateTimeImmutable|null, blocked: bool} $state
     */
    private function recordRequest(
        string $action,
        string $subjectHash,
        array $state,
        int $requestLimit,
        DateTimeImmutable $now
    ): void {
        if ($state['blocked']) {
            $attempts = $requestLimit;
            $blockedUntil = $state['blocked_until'];
        } else {
            $attempts = min($requestLimit, $state['attempts'] + 1);
            $blockedUntil = $attempts >= $requestLimit
                ? $this->addSeconds(
                    $now,
                    $this->rateLimitPolicy->blockSeconds()
                )
                : null;
        }

        if ($state['exists']) {
            $this->repository->updateRateLimit(
                $action,
                $subjectHash,
                $state['window_started_at'],
                $attempts,
                $blockedUntil,
                $now
            );

            return;
        }

        $this->repository->insertRateLimit(
            $action,
            $subjectHash,
            $state['window_started_at'],
            $attempts,
            $blockedUntil,
            $now,
            $requestLimit,
            $this->addSeconds($now, $this->rateLimitPolicy->blockSeconds())
        );
    }

    /** @param array<string, mixed> $session */
    private function sessionCsrfBindingIsValid(
        string $sessionToken,
        array $session
    ): bool {
        $storedHash = $session['csrf_token_hash'] ?? null;

        return is_string($storedHash)
            && $this->tokenGenerator->verify(
                $this->csrfTokenForSession($sessionToken),
                $storedHash
            );
    }

    /** @param array<string, mixed>|null $session */
    private function submittedCsrfIsValid(
        string $sessionToken,
        string $submittedToken,
        ?array $session
    ): bool {
        if ($session === null) {
            return false;
        }
        $expected = $this->csrfTokenForSession($sessionToken);

        return $this->sessionCsrfBindingIsValid($sessionToken, $session)
            && ConstantTime::equals($expected, $submittedToken);
    }

    private function csrfTokenForSession(string $sessionToken): string
    {
        return $this->securityKey->deriveToken(
            CredentialActionSessionSecrets::CSRF_PURPOSE,
            $sessionToken
        );
    }

    private function canonicalEmailOrNull(string $email): ?string
    {
        try {
            return EmailAddress::fromString($email)->value();
        } catch (InvalidEmailAddress) {
            return null;
        }
    }

    private function identifierSubjectHash(
        string $rawEmail,
        ?string $canonicalEmail,
        string $action
    ): string {
        $subject = $canonicalEmail === null
            ? 'invalid:' . hash('sha256', $rawEmail)
            : 'email:' . $canonicalEmail;

        return $this->securityKey->subjectHash($action, $subject);
    }

    private function ipSubjectHash(
        string $remoteAddress,
        string $action
    ): string {
        $packed = @inet_pton($remoteAddress);
        $subject = is_string($packed)
            ? 'ip:' . $packed
            : 'invalid';

        return $this->securityKey->subjectHash($action, $subject);
    }

    private function userAgentSubjectHash(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return $this->securityKey->subjectHash(
            'audit.user_agent',
            hash('sha256', $userAgent, true)
        );
    }

    private function safeLocale(string $locale): string
    {
        return WebAdminLocale::normalize($locale);
    }

    private function assertPurpose(string $purpose): void
    {
        if (!self::isSupportedPurpose($purpose)) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin credential-action purpose.'
            );
        }
    }

    private function uuidV4(): string
    {
        $value = $this->uuidGenerator->generateV4();

        return $this->uuidValue($value);
    }

    private function uuidValue(mixed $value): string
    {
        if (
            !is_string($value)
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $value
            ) !== 1
        ) {
            throw new InvalidArgumentException('Invalid UUID.');
        }

        return $value;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private function addSeconds(
        DateTimeImmutable $value,
        int $seconds
    ): DateTimeImmutable {
        return $value->modify('+' . $seconds . ' seconds');
    }

    private function earliest(
        DateTimeImmutable $first,
        DateTimeImmutable $second
    ): DateTimeImmutable {
        return $first < $second ? $first : $second;
    }

    private function positiveInteger(mixed $value): int
    {
        $parsed = $this->nullablePositiveInteger($value);
        if ($parsed === null) {
            throw new CredentialActionStorageException();
        }

        return $parsed;
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

    private function nonNegativeInteger(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        throw new CredentialActionStorageException();
    }

    private function stringValue(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new CredentialActionStorageException();
        }

        return $value;
    }
}
