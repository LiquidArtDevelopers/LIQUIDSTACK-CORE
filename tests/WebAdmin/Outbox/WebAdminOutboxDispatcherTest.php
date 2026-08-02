<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Mail\WebAdminMailMessage;
use App\Core\WebAdmin\Mail\WebAdminMailTransportInterface;
use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatcher;
use App\Core\WebAdmin\Outbox\WebAdminOutboxMessageFactoryInterface;
use App\Core\WebAdmin\Outbox\WebAdminOutboxRepository;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Support\ClockInterface;
use PHPUnit\Framework\TestCase;

final class WebAdminOutboxDispatcherTest extends TestCase
{
    private string $databasePath;
    private PDO $pdo;
    private MutableOutboxClock $clock;
    private string|false $previousExceptionArgumentSetting;
    private int $identitySequence = 0;

    protected function setUp(): void
    {
        $this->previousExceptionArgumentSetting = ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $this->databasePath = sys_get_temp_dir()
            . '/liquidstack-outbox-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = $this->connection();
        $this->installSchema($this->pdo);
        $this->clock = new MutableOutboxClock($this->utc(
            '2026-08-01 03:00:00.000000'
        ));
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
        if ($this->previousExceptionArgumentSetting !== false) {
            ini_set(
                'zend.exception_ignore_args',
                $this->previousExceptionArgumentSetting
            );
        }
    }

    public function testSuccessfulDeliveryCommitsClaimBeforeSendingAndAcks(): void
    {
        [$userId, $outboxId] = $this->queue('invite');
        $rawTokens = [];
        $factoryObservedTransaction = null;
        $transportObservedTransaction = null;
        $factory = $this->factory(function (
            string $kind,
            string $email,
            string $locale,
            #[SensitiveParameter] string $rawToken
        ) use (&$rawTokens, &$factoryObservedTransaction): WebAdminMailMessage {
            $factoryObservedTransaction = $this->pdo->inTransaction();
            $rawTokens[] = $rawToken;

            return new WebAdminMailMessage(
                $email,
                null,
                'Invitacion WebAdmin',
                'Token: ' . $rawToken,
                '<p>Token: ' . $rawToken . '</p>'
            );
        });
        $transport = $this->transport(function (
            WebAdminMailMessage $message
        ) use (&$transportObservedTransaction): void {
            $transportObservedTransaction = $this->pdo->inTransaction();
            self::assertSame(
                'owner1@example.test',
                $message->recipientEmail()
            );
        });

        $report = $this->dispatcher($factory, $transport)->dispatchBatch(10);

        self::assertSame([
            'examined' => 1,
            'claimed' => 1,
            'sent' => 1,
            'retry_scheduled' => 0,
            'permanently_failed' => 0,
            'fenced' => 0,
        ], $report->toArray());
        self::assertFalse($factoryObservedTransaction);
        self::assertFalse($transportObservedTransaction);
        self::assertCount(1, $rawTokens);
        $outbox = $this->row(
            'SELECT * FROM ls_webadmin_outbox WHERE id = ?',
            [$outboxId]
        );
        self::assertSame('sent', $outbox['status']);
        self::assertSame(1, (int) $outbox['attempts']);
        self::assertNull($outbox['locked_at']);
        self::assertNull($outbox['lock_token_hash']);
        self::assertNotNull($outbox['sent_at']);
        $token = $this->row(
            'SELECT * FROM ls_webadmin_action_tokens WHERE user_id = ?',
            [$userId]
        );
        self::assertNotSame($rawTokens[0], $token['token_hash']);
        self::assertSame(hash('sha256', $rawTokens[0]), $token['token_hash']);
        self::assertNotNull($token['delivered_at']);
        self::assertNull($token['revoked_at']);
        self::assertSame(
            '2026-08-04 03:00:00.000000',
            $token['expires_at']
        );
        self::assertStringNotContainsString(
            $rawTokens[0],
            json_encode(
                [$outbox, $token],
                JSON_THROW_ON_ERROR
            )
        );
    }

