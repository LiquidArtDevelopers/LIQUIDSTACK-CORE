<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheException;
use App\Core\Composer\BlogSitemapCacheInitCommandRuntimeException;
use App\Core\Composer\BlogSitemapCacheInitCommandRuntimeFactory;
use App\Core\Composer\BlogSitemapCacheInitCommandRuntimeFactoryInterface;
use App\Core\Composer\ProjectRootLocator;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

final class BlogSitemapCacheInitCommand extends BaseCommand
{
    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?string $coreRoot = null,
        private readonly ?BlogSitemapCacheInitCommandRuntimeFactoryInterface
            $runtimeFactory = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('liquidstack:blog:sitemap-cache:init')
            ->setDescription(
                'Inicializa de forma explícita la caché privada LKG del sitemap Blog.'
            )
            ->addOption('yes', 'y', InputOption::VALUE_NONE)
            ->addOption(
                'shared-storage-confirmed',
                null,
                InputOption::VALUE_NONE,
                'Confirma locks advisory y rename atómico compartidos en producción.'
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
        $yes = $input->getOption('yes') === true;
        if (!in_array($format, ['text', 'json'], true)) {
            return $this->failure('blog.sitemap_cache.init.format_invalid', $format, $output, self::INVALID);
        }
        if ($format === 'json' && !$yes) {
            return $this->failure('blog.sitemap_cache.init.json_requires_yes', $format, $output, self::INVALID);
        }
        if (!$yes) {
            if (!$input->isInteractive() || !$this->confirm($input, $output)) {
                return $this->failure('blog.sitemap_cache.init.confirmation_required', $format, $output, self::FAILURE);
            }
        }

        try {
            $runtime = ($this->runtimeFactory
                ?? new BlogSitemapCacheInitCommandRuntimeFactory())->create(
                    $this->projectRoot ?? ProjectRootLocator::fromComposerContext(),
                    $this->coreRoot ?? dirname(__DIR__, 4),
                    $input->getOption('shared-storage-confirmed') === true
                );
            $result = $runtime->initialize();
            if ($format === 'json') {
                $output->writeln((string) json_encode([
                    'schema' => 1,
                    'ok' => true,
                    'operation' => 'blog-sitemap-cache-init',
                    'result' => $result->toSafeArray(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $output->writeln($result->changed()
                    ? '<info>Caché privada LKG del sitemap inicializada.</info>'
                    : '<info>La caché privada LKG ya estaba inicializada.</info>');
            }
            return self::SUCCESS;
        } catch (BlogSitemapCacheInitCommandRuntimeException $exception) {
            return $this->failure($exception->issueCode(), $format, $output, self::FAILURE);
        } catch (BlogSitemapCacheException $exception) {
            return $this->failure($exception->issueCode(), $format, $output, self::FAILURE);
        } catch (Throwable) {
            return $this->failure('blog.sitemap_cache.init.internal_failure', $format, $output, self::FAILURE);
        }
    }

    private function confirm(InputInterface $input, OutputInterface $output): bool
    {
        $helper = $this->getHelper('question');
        return $helper instanceof QuestionHelper && (bool) $helper->ask(
            $input,
            $output,
            new ConfirmationQuestion(
                '¿Inicializar la caché privada LKG del sitemap? [y/N] ',
                false
            )
        );
    }

    private function failure(
        string $code,
        string $format,
        OutputInterface $output,
        int $status
    ): int {
        if (preg_match('/\Ablog\.sitemap_cache\.[a-z0-9_.]+\z/D', $code) !== 1) {
            $code = 'blog.sitemap_cache.init.internal_failure';
        }
        if ($format === 'json') {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'ok' => false,
                'operation' => 'blog-sitemap-cache-init',
                'error' => ['code' => $code],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $output->writeln(sprintf(
                '<error>Inicialización bloqueada de forma segura (%s).</error>',
                OutputFormatter::escape($code)
            ));
        }
        return $status;
    }
}
