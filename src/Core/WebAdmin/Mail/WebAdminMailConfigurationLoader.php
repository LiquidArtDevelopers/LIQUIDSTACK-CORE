<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use App\Core\WebAdmin\Security\EmailAddress;
use App\Core\WebAdmin\Security\InvalidEmailAddress;
use App\Core\WebAdmin\Security\OpaqueSecret;

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

        $origin = rtrim((string) $environment[
            WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV
        ], '/');

        return new WebAdminMailConfiguration(
            $origin,
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
            ])
        );
    }

    /**
     * @param array<string, mixed> $environment
     * @return array{0: list<string>, 1: list<string>}
     */
    public function inspect(
        #[\SensitiveParameter] array $environment
    ): array {
        $missing = [];
        $invalid = [];
        foreach (WebAdminMailConfiguration::REQUIRED_ENV as $name) {
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
                in_array(strtolower(trim((string) $environment[
                    WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV
                ])), [
                    WebAdminMailConfiguration::ENCRYPTION_STARTTLS,
                    WebAdminMailConfiguration::ENCRYPTION_SMTPS,
                ], true),
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

        foreach ($checks as $name => $valid) {
            if (!$valid) {
                $invalid[] = $name;
            }
        }

        return [[], $invalid];
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
