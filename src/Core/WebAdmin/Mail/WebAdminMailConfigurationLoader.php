<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use App\Core\Environment\ProjectRuntimeProfile;
use App\Core\WebAdmin\Security\EmailAddress;
use App\Core\WebAdmin\Security\InvalidEmailAddress;
use App\Core\WebAdmin\Security\OpaqueSecret;
use InvalidArgumentException;

final class WebAdminMailConfigurationLoader
{
    /**
     * This boundary consumes an already-loaded environment and never reads
     * `.env` itself. Exceptions identify only a variable name, never a value.
     *
     * @param array<string, mixed> $environment
     */
    public function load(
        #[\SensitiveParameter] array $environment
    ): WebAdminMailConfiguration {
        [$missing, $invalid] = $this->inspect($environment);
        if ($missing !== []) {
            throw new WebAdminMailConfigurationException(
                'mail.environment_missing',
                $missing[0]
            );
        }
        if ($invalid !== []) {
            throw new WebAdminMailConfigurationException(
                'mail.environment_invalid',
                $invalid[0]
            );
        }

        if (
            $this->safeTransportName($environment)
                === WebAdminMailConfiguration::TRANSPORT_LOCAL_CAPTURE_SMTP
        ) {
            return $this->loadLocalCapture($environment);
        }

        return $this->usesLegacySmtpNamespace($environment)
            ? $this->loadLegacySmtp($environment)
            : $this->loadGeneralSmtp($environment);
    }

    /**
     * @param array<string, mixed> $environment
     * @return array{0: list<string>, 1: list<string>}
     */
    public function inspect(
        #[\SensitiveParameter] array $environment
    ): array {
        $transport = $this->safeTransportName($environment);
        if ($transport === 'invalid') {
            return [[], [WebAdminMailConfiguration::TRANSPORT_ENV]];
        }

        if (
            $transport
                === WebAdminMailConfiguration::TRANSPORT_LOCAL_CAPTURE_SMTP
        ) {
            return $this->inspectLocalCapture($environment);
        }

        return $this->usesLegacySmtpNamespace($environment)
            ? $this->inspectLegacySmtp($environment)
            : $this->inspectGeneralSmtp($environment);
    }

    /** @param array<string, mixed> $environment */
    public function safeTransportName(
        #[\SensitiveParameter] array $environment
    ): string {
        $transport = $environment[
            WebAdminMailConfiguration::TRANSPORT_ENV
        ] ?? null;
        if ($transport === null || $transport === '') {
            return WebAdminMailConfiguration::TRANSPORT_SMTP;
        }
        if (!is_string($transport)) {
            return 'invalid';
        }

        return in_array($transport, [
            WebAdminMailConfiguration::TRANSPORT_SMTP,
            WebAdminMailConfiguration::TRANSPORT_LOCAL_CAPTURE_SMTP,
        ], true) ? $transport : 'invalid';
    }

    /**
     * @param array<string, mixed> $environment
     * @return list<string>
     */
    public function requiredEnvironmentNames(
        #[\SensitiveParameter] array $environment
    ): array {
        if (
            $this->safeTransportName($environment)
                === WebAdminMailConfiguration::TRANSPORT_LOCAL_CAPTURE_SMTP
        ) {
            return WebAdminMailConfiguration::LOCAL_CAPTURE_REQUIRED_ENV;
        }

        if ($this->usesLegacySmtpNamespace($environment)) {
            return WebAdminMailConfiguration::LEGACY_REQUIRED_ENV;
        }

        $required = array_slice(
            WebAdminMailConfiguration::GENERAL_REQUIRED_ENV,
            0,
            4
        );
        $configuredEncryption = $environment[
            WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV
        ] ?? null;
        $configuredPort = $environment[
            WebAdminMailConfiguration::GENERAL_SMTP_PORT_ENV
        ] ?? null;
        if (
            ($configuredEncryption !== null
                && $configuredEncryption !== '')
            || !is_string($configuredPort)
            || !$this->supportsLegacyEncryptionFallback($configuredPort)
        ) {
            $required[] =
                WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV;
        }
        $required = array_merge(
            $required,
            array_slice(WebAdminMailConfiguration::GENERAL_REQUIRED_ENV, 4)
        );
        $configuredFromName = $environment[
            WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV
        ] ?? null;
        $legacyFromName = $environment[
            WebAdminMailConfiguration::GENERAL_LEGACY_FROM_NAME_ENV
        ] ?? null;
        $required[] = (
            ($configuredFromName !== null
                && $configuredFromName !== '')
            || $legacyFromName === null
            || $legacyFromName === ''
        )
            ? WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV
            : WebAdminMailConfiguration::GENERAL_LEGACY_FROM_NAME_ENV;

        return $required;
    }

