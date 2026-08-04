<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Composer\BlogAnalyticsPurgeCommandRuntimeException;
use App\Core\Composer\BlogAnalyticsPurgeCommandRuntimeFactory;
use App\Core\Composer\BlogAnalyticsPurgeCommandRuntimeFactoryInterface;
use App\Core\Composer\ProjectRootLocator;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class BlogAnalyticsPurgeCommand extends BaseCommand
{
    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?string $coreRoot = null,
        private readonly ?BlogAnalyticsPurgeCommandRuntimeFactoryInterface
            $runtimeFactory = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('liquidstack:blog:analytics:purge')
            ->setDescription(
                'Elimina metricas Blog anteriores a la retencion configurada.'
            )
            ->addOption('yes', 'y', InputOption::VALUE_NONE)
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
            return $this->failure(
                'blog.analytics.purge.format_invalid',
                $format,
                $output,
                self::INVALID
            );
        }
        if ($input->getOption('yes') !== true) {
            return $this->failure(
                'blog.analytics.purge.confirmation_required',
                $format,
                $output,
                self::FAILURE
            );
        }

        try {
            $runtime = ($this->runtimeFactory
                ?? new BlogAnalyticsPurgeCommandRuntimeFactory())->create(
                    $this->projectRoot
                        ?? ProjectRootLocator::fromComposerContext(),
                    $this->coreRoot ?? dirname(__DIR__, 4)
                );
            $result = $runtime->purge();
            if ($format === 'json') {
                $output->writeln((string) json_encode([
                    'schema' => 1,
                    'ok' => true,
                    'operation' => 'blog-analytics-purge',
                    'result' => $result->toSafeArray(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR));
            } else {
                $output->writeln(sprintf(
                    '<info>Retencion aplicada: %d sesiones y %d vistas eliminadas.</info>',
                    $result->deletedSessions(),
                    $result->deletedViews()
                ));
            }

            return self::SUCCESS;
        } catch (BlogAnalyticsPurgeCommandRuntimeException $exception) {
            return $this->failure(
                $exception->issueCode(),
                $format,
                $output,
                self::FAILURE
            );
        } catch (Throwable) {
            return $this->failure(
                'blog.analytics.purge.internal_failure',
                $format,
                $output,
                self::FAILURE
            );
        }
    }

    private function failure(
        string $code,
        string $format,
        OutputInterface $output,
        int $status
    ): int {
        if (preg_match('/\Ablog\.analytics\.purge\.[a-z0-9_.]+\z/D', $code)
            !== 1) {
            $code = 'blog.analytics.purge.internal_failure';
        }
        if ($format === 'json') {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'ok' => false,
                'operation' => 'blog-analytics-purge',
                'error' => ['code' => $code],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR));
        } else {
            $output->writeln(sprintf(
                '<error>Purgado bloqueado de forma segura (%s).</error>',
                OutputFormatter::escape($code)
            ));
        }

        return $status;
    }
}
