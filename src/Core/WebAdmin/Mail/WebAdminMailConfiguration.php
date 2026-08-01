<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use App\Core\WebAdmin\Security\OpaqueSecret;
use LogicException;

final class WebAdminMailConfiguration
{
    public const PUBLIC_ORIGIN_ENV = 'LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN';
    public const SMTP_HOST_ENV = 'LIQUIDSTACK_WEBADMIN_SMTP_HOST';
    public const SMTP_PORT_ENV = 'LIQUIDSTACK_WEBADMIN_SMTP_PORT';
    public const SMTP_ENCRYPTION_ENV = 'LIQUIDSTACK_WEBADMIN_SMTP_ENCRYPTION';
    public const SMTP_USERNAME_ENV = 'LIQUIDSTACK_WEBADMIN_SMTP_USERNAME';
    public const SMTP_PASSWORD_ENV = 'LIQUIDSTACK_WEBADMIN_SMTP_PASSWORD';
    public const FROM_ADDRESS_ENV = 'LIQUIDSTACK_WEBADMIN_MAIL_FROM_ADDRESS';
    public const FROM_NAME_ENV = 'LIQUIDSTACK_WEBADMIN_MAIL_FROM_NAME';

    public const REQUIRED_ENV = [
        self::PUBLIC_ORIGIN_ENV,
        self::SMTP_HOST_ENV,
        self::SMTP_PORT_ENV,
        self::SMTP_ENCRYPTION_ENV,
        self::SMTP_USERNAME_ENV,
        self::SMTP_PASSWORD_ENV,
        self::FROM_ADDRESS_ENV,
        self::FROM_NAME_ENV,
    ];

    public const ENCRYPTION_STARTTLS = 'starttls';
    public const ENCRYPTION_SMTPS = 'smtps';
    public const SMTP_TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly string $publicOrigin,
        private readonly string $smtpHost,
        private readonly int $smtpPort,
        private readonly string $smtpEncryption,
        private readonly OpaqueSecret $smtpUsername,
        private readonly OpaqueSecret $smtpPassword,
        private readonly string $fromAddress,
        private readonly string $fromName
    ) {
    }

    public function publicOrigin(): string
    {
        return $this->publicOrigin;
    }

    public function smtpHost(): string
    {
        return $this->smtpHost;
    }

    public function smtpPort(): int
    {
        return $this->smtpPort;
    }

    public function smtpEncryption(): string
    {
        return $this->smtpEncryption;
    }

    public function smtpUsername(): string
    {
        return $this->smtpUsername->reveal();
    }

    public function smtpPassword(): string
    {
        return $this->smtpPassword->reveal();
    }

    public function fromAddress(): string
    {
        return $this->fromAddress;
    }

    public function fromName(): string
    {
        return $this->fromName;
    }

    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        return [
            'transport' => 'smtp',
            'public_origin_scheme' => 'https',
            'encryption' => $this->smtpEncryption,
            'timeout_seconds' => self::SMTP_TIMEOUT_SECONDS,
            'required_environment_names' => self::REQUIRED_ENV,
        ];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['configuration' => '[redacted]'];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new LogicException(
            'WebAdmin mail configuration cannot be serialized.'
        );
    }
}