    /** @param array<string, mixed> $environment */
    private function loadLegacySmtp(
        #[\SensitiveParameter] array $environment
    ): WebAdminMailConfiguration {
        return new WebAdminMailConfiguration(
            rtrim((string) $environment[
                WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV
            ], '/'),
            trim((string) $environment[
                WebAdminMailConfiguration::SMTP_HOST_ENV
            ]),
            (int) (string) $environment[
                WebAdminMailConfiguration::SMTP_PORT_ENV
            ],
            strtolower(trim((string) $environment[
                WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV
            ])),
            OpaqueSecret::fromString((string) $environment[
                WebAdminMailConfiguration::SMTP_USERNAME_ENV
            ]),
            OpaqueSecret::fromString((string) $environment[
                WebAdminMailConfiguration::SMTP_PASSWORD_ENV
            ]),
            EmailAddress::fromString((string) $environment[
                WebAdminMailConfiguration::FROM_ADDRESS_ENV
            ])->value(),
            trim((string) $environment[
                WebAdminMailConfiguration::FROM_NAME_ENV
            ]),
            WebAdminMailConfiguration::TRANSPORT_SMTP,
            true,
            WebAdminMailConfiguration::SOURCE_LEGACY_WEBADMIN
        );
    }

    /** @param array<string, mixed> $environment */
    private function loadGeneralSmtp(
        #[\SensitiveParameter] array $environment
    ): WebAdminMailConfiguration {
        try {
            $profile = ProjectRuntimeProfile::fromEnvironment($environment);
        } catch (InvalidArgumentException) {
            throw new WebAdminMailConfigurationException(
                'mail.environment_invalid',
                ProjectRuntimeProfile::ORIGIN_ENV
            );
        }

        $username = (string) $environment[
            WebAdminMailConfiguration::GENERAL_SMTP_USERNAME_ENV
        ];

        return new WebAdminMailConfiguration(
            $profile->origin(),
            trim((string) $environment[
                WebAdminMailConfiguration::GENERAL_SMTP_HOST_ENV
            ]),
            (int) (string) $environment[
                WebAdminMailConfiguration::GENERAL_SMTP_PORT_ENV
            ],
            $this->resolvedGeneralEncryption($environment),
            OpaqueSecret::fromString($username),
            OpaqueSecret::fromString((string) $environment[
                WebAdminMailConfiguration::GENERAL_SMTP_PASSWORD_ENV
            ]),
            EmailAddress::fromString($username)->value(),
            $this->resolvedGeneralFromName($environment),
            WebAdminMailConfiguration::TRANSPORT_SMTP,
            true,
            WebAdminMailConfiguration::SOURCE_GENERAL_MAIL,
            $this->requiredEnvironmentNames($environment)
        );
    }

