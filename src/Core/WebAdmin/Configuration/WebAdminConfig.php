<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Configuration;

use App\Core\Database\DatabaseConnectionProfile;
use InvalidArgumentException;

final class WebAdminConfig
{
    public const PROJECT_CONFIG_PATH = 'App/config/modules/webadmin.php';

    public const SHARED_DATABASE_ENV =
        DatabaseConnectionProfile::SHARED_ENVIRONMENT_NAMES;
    public const LIQUIDSTACK_DATABASE_ENV =
        DatabaseConnectionProfile::LIQUIDSTACK_ENVIRONMENT_NAMES;

    public const BOOTSTRAP_EMAIL_ENV = [
        'system_superadmin' => 'LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL',
        'site_admin' => 'LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL',
    ];

    public const SECURITY_KEY_ENV = 'LIQUIDSTACK_WEBADMIN_SECURITY_KEY';
    public const DEVELOPMENT_PROJECT_ID_ENV = 'LIQUIDSTACK_DEV_PROJECT_ID';

    public const DEFAULT_BASE_PATH = '/admin';
    public const DEFAULT_TABLE_PREFIX = 'ls_webadmin_';
    public const DEFAULT_COOKIE_NAME = 'LS_WEBADMIN_SID';
    public const PREAUTH_COOKIE_NAME = 'LS_WEBADMIN_PREAUTH';
    public const ACTION_COOKIE_NAME = 'LS_WEBADMIN_ACTION';
    public const DEFAULT_IDLE_TTL_SECONDS = 2_592_000;
    public const DEFAULT_ABSOLUTE_TTL_SECONDS = 2_592_000;
    public const MAX_SESSION_TTL_SECONDS = 2_592_000;

    /**
     * MySQL/MariaDB limit table identifiers to 64 characters. The prefix must
     * leave room for the longest canonical WebAdmin table suffix.
     */
    public const MYSQL_IDENTIFIER_MAX_LENGTH = 64;
    public const LONGEST_TABLE_SUFFIX = 'role_capabilities';
    public const LONGEST_TABLE_SUFFIX_LENGTH = 17;
    public const MAX_TABLE_PREFIX_LENGTH =
        self::MYSQL_IDENTIFIER_MAX_LENGTH
        - self::LONGEST_TABLE_SUFFIX_LENGTH;

    public const COOKIE_HTTP_ONLY = true;
    public const COOKIE_SECURE = true;
    public const COOKIE_SAME_SITE = 'Strict';
    public const PREAUTH_COOKIE_SAME_SITE = 'Lax';
    public const ACTION_COOKIE_SAME_SITE = 'Lax';
    public const COOKIE_HOST_ONLY = true;
    public const COOKIE_NAME_MAX_LENGTH = 64;
    public const DEVELOPMENT_PROJECT_ID_LENGTH = 24;

    private const DEVELOPMENT_COOKIE_SUFFIX_PREFIX = '_D_';

    private readonly ?string $developmentProjectId;

    public function __construct(
        private readonly string $basePath,
        private readonly string $tablePrefix,
        private readonly string $cookieName,
        private readonly int $idleTtlSeconds,
        private readonly int $absoluteTtlSeconds,
        private readonly string $source,
        private readonly string $databaseConnection =
            DatabaseConnectionProfile::SHARED,
        ?string $developmentProjectId = null
    ) {
        if (
            $developmentProjectId !== null
            && preg_match(
                '/\A[a-f0-9]{'
                    . self::DEVELOPMENT_PROJECT_ID_LENGTH
                    . '}\z/',
                $developmentProjectId
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid LiquidStack development project identifier.'
            );
        }

        $this->developmentProjectId = $developmentProjectId;
    }

    public static function defaults(?string $developmentProjectId = null): self
    {
        return new self(
            self::DEFAULT_BASE_PATH,
            self::DEFAULT_TABLE_PREFIX,
            self::DEFAULT_COOKIE_NAME,
            self::DEFAULT_IDLE_TTL_SECONDS,
            self::DEFAULT_ABSOLUTE_TTL_SECONDS,
            'defaults',
            DatabaseConnectionProfile::SHARED,
            $developmentProjectId
        );
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function tablePrefix(): string
    {
        return $this->tablePrefix;
    }

    public function databaseConnection(): string
    {
        return $this->databaseConnection;
    }

    public function cookieName(): string
    {
        return $this->developmentCookieName($this->cookieName, true);
    }

    public function cookiePath(): string
    {
        return $this->basePath;
    }

    /**
     * Credential links are bound to an isolated, short-lived session cookie.
     * It must never replace or be accepted as the authenticated cookie.
     */
    public function actionCookieName(): string
    {
        return $this->developmentCookieName(self::ACTION_COOKIE_NAME);
    }

    /**
     * Login and recovery forms use a separate browser cookie. A cross-site
     * navigation can therefore never replace an authenticated Strict cookie.
     */
    public function preAuthenticationCookieName(): string
    {
        return $this->developmentCookieName(self::PREAUTH_COOKIE_NAME);
    }

    public function idleTtlSeconds(): int
    {
        return $this->idleTtlSeconds;
    }

    public function absoluteTtlSeconds(): int
    {
        return $this->absoluteTtlSeconds;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function withBasePath(string $basePath): self
    {
        return new self(
            $basePath,
            $this->tablePrefix,
            $this->cookieName,
            $this->idleTtlSeconds,
            $this->absoluteTtlSeconds,
            $this->source,
            $this->databaseConnection,
            $this->developmentProjectId
        );
    }

    /**
     * Returns only non-secret settings suitable for diagnostics.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'source' => $this->source,
            'path' => $this->basePath,
            'database' => [
                'connection' => $this->databaseConnection(),
                'table_prefix' => $this->tablePrefix,
                'environment_names' => DatabaseConnectionProfile::environmentNames(
                    $this->databaseConnection()
                ),
            ],
            'session' => [
                'cookie_name' => $this->cookieName(),
                'preauth_cookie_name' =>
                    $this->preAuthenticationCookieName(),
                'preauth_cookie_same_site' =>
                    self::PREAUTH_COOKIE_SAME_SITE,
                'action_cookie_name' => $this->actionCookieName(),
                'action_cookie_same_site' => self::ACTION_COOKIE_SAME_SITE,
                'cookie_path' => $this->cookiePath(),
                'secure' => self::COOKIE_SECURE,
                'http_only' => self::COOKIE_HTTP_ONLY,
                'same_site' => self::COOKIE_SAME_SITE,
                'host_only' => self::COOKIE_HOST_ONLY,
                'development_isolated' =>
                    $this->developmentProjectId !== null,
                'idle_ttl_seconds' => $this->idleTtlSeconds,
                'absolute_ttl_seconds' => $this->absoluteTtlSeconds,
            ],
        ];
    }

    private function developmentCookieName(
        string $baseName,
        bool $truncateBase = false
    ): string {
        if ($this->developmentProjectId === null) {
            return $baseName;
        }

        $suffix = self::DEVELOPMENT_COOKIE_SUFFIX_PREFIX
            . $this->developmentProjectId;
        if (
            $truncateBase
            && strlen($baseName) + strlen($suffix)
                > self::COOKIE_NAME_MAX_LENGTH
        ) {
            $baseName = substr(
                $baseName,
                0,
                self::COOKIE_NAME_MAX_LENGTH - strlen($suffix)
            );
        }

        return $baseName . $suffix;
    }
}
