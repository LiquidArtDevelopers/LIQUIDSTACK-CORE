<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authentication;

use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Security\EmailAddress;
use App\Core\WebAdmin\Security\ConstantTime;
use App\Core\WebAdmin\Security\ExceptionTraceGuard;
use App\Core\WebAdmin\Security\InvalidEmailAddress;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Isolated WebAdmin login/session domain. It never reads PHP's legacy
 * session, cookies, forwarded-IP headers or environment variables directly.
 */
final class WebAdminAuthenticationService
{
    private const IDENTIFIER_ACTION = 'login.identifier';
    private const IP_ACTION = 'login.ip';
    private const PREAUTH_ISSUE_ACTION = 'preauth.issue';
    private const ACCESS_CAPABILITY = 'webadmin.access';
    private const CSRF_DERIVATION_PURPOSE = 'csrf.session';
    private const PREAUTH_ABSOLUTE_TTL_SECONDS = 600;
    private const GARBAGE_COLLECTION_BATCH_SIZE = 250;

    private readonly PasswordHasher $passwordHasher;

    public function __construct(
        private readonly WebAdminAuthenticationRepository $repository,
        private readonly WebAdminConfig $config,
        private readonly SecurityKey $securityKey,
        private readonly ClockInterface $clock,
        private readonly UuidGeneratorInterface $uuidGenerator,
        ?PasswordHasher $passwordHasher = null,
        private readonly SecureTokenGenerator $tokenGenerator = new SecureTokenGenerator(),
        private readonly LoginRateLimitPolicy $rateLimitPolicy = new LoginRateLimitPolicy()
    ) {
        ExceptionTraceGuard::assertEnabled();
        $this->passwordHasher = $passwordHasher
            ?? PasswordHasher::productive();
    }

    /**
     * Opens a pre-authentication session. A still-valid supplied session is
     * reused with its stable, session-bound CSRF secret.
     */
    public function openPreAuthenticationSession(
        #[\SensitiveParameter] ?string $existingSessionToken,
        #[\SensitiveParameter] string $remoteAddress = ''
    ): SessionSecrets {
        $now = $this->now();
        $ipHash = $this->ipSubjectHash($remoteAddress);

        return $this->repository->transaction(function () use (
            $existingSessionToken,
            $ipHash,
            $now
        ): SessionSecrets {
            $retentionSeconds = max(
                $this->rateLimitPolicy->windowSeconds(),
                $this->rateLimitPolicy->blockSeconds()
            ) * 2;
            $this->repository->purgeAuthenticationGarbage(
                $now,
                $now->modify('-' . $retentionSeconds . ' seconds'),
                self::GARBAGE_COLLECTION_BATCH_SIZE
            );

            $row = $this->sessionFromRawToken($existingSessionToken);
            if ($row !== null) {
                if (
                    $this->isUsablePreAuthenticationSession($row, $now)
                    && $this->sessionCsrfBindingIsValid(
                        (string) $existingSessionToken,
                        $row
                    )
                ) {
                    return $this->reusePreAuthenticationSession(
                        $row,
                        (string) $existingSessionToken,
                        $now
                    );
                }

                if (
                    ($row['session_type'] ?? null)
                        === SessionSecrets::PREAUTHENTICATED
                    && $row['revoked_at'] === null
                ) {
                    $this->repository->revokeSession(
                        $this->positiveInteger($row['id']),
                        $now
                    );
                }
            }

            $issueLimit = $this->readRateLimit(
                self::PREAUTH_ISSUE_ACTION,
                $ipHash,
                $now
            );
            if ($issueLimit['blocked']) {
                throw new PreAuthenticationRateLimited();
            }

            $session = $this->createSession(
                SessionSecrets::PREAUTHENTICATED,
                null,
                null,
                $now
            )['secrets'];
            $this->recordRateLimitFailure(
                self::PREAUTH_ISSUE_ACTION,
                $ipHash,
                $issueLimit,
                $this->rateLimitPolicy->preAuthenticationIssueLimit(),
                $now
            );

            return $session;
        });
    }

