<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Commerce\Outbox\CommerceOutboxDispatchReport;
use App\Core\Composer\CommerceMailDispatchCommandRuntimeException;
use App\Core\Composer\CommerceMailDispatchCommandRuntimeFactory;
use App\Core\Composer\CommerceMailDispatchCommandRuntimeFactoryInterface;
use App\Core\Composer\ProjectRootLocator;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class CommerceMailDispatchCommand extends BaseCommand
{
    private const DEFAULT_LIMIT = 20;
    private const SAFE_ISSUE_CODES = [
        'commerce.mail.module_not_enabled',
        'commerce.mail.environment_unusable',
        'commerce.mail.exception_trace_unsafe',
        'commerce.mail.configuration_invalid',
        'commerce.mail.connection_factory_invalid',
        'commerce.mail.schema_not_ready',
        'commerce.mail.routing_unavailable',
        'commerce.mail.runtime_unavailable',
    ];

    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?string $coreRoot = null,
        private readonly ?CommerceMailDispatchCommandRuntimeFactoryInterface $runtimeFactory = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('liquidstack:commerce-mail-dispatch')
            ->setDescription('Despacha un lote finito del outbox de solicitudes Commerce.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de trabajos (1-100).', (string) self::DEFAULT_LIMIT)
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: text o json.', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['text', 'json'], true)) {
            return $this->renderFailure('commerce.mail.format_invalid', $format, $output, self::INVALID);
        }
        $rawLimit = (string) $input->getOption('limit');
        if (preg_match('/\A[0-9]{1,3}\z/', $rawLimit) !== 1
            || (int) $rawLimit < 1 || (int) $rawLimit > 100) {
            return $this->renderFailure('commerce.mail.limit_invalid', $format, $output, self::INVALID);
        }
        try {
            $runtime = ($this->runtimeFactory ?? new CommerceMailDispatchCommandRuntimeFactory())->create(
                $this->projectRoot ?? ProjectRootLocator::fromComposerContext(),
                $this->coreRoot ?? dirname(__DIR__, 4)
            );
            $report = $runtime->dispatch((int) $rawLimit);
        } catch (CommerceMailDispatchCommandRuntimeException $exception) {
            return $this->renderFailure($this->safeIssueCode($exception->issueCode()), $format, $output, self::FAILURE);
        } catch (Throwable) {
            return $this->renderFailure('commerce.mail.dispatch_failed', $format, $output, self::FAILURE);
        }
        $failed = $report->retryScheduled() > 0
            || $report->permanentlyFailed() > 0 || $report->fenced() > 0;
        $this->renderReport($report, $format, $output, !$failed);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function renderReport(CommerceOutboxDispatchReport $report, string $format, OutputInterface $output, bool $ok): void
    {
        if ($format === 'json') {
            $output->writeln($this->encodeJson([
                'schema' => 1,
                'ok' => $ok,
                'operation' => 'commerce-mail-dispatch',
                'result' => $report->toArray(),
            ]));
            return;
        }
        $tag = $ok ? 'info' : 'error';
        $output->writeln(sprintf(
            '<%1$s>Outbox Commerce: %2$d examinados, %3$d reclamados, %4$d enviados, %5$d reintentos, %6$d fallos permanentes y %7$d resultados cercados.</%1$s>',
            $tag,
            $report->examined(),
            $report->claimed(),
            $report->sent(),
            $report->retryScheduled(),
            $report->permanentlyFailed(),
            $report->fenced()
        ));
    }

    private function renderFailure(string $code, string $format, OutputInterface $output, int $status): int
    {
        if ($format === 'json') {
            $output->writeln($this->encodeJson([
                'schema' => 1,
                'ok' => false,
                'operation' => 'commerce-mail-dispatch',
                'error' => ['code' => $code],
            ]));
            return $status;
        }
        $output->writeln(sprintf(
            '<error>Despacho Commerce bloqueado de forma segura (%s). No se muestran destinatarios ni contenido.</error>',
            OutputFormatter::escape($code)
        ));

        return $status;
    }

    private function safeIssueCode(string $code): string
    {
        return in_array($code, self::SAFE_ISSUE_CODES, true)
            ? $code
            : 'commerce.mail.runtime_unavailable';
    }

    /** @param array<string, mixed> $payload */
    private function encodeJson(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
