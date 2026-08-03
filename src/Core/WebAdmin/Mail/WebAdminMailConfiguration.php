<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use App\Core\Environment\ProjectRuntimeProfile;
use App\Core\WebAdmin\Security\OpaqueSecret;
use LogicException;

final class WebAdminMailConfiguration
{
    /** @var list<string> */
    private readonly array $effectiveRequiredEnvironmentNames;
    private readonly string $source;

    public const TRANSPORT_ENV = 'LIQUIDSTACK_WEBADMIN_MAIL_TRANSPORT';
    public const PUBLIC_ORIGIN_ENV = 'LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN';
    public const SMTP_HOST_ENV = 'LIQUIDSTACK_WEBADMIN_SMTP_HOST';
    public const SMTP_PORT_ENV = 'LIQUIDSTACK_WEBADMIN_SMTP_PORT';
    public const SMTP_ENCRYPTION_ENV = 'LIQUIDSTACK_WEBADMIN_SMTP_ENCRYPTION';
    public const SMTP_USERNAME_ENV = 'LIQUIDSTACK_WEBADMIN_SMTP_USERNAME';
    public const SMTP_PASSWORD_ENV = 'LIQUIDSTACK_WEBADMIN_SMTP_PASSWORD';
    public const FROM_ADDRESS_ENV = 'LIQUIDSTACK_WEBADMIN_MAIL_FROM_ADDRESS';
    public const FROM_NAME_ENV = 'LIQUIDSTACK_WEBADMIN_MAIL_FROM_NAME';

    public const GENERAL_SMTP_HOST_ENV = 'MAIL_HOST';
    public const GENERAL_SMTP_PORT_ENV = 'MAIL_PORT';
    public const GENERAL_SMTP_ENCRYPTION_ENV = 'MAIL_ENCRYPTION';
    public const GENERAL_SMTP_USERNAME_ENV = 'MAIL_USERNAME';
    public const GENERAL_SMTP_PASSWORD_ENV = 'MAIL_PASSWORD';
    public const GENERAL_FROM_NAME_ENV = 'MAIL_FROM_NAME';
    public const GENERAL_LEGACY_FROM_NAME_ENV = 'EMISOR_NAME';

    public const TRANSPORT_SMTP = 'smtp';
    public const TRANSPORT_LOCAL_CAPTURE_SMTP = 'local_capture_smtp';

    public const SOURCE_GENERAL_MAIL = 'general_mail';
    public const SOURCE_LEGACY_WEBADMIN = 'legacy_webadmin';
    public const SOURCE_LOCAL_CAPTURE = 'local_capture';

    public const LEGACY_REQUIRED_ENV = [
        self::PUBLIC_ORIGIN_ENV,
        self::SMTP_HOST_ENV,
        self::SMTP_PORT_ENV,
        self::SMTP_ENCRYPTION_ENV,
        self::SMTP_USERNAME_ENV,
        self::SMTP_PASSWORD_ENV,
        self::FROM_ADDRESS_ENV,
        self::FROM_NAME_ENV,
    ];

    public const LEGACY_SELECTION_ENV = [
        self::SMTP_HOST_ENV,
        self::SMTP_PORT_ENV,
        self::SMTP_ENCRYPTION_ENV,
        self::SMTP_USERNAME_ENV,
        self::SMTP_PASSWORD_ENV,
        self::FROM_ADDRESS_ENV,
        self::FROM_NAME_ENV,
    ];

    /** @deprecated Use LEGACY_REQUIRED_ENV or GENERAL_REQUIRED_ENV. */
    public const REQUIRED_ENV = self::LEGACY_REQUIRED_ENV;

    public const GENERAL_REQUIRED_ENV = [
        ProjectRuntimeProfile::ORIGIN_ENV,
        ProjectRuntimeProfile::DEVELOPMENT_MODE_ENV,
        self::GENERAL_SMTP_HOST_ENV,
        self::GENERAL_SMTP_PORT_ENV,
        self::GENERAL_SMTP_USERNAME_ENV,
        self::GENERAL_SMTP_PASSWORD_ENV,
    ];

    public const LOCAL_CAPTURE_REQUIRED_ENV = [
        ProjectRuntimeProfile::ORIGIN_ENV,
        ProjectRuntimeProfile::DEVELOPMENT_MODE_ENV,
        self::TRANSPORT_ENV,
        self::SMTP_HOST_ENV,
        self::SMTP_PORT_ENV,
        self::FROM_ADDRESS_ENV,
        self::FROM_NAME_ENV,
    ];

    public const ENCRYPTION_NONE = 'none';
    public const ENCRYPTION_STARTTLS = 'starttls';
    public const ENCRYPTION_SMTPS = 'smtps';
    public const SMTP_TIMEOUT_SECONDS = 15;

    /** @param ?list<string> $requiredEnvironmentNames */
    public function __construct(
        private readonly string $publicOrigin,
        private readonly string $smtpHost,
        private readonly int $smtpPort,
        private readonly string $smtpEncryption,
        private readonly OpaqueSecret $smtpUsername,
        private readonly OpaqueSecret $smtpPassword,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $transport = self::TRANSPORT_SMTP,
        private readonly bool $smtpAuthentication = true,
        ?string $source = null,
        ?array $requiredEnvironmentNames = null
    ) {
        $this->source = $source ?? (
            $this->transport === self::TRANSPORT_LOCAL_CAPTURE_SMTP
                ? self::SOURCE_LOCAL_CAPTURE
                : self::SOURCE_LEGACY_WEBADMIN
        );
        $this->effectiveRequiredEnvironmentNames =
            $requiredEnvironmentNames ?? match ($this->source) {
                self::SOURCE_GENERAL_MAIL => self::GENERAL_REQUIRED_ENV,
                self::SOURCE_LOCAL_CAPTURE => self::LOCAL_CAPTURE_REQUIRED_ENV,
                default => self::LEGACY_REQUIRED_ENV,
            };
    }

    public function transport(): string
    {
        return $this->transport;
    }

    public function isProductionSmtp(): bool
    {
        return $this->transport === self::TRANSPORT_SMTP;
    }

    public function isLocalCaptureSmtp(): bool
    {
        return $this->transport === self::TRANSPORT_LOCAL_CAPTURE_SMTP;
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

    public function usesSmtpAuthentication(): bool
    {
        return $this->smtpAuthentication;
    }

    public function fromAddress(): string
    {
        return $this->fromAddress;
    }

    public function fromName(): string
    {
        return $this->fromName;
    }

    public function source(): string
    {
        return $this->source;
    }

    /** @return list<string> */
    public function requiredEnvironmentNames(): array
    {
        return $this->effectiveRequiredEnvironmentNames;
    }

    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        return [
            'transport' => $this->transport,
            'source' => $this->source,
            'public_origin_scheme' => (string) parse_url(
                $this->publicOrigin,
                PHP_URL_SCHEME
            ),
            'encryption' => $this->smtpEncryption,
            'timeout_seconds' => self::SMTP_TIMEOUT_SECONDS,
            'required_environment_names' =>
                $this->requiredEnvironmentNames(),
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