    /**
     * Authenticates through a valid pre-auth session and its bound CSRF token.
     * Every externally observable credential failure has the same error code
     * and reuses the valid pre-auth binding. Rejected request bindings are
     * replaced. Success revokes pre-auth and issues an unrelated authenticated
     * session token (fixation defense).
     */
    public function authenticate(
        #[\SensitiveParameter] string $preAuthenticationSessionToken,
        #[\SensitiveParameter] string $csrfToken,
        #[\SensitiveParameter] string $email,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $remoteAddress,
        #[\SensitiveParameter] ?string $userAgent = null
    ): AuthenticationAttempt {
        $now = $this->now();
        $canonicalEmail = $this->canonicalEmailOrNull($email);
        $identifierHash = $this->identifierSubjectHash(
            $email,
            $canonicalEmail
        );
        $ipHash = $this->ipSubjectHash($remoteAddress);
        $userAgentHash = $this->userAgentSubjectHash($userAgent);

        return $this->repository->transaction(function () use (
            $preAuthenticationSessionToken,
            $csrfToken,
            $password,
            $canonicalEmail,
            $identifierHash,
            $ipHash,
            $userAgentHash,
            $now
        ): AuthenticationAttempt {
            $preAuthentication = $this->sessionFromRawToken(
                $preAuthenticationSessionToken
            );
            if (
                $preAuthentication === null
                || !$this->isUsablePreAuthenticationSession(
                    $preAuthentication,
                    $now
                )
                || !$this->submittedCsrfIsValid(
                    $preAuthenticationSessionToken,
                    $csrfToken,
                    $preAuthentication
                )
            ) {
                /*
                 * A rejected POST must not bypass the bounded pre-auth
                 * issuance path. Without this gate, a caller could chain the
                 * replacement returned by each invalid request and create an
                 * unbounded number of session and audit rows without ever
                 * opening the rate-limited login form.
                 */
                $issueLimit = $this->readRateLimit(
                    self::PREAUTH_ISSUE_ACTION,
                    $ipHash,
                    $now
                );
                if ($issueLimit['blocked']) {
                    throw new PreAuthenticationRateLimited();
                }

                if (
                    $preAuthentication !== null
                    && $preAuthentication['revoked_at'] === null
                    && ($preAuthentication['session_type'] ?? null)
                        === SessionSecrets::PREAUTHENTICATED
                ) {
                    $this->repository->revokeSession(
                        $this->positiveInteger($preAuthentication['id']),
                        $now
                    );
                }

                $next = $this->createSession(
                        SessionSecrets::PREAUTHENTICATED,
                        null,
                        null,
                        $now
                    )['secrets'];
                $this->recordRateLimitFailure(
                    self::PREAUTH_ISSUE_ACTION,
                    $ipHash,
                    $issueLimit,
                    $this->rateLimitPolicy->preAuthenticationIssueLimit(),
                    $now
                );
                $this->audit(
                    'webadmin.login',
                    'failure',
                    'request_rejected',
                    null,
                    null,
                    $ipHash,
                    $userAgentHash,
                    $now
                );

                return AuthenticationAttempt::failed($next);
            }

            $identifierLimit = $this->readRateLimit(
                self::IDENTIFIER_ACTION,
                $identifierHash,
                $now
            );
            $ipLimit = $this->readRateLimit(
                self::IP_ACTION,
                $ipHash,
                $now
            );

            $blocked = $identifierLimit['blocked'] || $ipLimit['blocked'];
            if ($blocked) {
                // The threshold event was already persisted. Requests made
                // during a live block do no password hashing and create no
                // unbounded audit/rate-limit writes.
                return AuthenticationAttempt::failed(new SessionSecrets(
                    $preAuthenticationSessionToken,
                    $csrfToken,
                    SessionSecrets::PREAUTHENTICATED,
                    WebAdminAuthenticationRepository::parseTimestamp(
                        $preAuthentication['absolute_expires_at'] ?? null
                    )
                ));
            }

            $user = $this->repository->findUserCredentialByEmailForUpdate(
                $canonicalEmail
                    ?? '__invalid__' . hash('sha256', $identifierHash)
            );
            $eligible = $this->isEligibleCredential($user);
            $candidateHash = $eligible
                ? $this->stringValue($user['password_hash'] ?? null)
                : $this->passwordHasher->verificationDummyHash();
            $passwordMatches = $this->passwordHasher->verify(
                $password,
                $candidateHash
            );
            $capabilityUserId = $eligible
                ? $this->positiveInteger($user['id'] ?? null)
                : 0;
            $hasAccess = $this->repository->userHasCapability(
                $capabilityUserId,
                self::ACCESS_CAPABILITY
            );

            if (!$eligible || !$passwordMatches || !$hasAccess) {
                $identifierNowBlocked = $this->recordRateLimitFailure(
                    self::IDENTIFIER_ACTION,
                    $identifierHash,
                    $identifierLimit,
                    $this->rateLimitPolicy->identifierFailureLimit(),
                    $now
                );
                $ipNowBlocked = $this->recordRateLimitFailure(
                    self::IP_ACTION,
                    $ipHash,
                    $ipLimit,
                    $this->rateLimitPolicy->ipFailureLimit(),
                    $now
                );
                $this->audit(
                    'webadmin.login',
                    $identifierNowBlocked || $ipNowBlocked
                        ? 'denied'
                        : 'failure',
                    $identifierNowBlocked || $ipNowBlocked
                        ? 'rate_limited'
                        : (
                            $eligible && $passwordMatches && !$hasAccess
                                ? 'authorization_denied'
                                : 'authentication_failed'
                        ),
                    $eligible && $passwordMatches
                        ? $capabilityUserId
                        : null,
                    null,
                    $ipHash,
                    $userAgentHash,
                    $now
                );

                return AuthenticationAttempt::failed(
                    $this->reusePreAuthenticationSession(
                        $preAuthentication,
                        $preAuthenticationSessionToken,
                        $now
                    )
                );
            }

            $userId = $this->positiveInteger($user['id'] ?? null);
            $authVersion = $this->positiveInteger(
                $user['auth_version'] ?? null
            );
            $this->repository->deleteRateLimit(
                self::IDENTIFIER_ACTION,
                $identifierHash
            );
            $this->repository->revokeSession(
                $this->positiveInteger($preAuthentication['id']),
                $now
            );
            $issued = $this->createSession(
                SessionSecrets::AUTHENTICATED,
                $userId,
                $authVersion,
                $now
            );
            $this->repository->recordSuccessfulLogin($userId, $now);
            $this->audit(
                'webadmin.login',
                'success',
                null,
                $userId,
                $issued['public_id'],
                $ipHash,
                $userAgentHash,
                $now
            );

            return AuthenticationAttempt::succeeded(
                $issued['secrets'],
                $this->identityFromUser(
                    $issued['public_id'],
                    $user,
                    $issued['idle_expires_at'],
                    $issued['absolute_expires_at']
                )
            );
        });
    }

