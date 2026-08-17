<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Profile;

use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;

final class WebAdminProfileService
{
    public function __construct(
        private readonly PdoWebAdminProfileRepository $repository,
        private readonly WebAdminAuthenticationService $authentication,
        private readonly WebAdminAuthorizationService $authorization,
        private readonly WebAdminMutationActorGate $actorGate,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly UuidGeneratorInterface $uuidGenerator =
            new RandomUuidV4Generator()
    ) {
    }

    public function current(string $sessionToken): ?WebAdminPublicProfile
    {
        $session = $this->authentication->resolveAuthenticatedSession(
            $sessionToken
        );
        if (
            $session === null
            || !$this->authorization->hasCapability(
                $sessionToken,
                'webadmin.profile.manage_self'
            )
        ) {
            return null;
        }
        return $this->repository->liveByPublicId($session->userPublicId());
    }

    public function livePublicProfile(string $userPublicId): ?WebAdminPublicProfile
    {
        return $this->repository->liveByPublicId($userPublicId);
    }

    public function updateCurrent(
        string $sessionToken,
        string $csrfToken,
        string $displayName,
        string $timeZone,
        int $expectedLockVersion
    ): bool {
        if ($expectedLockVersion < 0 || $expectedLockVersion > PHP_INT_MAX - 1) {
            return false;
        }
        $displayName = trim($displayName);
        $displayLength = function_exists('mb_strlen')
            ? mb_strlen($displayName, 'UTF-8')
            : preg_match_all('/./us', $displayName, $unused);
        if (
            preg_match('//u', $displayName) !== 1
            || !is_int($displayLength)
            || $displayLength > 120
            || preg_match('/[\x00-\x1F\x7F]/', $displayName)
        ) {
            return false;
        }
        $zone = trim($timeZone) === ''
            ? null : WebAdminTimeZone::fromIana($timeZone);

        return $this->repository->transactional(function () use (
            $sessionToken,
            $csrfToken,
            $displayName,
            $zone,
            $expectedLockVersion
        ): bool {
            $actor = $this->actorGate->authorize(
                $sessionToken,
                $csrfToken,
                'webadmin.profile.manage_self'
            );
            if ($actor === null) {
                return false;
            }
            $now = $this->clock->now();
            if (!$this->repository->update(
                $actor,
                $displayName === '' ? null : $displayName,
                $zone,
                $expectedLockVersion,
                $now
            )) {
                return false;
            }
            $this->repository->audit(
                $this->uuidGenerator->generateV4(),
                $actor,
                $now
            );
            return true;
        });
    }
}
