<?php

declare(strict_types=1);

use App\Core\WebAdmin\Mail\WebAdminCredentialMailMessageFactory;
use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use App\Core\WebAdmin\Mail\WebAdminMailMessageFactoryException;
use App\Core\WebAdmin\Outbox\WebAdminOutboxMessageFactoryInterface;
use App\Core\WebAdmin\Security\OpaqueSecret;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebAdminCredentialMailMessageFactoryTest extends TestCase
{
    public function testItFulfilsTheOutboxFactoryContract(): void
    {
        self::assertInstanceOf(
            WebAdminOutboxMessageFactoryInterface::class,
            $this->factory()
        );
    }

    #[DataProvider('credentialMessageProvider')]
    public function testItBuildsEscapedSpanishCredentialMessages(
        string $kind,
        string $expectedPath,
        string $expectedSubject,
        string $expectedCallToAction
    ): void {
        $token = (new SecureTokenGenerator())->generate();
        $message = $this->factory('/gestion-web')->create(
            $kind,
            'editor@example.test',
            'es-ES',
            $token
        );
        $url = 'https://portal.example.test:8443/gestion-web'
            . $expectedPath
            . '?token='
            . rawurlencode($token);

        self::assertSame('editor@example.test', $message->recipientEmail());
        self::assertNull($message->recipientName());
        self::assertSame($expectedSubject, $message->subject());
        self::assertStringContainsString($url, $message->textBody());
        self::assertStringContainsString(
            'personal, de un solo uso y caduca',
            $message->textBody()
        );
        self::assertStringContainsString('<html lang="es">', $message->htmlBody());
        self::assertStringContainsString('<meta charset="utf-8">', $message->htmlBody());
        self::assertStringContainsString(
            'href="' . htmlspecialchars(
                $url,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8'
            ) . '"',
            $message->htmlBody()
        );
        self::assertStringContainsString(
            '>' . $expectedCallToAction . '</a>',
            $message->htmlBody()
        );
        self::assertStringNotContainsString('<script', $message->htmlBody());
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function credentialMessageProvider(): iterable
    {
        yield 'invitation' => [
            WebAdminCredentialMailMessageFactory::KIND_INVITE,
            '/activate',
            'Activa tu acceso a la gestión web',
            'Crear mi contraseña',
        ];
        yield 'password reset' => [
            WebAdminCredentialMailMessageFactory::KIND_PASSWORD_RESET,
            '/password/reset',
            'Restablece tu contraseña de gestión web',
            'Crear una nueva contraseña',
        ];
    }

    public function testUndefinedLocaleUsesTheSafeSpanishTemplate(): void
    {
        $message = $this->factory()->create(
            WebAdminCredentialMailMessageFactory::KIND_INVITE,
            'editor@example.test',
            'und',
            (new SecureTokenGenerator())->generate()
        );

        self::assertStringContainsString(
            'Te han invitado',
            $message->textBody()
        );
    }

    public function testTypedLocalCaptureBuildsTheActionUrlFromRaiz(): void
    {
        $token = (new SecureTokenGenerator())->generate();
        $message = $this->localCaptureFactory()->create(
            WebAdminCredentialMailMessageFactory::KIND_INVITE,
            'editor@example.test',
            'es',
            $token
        );

        self::assertStringContainsString(
            'http://localhost:1309/admin/activate?token='
                . rawurlencode($token),
            $message->textBody()
        );
    }

    public function testAuthenticatedGeneralMailCanBuildLocalDevLink(): void
    {
        $token = (new SecureTokenGenerator())->generate();
        $message = $this->generalAuthenticatedFactory()->create(
            WebAdminCredentialMailMessageFactory::KIND_PASSWORD_RESET,
            'editor@example.test',
            'es',
            $token
        );

        self::assertStringContainsString(
            'http://localhost:1309/admin/password/reset?token='
                . rawurlencode($token),
            $message->textBody()
        );
    }

    public function testAuthenticatedGeneralMailRejectsRemoteHttpLink(): void
    {
        $this->expectException(WebAdminMailMessageFactoryException::class);
        $this->expectExceptionMessage(
            'WebAdmin mail message cannot be created.'
        );

        $this->generalAuthenticatedFactory(
            'http://must-not-leak.example.test'
        );
    }

    public function testLocalCaptureProfileCannotAuthorizeRemoteHttpLinks(): void
    {
        $this->expectException(WebAdminMailMessageFactoryException::class);
        $this->expectExceptionMessage(
            'WebAdmin mail message cannot be created.'
        );

        $this->localCaptureFactory('http://must-not-leak.example.test');
    }

    #[DataProvider('invalidInputProvider')]
    public function testInvalidInputsFailWithStableCodesAndNoSecretLeak(
        string $kind,
        string $locale,
        string $token,
        string $expectedIssue
    ): void {
        try {
            $this->factory()->create(
                $kind,
                'editor@example.test',
                $locale,
                $token
            );
            self::fail('Invalid message input should be rejected.');
        } catch (WebAdminMailMessageFactoryException $exception) {
            self::assertSame($expectedIssue, $exception->issueCode());
            self::assertSame(
                'WebAdmin mail message cannot be created.',
                $exception->getMessage()
            );
            self::assertStringNotContainsString($token, (string) $exception);
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidInputProvider(): iterable
    {
        $valid = str_repeat('A', SecureTokenGenerator::ENCODED_LENGTH);

        yield 'invalid token is checked first' => [
            WebAdminCredentialMailMessageFactory::KIND_INVITE,
            'und',
            'private-invalid-token',
            'mail.action_token_invalid',
        ];
        yield 'invalid locale' => [
            WebAdminCredentialMailMessageFactory::KIND_INVITE,
            'es"><script>alert(1)</script>',
            $valid,
            'mail.locale_invalid',
        ];
        yield 'unsupported kind' => [
            'private_credential_kind',
            'und',
            $valid,
            'mail.kind_unsupported',
        ];
    }

    public function testInvalidBasePathFailsWithoutEchoingIt(): void
    {
        $invalid = '/admin"><script>alert(1)</script>';

        try {
            $this->factory($invalid);
            self::fail('Invalid base path should be rejected.');
        } catch (WebAdminMailMessageFactoryException $exception) {
            self::assertSame('mail.base_path_invalid', $exception->issueCode());
            self::assertStringNotContainsString($invalid, (string) $exception);
        }
    }

    #[DataProvider('invalidOriginProvider')]
    public function testItCannotBuildCredentialLinksFromAnUnsafeOrigin(
        string $origin
    ): void {
        try {
            $this->factory('/admin', $origin);
            self::fail('Unsafe public origin should be rejected.');
        } catch (WebAdminMailMessageFactoryException $exception) {
            self::assertSame(
                'mail.public_origin_invalid',
                $exception->issueCode()
            );
            self::assertStringNotContainsString($origin, (string) $exception);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOriginProvider(): iterable
    {
        yield 'plain HTTP' => ['http://portal.example.test'];
        yield 'credentials' => ['https://user:secret@portal.example.test'];
        yield 'path' => ['https://portal.example.test/prefix'];
        yield 'query injection' => ['https://portal.example.test/?next=private'];
        yield 'trailing slash' => ['https://portal.example.test/'];
    }

    private function factory(
        string $basePath = '/admin',
        string $publicOrigin = 'https://portal.example.test:8443'
    ): WebAdminCredentialMailMessageFactory {
        return new WebAdminCredentialMailMessageFactory(
            new WebAdminMailConfiguration(
                $publicOrigin,
                'smtp.example.test',
                587,
                WebAdminMailConfiguration::ENCRYPTION_STARTTLS,
                OpaqueSecret::fromString('account'),
                OpaqueSecret::fromString('password'),
                'mailer@example.test',
                'LiquidStack'
            ),
            $basePath
        );
    }

    private function localCaptureFactory(
        string $publicOrigin = 'http://localhost:1309'
    ): WebAdminCredentialMailMessageFactory {
        return new WebAdminCredentialMailMessageFactory(
            new WebAdminMailConfiguration(
                $publicOrigin,
                '127.0.0.1',
                1025,
                WebAdminMailConfiguration::ENCRYPTION_NONE,
                OpaqueSecret::fromString(''),
                OpaqueSecret::fromString(''),
                'webadmin@aiwa.test',
                'AIWA WebAdmin dev',
                WebAdminMailConfiguration::TRANSPORT_LOCAL_CAPTURE_SMTP,
                false
            ),
            '/admin'
        );
    }

    private function generalAuthenticatedFactory(
        string $publicOrigin = 'http://localhost:1309'
    ): WebAdminCredentialMailMessageFactory {
        return new WebAdminCredentialMailMessageFactory(
            new WebAdminMailConfiguration(
                $publicOrigin,
                'smtp.example.test',
                465,
                WebAdminMailConfiguration::ENCRYPTION_SMTPS,
                OpaqueSecret::fromString('no-reply@example.test'),
                OpaqueSecret::fromString('password'),
                'no-reply@example.test',
                'LiquidStack',
                WebAdminMailConfiguration::TRANSPORT_SMTP,
                true,
                WebAdminMailConfiguration::SOURCE_GENERAL_MAIL
            ),
            '/admin'
        );
    }
}
