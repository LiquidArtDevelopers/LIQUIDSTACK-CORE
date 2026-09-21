<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use InvalidArgumentException;

final class CommerceInquiryEnvironment
{
    public const RECIPIENT_ENV = 'LIQUIDSTACK_COMMERCE_INQUIRY_RECIPIENT';
    public const LEGACY_RECIPIENT_ENV = 'MAIL_ADMIN';
    public const PRIVACY_VERSION_ENV = 'LIQUIDSTACK_COMMERCE_PRIVACY_VERSION';

    private function __construct(
        private readonly string $recipientEmail,
        private readonly string $privacyVersion
    ) {
    }

    /** @param array<string, mixed> $environment */
    public static function fromEnvironment(
        #[\SensitiveParameter] array $environment
    ): self {
        $recipient = $environment[self::RECIPIENT_ENV]
            ?? $environment[self::LEGACY_RECIPIENT_ENV]
            ?? null;
        if (!is_string($recipient)) {
            throw new InvalidArgumentException(
                'Commerce inquiry recipient is unavailable.'
            );
        }
        $recipient = strtolower(trim($recipient));
        if (
            strlen($recipient) > 254
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException(
                'Commerce inquiry recipient is invalid.'
            );
        }
        $privacyVersion = $environment[self::PRIVACY_VERSION_ENV] ?? 'v1';
        if (
            !is_string($privacyVersion)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $privacyVersion)
                !== 1
        ) {
            throw new InvalidArgumentException(
                'Commerce privacy version is invalid.'
            );
        }

        return new self($recipient, $privacyVersion);
    }

    public function recipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function privacyVersion(): string
    {
        return $this->privacyVersion;
    }
}
