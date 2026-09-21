<?php

declare(strict_types=1);

namespace Tests\Commerce;

use App\Core\Commerce\Outbox\CommerceInquiryMailMessageFactory;
use App\Core\Commerce\Outbox\CommerceInquiryOutboxDispatcher;
use App\Core\Commerce\Outbox\CommerceInquiryOutboxRepository;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\Composer\CommerceMailDispatchCommandRuntimeException;
use App\Core\Composer\CommerceMailDispatchCommandRuntimeFactory;
use App\Core\Composer\CommerceMailDispatchCommandRuntimeFactoryInterface;
use App\Core\Composer\CommerceMailDispatchCommandRuntimeInterface;
use App\Core\Composer\Command\CommerceMailDispatchCommand;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use App\Core\WebAdmin\Mail\WebAdminMailMessage;
use App\Core\WebAdmin\Mail\WebAdminMailTransportInterface;
use App\Core\WebAdmin\Security\OpaqueSecret;
use App\Core\WebAdmin\Support\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use Composer\Console\Application as ComposerApplication;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class CommerceOutboxDispatcherTest extends TestCase
{
    private PDO $pdo;
    private DateTimeImmutable $now;
    private CommerceTableNames $tables;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE ls_commerce_inquiry_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    inquiry_id INTEGER NOT NULL,
    audience TEXT NOT NULL,
    recipient_email TEXT NOT NULL,
    template_key TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    available_at TEXT NOT NULL,
    locked_at TEXT NULL,
    lock_token TEXT NULL,
    sent_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);
        $this->tables = CommerceTableNames::fromPdo($this->pdo, 'ls_commerce_');
        $this->now = new DateTimeImmutable('2032-04-05 10:00:00.000000', new DateTimeZone('UTC'));
    }

    public function testSuccessfulDeliveryMarksJobSent(): void
    {
        $this->insertJob('requester', 'ane@example.test', $this->requesterPayload());
        $transport = new RecordingCommerceTransport();
        $report = $this->dispatcher($transport)->dispatchBatch(20);

        self::assertSame(1, $report->sent());
        self::assertSame(1, $report->claimed());
        self::assertCount(1, $transport->messages);
        self::assertStringContainsString(
            'Referencia: 33333333-3333-4333-8333-333333333333',
            $transport->messages[0]->textBody()
        );
        self::assertStringContainsString(
            'Precio bajo consulta',
            $transport->messages[0]->htmlBody()
        );
        self::assertSame(
            ['status' => 'sent', 'attempts' => 1, 'locked_at' => null, 'lock_token' => null],
            $this->row()
        );
    }

    public function testTransportFailureReleasesLeaseAndSchedulesBoundedRetry(): void
    {
        $this->insertJob('admin', 'ventas@example.test', $this->adminPayload());
        $report = $this->dispatcher(new FailingCommerceTransport())->dispatchBatch(20);

        self::assertSame(1, $report->retryScheduled());
        self::assertSame(
            ['status' => 'pending', 'attempts' => 1, 'locked_at' => null, 'lock_token' => null],
            $this->row()
        );
        self::assertSame(
            '2032-04-05 10:01:00.000000',
            $this->pdo->query('SELECT available_at FROM ls_commerce_inquiry_outbox')->fetchColumn()
        );
    }

    public function testMailIncludesPublicCoverWithoutExposingStorageKeys(): void
    {
        $payload = $this->requesterPayload();
        $payload['items'][0]['cover_media_public_id'] =
            '44444444-4444-4444-8444-444444444444';
        $payload['items'][0]['sku'] = 'VAN-001';
        $payload['items'][0]['unit_price_minor'] = 5_990_000;
        $payload['items'][0]['currency'] = 'EUR';
        $this->insertJob('requester', 'ane@example.test', $payload);
        $transport = new RecordingCommerceTransport();

        $this->dispatcher($transport)->dispatchBatch(1);

        $body = $transport->messages[0]->htmlBody();
        self::assertStringContainsString(
            'https://example.test/_liquidstack/commerce/media/'
                . '44444444-4444-4444-8444-444444444444/640.avif',
            $body
        );
        self::assertStringContainsString('VAN-001', $body);
        self::assertStringContainsString('59.900,00 EUR', $body);
        self::assertStringNotContainsString('storage', $body);
    }

    public function testFifthFailureClosesJobPermanently(): void
    {
        $this->insertJob('requester', 'ane@example.test', $this->requesterPayload());
        $this->pdo->exec('UPDATE ls_commerce_inquiry_outbox SET attempts = 4');
        $report = $this->dispatcher(new FailingCommerceTransport())->dispatchBatch(20);

        self::assertSame(1, $report->permanentlyFailed());
        self::assertSame(
            ['status' => 'failed', 'attempts' => 5, 'locked_at' => null, 'lock_token' => null],
            $this->row()
        );
    }

    public function testInvalidSmtpConfigurationFailsBeforeDatabaseAndLeavesJobUntouched(): void
    {
        $this->insertJob('requester', 'ane@example.test', $this->requesterPayload());
        $root = sys_get_temp_dir() . '/ls-commerce-mail-' . bin2hex(random_bytes(6));
        mkdir($root . '/App/config/modules', 0777, true);
        file_put_contents($root . '/composer.json', json_encode([
            'require' => ['liquidstack/core' => '^1.32', 'liquidstack/commerce' => '*'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/App/config/langs.php', "<?php\nreturn ['es'];\n");
        file_put_contents($root . '/App/config/modules/commerce.php', "<?php\nreturn [];\n");
        file_put_contents($root . '/.env', implode("\n", [
            'BBDD_SERVER="localhost"',
            'BBDD_USER="commerce"',
            'BBDD_PASS="private-database-secret"',
            'BBDD_NAME="commerce"',
            'RAIZ="https://example.test"',
            'DEV_MODE="0"',
            'LIQUIDSTACK_WEBADMIN_MAIL_TRANSPORT="smtp"',
            // Deliberately no SMTP contract.
        ]) . "\n");
        $databaseCalls = 0;
        $factory = new CommerceMailDispatchCommandRuntimeFactory(
            connectionFactoryResolver: static function () use (&$databaseCalls): PdoConnectionFactoryInterface {
                ++$databaseCalls;
                throw new RuntimeException('Database must not be reached.');
            }
        );
        try {
            $factory->create($root, dirname(__DIR__, 2));
            self::fail('Invalid SMTP configuration must fail closed.');
        } catch (CommerceMailDispatchCommandRuntimeException $exception) {
            self::assertSame('commerce.mail.configuration_invalid', $exception->issueCode());
        } finally {
            @unlink($root . '/App/config/modules/commerce.php');
            @unlink($root . '/App/config/langs.php');
            @unlink($root . '/composer.json');
            @unlink($root . '/.env');
            @rmdir($root . '/App/config/modules');
            @rmdir($root . '/App/config');
            @rmdir($root . '/App');
            @rmdir($root);
        }
        self::assertSame(0, $databaseCalls);
        self::assertSame(
            ['status' => 'pending', 'attempts' => 0, 'locked_at' => null, 'lock_token' => null],
            $this->row()
        );
    }

    public function testLeaseDebugOutputRedactsRecipientPayloadAndToken(): void
    {
        $recipient = 'private-person@example.test';
        $payload = $this->requesterPayload();
        $this->insertJob('requester', $recipient, $payload);
        $lease = (new CommerceInquiryOutboxRepository($this->pdo, $this->tables))
            ->claimNext($this->now)->lease();
        $debug = print_r($lease, true);

        self::assertStringNotContainsString($recipient, $debug);
        self::assertStringNotContainsString('Furgoneta privada', $debug);
        self::assertStringNotContainsString($lease->lockToken(), $debug);
        self::assertStringContainsString('[redacted]', $debug);
    }

    public function testCommandOutputContainsOnlyAggregateCounts(): void
    {
        $recipient = 'private-person@example.test';
        $this->insertJob('requester', $recipient, $this->requesterPayload());
        $runtime = new StaticCommerceMailRuntime($this->dispatcher(new RecordingCommerceTransport()));
        $command = new CommerceMailDispatchCommand(
            __DIR__,
            dirname(__DIR__, 2),
            new StaticCommerceMailRuntimeFactory($runtime)
        );
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['--format' => 'json']));
        $display = $tester->getDisplay();

        self::assertStringNotContainsString($recipient, $display);
        self::assertStringNotContainsString('Furgoneta privada', $display);
        self::assertStringContainsString('"sent": 1', $display);
    }

    private function dispatcher(WebAdminMailTransportInterface $transport): CommerceInquiryOutboxDispatcher
    {
        return new CommerceInquiryOutboxDispatcher(
            new CommerceInquiryOutboxRepository($this->pdo, $this->tables),
            new CommerceInquiryMailMessageFactory($this->mailConfiguration()),
            $transport,
            new FixedCommerceClock($this->now)
        );
    }

    /** @return array{status:string,attempts:int,locked_at:?string,lock_token:?string} */
    private function row(): array
    {
        $row = $this->pdo->query(
            'SELECT status, attempts, locked_at, lock_token FROM ls_commerce_inquiry_outbox'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $row['attempts'] = (int) $row['attempts'];

        return $row;
    }

    /** @param array<string, mixed> $payload */
    private function insertJob(string $audience, string $recipient, array $payload): void
    {
        $timestamp = $this->now->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_commerce_inquiry_outbox '
            . '(public_id, inquiry_id, audience, recipient_email, template_key, payload_json, '
            . 'status, attempts, available_at, created_at, updated_at) VALUES '
            . '(:public_id, 1, :audience, :recipient, :template, :payload, '
            . "'pending', 0, :available_at, :created_at, :updated_at)"
        );
        $statement->execute([
            'public_id' => '11111111-1111-4111-8111-111111111111',
            'audience' => $audience,
            'recipient' => $recipient,
            'template' => 'commerce.inquiry.' . $audience,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'available_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /** @return array<string, mixed> */
    private function requesterPayload(): array
    {
        return [
            'inquiry_public_id' => '22222222-2222-4222-8222-222222222222',
            'locale' => 'es',
            'items' => [[
                'product_public_id' => '33333333-3333-4333-8333-333333333333',
                'sku' => null,
                'requested_locale' => 'es',
                'resolved_locale' => 'es',
                'title' => 'Furgoneta privada',
                'public_path' => '/commerce/furgonetas/modelo',
                'cover_media_public_id' => null,
                'quantity' => 1,
                'unit_price_minor' => null,
                'currency' => null,
                'availability_status' => 'available',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function adminPayload(): array
    {
        return $this->requesterPayload() + ['contact' => [
            'name' => 'Ane Bidaiari',
            'email' => 'ane@example.test',
            'phone' => '600123123',
            'message' => 'Quiero más información.',
        ]];
    }

    private function mailConfiguration(): WebAdminMailConfiguration
    {
        return new WebAdminMailConfiguration(
            'https://example.test',
            'smtp.example.test',
            587,
            WebAdminMailConfiguration::ENCRYPTION_STARTTLS,
            OpaqueSecret::fromString('mailer@example.test'),
            OpaqueSecret::fromString('private-password'),
            'mailer@example.test',
            'Commerce'
        );
    }
}

final class FixedCommerceClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

final class RecordingCommerceTransport implements WebAdminMailTransportInterface
{
    /** @var list<WebAdminMailMessage> */
    public array $messages = [];
    public function send(WebAdminMailMessage $message): void { $this->messages[] = $message; }
}

final class FailingCommerceTransport implements WebAdminMailTransportInterface
{
    public function send(WebAdminMailMessage $message): void
    {
        throw new RuntimeException('smtp-secret-that-must-not-be-logged');
    }
}

final class StaticCommerceMailRuntime implements CommerceMailDispatchCommandRuntimeInterface
{
    public function __construct(private readonly CommerceInquiryOutboxDispatcher $dispatcher) {}
    public function dispatch(int $limit): \App\Core\Commerce\Outbox\CommerceOutboxDispatchReport
    {
        return $this->dispatcher->dispatchBatch($limit);
    }
}

final class StaticCommerceMailRuntimeFactory implements CommerceMailDispatchCommandRuntimeFactoryInterface
{
    public function __construct(private readonly CommerceMailDispatchCommandRuntimeInterface $runtime) {}
    public function create(string $projectRoot, string $coreRoot): CommerceMailDispatchCommandRuntimeInterface
    {
        return $this->runtime;
    }
}
