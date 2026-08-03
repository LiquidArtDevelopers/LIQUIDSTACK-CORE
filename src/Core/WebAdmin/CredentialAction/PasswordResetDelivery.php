<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\CredentialAction;

use App\Core\WebAdmin\Security\OpaqueSecret;
use LogicException;

/**
 * Process-local delivery material for one password-reset request.
 *
 * The database stores only the token hash. Recipient and raw token remain
 * opaque and cannot be exported or serialized accidentally.
 *
 * @internal
 */
final class PasswordResetDelivery
{
    private readonly OpaqueSecret $recipientEmail;
    private readonly OpaqueSecret $rawToken;

    public function __construct(
        private readonly int $actionTokenId,
        private readonly int $userId,
        private readonly int $authVersion,
        #[\SensitiveParameter] string $recipientEmail,
        private readonly string $locale,
        #[\SensitiveParameter] string $rawToken
    ) {
        if ($actionTokenId < 1 || $userId < 1 || $authVersion < 1) {
            throw new LogicException('Invalid password-reset delivery.');
        }

        $this->recipientEmail = OpaqueSecret::fromString($recipientEmail);
        $this->rawToken = OpaqueSecret::fromString($rawToken);
    }

    public function actionTokenId(): int
    {
        return $this->actionTokenId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function authVersion(): int
    {
        return $this->authVersion;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function recipientEmail(): string
    {
        return $this->recipientEmail->reveal();
    }

    public function rawToken(): string
    {
        return $this->rawToken->reveal();
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return [
            'actionTokenId' => $this->actionTokenId,
            'userId' => $this->userId,
            'authVersion' => $this->authVersion,
            'locale' => $this->locale,
            'recipientEmail' => '[redacted]',
            'rawToken' => '[redacted]',
        ];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new LogicException(
            'Password-reset deliveries cannot be serialized.'
        );
    }

    private function __clone()
    {
    }
}
