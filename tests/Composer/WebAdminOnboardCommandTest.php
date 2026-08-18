<?php

declare(strict_types=1);

use App\Core\Composer\Command\WebAdminOnboardCommand;
use App\Core\Composer\WebAdminBootstrapCommandRuntimeFactoryInterface;
use App\Core\Composer\WebAdminBootstrapCommandRuntimeInterface;
use App\Core\Composer\WebAdminMailDispatchCommandRuntimeException;
use App\Core\Composer\WebAdminOnboardCommandRuntime;
use App\Core\Composer\WebAdminOnboardCommandRuntimeException;
use App\Core\Composer\WebAdminOnboardCommandRuntimeFactory;
use App\Core\Composer\WebAdminOnboardCommandRuntimeFactoryInterface;
use App\Core\Composer\WebAdminOnboardCommandRuntimeInterface;
use App\Core\Composer\WebAdminOnboardMailRuntimeFactoryInterface;
use App\Core\Composer\WebAdminOnboardMailRuntimeInterface;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\WebAdmin\Bootstrap\BootstrapInvitationReadiness;
use App\Core\WebAdmin\Bootstrap\BootstrapInvitationResendResult;
use App\Core\WebAdmin\Bootstrap\BootstrapOnboardingResult;
use App\Core\WebAdmin\Bootstrap\BootstrapResult;
use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatchReport;
use Composer\Console\Application as ComposerApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WebAdminOnboardCommandTest extends TestCase
{
    public function testConstructionIsSideEffectFree(): void
    {
        $factory = new OnboardCliRuntimeFactoryFixture(
            new OnboardCliRuntimeFixture($this->readyPlan(), $this->readyResult())
        );

        new WebAdminOnboardCommand('/project', '/core', $factory);

        self::assertSame(0, $factory->createCalls);
    }

    public function testInvalidInputStopsBeforeRuntimeCreation(): void
    {
        $factory = new OnboardCliRuntimeFactoryFixture(
            new OnboardCliRuntimeFixture($this->readyPlan(), $this->readyResult())
        );
        $invalidFormat = $this->tester($factory);

        self::assertSame(Command::INVALID, $invalidFormat->execute([
            '--yes' => true,
            '--format' => 'xml',
        ]));

        $jsonWithoutConfirmation = $this->tester($factory);
        self::assertSame(Command::INVALID, $jsonWithoutConfirmation->execute([
            '--format' => 'json',
        ]));
        self::assertSame(0, $factory->createCalls);
        self::assertStringContainsString(
            'webadmin.onboard.json_requires_yes',
            $jsonWithoutConfirmation->getDisplay()
        );
    }

    public function testPendingWebAdminMigrationBlocksBeforeMutation(): void
    {
        $runtime = new OnboardCliRuntimeFixture(
            new MigrationDatabasePlan(
                'sqlite',
                true,
                [self::entry('webadmin', 'pending')],
                []
            ),
            $this->readyResult()
        );
        $tester = $this->tester(new OnboardCliRuntimeFactoryFixture($runtime));

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
        self::assertSame(
            'webadmin.onboard.migrations_not_applied',
            $payload['error']['code']
        );
        self::assertSame(1, $runtime->previewCalls);
        self::assertSame(0, $runtime->onboardCalls);
    }

    public function testInteractiveCancellationDoesNotMutate(): void
    {
        $runtime = new OnboardCliRuntimeFixture(
            $this->readyPlan(),
            $this->readyResult()
        );
        $tester = $this->tester(new OnboardCliRuntimeFactoryFixture($runtime));
        $tester->setInputs(['no']);

        $status = $tester->execute(['--format' => 'text']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $runtime->previewCalls);
        self::assertSame(0, $runtime->onboardCalls);
        self::assertStringContainsString('Onboarding cancelado', $tester->getDisplay());
    }

    public function testPlanIsRevalidatedAndMustRemainIdentical(): void
    {
        $runtime = new OnboardCliRuntimeFixture(
            [
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
            ],
            $this->readyResult()
        );
        $tester = $this->tester(new OnboardCliRuntimeFactoryFixture($runtime));

        $status = $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(2, $runtime->previewCalls);
        self::assertSame(0, $runtime->onboardCalls);
        self::assertStringContainsString(
            'webadmin.onboard.migration_plan_changed',
            $tester->getDisplay()
        );
    }

    public function testReadyResultReturnsOnlySafeCountersAsJson(): void
    {
        $runtime = new OnboardCliRuntimeFixture(
            $this->readyPlan(),
            $this->readyResult()
        );
        $tester = $this->tester(new OnboardCliRuntimeFactoryFixture($runtime));

        $status = $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertTrue($payload['ok']);
        self::assertSame('webadmin-onboard', $payload['operation']);
        self::assertSame(2, $payload['result']['dispatch']['sent']);
        self::assertTrue($payload['result']['readiness']['ready']);
        self::assertSame(2, $runtime->previewCalls);
        self::assertSame(1, $runtime->onboardCalls);
        self::assertStringNotContainsString('@', $display);
        self::assertStringNotContainsString('token', strtolower($display));
    }

    public function testIncompleteDeliveryReturnsFailureWithSafeReport(): void
    {
        $result = new BootstrapOnboardingResult(
            BootstrapResult::completed(2, 0, 2),
            new WebAdminOutboxDispatchReport(2, 2, 0, 2, 0, 0),
            new BootstrapInvitationReadiness(0, 0, 2)
        );
        $tester = $this->tester(new OnboardCliRuntimeFactoryFixture(
            new OnboardCliRuntimeFixture($this->readyPlan(), $result)
        ));

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
        self::assertFalse($payload['ok']);
        self::assertSame(
            'webadmin.onboard.incomplete',
            $payload['error']['code']
        );
        self::assertSame(2, $payload['result']['dispatch']['retry_scheduled']);
        self::assertSame(2, $payload['result']['readiness']['incomplete_identities']);
    }

    public function testUnexpectedFailureCannotLeakPrivateDetails(): void
    {
        $runtime = new OnboardCliRuntimeFixture(
            $this->readyPlan(),
            new RuntimeException(
                'SMTP secret with owner@example.test and raw-token-value'
            )
        );
        $tester = $this->tester(new OnboardCliRuntimeFactoryFixture($runtime));

        $status = $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(
            'webadmin.onboard.internal_failure',
            $display
        );
        self::assertStringNotContainsString('owner@example.test', $display);
        self::assertStringNotContainsString('raw-token-value', $display);
        self::assertStringNotContainsString('SMTP secret', $display);
    }

    public function testRuntimeSkipsDeliveryWhenInitialAccessIsAlreadyReady(): void
    {
        $bootstrap = new OnboardBootstrapRuntimeFixture($this->readyPlan());
        $mail = new OnboardMailRuntimeFixture([
            new BootstrapInvitationReadiness(2, 0, 0),
        ]);
        $runtime = new WebAdminOnboardCommandRuntime($bootstrap, $mail);

        $result = $runtime->onboard();

        self::assertTrue($result->isReady());
        self::assertSame(0, $result->dispatch()->examined());
        self::assertSame(1, $mail->readinessCalls);
        self::assertSame(0, $mail->bootstrapDispatchCalls);
        self::assertSame(1, $bootstrap->bootstrapCalls);
    }

    public function testRuntimeDispatchesOnlyWhenNeededAndRechecksReadiness(): void
    {
        $bootstrap = new OnboardBootstrapRuntimeFixture($this->readyPlan());
        $mail = new OnboardMailRuntimeFixture([
            new BootstrapInvitationReadiness(0, 0, 2),
            new BootstrapInvitationReadiness(0, 2, 0),
        ]);
        $runtime = new WebAdminOnboardCommandRuntime($bootstrap, $mail);

        $result = $runtime->onboard();

        self::assertTrue($result->isReady());
        self::assertSame(2, $result->dispatch()->sent());
        self::assertSame(2, $mail->readinessCalls);
        self::assertSame(1, $mail->bootstrapDispatchCalls);
    }

    public function testFactoryValidatesMailBeforeCreatingBootstrapRuntime(): void
    {
        $calls = [];
        $factory = new WebAdminOnboardCommandRuntimeFactory(
            new OnboardMailRuntimeFactoryFixture(
                $calls,
                new WebAdminMailDispatchCommandRuntimeException(
                    'webadmin.mail.configuration_invalid'
                )
            ),
            new OnboardBootstrapRuntimeFactoryFixture(
                $calls,
                new OnboardBootstrapRuntimeFixture($this->readyPlan())
            )
        );

        try {
            $factory->create('/project', '/core');
            self::fail('Invalid mail configuration must block onboarding.');
        } catch (WebAdminOnboardCommandRuntimeException $exception) {
            self::assertSame(
                'webadmin.mail.configuration_invalid',
                $exception->issueCode()
            );
        }

        self::assertSame(['mail'], $calls);
    }

    private function tester(
        WebAdminOnboardCommandRuntimeFactoryInterface $factory
    ): CommandTester {
        $command = new WebAdminOnboardCommand('/project', '/core', $factory);
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

    private function readyResult(): BootstrapOnboardingResult
    {
        return new BootstrapOnboardingResult(
            BootstrapResult::completed(2, 0, 2),
            new WebAdminOutboxDispatchReport(2, 2, 2, 0, 0, 0),
            new BootstrapInvitationReadiness(0, 2, 0)
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

final class OnboardCliRuntimeFactoryFixture implements
    WebAdminOnboardCommandRuntimeFactoryInterface
{
    public int $createCalls = 0;

    public function __construct(
        private readonly WebAdminOnboardCommandRuntimeInterface|Throwable $result
    ) {
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): WebAdminOnboardCommandRuntimeInterface {
        ++$this->createCalls;
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

final class OnboardCliRuntimeFixture implements
    WebAdminOnboardCommandRuntimeInterface
{
    public int $previewCalls = 0;
    public int $onboardCalls = 0;

    /** @var list<MigrationDatabasePlan> */
    private array $plans;

    public function __construct(
        MigrationDatabasePlan|array $plans,
        private readonly BootstrapOnboardingResult|Throwable $result
    ) {
        $this->plans = $plans instanceof MigrationDatabasePlan
            ? [$plans]
            : array_values($plans);
    }

    public function preview(): MigrationDatabasePlan
    {
        $index = min($this->previewCalls, count($this->plans) - 1);
        ++$this->previewCalls;

        return $this->plans[$index];
    }

    public function onboard(): BootstrapOnboardingResult
    {
        ++$this->onboardCalls;
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

final class OnboardBootstrapRuntimeFixture implements
    WebAdminBootstrapCommandRuntimeInterface
{
    public int $bootstrapCalls = 0;

    public function __construct(
        private readonly MigrationDatabasePlan $plan
    ) {
    }

    public function preview(): MigrationDatabasePlan
    {
        return $this->plan;
    }

    public function bootstrap(): BootstrapResult
    {
        ++$this->bootstrapCalls;

        return BootstrapResult::alreadyCompleted();
    }

    public function resendInvitations(): BootstrapInvitationResendResult
    {
        return new BootstrapInvitationResendResult(0, 2);
    }
}

final class OnboardMailRuntimeFixture implements
    WebAdminOnboardMailRuntimeInterface
{
    public int $readinessCalls = 0;
    public int $bootstrapDispatchCalls = 0;

    /** @param list<BootstrapInvitationReadiness> $readiness */
    public function __construct(private readonly array $readiness)
    {
    }

    public function dispatch(int $limit): WebAdminOutboxDispatchReport
    {
        return new WebAdminOutboxDispatchReport(0, 0, 0, 0, 0, 0);
    }

    public function dispatchBootstrapInvitations(): WebAdminOutboxDispatchReport
    {
        ++$this->bootstrapDispatchCalls;

        return new WebAdminOutboxDispatchReport(2, 2, 2, 0, 0, 0);
    }

    public function bootstrapInvitationReadiness(): BootstrapInvitationReadiness
    {
        $index = min($this->readinessCalls, count($this->readiness) - 1);
        ++$this->readinessCalls;

        return $this->readiness[$index];
    }
}

final class OnboardMailRuntimeFactoryFixture implements
    WebAdminOnboardMailRuntimeFactoryInterface
{
    /** @param list<string> $calls */
    public function __construct(
        private array &$calls,
        private readonly WebAdminOnboardMailRuntimeInterface|Throwable $result
    ) {
    }

    public function createOnboard(
        string $projectRoot,
        string $coreRoot
    ): WebAdminOnboardMailRuntimeInterface {
        $this->calls[] = 'mail';
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

final class OnboardBootstrapRuntimeFactoryFixture implements
    WebAdminBootstrapCommandRuntimeFactoryInterface
{
    /** @param list<string> $calls */
    public function __construct(
        private array &$calls,
        private readonly WebAdminBootstrapCommandRuntimeInterface $runtime
    ) {
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): WebAdminBootstrapCommandRuntimeInterface {
        $this->calls[] = 'bootstrap';

        return $this->runtime;
    }
}