    /**
     * Resolves and slides a valid authenticated session. Invalid, expired,
     * suspended or auth-version-stale records fail closed and are revoked.
     */
    public function resolveAuthenticatedSession(
        #[\SensitiveParameter] string $sessionToken
    ): ?AuthenticatedSession {
        if (!$this->tokenGenerator->hasValidFormat($sessionToken)) {
            return null;
        }
        $now = $this->now();

        return $this->repository->transaction(function () use (
            $sessionToken,
            $now
        ): ?AuthenticatedSession {
            $locked = $this->lockAuthenticatedSession($sessionToken);
            $session = $locked['session'];
            if ($session === null) {
                return null;
            }

            $context = $this->activeAuthenticationContext(
                $session,
                $now,
                $locked['user']
            );
            if (
                $context === null
                || !$this->sessionCsrfBindingIsValid($sessionToken, $session)
            ) {
                if (
                    $context !== null
                    && ($session['revoked_at'] ?? null) === null
                ) {
                    $this->repository->revokeSession(
                        $this->positiveInteger($session['id']),
                        $now
                    );
                }
                return null;
            }

            $idleExpiresAt = $this->nextIdleExpiration(
                $now,
                $context['absolute_expires_at']
            );
            $this->repository->touchSession(
                $this->positiveInteger($session['id']),
                $now,
                $idleExpiresAt
            );

            return $this->identityFromUser(
                $this->stringValue($session['public_id'] ?? null),
                $context['user'],
                $idleExpiresAt,
                $context['absolute_expires_at']
            );
        });
    }

