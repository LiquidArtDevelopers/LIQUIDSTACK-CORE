<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Composer\ProjectRootLocator;
use App\Core\Composer\WebAdminBootstrapCommandRuntimeFactory;
use App\Core\Composer\WebAdminBootstrapCommandRuntimeFactoryInterface;
use App\Core\Composer\WebAdminBootstrapCommandRuntimeException;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\Modules\Migrations\MigrationException;
use App\Core\WebAdmin\Bootstrap\BootstrapInvitationResendResult;
use App\Core\WebAdmin\Bootstrap\BootstrapException;
use App\Core\WebAdmin\Bootstrap\BootstrapResult;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

final class WebAdminBootstrapCommand extends BaseCommand
{
    private string $operation = 'webadmin-bootstrap';

    /** @var list<string> */
    private const SAFE_ISSUE_CODES = [
        'webadmin.bootstrap.format_invalid',
        'webadmin.bootstrap.json_requires_yes',
        'webadmin.bootstrap.migration_plan_blocked',
        'webadmin.bootstrap.migration_catalog_missing',
        'webadmin.bootstrap.migrations_not_applied',
        'webadmin.bootstrap.confirmation_required',
        'webadmin.bootstrap.migration_plan_changed',
        'webadmin.bootstrap.migration_inspection_failed',
        'webadmin.bootstrap.environment_unusable',
        'webadmin.bootstrap.module_not_enabled',
        'webadmin.bootstrap.connection_factory_invalid',
        'webadmin.bootstrap.runtime_unavailable',
        'webadmin.bootstrap.internal_failure',
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
        'bootstrap.resend_requires_completed',
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
        private readonly ?WebAdminBootstrapCommandRuntimeFactoryInterface $runtimeFactory = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('liquidstack:webadmin:bootstrap')
            ->setDescription(
                'Crea de forma explícita e idempotente las identidades iniciales de WebAdmin.'
            )
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_NONE,
                'Confirma el bootstrap sin pregunta interactiva.'
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Formato de salida: text o json.',
                'text'
            )
            ->addOption(
                'resend-invites',
                null,
                InputOption::VALUE_NONE,
                'Revoca enlaces anteriores y reencola invitaciones bootstrap no activadas.'
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $resendInvites = (bool) $input->getOption('resend-invites');
        $this->operation = $resendInvites
            ? 'webadmin-bootstrap-invite-resend'
            : 'webadmin-bootstrap';
        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['text', 'json'], true)) {
            return $this->renderFailure(
                'webadmin.bootstrap.format_invalid',
                $format,
                $output,
                self::INVALID
            );
        }

        if ($format === 'json' && !$input->getOption('yes')) {
            return $this->renderFailure(
                'webadmin.bootstrap.json_requires_yes',
                $format,
                $output,
                self::INVALID
            );
        }

        try {
            $runtime = ($this->runtimeFactory
                ?? new WebAdminBootstrapCommandRuntimeFactory())->create(
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
                        'webadmin.bootstrap.confirmation_required',
                        $format,
                        $output,
                        self::FAILURE
                    );
                }
                if (!$this->confirmBootstrap(
                    $input,
                    $output,
                    $resendInvites
                )) {
                    return $this->renderCancelled($output);
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
                    'webadmin.bootstrap.migration_plan_changed',
                    $format,
                    $output,
                    self::FAILURE
                );
            }

            if ($resendInvites) {
                $result = $runtime->resendInvitations();
                $this->renderResendSuccess($result, $format, $output);
            } else {
                $result = $runtime->bootstrap();
                $this->renderSuccess($result, $format, $output);
            }

            return self::SUCCESS;
        } catch (WebAdminBootstrapCommandRuntimeException $exception) {
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
        } catch (MigrationException $exception) {
            return $this->renderFailure(
                'webadmin.bootstrap.migration_inspection_failed',
                $format,
                $output,
                self::FAILURE
            );
        } catch (Throwable) {
            return $this->renderFailure(
                'webadmin.bootstrap.internal_failure',
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
            return 'webadmin.bootstrap.migration_plan_blocked';
        }

        $webAdminEntries = array_values(array_filter(
            $plan->entries(),
            static fn (array $entry): bool =>
                ($entry['module'] ?? null) === 'webadmin'
        ));
        if ($webAdminEntries === []) {
            return 'webadmin.bootstrap.migration_catalog_missing';
        }

        foreach ($webAdminEntries as $entry) {
            if (($entry['status'] ?? null) !== 'applied') {
                return 'webadmin.bootstrap.migrations_not_applied';
            }
        }

        return null;
    }

    private function confirmBootstrap(
        InputInterface $input,
        OutputInterface $output,
        bool $resendInvites
    ): bool {
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return false;
        }

        return (bool) $helper->ask(
            $input,
            $output,
            new ConfirmationQuestion(
                $resendInvites
                    ? 'Revocar enlaces anteriores y reencolar las invitaciones bootstrap sin activar? [y/N] '
                    : 'Crear o reconciliar las identidades iniciales de WebAdmin? [y/N] ',
                false
            )
        );
    }

    private function renderCancelled(OutputInterface $output): int
    {
        $output->writeln(
            '<comment>Bootstrap cancelado; no se modificó la base de datos.</comment>'
        );

        return self::SUCCESS;
    }

    private function renderSuccess(
        BootstrapResult $result,
        string $format,
        OutputInterface $output
    ): void {
        if ($format === 'json') {
            $output->writeln($this->encodeJson([
                'schema' => 1,
                'ok' => true,
                'operation' => 'webadmin-bootstrap',
                'result' => $result->toSafeArray(),
            ]));

            return;
        }

        if (!$result->changed()) {
            $output->writeln(
                '<info>El bootstrap de WebAdmin ya estaba completado.</info>'
            );

            return;
        }

        $output->writeln(sprintf(
            '<info>Bootstrap completado: %d creadas, %d reconciliadas y %d invitaciones encoladas.</info>',
            $result->createdAccounts(),
            $result->reconciledAccounts(),
            $result->queuedInvites()
        ));
    }

    private function renderResendSuccess(
        BootstrapInvitationResendResult $result,
        string $format,
        OutputInterface $output
    ): void {
        if ($format === 'json') {
            $output->writeln($this->encodeJson([
                'schema' => 1,
                'ok' => true,
                'operation' => 'webadmin-bootstrap-invite-resend',
                'result' => $result->toSafeArray(),
            ]));

            return;
        }

        if (!$result->changed()) {
            $output->writeln(
                '<info>No hay invitaciones bootstrap que reencolar.</info>'
            );

            return;
        }

        $output->writeln(sprintf(
            '<info>Reenvío preparado: %d %s y %d %s.</info>',
            $result->queuedInvites(),
            $result->queuedInvites() === 1
                ? 'invitación encolada'
                : 'invitaciones encoladas',
            $result->skippedIdentities(),
            $result->skippedIdentities() === 1
                ? 'identidad omitida'
                : 'identidades omitidas'
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
                'operation' => $this->operation,
                'error' => ['code' => $code],
            ]));

            return $status;
        }

        $output->writeln(sprintf(
            '<error>Operación bloqueada de forma segura (%s). No se muestran datos privados ni detalles internos.</error>',
            OutputFormatter::escape($code)
        ));

        return $status;
    }

    private function safeIssueCode(string $code): string
    {
        if (in_array($code, self::SAFE_ISSUE_CODES, true)) {
            return $code;
        }

        return 'webadmin.bootstrap.internal_failure';
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
