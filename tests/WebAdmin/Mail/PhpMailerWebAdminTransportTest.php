<?php

declare(strict_types=1);

use App\Core\WebAdmin\Mail\LocalCaptureSmtpWebAdminTransport;
use App\Core\WebAdmin\Mail\PhpMailerWebAdminTransport;
use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use App\Core\WebAdmin\Mail\WebAdminMailMessage;
use App\Core\WebAdmin\Mail\WebAdminMailTransportException;
use App\Core\WebAdmin\Security\OpaqueSecret;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpMailerWebAdminTransportTest extends TestCase
{
    public function testStartTlsDeliveryUsesHardenedSmtpAndClearsMessageState(): void
    {
        $mailer = new MailTransportRecordingPhpMailer();
        $mailer->addAddress('stale@example.test');
        $mailer->Subject = 'stale subject';
        $mailer->Body = 'stale body';

        $transport = new PhpMailerWebAdminTransport(
            $this->configuration(
                WebAdminMailConfiguration::ENCRYPTION_STARTTLS,
                587
            ),
            static fn (): PHPMailer => $mailer
        );
        $transport->send(new WebAdminMailMessage(
            'editor@example.test',
            'María Editor',
            'Asunto seguro',
            'Cuerpo de texto',
            '<p>Cuerpo HTML</p>'
        ));

        self::assertCount(1, $mailer->deliveries);
        $delivery = $mailer->deliveries[0];
        self::assertSame('smtp', $delivery['mailer']);
        self::assertTrue($delivery['smtp_auth']);
        self::assertSame('smtp.example.test', $delivery['host']);
        self::assertSame(587, $delivery['port']);
        self::assertSame('smtp-account', $delivery['username']);
        self::assertSame('smtp-password', $delivery['password']);
        self::assertSame(PHPMailer::ENCRYPTION_STARTTLS, $delivery['secure']);
        self::assertTrue($delivery['auto_tls']);
        self::assertSame([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ], $delivery['smtp_options']);
        self::assertFalse($delivery['keep_alive']);
        self::assertSame(0, $delivery['debug']);
        self::assertSame(WebAdminMailConfiguration::SMTP_TIMEOUT_SECONDS, $delivery['timeout']);
        self::assertSame(WebAdminMailConfiguration::SMTP_TIMEOUT_SECONDS, $delivery['time_limit']);
        self::assertSame(PHPMailer::CHARSET_UTF8, $delivery['charset']);
        self::assertSame(PHPMailer::ENCODING_QUOTED_PRINTABLE, $delivery['encoding']);
        self::assertSame('mailer@example.test', $delivery['from']);
        self::assertSame('LiquidStack', $delivery['from_name']);
        self::assertSame(
            [['editor@example.test', 'María Editor']],
            $delivery['recipients']
        );
        self::assertSame('Asunto seguro', $delivery['subject']);
        self::assertSame('<p>Cuerpo HTML</p>', $delivery['body']);
        self::assertSame('Cuerpo de texto', $delivery['alt_body']);
        self::assertSame([], $mailer->getToAddresses());
        self::assertSame('', $mailer->Subject);
        self::assertSame('', $mailer->Body);
        self::assertSame('', $mailer->AltBody);
        self::assertSame('', $mailer->Username);
        self::assertSame('', $mailer->Password);
        self::assertSame('', $mailer->ErrorInfo);
    }

    public function testSmtpsIsMappedToImplicitTls(): void
    {
        $mailer = new MailTransportRecordingPhpMailer();
        (new PhpMailerWebAdminTransport(
            $this->configuration(
                WebAdminMailConfiguration::ENCRYPTION_SMTPS,
                465
            ),
            static fn (): PHPMailer => $mailer
        ))->send($this->message());

        self::assertSame(
            PHPMailer::ENCRYPTION_SMTPS,
            $mailer->deliveries[0]['secure']
        );
        self::assertSame(465, $mailer->deliveries[0]['port']);
    }

    public function testTypedLocalCaptureUsesPlainUnauthenticatedLoopbackOnly(): void
    {
        $mailer = new MailTransportRecordingPhpMailer();
        $configuration = $this->localCaptureConfiguration();

        (new LocalCaptureSmtpWebAdminTransport(
            $configuration,
            static fn (): PHPMailer => $mailer
        ))->send($this->message());

        self::assertCount(1, $mailer->deliveries);
        $delivery = $mailer->deliveries[0];
        self::assertSame('smtp', $delivery['mailer']);
        self::assertFalse($delivery['smtp_auth']);
        self::assertSame('127.0.0.1', $delivery['host']);
        self::assertSame(1025, $delivery['port']);
        self::assertSame('', $delivery['username']);
        self::assertSame('', $delivery['password']);
        self::assertSame('', $delivery['secure']);
        self::assertFalse($delivery['auto_tls']);
        self::assertSame([], $delivery['smtp_options']);
        self::assertFalse($delivery['keep_alive']);
        self::assertSame(0, $delivery['debug']);
        self::assertSame([], $mailer->getToAddresses());
        self::assertSame('', $mailer->Subject);
        self::assertSame('', $mailer->Body);
        self::assertSame('', $mailer->AltBody);
    }

    public function testProductionAndLocalAdaptersRejectTheOtherProfile(): void
    {
        try {
            new PhpMailerWebAdminTransport($this->localCaptureConfiguration());
            self::fail('Production SMTP must reject local capture settings.');
        } catch (WebAdminMailTransportException $exception) {
            self::assertSame(
                WebAdminMailTransportException::ISSUE_CODE,
                $exception->issueCode()
            );
        }

        $this->expectException(WebAdminMailTransportException::class);
        new LocalCaptureSmtpWebAdminTransport(
            $this->configuration(
                WebAdminMailConfiguration::ENCRYPTION_STARTTLS,
                587
            )
        );
    }

    #[DataProvider('failedDeliveryProvider')]
    public function testEveryTransportFailureUsesOneStableRedactedException(
        string $mode
    ): void {
        $mailer = new MailTransportRecordingPhpMailer();
        $mailer->failureMode = $mode;
        $secretDiagnostic = MailTransportRecordingPhpMailer::SECRET_DIAGNOSTIC;

        try {
            (new PhpMailerWebAdminTransport(
                $this->configuration(
                    WebAdminMailConfiguration::ENCRYPTION_STARTTLS,
                    587
                ),
                static fn (): PHPMailer => $mailer
            ))->send($this->message());
            self::fail('The delivery should fail.');
        } catch (WebAdminMailTransportException $exception) {
            self::assertSame(
                WebAdminMailTransportException::ISSUE_CODE,
                $exception->issueCode()
            );
            self::assertSame(
                'WebAdmin mail delivery failed.',
                $exception->getMessage()
            );
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString(
                $secretDiagnostic,
                (string) $exception
            );
            self::assertStringNotContainsString(
                'recipient@example.test',
                (string) $exception
            );
        }

        self::assertSame([], $mailer->getToAddresses());
        self::assertSame('', $mailer->Subject);
        self::assertSame('', $mailer->Body);
        self::assertSame('', $mailer->AltBody);
        self::assertSame('', $mailer->Username);
        self::assertSame('', $mailer->Password);
        self::assertSame('', $mailer->ErrorInfo);
    }

    /** @return iterable<string, array{string}> */
    public static function failedDeliveryProvider(): iterable
    {
        yield 'PHPMailer returns false' => ['false'];
        yield 'PHPMailer throws with a sensitive diagnostic' => ['throw'];
    }

    public function testInvalidInjectedFactoryIsAlsoHiddenBehindStableBoundary(): void
    {
        $transport = new PhpMailerWebAdminTransport(
            $this->configuration(
                WebAdminMailConfiguration::ENCRYPTION_STARTTLS,
                587
            ),
            /** @phpstan-ignore-next-line Deliberately violates the test seam. */
            static fn () => new stdClass()
        );

        $this->expectException(WebAdminMailTransportException::class);
        $this->expectExceptionMessage('WebAdmin mail delivery failed.');
        $transport->send($this->message());
    }

    private function message(): WebAdminMailMessage
    {
        return new WebAdminMailMessage(
            'recipient@example.test',
            null,
            'Asunto',
            'Texto privado',
            '<p>HTML privado</p>'
        );
    }

    private function configuration(
        string $encryption,
        int $port
    ): WebAdminMailConfiguration {
        return new WebAdminMailConfiguration(
            'https://www.example.test',
            'smtp.example.test',
            $port,
            $encryption,
            OpaqueSecret::fromString('smtp-account'),
            OpaqueSecret::fromString('smtp-password'),
            'mailer@example.test',
            'LiquidStack'
        );
    }

    private function localCaptureConfiguration(): WebAdminMailConfiguration
    {
        return new WebAdminMailConfiguration(
            'http://localhost:1309',
            '127.0.0.1',
            1025,
            WebAdminMailConfiguration::ENCRYPTION_NONE,
            OpaqueSecret::fromString(''),
            OpaqueSecret::fromString(''),
            'webadmin@aiwa.test',
            'AIWA WebAdmin dev',
            WebAdminMailConfiguration::TRANSPORT_LOCAL_CAPTURE_SMTP,
            false
        );
    }
}

