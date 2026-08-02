<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Composer\MediaInitCommandRuntimeException;
use App\Core\Composer\MediaInitCommandRuntimeFactory;
use App\Core\Composer\MediaInitCommandRuntimeFactoryInterface;
use App\Core\Composer\ProjectRootLocator;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaStorageInitializationResult;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

final class MediaInitCommand extends BaseCommand
{
    /** @var list<string> */
    private const SAFE_ISSUE_CODES = [
        'webadmin.media.init.format_invalid',
        'webadmin.media.init.json_requires_yes',
        'webadmin.media.init.confirmation_required',
        'webadmin.media.init.adoption_requires_yes',
        'webadmin.media.init.adoption_requires_backup_confirmation',
        'webadmin.media.init.backup_confirmation_without_adoption',
        'webadmin.media.init.module_not_enabled',
        'webadmin.media.init.environment_unusable',
        'webadmin.media.init.connection_factory_invalid',
        'webadmin.media.init.schema_not_ready',
        'webadmin.media.init.runtime_unavailable',
        'webadmin.media.init.internal_failure',
        'webadmin.media.project_root_invalid',
        'webadmin.media.storage_configuration_invalid',
        'webadmin.media.storage_configuration_missing',
        'webadmin.media.storage_root_dangerous',
        'webadmin.media.storage_root_not_absolute',
        'webadmin.media.storage_root_invalid',
        'webadmin.media.storage_path_invalid',
        'webadmin.media.storage_symlink_rejected',
        'webadmin.media.storage_create_failed',
        'webadmin.media.storage_not_writable',
        'webadmin.media.storage_layout_invalid',
        'webadmin.media.storage_requires_explicit_adoption',
        'webadmin.media.storage_marker_invalid',
        'webadmin.media.storage_marker_create_failed',
        'webadmin.media.storage_marker_write_failed',
        'webadmin.media.storage_marker_cleanup_failed',
        'webadmin.media.storage_lock_failed',
        'webadmin.media.storage_ignore_create_failed',
        'webadmin.media.storage_ignore_write_failed',
        'webadmin.media.storage_ignore_cleanup_failed',
        'webadmin.media.storage_ignore_invalid',
        'webadmin.media.storage_adoption_not_required',
        'webadmin.media.storage_adoption_mismatch',
        'webadmin.media.storage_adoption_database_failed',
    ];

    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?string $coreRoot = null,
        private readonly ?MediaInitCommandRuntimeFactoryInterface $runtimeFactory = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('liquidstack:media:init')
            ->setDescription(
                'Inicializa de forma explícita e idempotente el storage privado de Media.'
            )
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_NONE,
                'Confirma la inicialización sin pregunta interactiva.'
            )
            ->addOption(
                'adopt-existing',
                null,
                InputOption::VALUE_NONE,
                'Adopta una raíz legacy solo tras verificarla contra la DB.'
            )
            ->addOption(
                'backup-confirmed',
                null,
                InputOption::VALUE_NONE,
                'Confirma que existe un backup recuperable de DB y storage.'
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
                'webadmin.media.init.format_invalid',
                $format,
                $output,
                self::INVALID
            );
        }
        $yes = $input->getOption('yes') === true;
        $adoptExisting = $input->getOption('adopt-existing') === true;
        $backupConfirmed = $input->getOption('backup-confirmed') === true;
        if ($backupConfirmed && !$adoptExisting) {
            return $this->renderFailure(
                'webadmin.media.init.backup_confirmation_without_adoption',
                $format,
                $output,
                self::INVALID
            );
        }
        if ($adoptExisting && !$yes) {
            return $this->renderFailure(
                'webadmin.media.init.adoption_requires_yes',
                $format,
                $output,
                self::INVALID
            );
        }
        if ($adoptExisting && !$backupConfirmed) {
            return $this->renderFailure(
                'webadmin.media.init.adoption_requires_backup_confirmation',
                $format,
                $output,
                self::INVALID
            );
        }
        if ($format === 'json' && !$yes) {
            return $this->renderFailure(
                'webadmin.media.init.json_requires_yes',
                $format,
                $output,
                self::INVALID
            );
        }

        try {
            $runtime = ($this->runtimeFactory
                ?? new MediaInitCommandRuntimeFactory())->create(
                    $this->projectRoot
                        ?? ProjectRootLocator::fromComposerContext(),
                    $this->coreRoot ?? dirname(__DIR__, 4),
                    $adoptExisting
                );

            if (!$yes) {
                if (!$input->isInteractive()) {
                    return $this->renderFailure(
                        'webadmin.media.init.confirmation_required',
                        $format,
                        $output,
                        self::FAILURE
                    );
                }
                if (!$this->confirm($input, $output)) {
                    $output->writeln(
                        '<comment>Inicialización cancelada; no se modificó el storage.</comment>'
                    );

                    return self::SUCCESS;
                }
            }

            $this->renderSuccess($runtime->initialize(), $format, $output);

            return self::SUCCESS;
        } catch (MediaInitCommandRuntimeException $exception) {
            return $this->renderFailure(
                $exception->issueCode(),
                $format,
                $output,
                self::FAILURE
            );
        } catch (MediaException $exception) {
            return $this->renderFailure(
                $exception->issueCode(),
                $format,
                $output,
                self::FAILURE
            );
        } catch (Throwable) {
            return $this->renderFailure(
                'webadmin.media.init.internal_failure',
                $format,
                $output,
                self::FAILURE
            );
        }
    }

    private function confirm(
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
                '¿Inicializar el storage privado de Media? [y/N] ',
                false
            )
        );
    }

    private function renderSuccess(
        MediaStorageInitializationResult $result,
        string $format,
        OutputInterface $output
    ): void {
        if ($format === 'json') {
            $output->writeln($this->encodeJson([
                'schema' => 1,
                'ok' => true,
                'operation' => 'media-init',
                'result' => $result->toSafeArray(),
            ]));

            return;
        }

        if ($result->status() === 'adopted_existing') {
            $output->writeln(
                '<info>Storage legacy de Media verificado y adoptado.</info>'
            );

            return;
        }
        $output->writeln(
            $result->changed()
                ? '<info>Storage privado de Media inicializado.</info>'
                : '<info>El storage privado de Media ya estaba inicializado.</info>'
        );
    }

    private function renderFailure(
        string $code,
        string $format,
        OutputInterface $output,
        int $status
    ): int {
        $code = in_array($code, self::SAFE_ISSUE_CODES, true)
            ? $code
            : 'webadmin.media.init.internal_failure';
        if ($format === 'json') {
            $output->writeln($this->encodeJson([
                'schema' => 1,
                'ok' => false,
                'operation' => 'media-init',
                'error' => ['code' => $code],
            ]));

            return $status;
        }

        $output->writeln(sprintf(
            '<error>Inicialización bloqueada de forma segura (%s). No se muestran rutas ni detalles internos.</error>',
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