    /**
     * Returns the stable synchronizer token bound to an authenticated session.
     * Repeated GET/HEAD requests and concurrent tabs cannot invalidate forms.
     */
    public function authenticatedCsrfToken(
        #[\SensitiveParameter] string $sessionToken
    ): ?SessionCsrfToken {
        if (!$this->tokenGenerator->hasValidFormat($sessionToken)) {
            return null;
        }
        $now = $this->now();

        return $this->repository->transaction(function () use (
            $sessionToken,
            $now
        ): ?SessionCsrfToken {
            $locked = $this->lockAuthenticatedSession($sessionToken);
            $session = $locked['session'];
            if ($session === null) {
                return null;
            }
            $context = $this->activeAuthenticationContext(
                $session,
                $now,
                $locked['user']
            );
            if (
                $context === null
                || !$this->sessionCsrfBindingIsValid($sessionToken, $session)
            ) {
                if (
                    $context !== null
                    && ($session['revoked_at'] ?? null) === null
                ) {
                    $this->repository->revokeSession(
                        $this->positiveInteger($session['id']),
                        $now
                    );
                }
                return null;
            }

            $csrfToken = $this->csrfTokenForSession($sessionToken);
            $idleExpiresAt = $this->nextIdleExpiration(
                $now,
                $context['absolute_expires_at']
            );
            $this->repository->touchSession(
                $this->positiveInteger($session['id']),
                $now,
                $idleExpiresAt
            );

            return new SessionCsrfToken(
                $csrfToken,
                $idleExpiresAt,
                $context['absolute_expires_at']
            );
        });
    }

    /**
     * Validates a generic form submitted from the isolated pre-authentication
     * session. It grants no identity and never accepts action-bound sessions.
     */
    public function validatePreAuthenticationCsrf(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken
    ): bool {
        if (
            !$this->tokenGenerator->hasValidFormat($sessionToken)
            || !$this->tokenGenerator->hasValidFormat($csrfToken)
        ) {
            return false;
        }
        $now = $this->now();

        return $this->repository->transaction(function () use (
            $sessionToken,
            $csrfToken,
            $now
        ): bool {
            $session = $this->sessionFromRawToken($sessionToken);
            if (
                $session === null
                || !$this->isUsablePreAuthenticationSession($session, $now)
                || !$this->submittedCsrfIsValid(
                    $sessionToken,
                    $csrfToken,
                    $session
                )
            ) {
                return false;
            }

            $absoluteExpiresAt = WebAdminAuthenticationRepository::parseTimestamp(
                $session['absolute_expires_at'] ?? null
            );
            $this->repository->touchSession(
                $this->positiveInteger($session['id'] ?? null),
                $now,
                $this->nextIdleExpiration($now, $absoluteExpiresAt)
            );

            return true;
        });
    }

