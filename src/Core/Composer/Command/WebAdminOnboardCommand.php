<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Composer\ProjectRootLocator;
use App\Core\Composer\WebAdminOnboardCommandRuntimeException;
use App\Core\Composer\WebAdminOnboardCommandRuntimeFactory;
use App\Core\Composer\WebAdminOnboardCommandRuntimeFactoryInterface;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\Modules\Migrations\MigrationException;
use App\Core\WebAdmin\Bootstrap\BootstrapException;
use App\Core\WebAdmin\Bootstrap\BootstrapOnboardingResult;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

/**
 * Explicit one-step initial-access workflow for newly enabled WebAdmin stacks.
 *
 * Construction is intentionally side-effect free. Environment, database and
 * mail services are resolved only after Symfony invokes execute().
 */
final class WebAdminOnboardCommand extends BaseCommand
{
    private const OPERATION = 'webadmin-onboard';

    /** @var list<string> */
    private const SAFE_ISSUE_CODES = [
        'webadmin.onboard.format_invalid',
        'webadmin.onboard.json_requires_yes',
        'webadmin.onboard.confirmation_required',
        'webadmin.onboard.migration_plan_blocked',
        'webadmin.onboard.migration_catalog_missing',
        'webadmin.onboard.migrations_not_applied',
        'webadmin.onboard.migration_plan_changed',
        'webadmin.onboard.migration_inspection_failed',
        'webadmin.onboard.incomplete',
        'webadmin.onboard.runtime_unavailable',
        'webadmin.onboard.internal_failure',
        'webadmin.bootstrap.environment_unusable',
        'webadmin.bootstrap.module_not_enabled',
        'webadmin.bootstrap.connection_factory_invalid',
        'webadmin.bootstrap.runtime_unavailable',
        'webadmin.mail.module_not_enabled',
        'webadmin.mail.environment_unusable',
        'webadmin.mail.exception_trace_unsafe',
        'webadmin.mail.configuration_invalid',
        'webadmin.mail.connection_factory_invalid',
        'webadmin.mail.schema_not_ready',
        'webadmin.mail.routing_unavailable',
        'webadmin.mail.runtime_unavailable',
        'bootstrap.audit_failed',
        'bootstrap.capability_incompatible',
        'bootstrap.clock_failed',
        'bootstrap.completed_state_incompatible',
        'bootstrap.credential_collision',
        'bootstrap.environment_invalid',
        'bootstrap.environment_missing',
        'bootstrap.identities_not_distinct',
        'bootstrap.identity_collision',
        'bootstrap.outbox_collision',
        'bootstrap.pdo_configuration_invalid',
        'bootstrap.persistence_failed',
        'bootstrap.persistence_unavailable',
        'bootstrap.role_already_owned',
        'bootstrap.role_incompatible',
        'bootstrap.rollback_failed',
        'bootstrap.schema_not_ready',
        'bootstrap.state_changed',
        'bootstrap.state_invalid',
        'bootstrap.transaction_already_active',
        'bootstrap.uuid_failed',
    ];

    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?string $coreRoot = null,
        private readonly ?WebAdminOnboardCommandRuntimeFactoryInterface $runtimeFactory = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('liquidstack:webadmin:onboard')
            ->setDescription(
                'Prepara y entrega el acceso inicial protegido de WebAdmin.'
            )
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_NONE,
                'Confirma el onboarding sin pregunta interactiva.'
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Formato de salida: text o json.',
                'text'
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['text', 'json'], true)) {
            return $this->renderFailure(
                'webadmin.onboard.format_invalid',
                $format,
                $output,
                self::INVALID
            );
        }

        if ($format === 'json' && !$input->getOption('yes')) {
            return $this->renderFailure(
                'webadmin.onboard.json_requires_yes',
                $format,
                $output,
                self::INVALID
            );
        }

        try {
            $runtime = ($this->runtimeFactory
                ?? new WebAdminOnboardCommandRuntimeFactory())->create(
                    $this->projectRoot
                        ?? ProjectRootLocator::fromComposerContext(),
                    $this->coreRoot ?? dirname(__DIR__, 4)
                );
            $plan = $runtime->preview();
            $schemaError = $this->schemaReadinessError($plan);
            if ($schemaError !== null) {
                return $this->renderFailure(
                    $schemaError,
                    $format,
                    $output,
                    self::FAILURE
                );
            }

            if (!$input->getOption('yes')) {
                if (!$input->isInteractive()) {
                    return $this->renderFailure(
                        'webadmin.onboard.confirmation_required',
                        $format,
                        $output,
                        self::FAILURE
                    );
                }
                if (!$this->confirmOnboarding($input, $output)) {
                    $output->writeln(
                        '<comment>Onboarding cancelado; no se modificó la base de datos ni se envió correo.</comment>'
                    );

                    return self::SUCCESS;
                }
            }

            $confirmedPlan = $runtime->preview();
            $confirmedSchemaError = $this->schemaReadinessError(
                $confirmedPlan
            );
            if ($confirmedSchemaError !== null) {
                return $this->renderFailure(
                    $confirmedSchemaError,
                    $format,
                    $output,
                    self::FAILURE
                );
            }
            if (!hash_equals($plan->hash(), $confirmedPlan->hash())) {
                return $this->renderFailure(
                    'webadmin.onboard.migration_plan_changed',
                    $format,
                    $output,
                    self::FAILURE
                );
            }

            $result = $runtime->onboard();
            if (!$result->isReady()) {
                $this->renderResult(
                    $result,
                    $format,
                    $output,
                    false,
                    'webadmin.onboard.incomplete'
                );

                return self::FAILURE;
            }

            $this->renderResult($result, $format, $output, true);

            return self::SUCCESS;
        } catch (WebAdminOnboardCommandRuntimeException $exception) {
            return $this->renderFailure(
                $exception->issueCode(),
                $format,
                $output,
                self::FAILURE
            );
        } catch (BootstrapException $exception) {
            return $this->renderFailure(
                $exception->issueCode(),
                $format,
                $output,
                self::FAILURE
            );
        } catch (MigrationException) {
            return $this->renderFailure(
                'webadmin.onboard.migration_inspection_failed',
                $format,
                $output,
                self::FAILURE
            );
        } catch (Throwable) {
            return $this->renderFailure(
                'webadmin.onboard.internal_failure',
                $format,
                $output,
                self::FAILURE
            );
        }
    }

    private function schemaReadinessError(
        MigrationDatabasePlan $plan
    ): ?string {
        if (!$plan->isApplicable()) {
            return 'webadmin.onboard.migration_plan_blocked';
        }

        $webAdminEntries = array_values(array_filter(
            $plan->entries(),
            static fn (array $entry): bool =>
                ($entry['module'] ?? null) === 'webadmin'
        ));
        if ($webAdminEntries === []) {
            return 'webadmin.onboard.migration_catalog_missing';
        }

        foreach ($webAdminEntries as $entry) {
            if (($entry['status'] ?? null) !== 'applied') {
                return 'webadmin.onboard.migrations_not_applied';
            }
        }

        return null;
    }

    private function confirmOnboarding(
        InputInterface $input,
        OutputInterface $output
    ): bool {
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return false;
        }

        return (bool) $helper->ask(
            $input,
            $output,
            new ConfirmationQuestion(
                'Crear o reconciliar los accesos iniciales y entregar sus invitaciones? [y/N] ',
                false
            )
        );
    }

    private function renderResult(
        BootstrapOnboardingResult $result,
        string $format,
        OutputInterface $output,
        bool $ok,
        ?string $errorCode = null
    ): void {
        if ($format === 'json') {
            $payload = [
                'schema' => 1,
                'ok' => $ok,
                'operation' => self::OPERATION,
            ];
            if ($errorCode !== null) {
                $payload['error'] = [
                    'code' => $this->safeIssueCode($errorCode),
                ];
            }
            $payload['result'] = $result->toSafeArray();
            $output->writeln($this->encodeJson($payload));

            return;
        }

        $readiness = $result->readiness();
        $dispatch = $result->dispatch();
        if ($ok) {
            $output->writeln(sprintf(
                '<info>Acceso inicial listo: %d identidades activas, %d invitaciones entregadas y %d envíos en esta ejecución.</info>',
                $readiness->activeIdentities(),
                $readiness->deliveredInvitations(),
                $dispatch->sent()
            ));

            return;
        }

        $output->writeln(sprintf(
            '<error>Acceso inicial incompleto (%s): %d de %d identidades pendientes; %d reintentos, %d fallos permanentes y %d resultados cercados. No se muestran destinatarios, tokens ni detalles internos.</error>',
            OutputFormatter::escape($this->safeIssueCode(
                $errorCode ?? 'webadmin.onboard.incomplete'
            )),
            $readiness->incompleteIdentities(),
            $readiness::EXPECTED_IDENTITIES,
            $dispatch->retryScheduled(),
            $dispatch->permanentlyFailed(),
            $dispatch->fenced()
        ));
    }

    private function renderFailure(
        string $code,
        string $format,
        OutputInterface $output,
        int $status
    ): int {
        $code = $this->safeIssueCode($code);
        if ($format === 'json') {
            $output->writeln($this->encodeJson([
                'schema' => 1,
                'ok' => false,
                'operation' => self::OPERATION,
                'error' => ['code' => $code],
            ]));

            return $status;
        }

        $output->writeln(sprintf(
            '<error>Onboarding WebAdmin bloqueado de forma segura (%s). No se muestran destinatarios, tokens ni detalles internos.</error>',
            OutputFormatter::escape($code)
        ));

        return $status;
    }

    private function safeIssueCode(string $code): string
    {
        return in_array($code, self::SAFE_ISSUE_CODES, true)
            ? $code
            : 'webadmin.onboard.internal_failure';
    }

    /** @param array<string, mixed> $payload */
    private function encodeJson(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        );
    }
}