    public function testFailuresUseAllowlistedCodeBackoffAndStopAfterFive(): void
    {
        [, $outboxId] = $this->queue('password_reset');
        $rawTokens = [];
        $factory = $this->factory(function (
            string $kind,
            string $email,
            string $locale,
            #[SensitiveParameter] string $rawToken
        ) use (&$rawTokens): WebAdminMailMessage {
            $rawTokens[] = $rawToken;

            return new WebAdminMailMessage(
                $email,
                null,
                'Reset',
                $rawToken,
                '<p>' . $rawToken . '</p>'
            );
        });
        $secretFailureDetail = 'smtp-secret-detail-' . bin2hex(random_bytes(8));
        $transport = $this->transport(static function () use (
            $secretFailureDetail
        ): void {
            throw new RuntimeException($secretFailureDetail);
        });
        $dispatcher = $this->dispatcher($factory, $transport);
        $expectedDelays = [60, 300, 900, 3600];

        foreach ($expectedDelays as $index => $delay) {
            $before = $this->clock->now();
            $report = $dispatcher->dispatchBatch(1);
            self::assertSame(1, $report->retryScheduled());
            self::assertSame(0, $report->permanentlyFailed());
            $row = $this->row(
                'SELECT * FROM ls_webadmin_outbox WHERE id = ?',
                [$outboxId]
            );
            self::assertSame('pending', $row['status']);
            self::assertSame($index + 1, (int) $row['attempts']);
            self::assertSame('outbox.delivery_failed', $row['last_error_code']);
            self::assertStringNotContainsString(
                $secretFailureDetail,
                json_encode($row, JSON_THROW_ON_ERROR)
            );
            self::assertSame(
                $before->modify('+' . $delay . ' seconds')->format(
                    'Y-m-d H:i:s.u'
                ),
                $row['available_at']
            );
            $this->clock->set($before->modify('+' . $delay . ' seconds'));
        }

        $final = $dispatcher->dispatchBatch(1);
        self::assertSame(1, $final->permanentlyFailed());
        self::assertSame(0, $final->retryScheduled());
        $row = $this->row(
            'SELECT * FROM ls_webadmin_outbox WHERE id = ?',
            [$outboxId]
        );
        self::assertSame('failed', $row['status']);
        self::assertSame(5, (int) $row['attempts']);
        self::assertNull($row['locked_at']);
        self::assertNull($row['lock_token_hash']);
        self::assertNull($row['action_token_id']);
        self::assertCount(5, array_unique($rawTokens));
        $tokens = $this->pdo->query(
            'SELECT token_hash, delivered_at, revoked_at '
            . 'FROM ls_webadmin_action_tokens'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(5, $tokens);
        foreach ($tokens as $token) {
            self::assertNull($token['delivered_at']);
            self::assertNotNull($token['revoked_at']);
        }
    }

    public function testFactoryFailureCannotPersistExceptionOrRawToken(): void
    {
        [, $outboxId] = $this->queue('invite');
        $rawToken = null;
        $secretFailureDetail = 'template-secret-' . bin2hex(random_bytes(8));
        $factory = $this->factory(static function (
            string $kind,
            string $email,
            string $locale,
            #[SensitiveParameter] string $token
        ) use (&$rawToken, $secretFailureDetail): WebAdminMailMessage {
            $rawToken = $token;
            throw new RuntimeException($secretFailureDetail);
        });
        $transport = $this->transport(static function (): void {
            self::fail('Transport must not run after a factory failure.');
        });

        $report = $this->dispatcher($factory, $transport)->dispatchBatch(1);

        self::assertSame(1, $report->retryScheduled());
        self::assertIsString($rawToken);
        $databaseDump = json_encode([
            $this->row(
                'SELECT * FROM ls_webadmin_outbox WHERE id = ?',
                [$outboxId]
            ),
            $this->pdo->query(
                'SELECT * FROM ls_webadmin_action_tokens'
            )->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($rawToken, $databaseDump);
        self::assertStringNotContainsString($secretFailureDetail, $databaseDump);
        self::assertStringContainsString(
            'outbox.message_invalid',
            $databaseDump
        );
    }

    public function testStaleWorkerIsFencedAndOnlyNewestTokenCanBeDelivered(): void
    {
        [, $outboxId] = $this->queue('invite');
        $firstRepository = $this->repository($this->pdo);
        $first = $firstRepository->claimNext($this->clock->now())->lease();
        $secondPdo = $this->connection();
        $secondRepository = $this->repository($secondPdo);

        $claimedRow = $this->row(
            'SELECT lock_token_hash FROM ls_webadmin_outbox WHERE id = ?',
            [$outboxId]
        );
        self::assertNotSame(
            $first->revealLeaseToken(),
            $claimedRow['lock_token_hash']
        );
        self::assertSame(
            hash('sha256', $first->revealLeaseToken()),
            $claimedRow['lock_token_hash']
        );

        self::assertTrue(
            $secondRepository->claimNext($this->clock->now())->isNone(),
            'A live five-minute lease must exclude a second worker.'
        );

        $this->clock->set($this->clock->now()->modify('+301 seconds'));
        self::assertFalse(
            $firstRepository->acknowledge($first, $this->clock->now()),
            'An expired lease cannot ACK even before another worker reclaims.'
        );
        self::assertSame(
            WebAdminOutboxRepository::FAILURE_FENCED,
            $firstRepository->recordFailure(
                $first,
                $this->clock->now(),
                'outbox.delivery_failed'
            )
        );
        $second = $secondRepository->claimNext($this->clock->now())->lease();
        self::assertSame($outboxId, $second->outboxId());
        self::assertSame(2, $second->attempt());
        self::assertNotSame(
            $first->revealActionToken(),
            $second->revealActionToken()
        );
        self::assertFalse(
            $firstRepository->acknowledge($first, $this->clock->now())
        );
        self::assertTrue(
            $secondRepository->acknowledge($second, $this->clock->now())
        );

        $tokens = $this->pdo->query(
            'SELECT id, delivered_at, revoked_at '
            . 'FROM ls_webadmin_action_tokens ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $tokens);
        self::assertNull($tokens[0]['delivered_at']);
        self::assertNotNull($tokens[0]['revoked_at']);
        self::assertNotNull($tokens[1]['delivered_at']);
        self::assertNull($tokens[1]['revoked_at']);
    }

    public function testAStaleFifthAttemptIsClosedWithoutSending(): void
    {
        [, $outboxId] = $this->queue('invite');
        $repository = $this->repository($this->pdo);
        $lease = $repository->claimNext($this->clock->now())->lease();
        $this->pdo->prepare(
            'UPDATE ls_webadmin_outbox SET attempts = 5, locked_at = ? '
            . 'WHERE id = ?'
        )->execute([
            $this->clock->now()->modify('-301 seconds')->format(
                'Y-m-d H:i:s.u'
            ),
            $outboxId,
        ]);
        $factory = $this->factory(static function (): WebAdminMailMessage {
            self::fail('A terminal stale lease must not build mail.');
        });
        $transport = $this->transport(static function (): void {
            self::fail('A terminal stale lease must not send mail.');
        });

        $report = $this->dispatcher($factory, $transport)->dispatchBatch(1);

        self::assertSame(1, $report->permanentlyFailed());
        $row = $this->row(
            'SELECT * FROM ls_webadmin_outbox WHERE id = ?',
            [$outboxId]
        );
        self::assertSame('failed', $row['status']);
        self::assertSame('outbox.lease_expired', $row['last_error_code']);
        self::assertNull($row['action_token_id']);
        $token = $this->row(
            'SELECT * FROM ls_webadmin_action_tokens WHERE id = ?',
            [$lease->actionTokenId()]
        );
        self::assertNotNull($token['revoked_at']);
    }

    public function testBatchLimitsAreEnforcedAndWorkIsBounded(): void
    {
        $this->queue('invite');
        $this->queue('invite');
        $this->queue('invite');
        $factory = $this->successfulFactory();
        $transport = $this->transport(static function (): void {
        });
        $dispatcher = $this->dispatcher($factory, $transport);

        foreach ([0, 101] as $invalid) {
            try {
                $dispatcher->dispatchBatch($invalid);
                self::fail('Invalid outbox limit should fail.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $report = $dispatcher->dispatchBatch(2);
        self::assertSame(2, $report->examined());
        self::assertSame(2, $report->sent());
        self::assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM ls_webadmin_outbox WHERE status = 'pending'"
            )->fetchColumn()
        );
    }

    public function testDuplicatePurposeLeavesOnlyNewestDeliveredTokenValid(): void
    {
        [$userId] = $this->queue('invite');
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_outbox '
            . '(kind, user_id, locale, status, attempts, available_at, '
            . 'created_at) VALUES '
            . "('invite', ?, 'es', 'pending', 0, ?, ?)"
        );
        $statement->execute([$userId, $timestamp, $timestamp]);

        $dispatcher = $this->dispatcher(
            $this->successfulFactory(),
            $this->transport(static function (): void {
            })
        );
        self::assertSame(1, $dispatcher->dispatchBatch(1)->sent());
        $firstActionId = (int) $this->pdo->query(
            'SELECT id FROM ls_webadmin_action_tokens ORDER BY id LIMIT 1'
        )->fetchColumn();
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s.u');
        $this->pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, pending_action_token_id, '
            . 'created_at, last_seen_at, idle_expires_at, '
            . 'absolute_expires_at, revoked_at) VALUES '
            . "(?, NULL, 'preauth', ?, ?, NULL, ?, ?, ?, ?, ?, NULL)"
        )->execute([
            '00000000-0000-4000-8000-000000000999',
            hash('sha256', 'old-action-session'),
            hash('sha256', 'old-action-csrf'),
            $firstActionId,
            $timestamp,
            $timestamp,
            $this->clock->now()->modify('+10 minutes')->format(
                'Y-m-d H:i:s.u'
            ),
            $this->clock->now()->modify('+20 minutes')->format(
                'Y-m-d H:i:s.u'
            ),
        ]);

        $report = $dispatcher->dispatchBatch(1);

        self::assertSame(1, $report->sent());
        $tokens = $this->pdo->query(
            'SELECT delivered_at, revoked_at FROM '
            . 'ls_webadmin_action_tokens ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $tokens);
        self::assertNotNull($tokens[0]['delivered_at']);
        self::assertNotNull($tokens[0]['revoked_at']);
        self::assertNotNull($tokens[1]['delivered_at']);
        self::assertNull($tokens[1]['revoked_at']);
        self::assertNotNull($this->pdo->query(
            'SELECT revoked_at FROM ls_webadmin_sessions LIMIT 1'
        )->fetchColumn());
        self::assertSame(
            1,
            count(array_filter(
                $tokens,
                static fn (array $token): bool =>
                    $token['delivered_at'] !== null
                    && $token['revoked_at'] === null
            ))
        );
    }

    public function testIneligibleRecipientIsClosedWithoutCreatingASecret(): void
    {
        [$userId, $outboxId] = $this->queue('password_reset');
        $this->pdo->prepare(
            "UPDATE ls_webadmin_users SET status = 'suspended', "
            . 'suspended_at = ? WHERE id = ?'
        )->execute([
            $this->clock->now()->format('Y-m-d H:i:s.u'),
            $userId,
        ]);
        $factory = $this->factory(static function (): WebAdminMailMessage {
            self::fail('An ineligible recipient must not build mail.');
        });
        $transport = $this->transport(static function (): void {
            self::fail('An ineligible recipient must not send mail.');
        });

        $report = $this->dispatcher($factory, $transport)->dispatchBatch(1);

        self::assertSame(1, $report->permanentlyFailed());
        $outbox = $this->row(
            'SELECT * FROM ls_webadmin_outbox WHERE id = ?',
            [$outboxId]
        );
        self::assertSame('failed', $outbox['status']);
        self::assertSame(0, (int) $outbox['attempts']);
        self::assertSame(
            'outbox.recipient_unavailable',
            $outbox['last_error_code']
        );
        self::assertSame(
            0,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ls_webadmin_action_tokens'
            )->fetchColumn()
        );
    }

    public function testLeaseAndMessageDebugOutputRedactsEveryRawToken(): void
    {
        $this->queue('invite');
        $lease = $this->repository($this->pdo)
            ->claimNext($this->clock->now())
            ->lease();
        $rawToken = $lease->revealActionToken();
        $rawLease = $lease->revealLeaseToken();
        $message = new WebAdminMailMessage(
            $lease->recipientEmail(),
            null,
            'Invitation',
            $rawToken,
            '<p>' . $rawToken . '</p>'
        );
        ob_start();
        var_dump($lease, $message);
        $debug = (string) ob_get_clean();

        self::assertStringContainsString('[redacted]', $debug);
        self::assertStringNotContainsString($rawToken, $debug);
        self::assertStringNotContainsString($rawLease, $debug);
    }

    public function testUnsupportedLocaleIsTerminalAndCannotPoisonTheBatch(): void
    {
        [, $poisonId] = $this->queue('invite');
        [, $validId] = $this->queue('invite');
        $this->pdo->prepare(
            'UPDATE ls_webadmin_outbox SET locale = ? WHERE id = ?'
        )->execute(['es-419', $poisonId]);

        $report = $this->dispatcher(
            $this->successfulFactory(),
            $this->transport(static function (): void {
            })
        )->dispatchBatch(2);

        self::assertSame(2, $report->examined());
        self::assertSame(1, $report->claimed());
        self::assertSame(1, $report->sent());
        self::assertSame(1, $report->permanentlyFailed());
        $poison = $this->row(
            'SELECT status, attempts, last_error_code, action_token_id '
            . 'FROM ls_webadmin_outbox WHERE id = ?',
            [$poisonId]
        );
        self::assertSame('failed', $poison['status']);
        self::assertSame(0, (int) $poison['attempts']);
        self::assertSame(
            'outbox.locale_unsupported',
            $poison['last_error_code']
        );
        self::assertNull($poison['action_token_id']);
        self::assertSame('sent', $this->row(
            'SELECT status FROM ls_webadmin_outbox WHERE id = ?',
            [$validId]
        )['status']);
    }

    private function dispatcher(
        WebAdminOutboxMessageFactoryInterface $factory,
        WebAdminMailTransportInterface $transport
    ): WebAdminOutboxDispatcher {
        return new WebAdminOutboxDispatcher(
            $this->repository($this->pdo),
            $factory,
            $transport,
            $this->clock
        );
    }

    private function repository(PDO $pdo): WebAdminOutboxRepository
    {
        return new WebAdminOutboxRepository(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'ls_webadmin_')
        );
    }

    /** @return array{0: int, 1: int} */
    private function queue(string $kind): array
    {
        $this->identitySequence++;
        $status = $kind === 'invite' ? 'invited' : 'active';
        $email = 'owner' . $this->identitySequence . '@example.test';
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_users '
            . '(public_id, email_canonical, status, auth_version, '
            . 'invited_at, activated_at, created_at, updated_at) VALUES '
            . '(?, ?, ?, 1, ?, ?, ?, ?)'
        );
        $statement->execute([
            sprintf(
                '00000000-0000-4000-8000-%012d',
                $this->identitySequence
            ),
            $email,
            $status,
            $kind === 'invite' ? $timestamp : null,
            $kind === 'password_reset' ? $timestamp : null,
            $timestamp,
            $timestamp,
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at, created_at, '
            . 'updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $userId,
            $kind === 'password_reset'
                ? 'existing-password-hash'
                : null,
            $kind === 'password_reset' ? $timestamp : null,
            $timestamp,
            $timestamp,
        ]);
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_outbox '
            . '(kind, user_id, locale, status, attempts, available_at, '
            . 'created_at) VALUES (?, ?, ?, ?, 0, ?, ?)'
        );
        $statement->execute([
            $kind,
            $userId,
            'es',
            'pending',
            $timestamp,
            $timestamp,
        ]);

        return [$userId, (int) $this->pdo->lastInsertId()];
    }

