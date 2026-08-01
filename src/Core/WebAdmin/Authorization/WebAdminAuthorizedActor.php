<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authorization;

use LogicException;

/**
 * Minimal identity envelope returned to a module mutation transaction.
 *
 * It deliberately carries no session token, CSRF token, email address,
 * credential material or capability snapshot. Authorization is live and must
 * be repeated for every mutation transaction.
 */
final class WebAdminAuthorizedActor
{
    public function __construct(
        private readonly int $userId,
        private readonly string $userPublicId,
        private readonly string $sessionPublicId
    ) {
        if (
            $userId < 1
            || !self::isUuidV4($userPublicId)
            || !self::isUuidV4($sessionPublicId)
        ) {
            throw new LogicException('Invalid WebAdmin authorized actor.');
        }
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function userPublicId(): string
    {
        return $this->userPublicId;
    }

    public function sessionPublicId(): string
    {
        return $this->sessionPublicId;
    }

    /** @return array{user_id: int, user_public_id: string, session_public_id: string} */
    public function __debugInfo(): array
    {
        return [
            'user_id' => $this->userId,
            'user_public_id' => $this->userPublicId,
            'session_public_id' => $this->sessionPublicId,
        ];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new LogicException(
            'WebAdmin authorized actors cannot be serialized.'
        );
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException(
            'WebAdmin authorized actors cannot be unserialized.'
        );
    }

    private static function isUuidV4(string $value): bool
    {
        return preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $value
        ) === 1;
    }
}
