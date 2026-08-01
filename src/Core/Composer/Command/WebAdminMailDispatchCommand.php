<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Composer\ProjectRootLocator;
use App\Core\Composer\WebAdminMailDispatchCommandRuntimeException;
use App\Core\Composer\WebAdminMailDispatchCommandRuntimeFactory;
use App\Core\Composer\WebAdminMailDispatchCommandRuntimeFactoryInterface;
use App\Core\WebAdmin\Outbox\WebAdminOutboxDispatchReport;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class WebAdminMailDispatchCommand extends BaseCommand
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    /** @var list<string> */
    private const SAFE_ISSUE_CODES = [
        'webadmin.mail.module_not_enabled',
        'webadmin.mail.environment_unusable',
        'webadmin.mail.exception_trace_unsafe',
        'webadmin.mail.configuration_invalid',
        'webadmin.mail.connection_factory_invalid',
        'webadmin.mail.schema_not_ready',
        'webadmin.mail.routing_unavailable',
        'webadmin.mail.runtime_unavailable',
    ];

    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?string $coreRoot = null,
        private readonly ?WebAdminMailDispatchCommandRuntimeFactoryInterface $runtimeFactory = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('liquidstack:webadmin:mail:dispatch')
            ->setDescription(
                'Despacha un lote finito del outbox de correo de WebAdmin.'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Máximo de trabajos que se examinarán (1-100).',
                (string) self::DEFAULT_LIMIT
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
                'webadmin.mail.format_invalid',
                $format,
                $output,
                self::INVALID
            );
        }

        $rawLimit = (string) $input->getOption('limit');
        if (
            preg_match('/\A[0-9]{1,3}\z/', $rawLimit) !== 1
            || (int) $rawLimit < 1
            || (int) $rawLimit > self::MAX_LIMIT
        ) {
            return $this->renderFailure(
                'webadmin.mail.limit_invalid',
                $format,
                $output,
                self::INVALID
            );
        }

        try {
            $runtime = ($this->runtimeFactory
                ?? new WebAdminMailDispatchCommandRuntimeFactory())->create(
                    $this->projectRoot
                        ?? ProjectRootLocator::fromComposerContext(),
                    $this->coreRoot ?? dirname(__DIR__, 4)
                );
            $report = $runtime->dispatch((int) $rawLimit);
        } catch (WebAdminMailDispatchCommandRuntimeException $exception) {
            return $this->renderFailure(
                $this->safeRuntimeIssueCode($exception->issueCode()),
                $format,
                $output,
                self::FAILURE
            );
        } catch (Throwable) {
            return $this->renderFailure(
                'webadmin.mail.dispatch_failed',
                $format,
                $output,
                self::FAILURE
            );
        }

        $deliveryFailure = $report->retryScheduled() > 0
            || $report->permanentlyFailed() > 0
            || $report->fenced() > 0;
        $this->renderReport($report, $format, $output, !$deliveryFailure);

        return $deliveryFailure ? self::FAILURE : self::SUCCESS;
    }

    private function renderReport(
        WebAdminOutboxDispatchReport $report,
        string $format,
        OutputInterface $output,
        bool $ok
    ): void {
        if ($format === 'json') {
            $output->writeln($this->encodeJson([
                'schema' => 1,
                'ok' => $ok,
                'operation' => 'webadmin-mail-dispatch',
                'result' => $report->toArray(),
            ]));

            return;
        }

        $tag = $ok ? 'info' : 'error';
        $output->writeln(sprintf(
            '<%1$s>Outbox WebAdmin: %2$d examinados, %3$d reclamados, '
            . '%4$d enviados, %5$d reintentos, %6$d fallos permanentes y '
            . '%7$d resultados cercados.</%1$s>',
            $tag,
            $report->examined(),
            $report->claimed(),
            $report->sent(),
            $report->retryScheduled(),
            $report->permanentlyFailed(),
            $report->fenced()
        ));
    }

    private function renderFailure(
        string $code,
        string $format,
        OutputInterface $output,
        int $status
    ): int {
        if ($format === 'json') {
            $output->writeln($this->encodeJson([
                'schema' => 1,
                'ok' => false,
                'operation' => 'webadmin-mail-dispatch',
                'error' => ['code' => $code],
            ]));

            return $status;
        }

        $output->writeln(sprintf(
            '<error>Despacho WebAdmin bloqueado de forma segura (%s). No se muestran destinatarios, tokens ni detalles internos.</error>',
            OutputFormatter::escape($code)
        ));

        return $status;
    }

    private function safeRuntimeIssueCode(string $code): string
    {
        return in_array($code, self::SAFE_ISSUE_CODES, true)
            ? $code
            : 'webadmin.mail.runtime_unavailable';
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