    private function successfulFactory(): WebAdminOutboxMessageFactoryInterface
    {
        return $this->factory(static function (
            string $kind,
            string $email,
            string $locale,
            #[SensitiveParameter] string $rawToken
        ): WebAdminMailMessage {
            return new WebAdminMailMessage(
                $email,
                null,
                'WebAdmin action',
                $rawToken,
                '<p>' . $rawToken . '</p>'
            );
        });
    }

    private function factory(
        Closure $callback
    ): WebAdminOutboxMessageFactoryInterface {
        return new class ($callback) implements WebAdminOutboxMessageFactoryInterface {
            public function __construct(private readonly Closure $callback)
            {
            }

            public function create(
                string $kind,
                string $recipientEmail,
                string $locale,
                #[SensitiveParameter] string $rawToken
            ): WebAdminMailMessage {
                return ($this->callback)(
                    $kind,
                    $recipientEmail,
                    $locale,
                    $rawToken
                );
            }
        };
    }

    private function transport(
        Closure $callback
    ): WebAdminMailTransportInterface {
        return new class ($callback) implements WebAdminMailTransportInterface {
            public function __construct(private readonly Closure $callback)
            {
            }

            public function send(WebAdminMailMessage $message): void
            {
                ($this->callback)($message);
            }
        };
    }

    private function connection(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 1000');

        return $pdo;
    }

    private function installSchema(PDO $pdo): void
    {
        $migration = null;
        foreach (WebAdminMigrationProvider::migrations() as $candidate) {
            if ($candidate->id() === '0001_webadmin_identity_and_access') {
                $migration = $candidate;
                break;
            }
        }
        self::assertNotNull($migration);
        $scope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        foreach ($migration->statementsFor('sqlite', $scope) as $statement) {
            $pdo->exec($statement);
        }
    }

    /** @param list<mixed> $parameters @return array<string, mixed> */
    private function row(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function utc(string $timestamp): DateTimeImmutable
    {
        return new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
    }
}

final class MutableOutboxClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $value)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }

    public function set(DateTimeImmutable $value): void
    {
        $this->value = $value;
    }
}
