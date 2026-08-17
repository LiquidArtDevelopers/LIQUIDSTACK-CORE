<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Blog\BlogInput;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureCatalog;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureException;
use App\Core\Composer\BlogQaSeedMatrixCommandRuntimeFactory;
use App\Core\Composer\BlogQaSeedMatrixCommandRuntimeFactoryInterface;
use App\Core\Composer\ProjectRootLocator;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class BlogQaSeedMatrixCommand extends BaseCommand
{
    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?string $coreRoot = null,
        private readonly ?BlogQaSeedMatrixCommandRuntimeFactoryInterface
            $runtimeFactory = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('liquidstack:blog:qa:seed-matrix')
            ->setDescription(
                'Planifica o crea fixtures QA Matrix aislados del Blog.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preflight y resumen sin mutaciones.'
            )
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_NONE,
                'Confirma de forma explícita la creación idempotente.'
            )
            ->addOption(
                'locales',
                null,
                InputOption::VALUE_REQUIRED,
                'Subconjunto explícito del catálogo QA: es,en,eu.',
                BlogQaMatrixFixtureCatalog::DEFAULT_LOCALES
            )
            ->addOption(
                'media-asset',
                null,
                InputOption::VALUE_REQUIRED,
                'UUID de un activo AVIF real ya existente.'
            )
            ->addOption(
                'actor',
                null,
                InputOption::VALUE_REQUIRED,
                'UUID de un usuario WebAdmin activo para auditoría.'
            )
            ->addOption(
                'allow-mysql',
                null,
                InputOption::VALUE_NONE,
                'Opt-in explícito cuando la conexión usa MySQL.'
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
                'blog.qa_fixture.format_invalid',
                $format,
                $output,
                self::INVALID
            );
        }
        $dryRun = $input->getOption('dry-run') === true;
        $apply = $input->getOption('yes') === true;
        if ($dryRun === $apply) {
            return $this->failure(
                $dryRun
                    ? 'blog.qa_fixture.mode_conflict'
                    : 'blog.qa_fixture.mode_required',
                $format,
                $output,
                self::INVALID
            );
        }

        try {
            $rawLocales = $input->getOption('locales');
            $mediaAssetPublicId = $input->getOption('media-asset');
            $actorPublicId = $input->getOption('actor');
            if (
                !is_string($rawLocales)
                || !is_string($mediaAssetPublicId)
                || !is_string($actorPublicId)
            ) {
                throw new BlogQaMatrixFixtureException(
                    'blog.qa_fixture.options_required'
                );
            }
            $locales = BlogQaMatrixFixtureCatalog::parseLocales($rawLocales);
            try {
                BlogInput::generatedPublicId($mediaAssetPublicId);
                BlogInput::publicId($actorPublicId);
            } catch (Throwable) {
                throw new BlogQaMatrixFixtureException(
                    'blog.qa_fixture.identifier_invalid'
                );
            }
            $runtime = ($this->runtimeFactory
                ?? new BlogQaSeedMatrixCommandRuntimeFactory())->create(
                    $this->projectRoot
                        ?? ProjectRootLocator::fromComposerContext(),
                    $this->coreRoot ?? dirname(__DIR__, 4),
                    $input->getOption('allow-mysql') === true
                );
            $result = $runtime->run(
                $locales,
                $mediaAssetPublicId,
                $actorPublicId,
                $apply
            );
            if ($format === 'json') {
                $output->writeln((string) json_encode([
                    'schema' => 1,
                    'ok' => true,
                    'operation' => 'blog-qa-seed-matrix',
                    'result' => $result->toSafeArray(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR));
            } else {
                $safe = $result->toSafeArray();
                $output->writeln(sprintf(
                    '<info>%s completado: %d agregados, %d variantes '
                        . 'solicitadas, %d pendientes, %d existentes y %d '
                        . 'mutadas.</info>',
                    $apply ? 'Seeder QA Matrix' : 'Dry-run QA Matrix',
                    $safe['aggregates_total'],
                    $safe['variants_requested'],
                    $safe['variants_pending'],
                    $safe['variants_existing'],
                    $safe['variants_mutated']
                ));
            }

            return self::SUCCESS;
        } catch (BlogQaMatrixFixtureException $exception) {
            return $this->failure(
                $exception->issueCode(),
                $format,
                $output,
                self::FAILURE
            );
        } catch (Throwable) {
            return $this->failure(
                'blog.qa_fixture.internal_failure',
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
        if (preg_match('/\Ablog\.qa_fixture\.[a-z0-9_.]+\z/D', $code)
            !== 1) {
            $code = 'blog.qa_fixture.internal_failure';
        }
        if ($format === 'json') {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'ok' => false,
                'operation' => 'blog-qa-seed-matrix',
                'error' => ['code' => $code],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR));
        } else {
            $output->writeln(sprintf(
                '<error>Fixture QA bloqueado de forma segura (%s).</error>',
                OutputFormatter::escape($code)
            ));
        }

        return $status;
    }
}
