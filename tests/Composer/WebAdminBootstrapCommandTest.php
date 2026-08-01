<?php

declare(strict_types=1);

use App\Core\Composer\Command\WebAdminBootstrapCommand;
use App\Core\Composer\WebAdminBootstrapCommandRuntimeFactory;
use App\Core\Composer\WebAdminBootstrapCommandRuntimeFactoryInterface;
use App\Core\Composer\WebAdminBootstrapCommandRuntimeInterface;
use App\Core\Composer\WebAdminBootstrapCommandRuntimeException;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Migrations\MigrationApplyOptions;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\Modules\Migrations\MigrationDatabasePlanner;
use App\Core\Modules\Migrations\MigrationRunner;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\WebAdmin\Bootstrap\BootstrapException;
use App\Core\WebAdmin\Bootstrap\BootstrapInvitationResendResult;
use App\Core\WebAdmin\Bootstrap\BootstrapResult;
use Composer\Console\Application as ComposerApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class BootstrapCliRuntimeFixture implements
    WebAdminBootstrapCommandRuntimeInterface
{
    public int $previewCalls = 0;
    public int $bootstrapCalls = 0;
    public int $resendCalls = 0;

    public function __construct(
        private readonly MigrationDatabasePlan $plan,
        private readonly BootstrapResult|Throwable $outcome,
        private readonly BootstrapInvitationResendResult|Throwable|null
            $resendOutcome = null
    ) {
    }

    public function preview(): MigrationDatabasePlan
    {
        ++$this->previewCalls;

        return $this->plan;
    }

    public function bootstrap(): BootstrapResult
    {
        ++$this->bootstrapCalls;
        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }

    public function resendInvitations(): BootstrapInvitationResendResult
    {
        ++$this->resendCalls;
        if ($this->resendOutcome instanceof Throwable) {
            throw $this->resendOutcome;
        }

        return $this->resendOutcome
            ?? new BootstrapInvitationResendResult(0, 2);
    }
}

final class BootstrapCliRuntimeFactoryFixture implements
    WebAdminBootstrapCommandRuntimeFactoryInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly WebAdminBootstrapCommandRuntimeInterface|Throwable $outcome
    ) {
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): WebAdminBootstrapCommandRuntimeInterface {
        ++$this->calls;
        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

final class ChangingBootstrapCliRuntimeFixture implements
    WebAdminBootstrapCommandRuntimeInterface
{
    public int $previewCalls = 0;
    public int $bootstrapCalls = 0;
    public int $resendCalls = 0;

    /** @param list<MigrationDatabasePlan> $plans */
    public function __construct(private array $plans)
    {
    }

    public function preview(): MigrationDatabasePlan
    {
        ++$this->previewCalls;
        $plan = array_shift($this->plans);
        if (!$plan instanceof MigrationDatabasePlan) {
            throw new RuntimeException('Preview sequence exhausted.');
        }

        return $plan;
    }

    public function bootstrap(): BootstrapResult
    {
        ++$this->bootstrapCalls;

        return BootstrapResult::completed(2, 0, 2);
    }

    public function resendInvitations(): BootstrapInvitationResendResult
    {
        ++$this->resendCalls;

        return new BootstrapInvitationResendResult(2, 0);
    }
}

final class BootstrapCliPdoFactoryFixture implements
    PdoConnectionFactoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function connect(): PDO
    {
        return $this->pdo;
    }
}

