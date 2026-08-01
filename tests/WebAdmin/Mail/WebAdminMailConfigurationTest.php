<?php

declare(strict_types=1);

use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use App\Core\WebAdmin\Mail\WebAdminMailConfigurationException;
use App\Core\WebAdmin\Mail\WebAdminMailConfigurationLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebAdminMailConfigurationTest extends TestCase
{
    public function testLoadsACompleteStrictSmtpConfiguration(): void
    {
        $configuration = (new WebAdminMailConfigurationLoader())->load(
            $this->validEnvironment()
        );

        self::assertSame('https://example.test', $configuration->publicOrigin());
        self::assertSame('smtp.example.test', $configuration->smtpHost());
        self::assertSame(587, $configuration->smtpPort());
        self::assertSame('starttls', $configuration->smtpEncryption());
        self::assertSame('mailer@example.test', $configuration->smtpUsername());
        self::assertSame('not-a-real-password', $configuration->smtpPassword());
        self::assertSame('webadmin@example.test', $configuration->fromAddress());
        self::assertSame('LiquidStack WebAdmin', $configuration->fromName());
        self::assertSame([
            'transport' => 'smtp',
            'public_origin_scheme' => 'https',
            'encryption' => 'starttls',
            'timeout_seconds' => 15,
            'required_environment_names' =>
                WebAdminMailConfiguration::REQUIRED_ENV,
        ], $configuration->toSafeArray());
    }

    public function testNormalizesOnlyDocumentedNonSecretSurface(): void
    {
        $environment = $this->validEnvironment();
        $environment[WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV]
            = 'https://example.test/';
        $environment[WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV]
            = 'SMTPS';
        $environment[WebAdminMailConfiguration::FROM_ADDRESS_ENV]
            = 'WEBADMIN@EXAMPLE.TEST';

        $configuration = (new WebAdminMailConfigurationLoader())->load(
            $environment
        );

        self::assertSame('https://example.test', $configuration->publicOrigin());
        self::assertSame('smtps', $configuration->smtpEncryption());
        self::assertSame('webadmin@example.test', $configuration->fromAddress());
    }

    public function testMissingVariablesAreReportedByNameOnly(): void
    {
        $environment = $this->validEnvironment();
        unset($environment[WebAdminMailConfiguration::SMTP_PASSWORD_ENV]);

        [$missing, $invalid] = (new WebAdminMailConfigurationLoader())
            ->inspect($environment);

        self::assertSame([
            WebAdminMailConfiguration::SMTP_PASSWORD_ENV,
        ], $missing);
        self::assertSame([], $invalid);

        try {
            (new WebAdminMailConfigurationLoader())->load($environment);
            self::fail('The missing mail password must be rejected.');
        } catch (WebAdminMailConfigurationException $exception) {
            self::assertSame(
                'mail.environment_missing',
                $exception->issueCode()
            );
            self::assertSame(
                WebAdminMailConfiguration::SMTP_PASSWORD_ENV,
                $exception->environmentName()
            );
            self::assertStringNotContainsString(
                'not-a-real-password',
                $exception->getMessage()
            );
        }
    }

    #[DataProvider('invalidEnvironmentProvider')]
    public function testRejectsInvalidValuesWithoutEchoingThem(
        string $name,
        string $value
    ): void {
        $environment = $this->validEnvironment();
        $environment[$name] = $value;

        [$missing, $invalid] = (new WebAdminMailConfigurationLoader())
            ->inspect($environment);
        self::assertSame([], $missing);
        self::assertContains($name, $invalid);

        try {
            (new WebAdminMailConfigurationLoader())->load($environment);
            self::fail('The invalid mail setting must be rejected.');
        } catch (WebAdminMailConfigurationException $exception) {
            self::assertSame('mail.environment_invalid', $exception->issueCode());
            self::assertSame($name, $exception->environmentName());
            self::assertStringNotContainsString($value, $exception->getMessage());
        }
    }

    /** @return array<string, array{string, string}> */
    public static function invalidEnvironmentProvider(): array
    {
        return [
            'http origin' => [
                WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV,
                'http://example.test',
            ],
            'origin path' => [
                WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV,
                'https://example.test/application',
            ],
            'origin credentials' => [
                WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV,
                'https://user:pass@example.test',
            ],
            'origin query' => [
                WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV,
                'https://example.test/?secret=value',
            ],
            'invalid host' => [
                WebAdminMailConfiguration::SMTP_HOST_ENV,
                "smtp.example.test\r\nInjected: yes",
            ],
            'zero port' => [
                WebAdminMailConfiguration::SMTP_PORT_ENV,
                '0',
            ],
            'oversized port' => [
                WebAdminMailConfiguration::SMTP_PORT_ENV,
                '65536',
            ],
            'implicit plaintext' => [
                WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV,
                'none',
            ],
            'legacy tls spelling' => [
                WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV,
                'tls',
            ],
            'username injection' => [
                WebAdminMailConfiguration::SMTP_USERNAME_ENV,
                "mailer\r\nBcc: victim@example.test",
            ],
            'password line break' => [
                WebAdminMailConfiguration::SMTP_PASSWORD_ENV,
                "secret\nnext",
            ],
            'invalid from address' => [
                WebAdminMailConfiguration::FROM_ADDRESS_ENV,
                'not-an-email',
            ],
            'invalid from name' => [
                WebAdminMailConfiguration::FROM_NAME_ENV,
                "Sender\r\nBcc: victim@example.test",
            ],
        ];
    }

    public function testConfigurationDebugAndSerializationCannotLeakSecrets(): void
    {
        $configuration = (new WebAdminMailConfigurationLoader())->load(
            $this->validEnvironment()
        );
        $dump = print_r($configuration, true);

        self::assertStringContainsString('[redacted]', $dump);
        self::assertStringNotContainsString('not-a-real-password', $dump);
        self::assertStringNotContainsString('mailer@example.test', $dump);

        $this->expectException(LogicException::class);
        serialize($configuration);
    }

    /** @return array<string, string> */
    private function validEnvironment(): array
    {
        return [
            WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV =>
                'https://example.test',
            WebAdminMailConfiguration::SMTP_HOST_ENV =>
                'smtp.example.test',
            WebAdminMailConfiguration::SMTP_PORT_ENV => '587',
            WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV => 'starttls',
            WebAdminMailConfiguration::SMTP_USERNAME_ENV =>
                'mailer@example.test',
            WebAdminMailConfiguration::SMTP_PASSWORD_ENV =>
                'not-a-real-password',
            WebAdminMailConfiguration::FROM_ADDRESS_ENV =>
                'webadmin@example.test',
            WebAdminMailConfiguration::FROM_NAME_ENV =>
                'LiquidStack WebAdmin',
        ];
    }
}
