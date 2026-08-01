<?php

declare(strict_types=1);

use App\Core\WebAdmin\Mail\WebAdminMailMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebAdminMailMessageSecurityTest extends TestCase
{
    public function testCredentialBearingFieldsStayOutOfDebugExportAndJson(): void
    {
        $token = 'private-token-in-bodies';
        $recipient = 'private-recipient@example.test';
        $message = new WebAdminMailMessage(
            $recipient,
            'Nombre Privado',
            'Asunto privado ' . $token,
            'Texto con ' . $token,
            '<a href="https://example.test/?token=' . $token . '">Acceso</a>'
        );

        $representations = [
            print_r($message, true),
            var_export($message, true),
            json_encode($message, JSON_THROW_ON_ERROR),
        ];
        foreach ($representations as $representation) {
            self::assertStringNotContainsString($token, $representation);
            self::assertStringNotContainsString($recipient, $representation);
            self::assertStringNotContainsString('Nombre Privado', $representation);
        }

        self::assertSame(
            [
                'recipient' => '[redacted]',
                'subject' => '[redacted]',
                'text_body' => '[redacted]',
                'html_body' => '[redacted]',
            ],
            $message->__debugInfo()
        );
        self::assertSame($recipient, $message->recipientEmail());
        self::assertStringContainsString($token, $message->textBody());
    }

    public function testSerializationIsDeniedWithoutLeakingMessageData(): void
    {
        $token = 'private-serialization-token';
        $message = new WebAdminMailMessage(
            'recipient@example.test',
            null,
            'Asunto',
            'Texto ' . $token,
            '<p>' . $token . '</p>'
        );

        try {
            serialize($message);
            self::fail('Credential messages must not be serializable.');
        } catch (LogicException $exception) {
            self::assertSame(
                'WebAdmin mail messages cannot be serialized.',
                $exception->getMessage()
            );
            self::assertStringNotContainsString($token, (string) $exception);
        }
    }

    #[DataProvider('invalidMessageProvider')]
    public function testHeaderAndBodyValidationRejectsUnsafeMessages(
        string $recipient,
        ?string $name,
        string $subject,
        string $text,
        string $html
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new WebAdminMailMessage($recipient, $name, $subject, $text, $html);
    }

    /** @return iterable<string, array{string, ?string, string, string, string}> */
    public static function invalidMessageProvider(): iterable
    {
        yield 'non-canonical recipient' => [
            'EDITOR@example.test', null, 'Asunto', 'Texto', '<p>HTML</p>',
        ];
        yield 'recipient name injection' => [
            'editor@example.test', "Nombre\r\nBcc: victim@example.test", 'Asunto', 'Texto', '<p>HTML</p>',
        ];
        yield 'subject injection' => [
            'editor@example.test', null, "Asunto\r\nBcc: victim@example.test", 'Texto', '<p>HTML</p>',
        ];
        yield 'empty text body' => [
            'editor@example.test', null, 'Asunto', '', '<p>HTML</p>',
        ];
        yield 'empty HTML body' => [
            'editor@example.test', null, 'Asunto', 'Texto', '',
        ];
    }
}