    /**
     * @param array<string, mixed> $environment
     * @return array{0: list<string>, 1: list<string>}
     */
    private function inspectLegacySmtp(
        #[\SensitiveParameter] array $environment
    ): array {
        [$missing, $invalid] = $this->inspectRequiredStrings(
            $environment,
            WebAdminMailConfiguration::LEGACY_REQUIRED_ENV
        );
        if ($missing !== [] || $invalid !== []) {
            return [$missing, $invalid];
        }

        $checks = [
            WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV =>
                $this->validPublicOrigin((string) $environment[
                    WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV
                ]),
            WebAdminMailConfiguration::SMTP_HOST_ENV =>
                $this->validHost((string) $environment[
                    WebAdminMailConfiguration::SMTP_HOST_ENV
                ]),
            WebAdminMailConfiguration::SMTP_PORT_ENV =>
                $this->validPort((string) $environment[
                    WebAdminMailConfiguration::SMTP_PORT_ENV
                ]),
            WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV =>
                $this->validEncryption((string) $environment[
                    WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV
                ]),
            WebAdminMailConfiguration::SMTP_USERNAME_ENV =>
                $this->validCredential((string) $environment[
                    WebAdminMailConfiguration::SMTP_USERNAME_ENV
                ], 254),
            WebAdminMailConfiguration::SMTP_PASSWORD_ENV =>
                $this->validCredential((string) $environment[
                    WebAdminMailConfiguration::SMTP_PASSWORD_ENV
                ], 4096),
            WebAdminMailConfiguration::FROM_ADDRESS_ENV =>
                $this->validEmail((string) $environment[
                    WebAdminMailConfiguration::FROM_ADDRESS_ENV
                ]),
            WebAdminMailConfiguration::FROM_NAME_ENV =>
                $this->validDisplayName((string) $environment[
                    WebAdminMailConfiguration::FROM_NAME_ENV
                ]),
        ];

        return [[], $this->invalidCheckNames($checks)];
    }

    /**
     * @param array<string, mixed> $environment
     * @return array{0: list<string>, 1: list<string>}
     */
    private function inspectGeneralSmtp(
        #[\SensitiveParameter] array $environment
    ): array {
        [$missing, $invalid] = $this->inspectRequiredStrings(
            $environment,
            $this->requiredEnvironmentNames($environment)
        );
        if ($missing !== [] || $invalid !== []) {
            return [$missing, $invalid];
        }

        $configuredFromName = $environment[
            WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV
        ] ?? null;
        $selectedFromNameEnv = $configuredFromName !== null
            && $configuredFromName !== ''
                ? WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV
                : WebAdminMailConfiguration::GENERAL_LEGACY_FROM_NAME_ENV;
        $encryptionName =
            WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV;
        $encryptionValue = $environment[$encryptionName] ?? null;

        try {
            ProjectRuntimeProfile::fromEnvironment($environment);
            $validProfile = true;
        } catch (InvalidArgumentException) {
            $validProfile = false;
        }

        $username = (string) $environment[
            WebAdminMailConfiguration::GENERAL_SMTP_USERNAME_ENV
        ];
        $fromName = $this->resolvedGeneralFromName($environment);
        $checks = [
            ProjectRuntimeProfile::ORIGIN_ENV => $validProfile,
            ProjectRuntimeProfile::DEVELOPMENT_MODE_ENV =>
                $this->validDevelopmentMode((string) $environment[
                    ProjectRuntimeProfile::DEVELOPMENT_MODE_ENV
                ]),
            WebAdminMailConfiguration::GENERAL_SMTP_HOST_ENV =>
                $this->validHost((string) $environment[
                    WebAdminMailConfiguration::GENERAL_SMTP_HOST_ENV
                ]),
            WebAdminMailConfiguration::GENERAL_SMTP_PORT_ENV =>
                $this->validPort((string) $environment[
                    WebAdminMailConfiguration::GENERAL_SMTP_PORT_ENV
                ]),
            WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV =>
                ($encryptionValue === null || $encryptionValue === '')
                    ? $this->supportsLegacyEncryptionFallback((string) (
                        $environment[
                            WebAdminMailConfiguration::GENERAL_SMTP_PORT_ENV
                        ]
                    ))
                    : is_string($encryptionValue)
                        && $this->validEncryption($encryptionValue),
            WebAdminMailConfiguration::GENERAL_SMTP_USERNAME_ENV =>
                trim($username) === $username
                && $this->validCredential($username, 254)
                && $this->validEmail($username),
            WebAdminMailConfiguration::GENERAL_SMTP_PASSWORD_ENV =>
                $this->validCredential((string) $environment[
                    WebAdminMailConfiguration::GENERAL_SMTP_PASSWORD_ENV
                ], 4096),
            $selectedFromNameEnv =>
                $this->validDisplayName($fromName),
        ];

        return [[], $this->invalidCheckNames($checks)];
    }