final class WebAdminBootstrapCommandTest extends TestCase
{
    private Filesystem $filesystem;
    private string $temporaryRoot;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->temporaryRoot = sys_get_temp_dir()
            . '/liquidstack-webadmin-bootstrap-cli-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->temporaryRoot);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->temporaryRoot);
    }

    public function testConstructionIsSideEffectFree(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::alreadyCompleted()
        );
        $factory = new BootstrapCliRuntimeFactoryFixture($runtime);

        $command = new WebAdminBootstrapCommand(
            $this->temporaryRoot,
            dirname(__DIR__, 2),
            $factory
        );

        self::assertSame(0, $factory->calls);
        self::assertSame(0, $runtime->previewCalls);
        self::assertSame(0, $runtime->bootstrapCalls);
        self::assertSame(
            'liquidstack:webadmin:bootstrap',
            $command->getName()
        );
    }

    public function testJsonRequiresYesBeforeBuildingTheRuntime(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::alreadyCompleted()
        );
        $factory = new BootstrapCliRuntimeFactoryFixture($runtime);
        $tester = $this->tester($factory);

        $status = $tester->execute(['--format' => 'json']);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::INVALID, $status);
        self::assertSame(0, $factory->calls);
        self::assertSame(
            'webadmin.bootstrap.json_requires_yes',
            $payload['error']['code']
        );
    }

    public function testInvalidFormatDoesNotBuildTheRuntime(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::alreadyCompleted()
        );
        $factory = new BootstrapCliRuntimeFactoryFixture($runtime);
        $tester = $this->tester($factory);

        $status = $tester->execute(['--format' => 'xml']);

        self::assertSame(Command::INVALID, $status);
        self::assertSame(0, $factory->calls);
        self::assertStringContainsString(
            'webadmin.bootstrap.format_invalid',
            $tester->getDisplay()
        );
    }

    /**
     * @dataProvider blockedPlans
     * @param list<array<string, mixed>> $entries
     * @param list<array{code: string, module: ?string, migration: ?string}> $blockers
     */
    public function testSchemaMustBeFullyAppliedAndWithoutBlockers(
        array $entries,
        array $blockers,
        string $expectedCode
    ): void {
        $plan = new MigrationDatabasePlan(
            'sqlite',
            true,
            $entries,
            $blockers
        );
        $runtime = new BootstrapCliRuntimeFixture(
            $plan,
            BootstrapResult::completed(2, 0, 2)
        );
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );

        $status = $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::FAILURE, $status);
        self::assertSame($expectedCode, $payload['error']['code']);
        self::assertSame(0, $runtime->bootstrapCalls);
    }

    /**
     * @return iterable<string, array{
     *     list<array<string, mixed>>,
     *     list<array{code: string, module: ?string, migration: ?string}>,
     *     string
     * }>
     */
    public static function blockedPlans(): iterable
    {
        yield 'database or drift blocker' => [[
            self::entry('webadmin', 'applied'),
        ], [[
            'code' => 'migration.postcondition_drift',
            'module' => 'webadmin',
            'migration' => '0001_initial_schema',
        ]], 'webadmin.bootstrap.migration_plan_blocked'];

        yield 'missing WebAdmin catalog' => [[
            self::entry('blog', 'applied'),
        ], [], 'webadmin.bootstrap.migration_catalog_missing'];

        yield 'pending WebAdmin migration' => [[
            self::entry('webadmin', 'pending'),
        ], [], 'webadmin.bootstrap.migrations_not_applied'];
    }

    public function testNonInteractiveExecutionRequiresConfirmation(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::completed(2, 0, 2)
        );
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );

        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(
            'webadmin.bootstrap.confirmation_required',
            $tester->getDisplay()
        );
        self::assertSame(0, $runtime->bootstrapCalls);
    }

    public function testInteractiveCancellationDoesNotBootstrap(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::completed(2, 0, 2)
        );
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );
        $tester->setInputs(['no']);

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(0, $runtime->bootstrapCalls);
        self::assertStringContainsString(
            'no se modificó la base de datos',
            $tester->getDisplay()
        );
    }

    public function testInteractiveConfirmationBootstraps(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::completed(2, 0, 2)
        );
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );
        $tester->setInputs(['yes']);

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $runtime->bootstrapCalls);
        self::assertStringContainsString(
            'Bootstrap completado: 2 creadas, 0 reconciliadas y 2 invitaciones encoladas.',
            $tester->getDisplay()
        );
    }

    public function testResendModeRequiresExplicitConfirmationOrYes(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::alreadyCompleted(),
            new BootstrapInvitationResendResult(2, 0)
        );
        $nonInteractive = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );

        $status = $nonInteractive->execute(
            ['--resend-invites' => true],
            ['interactive' => false]
        );

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(
            'webadmin.bootstrap.confirmation_required',
            $nonInteractive->getDisplay()
        );
        self::assertSame(1, $runtime->previewCalls);
        self::assertSame(0, $runtime->bootstrapCalls);
        self::assertSame(0, $runtime->resendCalls);

        $cancelledRuntime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::alreadyCompleted(),
            new BootstrapInvitationResendResult(2, 0)
        );
        $cancelled = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($cancelledRuntime)
        );
        $cancelled->setInputs(['no']);

        $cancelledStatus = $cancelled->execute([
            '--resend-invites' => true,
        ]);

        self::assertSame(Command::SUCCESS, $cancelledStatus);
        self::assertStringContainsString(
            'Revocar enlaces anteriores',
            $cancelled->getDisplay()
        );
        self::assertStringContainsString(
            'no se modificó la base de datos',
            $cancelled->getDisplay()
        );
        self::assertSame(1, $cancelledRuntime->previewCalls);
        self::assertSame(0, $cancelledRuntime->bootstrapCalls);
        self::assertSame(0, $cancelledRuntime->resendCalls);
    }

    public function testResendJsonRequiresYesBeforeRuntimeCreation(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::alreadyCompleted(),
            new BootstrapInvitationResendResult(2, 0)
        );
        $factory = new BootstrapCliRuntimeFactoryFixture($runtime);
        $tester = $this->tester($factory);

        $status = $tester->execute([
            '--resend-invites' => true,
            '--format' => 'json',
        ]);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::INVALID, $status);
        self::assertSame(
            'webadmin.bootstrap.json_requires_yes',
            $payload['error']['code']
        );
        self::assertSame(0, $factory->calls);
        self::assertSame(0, $runtime->previewCalls);
        self::assertSame(0, $runtime->bootstrapCalls);
        self::assertSame(0, $runtime->resendCalls);
    }

    public function testConfirmedResendUsesTwoStablePreviewsAndNeverBootstraps(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::completed(99, 99, 99),
            new BootstrapInvitationResendResult(2, 0)
        );
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );

        $status = $tester->execute([
            '--resend-invites' => true,
            '--yes' => true,
            '--format' => 'text',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(2, $runtime->previewCalls);
        self::assertSame(0, $runtime->bootstrapCalls);
        self::assertSame(1, $runtime->resendCalls);
        self::assertStringContainsString(
            '2 invitaciones encoladas y 0 identidades omitidas',
            $tester->getDisplay()
        );
        self::assertStringNotContainsString('99', $tester->getDisplay());
    }

    public function testResendRejectsChangedPlanBeforeMutation(): void
    {
        $runtime = new ChangingBootstrapCliRuntimeFixture([
            $this->readyPlan(),
            new MigrationDatabasePlan(
                'sqlite',
                true,
                [
                    self::entry('webadmin', 'applied'),
                    self::entry('blog', 'pending'),
                ],
                []
            ),
        ]);
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );

        $status = $tester->execute([
            '--resend-invites' => true,
            '--yes' => true,
            '--format' => 'json',
        ]);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            'webadmin.bootstrap.migration_plan_changed',
            $payload['error']['code']
        );
        self::assertSame(2, $runtime->previewCalls);
        self::assertSame(0, $runtime->bootstrapCalls);
        self::assertSame(0, $runtime->resendCalls);
    }

    public function testResendRejectsUnappliedSchemaBeforeMutation(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            new MigrationDatabasePlan(
                'sqlite',
                true,
                [self::entry('webadmin', 'pending')],
                []
            ),
            BootstrapResult::alreadyCompleted(),
            new BootstrapInvitationResendResult(2, 0)
        );
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );

        $status = $tester->execute([
            '--resend-invites' => true,
            '--yes' => true,
            '--format' => 'json',
        ]);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            'webadmin.bootstrap.migrations_not_applied',
            $payload['error']['code']
        );
        self::assertSame(1, $runtime->previewCalls);
        self::assertSame(0, $runtime->bootstrapCalls);
        self::assertSame(0, $runtime->resendCalls);
    }

    public function testResendTextOutputIsSafeForChangedAndNoChange(): void
    {
        $changedRuntime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::completed(99, 99, 99),
            new BootstrapInvitationResendResult(1, 1)
        );
        $changed = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($changedRuntime)
        );
        $changedStatus = $changed->execute([
            '--resend-invites' => true,
            '--yes' => true,
        ]);

        self::assertSame(Command::SUCCESS, $changedStatus);
        self::assertStringContainsString(
            '1 invitación encolada y 1 identidad omitida',
            $changed->getDisplay()
        );
        self::assertSame(0, $changedRuntime->bootstrapCalls);
        self::assertSame(1, $changedRuntime->resendCalls);

        $unchangedRuntime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::completed(99, 99, 99),
            new BootstrapInvitationResendResult(0, 2)
        );
        $unchanged = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($unchangedRuntime)
        );
        $unchangedStatus = $unchanged->execute([
            '--resend-invites' => true,
            '--yes' => true,
        ]);

        self::assertSame(Command::SUCCESS, $unchangedStatus);
        self::assertStringContainsString(
            'No hay invitaciones bootstrap que reencolar.',
            $unchanged->getDisplay()
        );
        self::assertSame(0, $unchangedRuntime->bootstrapCalls);
        self::assertSame(1, $unchangedRuntime->resendCalls);

        $display = $changed->getDisplay() . $unchanged->getDisplay();
        foreach (['@', 'secret', 'SELECT ', 'CREATE TABLE'] as $private) {
            self::assertStringNotContainsString($private, $display);
        }
    }

    public function testResendJsonOutputIsSafeForChangedAndNoChange(): void
    {
        foreach ([
            'changed' => new BootstrapInvitationResendResult(2, 0),
            'unchanged' => new BootstrapInvitationResendResult(0, 2),
        ] as $case => $result) {
            $runtime = new BootstrapCliRuntimeFixture(
                $this->readyPlan(),
                BootstrapResult::completed(99, 99, 99),
                $result
            );
            $tester = $this->tester(
                new BootstrapCliRuntimeFactoryFixture($runtime)
            );
            $status = $tester->execute([
                '--resend-invites' => true,
                '--yes' => true,
                '--format' => 'json',
            ]);
            $display = $tester->getDisplay();
            $payload = json_decode(
                $display,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertSame(Command::SUCCESS, $status, $case);
            self::assertSame(1, $payload['schema']);
            self::assertTrue($payload['ok']);
            self::assertSame(
                'webadmin-bootstrap-invite-resend',
                $payload['operation']
            );
            self::assertSame($result->toSafeArray(), $payload['result']);
            self::assertSame(0, $runtime->bootstrapCalls);
            self::assertSame(1, $runtime->resendCalls);
            self::assertStringNotContainsString('@', $display);
            self::assertStringNotContainsString('secret', $display);
            self::assertStringNotContainsString('CREATE TABLE', $display);
        }
    }

    public function testResendRequiresCompletedStateWithoutLeakingDetails(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::completed(99, 99, 99),
            new BootstrapException('bootstrap.resend_requires_completed')
        );
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );

        $status = $tester->execute([
            '--resend-invites' => true,
            '--yes' => true,
            '--format' => 'json',
        ]);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            'bootstrap.resend_requires_completed',
            $payload['error']['code']
        );
        self::assertSame(
            'webadmin-bootstrap-invite-resend',
            $payload['operation']
        );
        self::assertSame(2, $runtime->previewCalls);
        self::assertSame(0, $runtime->bootstrapCalls);
        self::assertSame(1, $runtime->resendCalls);
        self::assertStringNotContainsString('@', $display);
        self::assertStringNotContainsString('secret', $display);
        self::assertStringNotContainsString('PDO', $display);
    }

    public function testPlanIsRevalidatedAfterConfirmationAndMustRemainIdentical(): void
    {
        $initial = $this->readyPlan();
        $changed = new MigrationDatabasePlan(
            'sqlite',
            true,
            [
                self::entry('webadmin', 'applied'),
                self::entry('blog', 'pending'),
            ],
            []
        );
        $runtime = new ChangingBootstrapCliRuntimeFixture([
            $initial,
            $changed,
        ]);
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );
        $tester->setInputs(['yes']);

        $status = $tester->execute(['--format' => 'text']);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(0, $runtime->bootstrapCalls);
        self::assertStringContainsString(
            'webadmin.bootstrap.migration_plan_changed',
            $tester->getDisplay()
        );
    }

    public function testYesReturnsOnlySafeStatusAndCountersAsJson(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            BootstrapResult::completed(2, 0, 2)
        );
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );

        $status = $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $runtime->bootstrapCalls);
        self::assertSame('webadmin-bootstrap', $payload['operation']);
        self::assertSame([
            'status' => 'completed',
            'changed' => true,
            'created_accounts' => 2,
            'reconciled_accounts' => 0,
            'queued_invites' => 2,
        ], $payload['result']);
        self::assertStringNotContainsString('@', $display);
        self::assertStringNotContainsString('CREATE TABLE', $display);
    }

    public function testKnownFailuresExposeOnlyStableCodes(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            new BootstrapException('bootstrap.identity_collision')
        );
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );

        $status = $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            'bootstrap.identity_collision',
            $payload['error']['code']
        );
        self::assertSame([
            'schema',
            'ok',
            'operation',
            'error',
        ], array_keys($payload));
        self::assertStringNotContainsString('@', $display);
    }

    public function testUnexpectedBootstrapFailureCannotLeakInternalData(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            new RuntimeException(
                'PDO exposed system@example.test, secret-password and SQL'
            )
        );
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );

        $status = $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            'webadmin.bootstrap.internal_failure',
            $payload['error']['code']
        );
        self::assertStringNotContainsString('system@example.test', $display);
        self::assertStringNotContainsString('secret-password', $display);
        self::assertStringNotContainsString('PDO exposed', $display);
    }

    public function testMalformedIssueCodeIsMappedBeforeRendering(): void
    {
        $runtime = new BootstrapCliRuntimeFixture(
            $this->readyPlan(),
            new BootstrapException(
                'bootstrap.failure_secret_example_test_create_table_users'
            )
        );
        $tester = $this->tester(
            new BootstrapCliRuntimeFactoryFixture($runtime)
        );

        $status = $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            'webadmin.bootstrap.internal_failure',
            $payload['error']['code']
        );
        self::assertStringNotContainsString(
            'failure_secret_example_test',
            $display
        );
        self::assertStringNotContainsString('create_table_users', $display);
    }

    public function testRuntimeFailuresAreSanitized(): void
    {
        $factory = new BootstrapCliRuntimeFactoryFixture(
            new WebAdminBootstrapCommandRuntimeException(
                'webadmin.bootstrap.module_not_enabled'
            )
        );
        $tester = $this->tester($factory);

        $status = $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(
            'webadmin.bootstrap.module_not_enabled',
            $tester->getDisplay()
        );
    }

    public function testFactoryRejectsAProjectWithoutWebAdminBeforeReadingEnvironmentOrConnecting(): void
    {
        $project = $this->temporaryRoot . '/without-webadmin';
        $this->filesystem->mkdir($project);
        $this->filesystem->dumpFile(
            $project . '/composer.json',
            json_encode([
                'require' => ['liquidstack/core' => '^1.9'],
            ], JSON_THROW_ON_ERROR)
        );
        $this->filesystem->mkdir($project . '/.env');
        $calls = 0;
        $factory = new WebAdminBootstrapCommandRuntimeFactory(
            connectionFactoryResolver: static function () use (&$calls): PdoConnectionFactoryInterface {
                ++$calls;
                throw new RuntimeException('Must not connect.');
            }
        );

        try {
            $factory->create($project, dirname(__DIR__, 2));
            self::fail('A disabled WebAdmin module must fail closed.');
        } catch (WebAdminBootstrapCommandRuntimeException $exception) {
            self::assertSame(
                'webadmin.bootstrap.module_not_enabled',
                $exception->issueCode()
            );
        }
        self::assertSame(0, $calls);
    }

    public function testRealRuntimeRequiresAppliedSchemaAndBootstrapsIdempotently(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite es necesario para probar el CLI.');
        }

        $project = $this->temporaryRoot . '/with-webadmin';
        $this->filesystem->mkdir($project . '/App/config/modules');
        $this->filesystem->dumpFile(
            $project . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '^1.9',
                    'liquidstack/webadmin' => '*',
                ],
            ], JSON_THROW_ON_ERROR)
        );
        $this->filesystem->dumpFile(
            $project . '/.env',
            "BBDD_SERVER=localhost\n"
                . "BBDD_USER=fixture\n"
                . "BBDD_PASS=private-database-value\n"
                . "BBDD_NAME=fixture\n"
                . "LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL=system@example.test\n"
                . "LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL=site@example.test\n"
        );
        $this->filesystem->dumpFile(
            $project . '/App/config/modules/webadmin.php',
            "<?php\nreturn [\n"
                . "    'database' => ['table_prefix' => 'cli_webadmin_'],\n"
                . "];\n"
        );

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $coreRoot = dirname(__DIR__, 2);
        $factory = new WebAdminBootstrapCommandRuntimeFactory(
            connectionFactoryResolver: static fn (array $environment): PdoConnectionFactoryInterface =>
                new BootstrapCliPdoFactoryFixture($pdo)
        );
        $beforeMigrationTester = $this->tester(
            $factory,
            $project,
            $coreRoot
        );
        $beforeMigrationStatus = $beforeMigrationTester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $beforeMigration = json_decode(
            $beforeMigrationTester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::FAILURE, $beforeMigrationStatus);
        self::assertSame(
            'webadmin.bootstrap.migrations_not_applied',
            $beforeMigration['error']['code']
        );
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'"
        )->fetchColumn());

        $registry = ModuleRegistry::forProject($project, $coreRoot);
        $catalog = MigrationCatalog::fromRegistry($registry);
        $scopes = MigrationScopeCollection::fromTablePrefixes([
            'webadmin' => 'cli_webadmin_',
        ]);
        $planner = new MigrationDatabasePlanner();
        $preview = $planner->plan($pdo, $catalog, $scopes);
        (new MigrationRunner())->apply(
            $pdo,
            $catalog,
            $scopes,
            new MigrationApplyOptions(expectedPlanHash: $preview->hash())
        );

        $tester = $this->tester($factory, $project, $coreRoot);

        $firstStatus = $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $firstDisplay = $tester->getDisplay();
        $first = json_decode($firstDisplay, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $firstStatus);
        self::assertSame('completed', $first['result']['status']);
        self::assertSame(2, (int) $pdo->query(
            'SELECT COUNT(*) FROM cli_webadmin_users'
        )->fetchColumn());
        self::assertStringNotContainsString('system@example.test', $firstDisplay);
        self::assertStringNotContainsString('private-database-value', $firstDisplay);
        self::assertStringNotContainsString('CREATE TABLE', $firstDisplay);

        $secondTester = $this->tester($factory, $project, $coreRoot);
        $secondStatus = $secondTester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $second = json_decode(
            $secondTester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::SUCCESS, $secondStatus);
        self::assertSame('already_completed', $second['result']['status']);
        self::assertFalse($second['result']['changed']);
        self::assertSame(2, (int) $pdo->query(
            'SELECT COUNT(*) FROM cli_webadmin_users'
        )->fetchColumn());
    }

    private function tester(
        WebAdminBootstrapCommandRuntimeFactoryInterface $factory,
        ?string $projectRoot = null,
        ?string $coreRoot = null
    ): CommandTester {
        $command = new WebAdminBootstrapCommand(
            $projectRoot ?? $this->temporaryRoot,
            $coreRoot ?? dirname(__DIR__, 2),
            $factory
        );
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($command);
    }

    private function readyPlan(): MigrationDatabasePlan
    {
        return new MigrationDatabasePlan(
            'sqlite',
            true,
            [self::entry('webadmin', 'applied')],
            []
        );
    }

    /** @return array<string, mixed> */
    private static function entry(string $module, string $status): array
    {
        return [
            'module' => $module,
            'id' => '0001_initial_schema',
            'description' => 'Fixture.',
            'checksum' => str_repeat('a', 64),
            'scope_hash' => str_repeat('b', 64),
            'destructive' => false,
            'status' => $status,
        ];
    }
}
