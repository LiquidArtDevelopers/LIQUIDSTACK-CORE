<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Composer\ManagedFilePlanChangedException;
use App\Core\Composer\ManagedFilePlanBlockedException;
use App\Core\Composer\ManagedFileSynchronizer;
use App\Core\Composer\ManagedProjectSyncRuntime;
use Composer\Command\BaseCommand;
use Composer\Composer;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class ManagedSyncCommand extends BaseCommand
{
    private const EXIT_SUCCESS = 0;
    private const EXIT_FAILURE = 1;
    private const EXIT_INVALID = 2;

    public function __construct(
        private readonly ?ManagedProjectSyncRuntime $runtime = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('liquidstack:sync')
            ->setDescription(
                'Planifica o aplica la sincronizacion de ficheros gestionados.'
            )
            ->addOption(
                'plan',
                null,
                InputOption::VALUE_NONE,
                'Enumera la cola y sus politicas sin evaluar acciones.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Evalua acciones y genera un plan hash sin escribir.'
            )
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Aplica un plan hash vigente bajo el lock del proyecto.'
            )
            ->addOption(
                'plan-hash',
                null,
                InputOption::VALUE_REQUIRED,
                'Hash exacto producido por --dry-run.'
            )
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_NONE,
                'Confirma expresamente la aplicacion.'
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
        $mode = $this->selectedMode($input);
        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['text', 'json'], true)) {
            return $this->failure(
                'sync.format_invalid',
                $format,
                $output,
                self::EXIT_INVALID,
                $mode
            );
        }

        if ($mode === null) {
            return $this->failure(
                'sync.mode_required',
                $format,
                $output,
                self::EXIT_INVALID,
                $mode
            );
        }

        $planHash = $input->getOption('plan-hash');
        $confirmed = (bool) $input->getOption('yes');
        if (
            $mode !== 'apply'
            && ($planHash !== null || $confirmed)
        ) {
            return $this->failure(
                'sync.option_not_applicable',
                $format,
                $output,
                self::EXIT_INVALID,
                $mode
            );
        }
        if (
            $mode === 'apply'
            && (
                !is_string($planHash)
                || preg_match('/\Asha256:[a-f0-9]{64}\z/', $planHash) !== 1
            )
        ) {
            return $this->failure(
                'sync.plan_hash_required',
                $format,
                $output,
                self::EXIT_INVALID,
                $mode
            );
        }
        if ($mode === 'apply' && !$confirmed) {
            return $this->failure(
                'sync.confirmation_required',
                $format,
                $output,
                self::EXIT_FAILURE,
                $mode
            );
        }

        try {
            $runtime = $this->runtime;
            if ($runtime === null) {
                $composer = $this->getComposer();
                if (!$composer instanceof Composer) {
                    throw new \RuntimeException(
                        'sync.composer_not_available'
                    );
                }
                $runtime = ManagedProjectSyncRuntime::fromComposer($composer);
            }

            if ($mode === 'plan') {
                $payload = $runtime->catalog();
                $this->render($mode, $payload, $format, $output);

                return ($payload['status'] ?? null) === 'ready'
                    ? self::EXIT_SUCCESS
                    : self::EXIT_FAILURE;
            }

            if ($mode === 'dry-run') {
                $preview = $runtime->preview();
                $this->render($mode, $preview, $format, $output);

                return ($preview['status'] ?? null) === 'ready'
                    ? self::EXIT_SUCCESS
                    : self::EXIT_FAILURE;
            }

            $runtime->apply((string) $planHash);
            $stats = $runtime->stats();
            $payload = [
                'schema' => 1,
                'package' => 'liquidstack/core',
                'protocol' => ManagedFileSynchronizer::SYNC_PLAN_PROTOCOL,
                'status' => ($stats['errors'] ?? 0) === 0
                    ? 'applied'
                    : 'failed',
                'plan_hash' => $planHash,
                'stats' => $stats,
            ];
            $this->render($mode, $payload, $format, $output);

            return ($stats['errors'] ?? 0) === 0
                ? self::EXIT_SUCCESS
                : self::EXIT_FAILURE;
        } catch (ManagedFilePlanChangedException) {
            return $this->failure(
                'sync.plan_changed',
                $format,
                $output,
                self::EXIT_FAILURE,
                $mode
            );
        } catch (ManagedFilePlanBlockedException) {
            return $this->failure(
                'sync.plan_blocked',
                $format,
                $output,
                self::EXIT_FAILURE,
                $mode
            );
        } catch (Throwable) {
            return $this->failure(
                'sync.runtime_failed',
                $format,
                $output,
                self::EXIT_FAILURE,
                $mode
            );
        }
    }

    private function selectedMode(InputInterface $input): ?string
    {
        $selected = array_values(array_filter(
            ['plan', 'dry-run', 'apply'],
            static fn (string $mode): bool => (bool) $input->getOption($mode)
        ));

        return count($selected) === 1 ? $selected[0] : null;
    }

    /** @param array<string, mixed> $payload */
    private function render(
        string $mode,
        array $payload,
        string $format,
        OutputInterface $output
    ): void {
        if ($format === 'json') {
            $status = $payload['status'] ?? null;
            $ok = !in_array($status, ['blocked', 'failed'], true);
            $output->writeln(json_encode(
                ['ok' => $ok, 'mode' => $mode] + $payload,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            ));

            return;
        }

        $output->writeln(sprintf(
            '<info>LiquidStack sync %s</info>',
            OutputFormatter::escape($mode)
        ));
        foreach ([
            'status',
            'protocol',
            'catalog_hash',
            'plan_hash',
            'standard_resources_ready',
            'pending_transactions',
            'queue_errors',
        ] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = is_bool($payload[$key])
                ? ($payload[$key] ? 'true' : 'false')
                : (string) $payload[$key];
            $output->writeln(sprintf(
                '%s: %s',
                $key,
                OutputFormatter::escape($value)
            ));
        }

        foreach (($payload['blockers'] ?? []) as $blocker) {
            $output->writeln(sprintf(
                '<error>%s</error>',
                OutputFormatter::escape((string) $blocker)
            ));
        }
        foreach (($payload['counts'] ?? $payload['stats'] ?? []) as $key => $value) {
            $output->writeln(sprintf(
                '%s: %d',
                OutputFormatter::escape((string) $key),
                (int) $value
            ));
        }

        foreach (($payload['entries'] ?? []) as $entry) {
            if (
                $mode !== 'plan'
                && ($entry['action'] ?? null) === 'unchanged'
            ) {
                continue;
            }
            $label = $mode === 'plan'
                ? (string) ($entry['policy'] ?? 'unknown')
                : (string) ($entry['action'] ?? 'unknown');
            $code = $mode === 'plan'
                ? (string) ($entry['group'] ?? 'ungrouped')
                : (string) ($entry['code'] ?? 'sync.unknown');
            $output->writeln(sprintf(
                '[%s] %s (%s)',
                OutputFormatter::escape($label),
                OutputFormatter::escape((string) ($entry['target'] ?? '')),
                OutputFormatter::escape($code)
            ));
        }
    }

    private function failure(
        string $code,
        string $format,
        OutputInterface $output,
        int $status,
        ?string $mode
    ): int {
        if ($format === 'json') {
            $output->writeln(json_encode(
                [
                    'schema' => 1,
                    'package' => 'liquidstack/core',
                    'protocol' => ManagedFileSynchronizer::SYNC_PLAN_PROTOCOL,
                    'ok' => false,
                    'mode' => $mode ?? 'invalid',
                    'error' => ['code' => $code],
                ],
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            ));
        } else {
            $output->writeln(sprintf(
                '<error>%s</error>',
                OutputFormatter::escape($code)
            ));
        }

        return $status;
    }
}
