<?php

declare(strict_types=1);

use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use App\Core\WebAdmin\Mail\WebAdminMailConfigurationException;
use App\Core\WebAdmin\Mail\WebAdminMailConfigurationLoader;
use App\Core\WebAdmin\Security\OpaqueSecret;
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
            'source' => WebAdminMailConfiguration::SOURCE_LEGACY_WEBADMIN,
            'public_origin_scheme' => 'https',
            'encryption' => 'starttls',
            'timeout_seconds' => 15,
            'required_environment_names' =>
                WebAdminMailConfiguration::REQUIRED_ENV,
        ], $configuration->toSafeArray());
    }

    public function testLoadsCanonicalGeneralMailAndUsesUsernameAsFrom(): void
    {
        $configuration = (new WebAdminMailConfigurationLoader())->load(
            $this->validGeneralEnvironment()
        );

        self::assertSame(
            WebAdminMailConfiguration::SOURCE_GENERAL_MAIL,
            $configuration->source()
        );
        self::assertSame(
            'http://localhost:1309',
            $configuration->publicOrigin()
        );
        self::assertSame('smtp.example.test', $configuration->smtpHost());
        self::assertSame(465, $configuration->smtpPort());
        self::assertSame('smtps', $configuration->smtpEncryption());
        self::assertSame(
            'No-Reply@example.test',
            $configuration->smtpUsername()
        );
        self::assertSame(
            'no-reply@example.test',
            $configuration->fromAddress()
        );
        self::assertSame('Example website', $configuration->fromName());
        self::assertSame(
            [
                'RAIZ',
                'DEV_MODE',
                WebAdminMailConfiguration::GENERAL_SMTP_HOST_ENV,
                WebAdminMailConfiguration::GENERAL_SMTP_PORT_ENV,
                WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV,
                WebAdminMailConfiguration::GENERAL_SMTP_USERNAME_ENV,
                WebAdminMailConfiguration::GENERAL_SMTP_PASSWORD_ENV,
                WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV,
            ],
            $configuration->requiredEnvironmentNames()
        );
    }

    public function testLoadsCanonicalGeneralMailForHttpsProduction(): void
    {
        $environment = $this->validGeneralEnvironment();
        $environment['RAIZ'] = 'https://www.example.test';
        $environment['DEV_MODE'] = '0';

        $configuration = (new WebAdminMailConfigurationLoader())->load(
            $environment
        );

        self::assertSame(
            'https://www.example.test',
            $configuration->publicOrigin()
        );
        self::assertSame(
            WebAdminMailConfiguration::SOURCE_GENERAL_MAIL,
            $configuration->source()
        );
    }

    #[DataProvider('invalidGeneralEnvironmentProvider')]
    public function testRejectsInvalidGeneralMailWithoutLeakingValues(
        string $name,
        string $value
    ): void {
        $environment = $this->validGeneralEnvironment();
        $environment[$name] = $value;
        $loader = new WebAdminMailConfigurationLoader();

        [$missing, $invalid] = $loader->inspect($environment);

        self::assertSame([], $missing);
        self::assertContains($name, $invalid);

        try {
            $loader->load($environment);
            self::fail('Invalid general mail configuration must fail closed.');
        } catch (WebAdminMailConfigurationException $exception) {
            self::assertSame('mail.environment_invalid', $exception->issueCode());
            self::assertStringNotContainsString($value, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidGeneralEnvironmentProvider(): iterable
    {
        yield 'remote http origin' => [
            'RAIZ',
            'http://remote.example.test',
        ];
        yield 'host header injection' => [
            WebAdminMailConfiguration::GENERAL_SMTP_HOST_ENV,
            "smtp.example.test\r\nInjected: yes",
        ];
        yield 'zero port' => [
            WebAdminMailConfiguration::GENERAL_SMTP_PORT_ENV,
            '0',
        ];
        yield 'plaintext encryption' => [
            WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV,
            'none',
        ];
        yield 'legacy tls spelling' => [
            WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV,
            'tls',
        ];
        yield 'username must be an email' => [
            WebAdminMailConfiguration::GENERAL_SMTP_USERNAME_ENV,
            'smtp-account',
        ];
        yield 'username header injection' => [
            WebAdminMailConfiguration::GENERAL_SMTP_USERNAME_ENV,
            "mailer@example.test\r\nBcc: victim@example.test",
        ];
        yield 'password line break' => [
            WebAdminMailConfiguration::GENERAL_SMTP_PASSWORD_ENV,
            "secret\nnext",
        ];
        yield 'from name header injection' => [
            WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV,
            "Sender\r\nBcc: victim@example.test",
        ];
    }

    public function testAnyLegacyMailValueSelectsTheWholeLegacyBlock(): void
    {
        $environment = $this->validGeneralEnvironment();
        $environment[WebAdminMailConfiguration::SMTP_HOST_ENV]
            = 'legacy.example.test';

        [$missing, $invalid] = (new WebAdminMailConfigurationLoader())
            ->inspect($environment);

        self::assertSame([], $invalid);
        self::assertContains(
            WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV,
            $missing
        );
        self::assertContains(
            WebAdminMailConfiguration::SMTP_PASSWORD_ENV,
            $missing
        );
        self::assertNotContains(
            WebAdminMailConfiguration::GENERAL_SMTP_HOST_ENV,
            $missing
        );
    }

    public function testCompleteLegacyBlockKeepsPrecedenceDuringMigration(): void
    {
        $environment = array_merge(
            $this->validGeneralEnvironment(),
            $this->validEnvironment()
        );

        $configuration = (new WebAdminMailConfigurationLoader())->load(
            $environment
        );

        self::assertSame(
            WebAdminMailConfiguration::SOURCE_LEGACY_WEBADMIN,
            $configuration->source()
        );
        self::assertSame('smtp.example.test', $configuration->smtpHost());
        self::assertSame(
            'mailer@example.test',
            $configuration->smtpUsername()
        );
        self::assertSame(
            'webadmin@example.test',
            $configuration->fromAddress()
        );
        self::assertSame(
            WebAdminMailConfiguration::LEGACY_REQUIRED_ENV,
            $configuration->requiredEnvironmentNames()
        );
    }

    public function testIsolatedLegacyOriginDoesNotHijackGeneralMail(): void
    {
        $environment = $this->validGeneralEnvironment();
        $environment[WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV]
            = 'https://legacy-blog-origin.example.test';

        $configuration = (new WebAdminMailConfigurationLoader())->load(
            $environment
        );

        self::assertSame(
            WebAdminMailConfiguration::SOURCE_GENERAL_MAIL,
            $configuration->source()
        );
        self::assertSame(
            'http://localhost:1309',
            $configuration->publicOrigin()
        );
        self::assertSame(
            'No-Reply@example.test',
            $configuration->smtpUsername()
        );
    }

    public function testExistingProjectMailBlockHasBoundedCompatibility(): void
    {
        $environment = $this->validGeneralEnvironment();
        unset(
            $environment[
                WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV
            ],
            $environment[WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV]
        );

        $configuration = (new WebAdminMailConfigurationLoader())->load(
            $environment
        );

        self::assertSame(
            WebAdminMailConfiguration::ENCRYPTION_SMTPS,
            $configuration->smtpEncryption()
        );
        self::assertSame('Existing website', $configuration->fromName());
        self::assertSame(
            array_merge(
                WebAdminMailConfiguration::GENERAL_REQUIRED_ENV,
                [
                    WebAdminMailConfiguration::GENERAL_LEGACY_FROM_NAME_ENV,
                ]
            ),
            $configuration->requiredEnvironmentNames()
        );
    }

    public function testGeneralRequiredNamesMatchMissingFallbackInputs(): void
    {
        $loader = new WebAdminMailConfigurationLoader();

        $withoutName = $this->validGeneralEnvironment();
        unset(
            $withoutName[WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV],
            $withoutName[
                WebAdminMailConfiguration::GENERAL_LEGACY_FROM_NAME_ENV
            ]
        );
        [$missingName, $invalidName] = $loader->inspect($withoutName);

        self::assertSame([], $invalidName);
        self::assertSame(
            [WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV],
            $missingName
        );
        self::assertContains(
            WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV,
            $loader->requiredEnvironmentNames($withoutName)
        );

        $withoutEncryption = $this->validGeneralEnvironment();
        unset($withoutEncryption[
            WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV
        ]);
        $withoutEncryption[
            WebAdminMailConfiguration::GENERAL_SMTP_PORT_ENV
        ] = '2525';
        [$missingEncryption, $invalidEncryption] = $loader->inspect(
            $withoutEncryption
        );

        self::assertSame([], $invalidEncryption);
        self::assertSame(
            [WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV],
            $missingEncryption
        );
        self::assertContains(
            WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV,
            $loader->requiredEnvironmentNames($withoutEncryption)
        );
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

    public function testLoadsTypedLocalCaptureFromTheDevelopmentProfile(): void
    {
        $configuration = (new WebAdminMailConfigurationLoader())->load(
            $this->validLocalCaptureEnvironment()
        );

        self::assertSame(
            WebAdminMailConfiguration::TRANSPORT_LOCAL_CAPTURE_SMTP,
            $configuration->transport()
        );
        self::assertTrue($configuration->isLocalCaptureSmtp());
        self::assertFalse($configuration->isProductionSmtp());
        self::assertSame(
            'http://localhost:1309',
            $configuration->publicOrigin()
        );
        self::assertSame('127.0.0.1', $configuration->smtpHost());
        self::assertSame(1025, $configuration->smtpPort());
        self::assertSame(
            WebAdminMailConfiguration::ENCRYPTION_NONE,
            $configuration->smtpEncryption()
        );
        self::assertFalse($configuration->usesSmtpAuthentication());
        self::assertSame('', $configuration->smtpUsername());
        self::assertSame('', $configuration->smtpPassword());
        self::assertSame([
            'transport' =>
                WebAdminMailConfiguration::TRANSPORT_LOCAL_CAPTURE_SMTP,
            'source' => WebAdminMailConfiguration::SOURCE_LOCAL_CAPTURE,
            'public_origin_scheme' => 'http',
            'encryption' => WebAdminMailConfiguration::ENCRYPTION_NONE,
            'timeout_seconds' => 15,
            'required_environment_names' =>
                WebAdminMailConfiguration::LOCAL_CAPTURE_REQUIRED_ENV,
        ], $configuration->toSafeArray());
    }

    public function testLocalCaptureAcceptsCanonicalBracketedIpv6Loopback(): void
    {
        $environment = $this->validLocalCaptureEnvironment();
        $environment['RAIZ'] = 'http://[::1]:1309';
        $environment[WebAdminMailConfiguration::SMTP_HOST_ENV] = '[::1]';

        $configuration = (new WebAdminMailConfigurationLoader())->load(
            $environment
        );

        self::assertSame('http://[::1]:1309', $configuration->publicOrigin());
        self::assertSame('[::1]', $configuration->smtpHost());
    }

    public function testLegacyConstructorInfersLocalCaptureSource(): void
    {
        $configuration = new WebAdminMailConfiguration(
            'http://localhost:1309',
            '127.0.0.1',
            1025,
            WebAdminMailConfiguration::ENCRYPTION_NONE,
            OpaqueSecret::fromString(''),
            OpaqueSecret::fromString(''),
            'webadmin@example.test',
            'WebAdmin dev',
            WebAdminMailConfiguration::TRANSPORT_LOCAL_CAPTURE_SMTP,
            false
        );

        self::assertSame(
            WebAdminMailConfiguration::SOURCE_LOCAL_CAPTURE,
            $configuration->source()
        );
        self::assertSame(
            WebAdminMailConfiguration::LOCAL_CAPTURE_REQUIRED_ENV,
            $configuration->requiredEnvironmentNames()
        );
    }

    #[DataProvider('invalidLocalCaptureEnvironmentProvider')]
    public function testLocalCaptureFailsClosedWithoutLeakingConfiguration(
        string $name,
        string $value
    ): void {
        $environment = $this->validLocalCaptureEnvironment();
        $environment[$name] = $value;

        [$missing, $invalid] = (new WebAdminMailConfigurationLoader())
            ->inspect($environment);
        self::assertSame([], $missing);
        self::assertNotSame([], $invalid);

        try {
            (new WebAdminMailConfigurationLoader())->load($environment);
            self::fail('Unsafe local capture configuration must fail closed.');
        } catch (WebAdminMailConfigurationException $exception) {
            self::assertSame('mail.environment_invalid', $exception->issueCode());
            self::assertStringNotContainsString(
                $value,
                $exception->getMessage()
            );
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidLocalCaptureEnvironmentProvider(): iterable
    {
        yield 'production mode' => ['DEV_MODE', '0'];
        yield 'non-loopback origin' => ['RAIZ', 'http://example.test:1309'];
        yield 'production origin' => ['RAIZ', 'https://example.test'];
        yield 'remote SMTP host' => [
            WebAdminMailConfiguration::SMTP_HOST_ENV,
            'smtp.example.test',
        ];
        yield 'plaintext mode cannot carry credentials' => [
            WebAdminMailConfiguration::SMTP_PASSWORD_ENV,
            'must-not-leak-local-password',
        ];
        yield 'plaintext mode cannot request TLS' => [
            WebAdminMailConfiguration::SMTP_ENCRYPTION_ENV,
            'starttls',
        ];
        yield 'legacy origin cannot override RAIZ' => [
            WebAdminMailConfiguration::PUBLIC_ORIGIN_ENV,
            'https://must-not-leak.example.test',
        ];
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

    /** @return array<string, string> */
    private function validGeneralEnvironment(): array
    {
        return [
            'RAIZ' => 'http://localhost:1309',
            'DEV_MODE' => '1',
            WebAdminMailConfiguration::TRANSPORT_ENV =>
                WebAdminMailConfiguration::TRANSPORT_SMTP,
            WebAdminMailConfiguration::GENERAL_SMTP_HOST_ENV =>
                'smtp.example.test',
            WebAdminMailConfiguration::GENERAL_SMTP_PORT_ENV => '465',
            WebAdminMailConfiguration::GENERAL_SMTP_ENCRYPTION_ENV =>
                'smtps',
            WebAdminMailConfiguration::GENERAL_SMTP_USERNAME_ENV =>
                'No-Reply@example.test',
            WebAdminMailConfiguration::GENERAL_SMTP_PASSWORD_ENV =>
                'not-a-real-password',
            WebAdminMailConfiguration::GENERAL_FROM_NAME_ENV =>
                'Example website',
            WebAdminMailConfiguration::GENERAL_LEGACY_FROM_NAME_ENV =>
                'Existing website',
        ];
    }

    /** @return array<string, string> */
    private function validLocalCaptureEnvironment(): array
    {
        return [
            'RAIZ' => 'http://localhost:1309',
            'DEV_MODE' => '1',
            WebAdminMailConfiguration::TRANSPORT_ENV =>
                WebAdminMailConfiguration::TRANSPORT_LOCAL_CAPTURE_SMTP,
            WebAdminMailConfiguration::SMTP_HOST_ENV => '127.0.0.1',
            WebAdminMailConfiguration::SMTP_PORT_ENV => '1025',
            WebAdminMailConfiguration::FROM_ADDRESS_ENV =>
                'webadmin@aiwa.test',
            WebAdminMailConfiguration::FROM_NAME_ENV => 'AIWA WebAdmin dev',
        ];
    }
}