final class MailTransportRecordingPhpMailer extends PHPMailer
{
    public const SECRET_DIAGNOSTIC = 'smtp-password-from-server';

    /** @var list<array<string, mixed>> */
    public array $deliveries = [];
    public string $failureMode = 'success';

    public function __construct()
    {
        parent::__construct(false);
    }

    public function send()
    {
        $this->deliveries[] = [
            'mailer' => $this->Mailer,
            'smtp_auth' => $this->SMTPAuth,
            'host' => $this->Host,
            'port' => $this->Port,
            'username' => $this->Username,
            'password' => $this->Password,
            'secure' => $this->SMTPSecure,
            'auto_tls' => $this->SMTPAutoTLS,
            'smtp_options' => $this->SMTPOptions,
            'keep_alive' => $this->SMTPKeepAlive,
            'debug' => $this->SMTPDebug,
            'timeout' => $this->Timeout,
            'time_limit' => $this->getSMTPInstance()->Timelimit,
            'charset' => $this->CharSet,
            'encoding' => $this->Encoding,
            'from' => $this->From,
            'from_name' => $this->FromName,
            'recipients' => $this->getToAddresses(),
            'subject' => $this->Subject,
            'body' => $this->Body,
            'alt_body' => $this->AltBody,
        ];

        if ($this->failureMode === 'throw') {
            throw new RuntimeException(self::SECRET_DIAGNOSTIC);
        }

        return $this->failureMode !== 'false';
    }
}