    /**
     * Browser logout requires both secrets. The boolean only tells the HTTP
     * adapter whether it may expire the cookie; its response body/status must
     * remain indistinguishable.
     */
    public function logout(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        #[\SensitiveParameter] string $remoteAddress = '',
        #[\SensitiveParameter] ?string $userAgent = null
    ): bool
    {
        if (!$this->tokenGenerator->hasValidFormat($sessionToken)) {
            return false;
        }
        $now = $this->now();
        $ipHash = $this->ipSubjectHash($remoteAddress);
        $userAgentHash = $this->userAgentSubjectHash($userAgent);

        return $this->repository->transaction(function () use (
            $sessionToken,
            $csrfToken,
            $ipHash,
            $userAgentHash,
            $now
        ): bool {
            $locked = $this->lockAuthenticatedSession($sessionToken);
            $session = $locked['session'];
            if ($session === null) {
                return false;
            }
            $context = $this->activeAuthenticationContext(
                $session,
                $now,
                $locked['user']
            );
            if (
                $context !== null
                && $this->submittedCsrfIsValid(
                    $sessionToken,
                    $csrfToken,
                    $session
                )
            ) {
                $this->repository->revokeSession(
                    $this->positiveInteger($session['id']),
                    $now
                );
                $user = $context['user'];
                $this->audit(
                    'webadmin.logout',
                    'success',
                    null,
                    $this->positiveInteger($user['id'] ?? null),
                    $this->stringValue($session['public_id'] ?? null),
                    $ipHash,
                    $userAgentHash,
                    $now
                );

                return true;
            }

            return false;
        });
    }

    /**
     * Privileged server-side invalidation primitive. HTTP logout must use
     * logout(), which enforces CSRF.
     */
    public function revokeSession(
        #[\SensitiveParameter] string $sessionToken
    ): void
    {
        if (!$this->tokenGenerator->hasValidFormat($sessionToken)) {
            return;
        }
        $now = $this->now();

        $this->repository->transaction(function () use (
            $sessionToken,
            $now
        ): void {
            $session = $this->lockAuthenticatedSession(
                $sessionToken
            )['session'];
            if ($session !== null && $session['revoked_at'] === null) {
                $this->repository->revokeSession(
                    $this->positiveInteger($session['id']),
                    $now
                );
            }
        });
    }

    /** @return array<string, mixed>|null */
    private function sessionFromRawToken(?string $token): ?array
    {
        if (
            !is_string($token)
            || !$this->tokenGenerator->hasValidFormat($token)
        ) {
            return null;
        }

        return $this->repository->findSessionForUpdate(
            $this->tokenGenerator->hashForStorage($token)
        );
    }

    /**
     * Authenticated-session operations follow user -> session. A snapshot is
     * never trusted: both rows are re-read under locks before validation.
     * Non-authenticated cookies lock only their unrelated session row.
     *
     * @return array{session: array<string, mixed>|null, user: array<string, mixed>|null}
     */
    private function lockAuthenticatedSession(string $token): array
    {
        $tokenHash = $this->tokenGenerator->hashForStorage($token);
        $reference = $this->repository->sessionReference($tokenHash);
        $user = null;
        if (
            ($reference['session_type'] ?? null)
                === SessionSecrets::AUTHENTICATED
        ) {
            $userId = $this->nullablePositiveInteger(
                $reference['user_id'] ?? null
            );
            if ($userId !== null) {
                $user = $this->repository
                    ->findUserCredentialByIdForUpdate($userId);
            }
        }

        return [
            'session' => $this->repository->findSessionForUpdate($tokenHash),
            'user' => $user,
        ];
    }

