<?php

declare(strict_types=1);

use App\Core\WebAdmin\CredentialAction\PasswordResetDelivery;
use App\Core\WebAdmin\Mail\ImmediatePasswordResetMailSender;
use App\Core\WebAdmin\Mail\WebAdminCredentialMailMessageFactory;
use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use App\Core\WebAdmin\Mail\WebAdminMailMessage;
use App\Core\WebAdmin\Mail\WebAdminMailTransportInterface;
use App\Core\WebAdmin\Security\OpaqueSecret;
use PHPUnit\Framework\TestCase;

final class ImmediateResetRecordingTransport implements
    WebAdminMailTransportInterface
{
    /** @var list<WebAdminMailMessage> */
    public array $messages = [];

    public function send(WebAdminMailMessage $message): void
    {
        $this->messages[] = $message;
    }
}

final class ImmediatePasswordResetMailSenderTest extends TestCase
{
    public function testSenderBuildsExactlyOnePasswordResetMessage(): void
    {
        $recipient = 'private-recipient@example.test';
        $rawToken = str_repeat('A', 43);
        $transport = new ImmediateResetRecordingTransport();
        $sender = new ImmediatePasswordResetMailSender(
            new WebAdminCredentialMailMessageFactory(
                $this->configuration(),
                '/admin'
            ),
            $transport
        );

        $sender->send(new PasswordResetDelivery(
            10,
            20,
            3,
            $recipient,
            'es-ES',
            $rawToken
        ));

        self::assertCount(1, $transport->messages);
        $message = $transport->messages[0];
        self::assertSame($recipient, $message->recipientEmail());
        self::assertStringContainsString(
            'Restablece tu contrase',
            $message->subject()
        );
        self::assertStringContainsString(
            'https://example.test/admin/password/reset?token=' . $rawToken,
            $message->textBody()
        );
        self::assertStringNotContainsString('/activate?', $message->textBody());
    }

    public function testDeliverySecretsStayRedactedAndCannotBeSerialized(): void
    {
        $recipient = 'private-delivery@example.test';
        $rawToken = str_repeat('B', 43);
        $delivery = new PasswordResetDelivery(
            11,
            21,
            4,
            $recipient,
            'und',
            $rawToken
        );

        foreach ([
            print_r($delivery, true),
            var_export($delivery, true),
            var_export((array) $delivery, true),
            json_encode($delivery, JSON_THROW_ON_ERROR),
        ] as $representation) {
            self::assertStringNotContainsString($recipient, $representation);
            self::assertStringNotContainsString($rawToken, $representation);
        }
        self::assertSame([
            'actionTokenId' => 11,
            'userId' => 21,
            'authVersion' => 4,
            'locale' => 'und',
            'recipientEmail' => '[redacted]',
            'rawToken' => '[redacted]',
        ], $delivery->__debugInfo());
        self::assertSame($recipient, $delivery->recipientEmail());
        self::assertSame($rawToken, $delivery->rawToken());

        try {
            serialize($delivery);
            self::fail('Password-reset deliveries must not be serializable.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Password-reset deliveries cannot be serialized.',
                $exception->getMessage()
            );
            self::assertStringNotContainsString(
                $recipient,
                (string) $exception
            );
            self::assertStringNotContainsString(
                $rawToken,
                (string) $exception
            );
        }
    }

    private function configuration(): WebAdminMailConfiguration
    {
        return new WebAdminMailConfiguration(
            'https://example.test',
            'smtp.example.test',
            587,
            WebAdminMailConfiguration::ENCRYPTION_STARTTLS,
            OpaqueSecret::fromString('mailer@example.test'),
            OpaqueSecret::fromString('test-password-never-used'),
            'mailer@example.test',
            'LiquidStack test'
        );
    }
}
