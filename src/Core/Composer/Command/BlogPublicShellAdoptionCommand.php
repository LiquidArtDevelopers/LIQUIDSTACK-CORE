<?php

declare(strict_types=1);

namespace App\Core\Composer\Command;

use App\Core\Composer\BlogPublicShellAdoption;
use App\Core\Composer\BlogPublicShellAdoptionException;
use App\Core\Composer\BlogPublicShellAdoptionResult;
use App\Core\Composer\ProjectRootLocator;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class BlogPublicShellAdoptionCommand extends BaseCommand
{
    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?BlogPublicShellAdoption $adoption = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('liquidstack:blog:adopt-public-shell')
            ->setDescription(
                'Planifica o activa el shell publico project-owned de Blog.'
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
                'Activa la vista publica en la configuracion Blog.'
            )
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_NONE,
                'Confirma de forma explicita --apply.'
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
                'blog.public_shell_adoption.format_invalid',
                $format,
                $output,
                self::INVALID
            );
        }
        $apply = $input->getOption('apply') === true;
        $dryRun = $input->getOption('dry-run') === true;
        $yes = $input->getOption('yes') === true;
        if ($apply && $dryRun) {
            return $this->failure(
                'blog.public_shell_adoption.mode_conflict',
                $format,
                $output,
                self::INVALID
            );
        }
        if (!$apply && $yes) {
            return $this->failure(
                'blog.public_shell_adoption.apply_required',
                $format,
                $output,
                self::INVALID
            );
        }
        if ($apply && !$yes) {
            return $this->failure(
                'blog.public_shell_adoption.confirmation_required',
                $format,
                $output,
                self::FAILURE
            );
        }

        try {
            $result = ($this->adoption ?? new BlogPublicShellAdoption())->run(
                $this->projectRoot ?? ProjectRootLocator::fromComposerContext(),
                $apply
            );
            $this->success($result, $format, $output);

            return self::SUCCESS;
        } catch (BlogPublicShellAdoptionException $exception) {
            return $this->failure(
                $exception->issueCode(),
                $format,
                $output,
                self::FAILURE,
                $exception->relativePath()
            );
        } catch (Throwable) {
            return $this->failure(
                'blog.public_shell_adoption.internal_failure',
                $format,
                $output,
                self::FAILURE
            );
        }
    }

    private function success(
        BlogPublicShellAdoptionResult $result,
        string $format,
        OutputInterface $output
    ): void {
        if ($format === 'json') {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'ok' => true,
                'operation' => 'blog-adopt-public-shell',
                'result' => $result->toSafeArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR));

            return;
        }
        if ($result->status() === 'activated') {
            $output->writeln(
                '<info>Shell publico Blog activado en la configuracion del proyecto.</info>'
            );

            return;
        }
        if ($result->status() === 'already_active') {
            $output->writeln(
                '<info>El shell publico Blog ya estaba activado; no hubo cambios.</info>'
            );

            return;
        }

        $output->writeln(
            '<info>Plan listo: el scaffold publico esta completo y la configuracion puede activarse.</info>'
        );
        $output->writeln('<comment>No se ha modificado ningun fichero.</comment>');
        $output->writeln(
            '<comment>Antes de activar: ejecuta npm run build y comprueba la entrada src/js/blogArticle.js en public/.vite/manifest.json.</comment>'
        );
        $output->writeln(
            '<comment>Aplica con: composer liquidstack:blog:adopt-public-shell --apply --yes</comment>'
        );
        $output->writeln(
            '<comment>Despues de activar: ejecuta composer liquidstack:doctor.</comment>'
        );
    }

    private function failure(
        string $code,
        string $format,
        OutputInterface $output,
        int $status,
        ?string $relativePath = null
    ): int {
        if (preg_match(
            '/\Ablog\.public_shell_adoption\.[a-z0-9_.]+\z/D',
            $code
        ) !== 1) {
            $code = 'blog.public_shell_adoption.internal_failure';
        }
        $requiredConfig = in_array($code, [
            'blog.public_shell_adoption.config_not_literal',
            'blog.public_shell_adoption.config_value_incompatible',
        ], true)
            ? [
                'path' => BlogPublicShellAdoptionResult::CONFIG_PATH,
                'entry' => "'" . BlogPublicShellAdoptionResult::CONFIG_KEY
                    . "' => '" . BlogPublicShellAdoptionResult::CONFIG_VALUE
                    . "',",
            ]
            : null;
        $relativePath = $this->safeRelativePath($relativePath);
        if ($format === 'json') {
            $error = [
                'code' => $code,
                'changed' => false,
            ];
            if (is_array($requiredConfig)) {
                $error['required_config'] = $requiredConfig;
            }
            if (is_string($relativePath)) {
                $error['path'] = $relativePath;
            }
            $output->writeln((string) json_encode([
                'schema' => 1,
                'ok' => false,
                'operation' => 'blog-adopt-public-shell',
                'error' => $error,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR));

            return $status;
        }

        $output->writeln(sprintf(
            '<error>Adopcion bloqueada de forma segura (%s).</error>',
            OutputFormatter::escape($code)
        ));
        $output->writeln('<comment>No se ha modificado ningun fichero.</comment>');
        if (is_string($relativePath)) {
            $output->writeln(sprintf(
                '<comment>Ruta que requiere atencion: %s</comment>',
                OutputFormatter::escape($relativePath)
            ));
        }
        if (is_array($requiredConfig)) {
            $output->writeln(sprintf(
                '<comment>Configuracion requerida en %s:</comment>',
                BlogPublicShellAdoptionResult::CONFIG_PATH
            ));
            $output->writeln('  ' . $requiredConfig['entry']);
        }

        return $status;
    }

    private function safeRelativePath(?string $relativePath): ?string
    {
        if (!is_string($relativePath) || $relativePath === '') {
            return null;
        }
        if ($relativePath === 'composer.json') {
            return $relativePath;
        }
        if (
            preg_match(
                '/\A(?:App|src|public)(?:\/[A-Za-z0-9_.-]+)+\z/D',
                $relativePath
            ) !== 1
            || in_array('..', explode('/', $relativePath), true)
        ) {
            return null;
        }

        return $relativePath;
    }
}