    /** @param array<string, mixed> $session */
    private function isUsablePreAuthenticationSession(
        array $session,
        DateTimeImmutable $now
    ): bool {
        if (
            ($session['session_type'] ?? null)
                !== SessionSecrets::PREAUTHENTICATED
            || ($session['revoked_at'] ?? null) !== null
            || ($session['user_id'] ?? null) !== null
            || ($session['auth_version'] ?? null) !== null
            || ($session['pending_action_token_id'] ?? null) !== null
        ) {
            return false;
        }

        return $now < WebAdminAuthenticationRepository::parseTimestamp(
            $session['idle_expires_at'] ?? null
        ) && $now < WebAdminAuthenticationRepository::parseTimestamp(
            $session['absolute_expires_at'] ?? null
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array{user: array<string, mixed>, absolute_expires_at: DateTimeImmutable}|null
     */
    private function activeAuthenticationContext(
        array $session,
        DateTimeImmutable $now,
        ?array $lockedUser
    ): ?array {
        $sessionId = $this->positiveInteger($session['id'] ?? null);
        if (
            ($session['session_type'] ?? null)
                !== SessionSecrets::AUTHENTICATED
            || ($session['revoked_at'] ?? null) !== null
            || ($session['pending_action_token_id'] ?? null) !== null
        ) {
            return null;
        }

        $idleExpiresAt = WebAdminAuthenticationRepository::parseTimestamp(
            $session['idle_expires_at'] ?? null
        );
        $absoluteExpiresAt = WebAdminAuthenticationRepository::parseTimestamp(
            $session['absolute_expires_at'] ?? null
        );
        if ($now >= $idleExpiresAt || $now >= $absoluteExpiresAt) {
            $this->repository->revokeSession($sessionId, $now);

            return null;
        }

        $userId = $this->nullablePositiveInteger($session['user_id'] ?? null);
        $sessionAuthVersion = $this->nullablePositiveInteger(
            $session['auth_version'] ?? null
        );
        if ($userId === null || $sessionAuthVersion === null) {
            $this->repository->revokeSession($sessionId, $now);

            return null;
        }

        $user = $lockedUser;
        if (
            !$this->isEligibleCredential($user)
            || $this->positiveInteger($user['id'] ?? null) !== $userId
            || $this->positiveInteger($user['auth_version'] ?? null)
                !== $sessionAuthVersion
        ) {
            $this->repository->revokeSession($sessionId, $now);

            return null;
        }

        return [
            'user' => $user,
            'absolute_expires_at' => $absoluteExpiresAt,
        ];
    }

    /** @param array<string, mixed>|null $user */
    private function isEligibleCredential(?array $user): bool
    {
        $userId = $user === null
            ? null
            : $this->nullablePositiveInteger($user['id'] ?? null);
        if (
            $user === null
            || ($user['status'] ?? null) !== 'active'
            || ($user['suspended_at'] ?? null) !== null
            || $userId === null
            || $this->nullablePositiveInteger(
                $user['auth_version'] ?? null
            ) === null
            || $this->nullablePositiveInteger(
                $user['credential_user_id'] ?? null
            ) !== $userId
            || !$this->isValidLifecycleTimestamp(
                $user['activated_at'] ?? null
            )
            || !$this->isValidLifecycleTimestamp(
                $user['password_set_at'] ?? null
            )
        ) {
            return false;
        }

        $hash = $user['password_hash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return $this->passwordHasher->isCurrentHash($hash);
    }

    private function isValidLifecycleTimestamp(mixed $value): bool
    {
        // Lifecycle drift is an ineligible identity, not an observable storage
        // error. Login therefore stays generic and live sessions are revoked
        // by activeAuthenticationContext().
        try {
            WebAdminAuthenticationRepository::parseTimestamp($value);
        } catch (AuthenticationStorageException) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function reusePreAuthenticationSession(
        array $session,
        string $rawSessionToken,
        DateTimeImmutable $now
    ): SessionSecrets {
        $csrfToken = $this->csrfTokenForSession($rawSessionToken);
        $absoluteExpiresAt = WebAdminAuthenticationRepository::parseTimestamp(
            $session['absolute_expires_at'] ?? null
        );
        $idleExpiresAt = $this->nextIdleExpiration($now, $absoluteExpiresAt);
        $this->repository->touchSession(
            $this->positiveInteger($session['id'] ?? null),
            $now,
            $idleExpiresAt
        );

        return new SessionSecrets(
            $rawSessionToken,
            $csrfToken,
            SessionSecrets::PREAUTHENTICATED,
            $absoluteExpiresAt
        );
    }

    /**
     * @return array{secrets: SessionSecrets, public_id: string, idle_expires_at: DateTimeImmutable, absolute_expires_at: DateTimeImmutable}
     */
    private function createSession(
        string $sessionType,
        ?int $userId,
        ?int $authVersion,
        DateTimeImmutable $now
    ): array {
        $sessionToken = $this->tokenGenerator->generate();
        $csrfToken = $this->csrfTokenForSession($sessionToken);
        $publicId = $this->uuidGenerator->generateV4();
        if (
            preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $publicId
            ) !== 1
        ) {
            throw new AuthenticationStorageException();
        }
        $absoluteExpiresAt = $this->addSeconds(
            $now,
            $sessionType === SessionSecrets::PREAUTHENTICATED
                ? min(
                    self::PREAUTH_ABSOLUTE_TTL_SECONDS,
                    $this->config->absoluteTtlSeconds()
                )
                : $this->config->absoluteTtlSeconds()
        );
        $idleExpiresAt = $this->nextIdleExpiration(
            $now,
            $absoluteExpiresAt,
            $sessionType === SessionSecrets::PREAUTHENTICATED
                ? min(
                    self::PREAUTH_ABSOLUTE_TTL_SECONDS,
                    $this->config->idleTtlSeconds()
                )
                : null
        );
        $this->repository->insertSession(
            $publicId,
            $userId,
            $sessionType,
            $this->tokenGenerator->hashForStorage($sessionToken),
            $this->tokenGenerator->hashForStorage($csrfToken),
            $authVersion,
            $now,
            $idleExpiresAt,
            $absoluteExpiresAt
        );

        return [
            'secrets' => new SessionSecrets(
                $sessionToken,
                $csrfToken,
                $sessionType,
                $absoluteExpiresAt
            ),
            'public_id' => $publicId,
            'idle_expires_at' => $idleExpiresAt,
            'absolute_expires_at' => $absoluteExpiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function identityFromUser(
        string $sessionPublicId,
        array $user,
        DateTimeImmutable $idleExpiresAt,
        DateTimeImmutable $absoluteExpiresAt
    ): AuthenticatedSession {
        $displayName = $user['display_name'] ?? null;
        if ($displayName !== null && !is_string($displayName)) {
            throw new AuthenticationStorageException();
        }

        return new AuthenticatedSession(
            $sessionPublicId,
            $this->positiveInteger($user['id'] ?? null),
            $this->stringValue($user['public_id'] ?? null),
            $this->stringValue($user['email_canonical'] ?? null),
            $displayName,
            $this->positiveInteger($user['auth_version'] ?? null),
            $idleExpiresAt,
            $absoluteExpiresAt
        );
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

        $windowStartedAt = WebAdminAuthenticationRepository::parseTimestamp(
            $row['window_started_at'] ?? null
        );
        $blockedUntil = ($row['blocked_until'] ?? null) === null
            ? null
            : WebAdminAuthenticationRepository::parseTimestamp(
                $row['blocked_until']
            );
        $attempts = $this->nonNegativeInteger($row['attempts'] ?? null);
        $windowExpired = $now >= $this->addSeconds(
            $windowStartedAt,
            $this->rateLimitPolicy->windowSeconds()
        );
        $blockExpired = $blockedUntil !== null && $now >= $blockedUntil;

        // A live block always wins over the accounting window. Otherwise an
        // attempt reaching the threshold near the end of the window could be
        // released as soon as that window elapsed, even though blocked_until
        // was still in the future.
        if ($blockedUntil !== null && !$blockExpired) {
            return [
                'exists' => true,
                'window_started_at' => $windowStartedAt,
                'attempts' => $attempts,
                'blocked_until' => $blockedUntil,
                'blocked' => true,
            ];
        }

        if ($windowExpired || $blockExpired) {
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
            'blocked_until' => $blockedUntil,
            'blocked' => $blockedUntil !== null && $now < $blockedUntil,
        ];
    }

    /**
     * @param array{exists: bool, window_started_at: DateTimeImmutable, attempts: int, blocked_until: DateTimeImmutable|null, blocked: bool} $state
     */
    private function recordRateLimitFailure(
        string $action,
        string $subjectHash,
        array $state,
        int $failureLimit,
        DateTimeImmutable $now
    ): bool {
        $attempts = $state['attempts'] >= $failureLimit
            ? $failureLimit
            : $state['attempts'] + 1;
        $blockedUntil = $state['blocked_until'];
        $newlyBlocked = $blockedUntil === null
            && $attempts >= $failureLimit;
        if ($newlyBlocked) {
            $blockedUntil = $this->addSeconds(
                $now,
                $this->rateLimitPolicy->blockSeconds()
            );
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

            return $newlyBlocked;
        }

        $this->repository->insertRateLimit(
            $action,
            $subjectHash,
            $state['window_started_at'],
            $attempts,
            $blockedUntil,
            $now,
            $failureLimit,
            $this->addSeconds(
                $now,
                $this->rateLimitPolicy->blockSeconds()
            )
        );

        return $newlyBlocked;
    }

    private function canonicalEmailOrNull(string $email): ?string
    {
        try {
            return EmailAddress::fromString($email)->value();
        } catch (InvalidEmailAddress) {
            return null;
        }
    }

    /** @param array<string, mixed> $session */
    private function sessionCsrfBindingIsValid(
        string $sessionToken,
        array $session
    ): bool
    {
        $storedHash = $session['csrf_token_hash'] ?? null;

        return is_string($storedHash)
            && $this->tokenGenerator->verify(
                $this->csrfTokenForSession($sessionToken),
                $storedHash
            );
    }

    /** @param array<string, mixed> $session */
    private function submittedCsrfIsValid(
        string $sessionToken,
        string $submittedToken,
        array $session
    ): bool {
        $expected = $this->csrfTokenForSession($sessionToken);

        return $this->sessionCsrfBindingIsValid($sessionToken, $session)
            && ConstantTime::equals($expected, $submittedToken);
    }

    private function csrfTokenForSession(string $sessionToken): string
    {
        return $this->securityKey->deriveToken(
            self::CSRF_DERIVATION_PURPOSE,
            $sessionToken
        );
    }

    private function identifierSubjectHash(
        string $rawEmail,
        ?string $canonicalEmail
    ): string {
        $subject = $canonicalEmail === null
            ? 'invalid:' . hash('sha256', $rawEmail)
            : 'email:' . $canonicalEmail;

        return $this->securityKey->subjectHash(
            self::IDENTIFIER_ACTION,
            $subject
        );
    }

    private function ipSubjectHash(string $remoteAddress): string
    {
        $packed = @inet_pton($remoteAddress);
        $subject = is_string($packed)
            ? 'ip:' . $packed
            : 'invalid';

        return $this->securityKey->subjectHash(self::IP_ACTION, $subject);
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

    private function audit(
        string $eventCode,
        string $outcome,
        ?string $reasonCode,
        ?int $actorUserId,
        ?string $actorSessionPublicId,
        ?string $ipHash,
        ?string $userAgentHash,
        DateTimeImmutable $now
    ): void {
        $requestId = $this->uuidGenerator->generateV4();
        if (
            preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $requestId
            ) !== 1
        ) {
            throw new AuthenticationStorageException();
        }
        $this->repository->insertAuditEvent(
            $requestId,
            $actorUserId,
            $actorSessionPublicId,
            $eventCode,
            $outcome,
            $reasonCode,
            $ipHash,
            $userAgentHash,
            $now
        );
    }

    private function nextIdleExpiration(
        DateTimeImmutable $now,
        DateTimeImmutable $absoluteExpiresAt,
        ?int $ttlSeconds = null
    ): DateTimeImmutable {
        $candidate = $this->addSeconds(
            $now,
            $ttlSeconds ?? $this->config->idleTtlSeconds()
        );

        return $candidate < $absoluteExpiresAt
            ? $candidate
            : $absoluteExpiresAt;
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

    private function positiveInteger(mixed $value): int
    {
        $parsed = $this->nullablePositiveInteger($value);
        if ($parsed === null) {
            throw new AuthenticationStorageException();
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

        throw new AuthenticationStorageException();
    }

    private function stringValue(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new AuthenticationStorageException();
        }

        return $value;
    }
}
