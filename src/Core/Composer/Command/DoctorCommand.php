<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Composer\ProjectRootLocator;
use App\Core\Modules\Diagnostics\DoctorReport;
use App\Core\Modules\Diagnostics\ModuleDoctor;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DoctorCommand extends BaseCommand
{
    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?string $coreRoot = null,
        private readonly ?ModuleDoctor $doctor = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('liquidstack:doctor')
            ->setDescription(
                'Diagnostica módulos, configuración y migraciones sin modificar el proyecto.'
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
            $output->writeln(
                '<error>--format solo admite text o json.</error>'
            );

            return self::INVALID;
        }

        try {
            $report = ($this->doctor ?? new ModuleDoctor())->inspect(
                $this->projectRoot ?? ProjectRootLocator::fromComposerContext(),
                $this->coreRoot ?? dirname(__DIR__, 4)
            );

            if ($format === 'json') {
                $output->writeln((string) json_encode(
                    $report->toArray(),
                    JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                ));
            } else {
                $this->renderText($report, $output);
            }
        } catch (\Throwable) {
            $this->renderInternalFailure($format, $output);

            return self::FAILURE;
        }

        return $report->isHealthy() ? self::SUCCESS : self::FAILURE;
    }

    private function renderText(
        DoctorReport $report,
        OutputInterface $output
    ): void {
        $output->writeln('<info>LiquidStack doctor (solo lectura)</info>');

        foreach ($report->checks() as $check) {
            $data = $check->toArray();
            $label = match ($data['status']) {
                'ok' => '<info>[OK]</info>',
                'warning' => '<comment>[AVISO]</comment>',
                default => '<error>[ERROR]</error>',
            };
            $output->writeln(sprintf(
                '%s %s: %s',
                $label,
                $data['id'],
                OutputFormatter::escape($data['message'])
            ));
        }

        $requested = $report->requestedModules();
        $enabled = $report->enabledModules();
        $output->writeln('Solicitados: '
            . ($requested === [] ? 'ninguno' : implode(', ', $requested)));
        $output->writeln('Activos: '
            . ($enabled === [] ? 'core' : 'core, ' . implode(', ', $enabled)));
        $output->writeln(sprintf(
            'Migraciones declaradas: %d. WebAdmin se comprueba contra la DB en solo lectura cuando está activo.',
            count($report->migrationPlan()->entries())
        ));

        if ($report->isHealthy()) {
            $output->writeln(sprintf(
                '<info>Diagnóstico correcto%s.</info>',
                $report->warningCount() > 0
                    ? sprintf(' con %d aviso(s)', $report->warningCount())
                    : ''
            ));
        } else {
            $output->writeln(
                '<error>Diagnóstico con bloqueadores; no se ha modificado nada.</error>'
            );
        }
    }

    private function renderInternalFailure(
        string $format,
        OutputInterface $output
    ): void {
        $message = 'No se pudo completar el diagnóstico sin exponer detalles internos.';
        if ($format === 'json') {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'ok' => false,
                'error' => [
                    'code' => 'doctor.internal_failure',
                    'message' => $message,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $output->writeln(
            '<error>' . OutputFormatter::escape($message) . '</error>'
        );
    }
}