    /**
     * @param array<string, mixed> $environment
     * @param list<string> $required
     * @return array{0: list<string>, 1: list<string>}
     */
    private function inspectRequiredStrings(
        #[\SensitiveParameter] array $environment,
        array $required
    ): array {
        $missing = [];
        $invalid = [];
        foreach ($required as $name) {
            if (
                !array_key_exists($name, $environment)
                || $environment[$name] === null
                || $environment[$name] === ''
            ) {
                $missing[] = $name;
                continue;
            }
            if (!is_string($environment[$name])) {
                $invalid[] = $name;
            }
        }

        return [$missing, $invalid];
    }

    /**
     * @param array<string, bool> $checks
     * @return list<string>
     */
    private function invalidCheckNames(array $checks): array
    {
        return array_keys(array_filter(
            $checks,
            static fn (bool $valid): bool => !$valid
        ));
    }

    /** @param array<string, mixed> $environment */
    private function usesLegacySmtpNamespace(
        #[\SensitiveParameter] array $environment
    ): bool {
        foreach (WebAdminMailConfiguration::LEGACY_SELECTION_ENV as $name) {
            if (
                array_key_exists($name, $environment)
                && $environment[$name] !== null
                && $environment[$name] !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $environment */
    private function resolvedGeneralEncryption(
        #[\SensitiveParameter] array $environment
    ): string {
        $configured = $environment[
            WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV
        ] ?? null;
        if (is_string($configured) && $configured !== '') {
            return strtolower(trim($configured));
        }

        return match ((string) ($environment[
            WebAdminMailConfiguration::GENERAL_SMTP_PORT_ENV
        ] ?? '')) {
            '465' => WebAdminMailConfiguration::ENCRYPTION_SMTPS,
            '587' => WebAdminMailConfiguration::ENCRYPTION_STARTTLS,
            default => '',
        };
    }

    /** @param array<string, mixed> $environment */
    private function resolvedGeneralFromName(
        #[\SensitiveParameter] array $environment
    ): string {
        $configured = $environment[
            WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV
        ] ?? null;
        if ($configured !== null && $configured !== '') {
            return is_string($configured) ? trim($configured) : '';
        }

        $fallback = $environment[
            WebAdminMailConfiguration::GENERAL_LEGACY_FROM_NAME_ENV
        ] ?? null;

        return is_string($fallback) ? trim($fallback) : '';
    }

    private function supportsLegacyEncryptionFallback(string $port): bool
    {
        return in_array($port, ['465', '587'], true);
    }

    /** @param array<string, mixed> $environment */
    private function loadLocalCapture(
        #[\SensitiveParameter] array $environment
    ): WebAdminMailConfiguration {
        try {
            $profile = ProjectRuntimeProfile::fromEnvironment($environment);
        } catch (InvalidArgumentException) {
            throw new WebAdminMailConfigurationException(
                'mail.environment_invalid',
                ProjectRuntimeProfile::ORIGIN_ENV
            );
        }

        if (!$profile->isDevelopmentLoopbackHttp()) {
            throw new WebAdminMailConfigurationException(
                'mail.environment_invalid',
                WebAdminMailConfiguration::TRANSPORT_ENV
            );
        }

        return new WebAdminMailConfiguration(
            $profile->origin(),
            (string) $environment[WebAdminMailConfiguration::SMTP_HOST_ENV],
            (int) (string) $environment[
                WebAdminMailConfiguration::SMTP_PORT_ENV
            ],
            WebAdminMailConfiguration::ENCRYPTION_NONE,
            OpaqueSecret::fromString(''),
            OpaqueSecret::fromString(''),
            EmailAddress::fromString((string) $environment[
                WebAdminMailConfiguration::FROM_ADDRESS_ENV
            ])->value(),
            trim((string) $environment[
                WebAdminMailConfiguration::FROM_NAME_ENV
            ]),
            WebAdminMailConfiguration::TRANSPORT_LOCAL_CAPTURE_SMTP,
            false,
            WebAdminMailConfiguration::SOURCE_LOCAL_CAPTURE
        );
    }

    /**
     * @param array<string, mixed> $environment
     * @return array{0: list<string>, 1: list<string>}
     */
    private function inspectLocalCapture(
        #[\SensitiveParameter] array $environment
    ): array {
        $missing = [];
        $invalid = [];
        foreach (
            WebAdminMailConfiguration::LOCAL_CAPTURE_REQUIRED_ENV as $name
        ) {
            if (
                !array_key_exists($name, $environment)
                || $environment[$name] === null
                || $environment[$name] === ''
            ) {
                $missing[] = $name;
                continue;
            }
            if (!is_string($environment[$name])) {
                $invalid[] = $name;
            }
        }

        if ($missing !== [] || $invalid !== []) {
            return [$missing, $invalid];
        }

        try {
            $profile = ProjectRuntimeProfile::fromEnvironment($environment);
            if (!$profile->isDevelopmentLoopbackHttp()) {
                $invalid[] = WebAdminMailConfiguration::TRANSPORT_ENV;
            }
        } catch (InvalidArgumentException) {
            $invalid[] = ProjectRuntimeProfile::ORIGIN_ENV;
            $invalid[] = ProjectRuntimeProfile::DEVELOPMENT_MODE_ENV;
        }

        if (!in_array($environment[
            WebAdminMailConfiguration::SMTP_HOST_ENV
        ], ['127.0.0.1', '[::1]'], true)) {
            $invalid[] = WebAdminMailConfiguration::SMTP_HOST_ENV;
        }
        if (!$this->validPort((string) $environment[
            WebAdminMailConfiguration::SMTP_PORT_ENV
        ])) {
            $invalid[] = WebAdminMailConfiguration::SMTP_PORT_ENV;
        }
        if (!$this->validEmail((string) $environment[
            WebAdminMailConfiguration::FROM_ADDRESS_ENV
        ])) {
            $invalid[] = WebAdminMailConfiguration::FROM_ADDRESS_ENV;
        }
        if (!$this->validDisplayName((string) $environment[
            WebAdminMailConfiguration::FROM_NAME_ENV
        ])) {
            $invalid[] = WebAdminMailConfiguration::FROM_NAME_ENV;
        }

        foreach ([
            WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV,
            WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV,
            WebAdminMailConfiguration::SMTP_USERNAME_ENV,
            WebAdminMailConfiguration::SMTP_PASSWORD_ENV,
        ] as $incompatibleName) {
            if (
                array_key_exists($incompatibleName, $environment)
                && $environment[$incompatibleName] !== null
                && $environment[$incompatibleName] !== ''
            ) {
                $invalid[] = $incompatibleName;
            }
        }

        return [[], array_values(array_unique($invalid))];
    }

    private function validPublicOrigin(string $value): bool
    {
        if (
            $value === ''
            || strlen($value) > 2048
            || trim($value) !== $value
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            return false;
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return false;
        }
        $path = $parts['path'] ?? '';

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && $this->validHost((string) $parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && in_array($path, ['', '/'], true)
            && (!isset($parts['port'])
                || ((int) $parts['port'] >= 1 && (int) $parts['port'] <= 65535));
    }

    private function validHost(string $value): bool
    {
        $host = trim($value);
        if (
            $host === ''
            || $host !== $value
            || strlen($host) > 253
            || preg_match('/[\x00-\x20\x7F]/', $host) === 1
        ) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var(
                $host,
                FILTER_VALIDATE_DOMAIN,
                FILTER_FLAG_HOSTNAME
            ) !== false;
    }

    private function validPort(string $value): bool
    {
        return preg_match('/\A[0-9]{1,5}\z/', $value) === 1
            && (int) $value >= 1
            && (int) $value <= 65535;
    }

    private function validEncryption(string $value): bool
    {
        return in_array(strtolower(trim($value)), [
            WebAdminMailConfiguration::ENCRYPTION_STARTTLS,
            WebAdminMailConfiguration::ENCRYPTION_SMTPS,
        ], true);
    }

    private function validDevelopmentMode(string $value): bool
    {
        return in_array(strtolower($value), [
            '0',
            '1',
            'false',
            'true',
            'off',
            'on',
            'no',
            'yes',
        ], true);
    }

    private function validCredential(string $value, int $maxBytes): bool
    {
        return $value !== ''
            && strlen($value) <= $maxBytes
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function validEmail(string $value): bool
    {
        try {
            EmailAddress::fromString($value);

            return true;
        } catch (InvalidEmailAddress) {
            return false;
        }
    }

    private function validDisplayName(string $value): bool
    {
        $value = trim($value);

        return $value !== ''
            && strlen($value) <= 120
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && preg_match('//u', $value) === 1;
    }
}
