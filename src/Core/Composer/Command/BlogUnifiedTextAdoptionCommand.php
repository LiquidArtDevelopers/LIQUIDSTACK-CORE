<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Blog\BlogInput;
use App\Core\Blog\StructuredContent\Adoption\BlogUnifiedTextAdoptionException;
use App\Core\Blog\StructuredContent\Adoption\BlogUnifiedTextAdoptionRequest;
use App\Core\Composer\BlogUnifiedTextAdoptionCommandRuntimeFactory;
use App\Core\Composer\BlogUnifiedTextAdoptionCommandRuntimeFactoryInterface;
use App\Core\Composer\ProjectRootLocator;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class BlogUnifiedTextAdoptionCommand extends BaseCommand
{
    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?string $coreRoot = null,
        private readonly ?BlogUnifiedTextAdoptionCommandRuntimeFactoryInterface
            $runtimeFactory = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $arrayOption = InputOption::VALUE_REQUIRED
            | InputOption::VALUE_IS_ARRAY;
        $this
            ->setName('liquidstack:blog:adopt-unified-text')
            ->setDescription(
                'Planifica o adopta Texto unificado en contenido activo; '
                    . 'siempre excluye QA Dummy y papelera.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Fuerza el modo de solo lectura, que ya es el predeterminado.'
            )
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Solicita guardar nuevas revisiones mediante el editor.'
            )
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_NONE,
                'Confirma de forma explicita --apply.'
            )
            ->addOption(
                'actor',
                null,
                InputOption::VALUE_REQUIRED,
                'UUID de un editor WebAdmin activo para autoria y auditoria.'
            )
            ->addOption(
                'post',
                null,
                $arrayOption,
                'UUID de post a incluir; se puede repetir.'
            )
            ->addOption(
                'locale',
                null,
                $arrayOption,
                'Locale a incluir; se puede repetir.'
            )
            ->addOption(
                'status',
                null,
                $arrayOption,
                'Estado a incluir: draft o published; se puede repetir.'
            )
            ->addOption(
                'max',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximo de variantes candidatas; el exceso bloquea todo.',
                (string) BlogUnifiedTextAdoptionRequest::DEFAULT_MAX_CANDIDATES
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
            return $this->failure(
                'blog.unified_text_adoption.format_invalid',
                $format,
                $output,
                self::INVALID
            );
        }
        $apply = $input->getOption('apply') === true;
        $dryRun = $input->getOption('dry-run') === true;
        $confirmed = $input->getOption('yes') === true;
        if ($apply && $dryRun) {
            return $this->failure(
                'blog.unified_text_adoption.mode_conflict',
                $format,
                $output,
                self::INVALID
            );
        }
        if (!$apply && $confirmed) {
            return $this->failure(
                'blog.unified_text_adoption.apply_required',
                $format,
                $output,
                self::INVALID
            );
        }
        if ($apply && !$confirmed) {
            return $this->failure(
                'blog.unified_text_adoption.confirmation_required',
                $format,
                $output,
                self::FAILURE
            );
        }

        try {
            $actor = $input->getOption('actor');
            if ($actor !== null && !is_string($actor)) {
                throw $this->inputFailure('actor_invalid');
            }
            if ($apply && (!is_string($actor) || $actor === '')) {
                throw $this->inputFailure('actor_required');
            }
            if (is_string($actor)) {
                try {
                    $actor = BlogInput::publicId($actor);
                } catch (Throwable) {
                    throw $this->inputFailure('actor_invalid');
                }
            }
            $maximum = $this->maximumCandidates($input->getOption('max'));
            $posts = $this->stringList($input->getOption('post'));
            $locales = $this->stringList($input->getOption('locale'));
            $statuses = $this->stringList($input->getOption('status'));
            // Validate every caller-controlled filter before configuration or
            // PDO are opened. The runtime reconstructs the same immutable
            // request at the domain boundary.
            new BlogUnifiedTextAdoptionRequest(
                $posts,
                $locales,
                $statuses,
                $maximum,
                $apply,
                $actor
            );
            $runtime = ($this->runtimeFactory
                ?? new BlogUnifiedTextAdoptionCommandRuntimeFactory())->create(
                    $this->projectRoot
                        ?? ProjectRootLocator::fromComposerContext(),
                    $this->coreRoot ?? dirname(__DIR__, 4)
                );
            $result = $runtime->run(
                $posts,
                $locales,
                $statuses,
                $maximum,
                $actor,
                $apply
            );
            $safe = $result->toSafeArray();
            if ($format === 'json') {
                $output->writeln((string) json_encode([
                    'schema' => 1,
                    'ok' => true,
                    'operation' => 'blog-adopt-unified-text',
                    'result' => $safe,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR));
            } elseif ($apply) {
                $output->writeln(sprintf(
                    '<info>Adopcion completada: %d variantes guardadas; '
                        . '%d publicadas quedaron como workspace privado; '
                        . '0 republicadas.</info>',
                    $safe['variants_mutated'],
                    $safe['published_private_workspaces_created_or_advanced']
                ));
                $output->writeln(
                    '<comment>QA Dummy y papelera se excluyeron siempre.</comment>'
                );
            } else {
                $output->writeln(sprintf(
                    '<info>Plan de adopcion: %d candidatas activas no QA; '
                        . '%d pendientes (%d borradores y %d publicadas); '
                        . '%d ya unificadas, %d sin documento y %d no '
                        . 'convertibles sin perdida.</info>',
                    $safe['active_non_qa_candidates'],
                    $safe['pending_total'],
                    $safe['pending_drafts'],
                    $safe['pending_published'],
                    $safe['already_unified'],
                    $safe['without_structured_document'],
                    $safe['not_losslessly_convertible']
                ));
                $output->writeln(
                    '<comment>Solo lectura; QA Dummy y papelera excluidas.</comment>'
                );
            }

            return self::SUCCESS;
        } catch (BlogUnifiedTextAdoptionException $exception) {
            return $this->failure(
                $exception->issueCode(),
                $format,
                $output,
                self::FAILURE
            );
        } catch (Throwable) {
            return $this->failure(
                'blog.unified_text_adoption.internal_failure',
                $format,
                $output,
                self::FAILURE
            );
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->inputFailure('filters_invalid');
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw $this->inputFailure('filters_invalid');
            }
        }

        return array_values($value);
    }

    private function maximumCandidates(mixed $value): int
    {
        if (
            !is_string($value)
            || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1
            || (string) (int) $value !== $value
        ) {
            throw $this->inputFailure('maximum_invalid');
        }
        $maximum = (int) $value;
        if ($maximum > BlogUnifiedTextAdoptionRequest::MAX_CANDIDATES) {
            throw $this->inputFailure('maximum_invalid');
        }

        return $maximum;
    }

    private function inputFailure(
        string $suffix
    ): BlogUnifiedTextAdoptionException {
        return new BlogUnifiedTextAdoptionException(
            'blog.unified_text_adoption.' . $suffix
        );
    }

    private function failure(
        string $code,
        string $format,
        OutputInterface $output,
        int $status
    ): int {
        if (preg_match(
            '/\Ablog\.unified_text_adoption\.[a-z0-9_.]+\z/D',
            $code
        ) !== 1) {
            $code = 'blog.unified_text_adoption.internal_failure';
        }
        if ($format === 'json') {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'ok' => false,
                'operation' => 'blog-adopt-unified-text',
                'error' => ['code' => $code],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR));
        } else {
            $output->writeln(sprintf(
                '<error>Adopcion bloqueada de forma segura (%s).</error>',
                OutputFormatter::escape($code)
            ));
        }

        return $status;
    }
}
