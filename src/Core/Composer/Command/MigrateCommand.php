<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Composer\MigrationCommandRuntimeFactory;
use App\Core\Composer\MigrationCommandRuntimeFactoryInterface;
use App\Core\Composer\MigrationCommandRuntimeException;
use App\Core\Composer\ProjectRootLocator;
use App\Core\Modules\Diagnostics\DoctorReport;
use App\Core\Modules\Diagnostics\ModuleDoctor;
use App\Core\Modules\Migrations\MigrationApplyOptions;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\Modules\Migrations\MigrationException;
use App\Core\Modules\Migrations\MigrationRunResult;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

final class MigrateCommand extends BaseCommand
{
    private const MODE_PLAN = 'plan';
    private const MODE_DRY_RUN = 'dry-run';
    private const MODE_APPLY = 'apply';

    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?string $coreRoot = null,
        private readonly ?ModuleDoctor $doctor = null,
        private readonly ?MigrationCommandRuntimeFactoryInterface $runtimeFactory = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('liquidstack:migrate')
            ->setDescription(
                'Planifica o aplica de forma controlada las migraciones modulares.'
            )
            ->addOption(
                self::MODE_PLAN,
                null,
                InputOption::VALUE_NONE,
                'Lista el catálogo sin abrir ninguna conexión de base de datos.'
            )
            ->addOption(
                self::MODE_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Conecta y compara el catálogo con la DB sin modificarla.'
            )
            ->addOption(
                self::MODE_APPLY,
                null,
                InputOption::VALUE_NONE,
                'Aplica el plan mostrado tras confirmación explícita.'
            )
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_NONE,
                'Confirma la aplicación sin pregunta interactiva.'
            )
            ->addOption(
                'allow-destructive',
                null,
                InputOption::VALUE_NONE,
                'Autoriza migraciones marcadas como destructivas.'
            )
            ->addOption(
                'backup-confirmed',
                null,
                InputOption::VALUE_NONE,
                'Declara que existe un backup verificado antes de una migración destructiva.'
            )
            ->addOption(
                'lock-timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Segundos de espera del lock de aplicación (0-300).'
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
                'migrate.format_invalid',
                $format,
                $output,
                self::INVALID
            );
        }

        $mode = $this->selectedMode($input);
        if ($mode === null) {
            return $this->renderFailure(
                'migrate.mode_required',
                $format,
                $output,
                self::INVALID
            );
        }
        if (!$this->optionsMatchMode($input, $mode)) {
            return $this->renderFailure(
                'migrate.option_not_applicable',
                $format,
                $output,
                self::INVALID
            );
        }

        if ($mode === self::MODE_PLAN) {
            return $this->executeCatalogPlan($format, $output);
        }

        $lockTimeout = $this->lockTimeout($input);
        if ($lockTimeout === null) {
            return $this->renderFailure(
                'migrate.lock_timeout_invalid',
                $format,
                $output,
                self::INVALID
            );
        }
        if (
            $mode === self::MODE_APPLY
            && $input->getOption('backup-confirmed')
            && !$input->getOption('allow-destructive')
        ) {
            return $this->renderFailure(
                'migrate.backup_confirmation_without_destructive_gate',
                $format,
                $output,
                self::INVALID
            );
        }
        if (
            $mode === self::MODE_APPLY
            && $format === 'json'
            && !$input->getOption('yes')
        ) {
            return $this->renderFailure(
                'migrate.json_apply_requires_yes',
                $format,
                $output,
                self::INVALID
            );
        }

        try {
            $runtime = ($this->runtimeFactory
                ?? new MigrationCommandRuntimeFactory())->create(
                    $this->projectRoot
                        ?? ProjectRootLocator::fromComposerContext(),
                    $this->coreRoot ?? dirname(__DIR__, 4)
                );
            $plan = $runtime->preview();

            if ($mode === self::MODE_DRY_RUN) {
                $this->renderDatabasePlan(
                    $plan,
                    $runtime->activeModuleIds(),
                    self::MODE_DRY_RUN,
                    $format,
                    $output
                );

                return $plan->isApplicable()
                    ? self::SUCCESS
                    : self::FAILURE;
            }

            $this->renderDatabasePlan(
                $plan,
                $runtime->activeModuleIds(),
                self::MODE_APPLY,
                $format,
                $output
            );
            if (!$plan->isApplicable()) {
                return self::FAILURE;
            }
            if (
                $plan->hasPendingDestructive()
                && !$input->getOption('allow-destructive')
            ) {
                return $this->renderFailure(
                    'migrations.destructive_not_allowed',
                    $format,
                    $output,
                    self::FAILURE
                );
            }
            if (
                $plan->hasPendingDestructive()
                && !$input->getOption('backup-confirmed')
            ) {
                return $this->renderFailure(
                    'migrations.backup_not_confirmed',
                    $format,
                    $output,
                    self::FAILURE
                );
            }

            if (
                $plan->pendingEntries() !== []
                && !$input->getOption('yes')
            ) {
                if (!$input->isInteractive()) {
                    return $this->renderFailure(
                        'migrate.confirmation_required',
                        $format,
                        $output,
                        self::FAILURE
                    );
                }
                if (!$this->confirmApply($input, $output)) {
                    $output->writeln(
                        '<comment>Aplicación cancelada; no se modificó la base de datos.</comment>'
                    );

                    return self::SUCCESS;
                }
            }

            $result = $runtime->apply(new MigrationApplyOptions(
                expectedPlanHash: $plan->hash(),
                allowDestructive: (bool) $input->getOption(
                    'allow-destructive'
                ),
                backupConfirmed: (bool) $input->getOption(
                    'backup-confirmed'
                ),
                lockTimeoutSeconds: $lockTimeout
            ));
            $this->renderApplyResult($result, $format, $output);

            return self::SUCCESS;
        } catch (MigrationException $exception) {
            return $this->renderFailure(
                $exception->issueCode(),
                $format,
                $output,
                self::FAILURE,
                $exception->moduleId(),
                $exception->migrationId()
            );
        } catch (MigrationCommandRuntimeException $exception) {
            return $this->renderFailure(
                $exception->issueCode(),
                $format,
                $output,
                self::FAILURE
            );
        } catch (Throwable) {
            return $this->renderFailure(
                'migrate.internal_failure',
                $format,
                $output,
                self::FAILURE
            );
        }
    }

    private function executeCatalogPlan(
        string $format,
        OutputInterface $output
    ): int {
        try {
            $report = ($this->doctor ?? new ModuleDoctor())->inspect(
                $this->projectRoot
                    ?? ProjectRootLocator::fromComposerContext(),
                $this->coreRoot ?? dirname(__DIR__, 4),
                false
            );

            if ($format === 'json') {
                $output->writeln($this->encodeJson(
                    $this->catalogPlanPayload($report)
                ));
            } else {
                $this->renderCatalogPlanText($report, $output);
            }
        } catch (Throwable) {
            return $this->renderFailure(
                'migrate.internal_failure',
                $format,
                $output,
                self::FAILURE
            );
        }

        return $report->isHealthy() ? self::SUCCESS : self::FAILURE;
    }

    private function selectedMode(InputInterface $input): ?string
    {
        $selected = array_values(array_filter(
            [self::MODE_PLAN, self::MODE_DRY_RUN, self::MODE_APPLY],
            static fn (string $mode): bool => (bool) $input->getOption($mode)
        ));

        return count($selected) === 1 ? $selected[0] : null;
    }

    private function optionsMatchMode(
        InputInterface $input,
        string $mode
    ): bool {
        if ($mode === self::MODE_APPLY) {
            return true;
        }

        return !$input->getOption('yes')
            && !$input->getOption('allow-destructive')
            && !$input->getOption('backup-confirmed')
            && $input->getOption('lock-timeout') === null;
    }

    private function lockTimeout(InputInterface $input): ?int
    {
        $raw = $input->getOption('lock-timeout');
        if ($raw === null) {
            return 10;
        }
        if (
            !is_string($raw)
            || preg_match('/\A[0-9]{1,3}\z/', $raw) !== 1
            || (int) $raw > 300
        ) {
            return null;
        }

        return (int) $raw;
    }

    private function confirmApply(
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
                'Aplicar exactamente este plan? [y/N] ',
                false
            )
        );
    }

    /** @return array<string, mixed> */
    private function catalogPlanPayload(DoctorReport $report): array
    {
        $doctor = $report->toArray();

        return [
            'schema' => 1,
            'ok' => $report->isHealthy(),
            'operation' => 'migrate-plan',
            'modules' => $doctor['modules'],
            'migrations' => $report->migrationPlan()->toArray(),
            'checks' => $doctor['checks'],
        ];
    }

    private function renderCatalogPlanText(
        DoctorReport $report,
        OutputInterface $output
    ): void {
        $output->writeln(
            '<info>LiquidStack migration plan (solo lectura, sin conexión DB)</info>'
        );
        $output->writeln(
            'No se consulta ni modifica la base de datos; el estado aplicado no se evalúa.'
        );

        $entries = $report->migrationPlan()->entries();
        if ($entries === []) {
            $output->writeln('No hay definiciones de migración activas.');
        } else {
            foreach ($entries as $entry) {
                $output->writeln(sprintf(
                    '- %s:%s%s — %s [%s]',
                    OutputFormatter::escape($entry['module']),
                    OutputFormatter::escape($entry['id']),
                    $entry['destructive'] ? ' (destructiva)' : '',
                    OutputFormatter::escape($entry['description']),
                    OutputFormatter::escape($entry['checksum'])
                ));
            }
        }

        if (!$report->isHealthy()) {
            $output->writeln(
                '<error>El preflight tiene bloqueadores; no se ha modificado nada.</error>'
            );
        }
    }

    /** @param list<string> $modules */
    private function renderDatabasePlan(
        MigrationDatabasePlan $plan,
        array $modules,
        string $mode,
        string $format,
        OutputInterface $output
    ): void {
        if ($format === 'json') {
            if ($mode === self::MODE_DRY_RUN || !$plan->isApplicable()) {
                $output->writeln($this->encodeJson([
                    'schema' => 1,
                    'ok' => $plan->isApplicable(),
                    'operation' => 'migrate-' . $mode,
                    'modules' => $modules,
                    'migrations' => $plan->toArray(),
                ]));
            }

            return;
        }

        $output->writeln(sprintf(
            '<info>LiquidStack migration %s</info>',
            $mode === self::MODE_DRY_RUN
                ? 'dry-run (DB en solo lectura)'
                : 'apply preview'
        ));
        $output->writeln('Plan hash: ' . OutputFormatter::escape($plan->hash()));
        foreach ($plan->entries() as $entry) {
            $output->writeln(sprintf(
                '- %s:%s%s [%s]',
                OutputFormatter::escape((string) $entry['module']),
                OutputFormatter::escape((string) $entry['id']),
                $entry['destructive'] ? ' (destructiva)' : '',
                OutputFormatter::escape((string) $entry['status'])
            ));
        }
        if ($plan->entries() === []) {
            $output->writeln('No hay migraciones activas.');
        }
        foreach ($plan->blockers() as $blocker) {
            $output->writeln(sprintf(
                '<error>Bloqueador: %s (%s:%s)</error>',
                OutputFormatter::escape($blocker['code']),
                OutputFormatter::escape($blocker['module'] ?? '-'),
                OutputFormatter::escape($blocker['migration'] ?? '-')
            ));
        }
    }

    private function renderApplyResult(
        MigrationRunResult $result,
        string $format,
        OutputInterface $output
    ): void {
        if ($format === 'json') {
            $output->writeln($this->encodeJson(array_merge(
                ['schema' => 1],
                $result->toArray()
            )));

            return;
        }

        if (!$result->changed()) {
            $output->writeln('<info>La base de datos ya estaba al día.</info>');

            return;
        }

        $output->writeln(sprintf(
            '<info>Aplicadas %d migraciones en el lote %d.</info>',
            count($result->applied()),
            $result->batch()
        ));
    }

    private function renderFailure(
        string $code,
        string $format,
        OutputInterface $output,
        int $status,
        ?string $module = null,
        ?string $migration = null
    ): int {
        if ($format === 'json') {
            $error = ['code' => $code];
            if ($module !== null) {
                $error['module'] = $module;
            }
            if ($migration !== null) {
                $error['migration'] = $migration;
            }
            $output->writeln($this->encodeJson([
                'schema' => 1,
                'ok' => false,
                'operation' => 'migrate',
                'error' => $error,
            ]));

            return $status;
        }

        $output->writeln(sprintf(
            '<error>Operación bloqueada de forma segura (%s). No se muestran secretos ni SQL.</error>',
            OutputFormatter::escape($code)
        ));

        return $status;
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
