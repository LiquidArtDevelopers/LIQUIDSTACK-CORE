#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace LiquidStack\Release;

final class Version
{
    public function __construct(
        private int $major,
        private int $minor,
        private int $patch
    ) {
    }

    public static function fromTag(string $tag, bool $strict = true): ?self
    {
        $pattern = $strict
            ? '/\Av?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/'
            : '/\Av?([0-9]+)\.([0-9]+)\.([0-9]+)\z/';

        if (preg_match($pattern, trim($tag), $matches) !== 1) {
            return null;
        }

        return new self((int) $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    /**
     * @param list<string> $tags
     *
     * @return array{tag: string, version: self}|null
     */
    public static function latestFromTags(array $tags): ?array
    {
        $latest = null;

        foreach ($tags as $tag) {
            $version = self::fromTag($tag, false);

            if ($version === null) {
                continue;
            }

            if ($latest === null || $version->compare($latest['version']) > 0) {
                $latest = [
                    'tag'     => trim($tag),
                    'version' => $version,
                ];
            }
        }

        return $latest;
    }

    public function bump(string $type): self
    {
        return match ($type) {
            'patch' => new self($this->major, $this->minor, $this->patch + 1),
            'minor' => new self($this->major, $this->minor + 1, 0),
            'major' => new self($this->major + 1, 0, 0),
            default => throw new \InvalidArgumentException(sprintf('Tipo de incremento no válido: %s', $type)),
        };
    }

    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch]
            <=> [$other->major, $other->minor, $other->patch];
    }

    public function tag(): string
    {
        return sprintf('v%d.%d.%d', $this->major, $this->minor, $this->patch);
    }
}

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output
    ) {
    }
}

final class ReleaseCommand
{
    private const PACKAGE_NAME = 'liquidstack/core';
    private const RELEASE_BRANCH = 'main';
    private const DEFAULT_REMOTE = 'origin';

    /** @var (\Closure(string, string): string)|null */
    private ?\Closure $promptHandler;

    /** @var (\Closure(string): bool)|null */
    private ?\Closure $confirmationHandler;

    /** @var (\Closure(string): void)|null */
    private ?\Closure $writeHandler;

    public function __construct(
        private string $projectRoot,
        ?callable $promptHandler = null,
        ?callable $confirmationHandler = null,
        ?callable $writeHandler = null
    ) {
        $resolvedRoot = realpath($projectRoot);

        if ($resolvedRoot === false) {
            throw new \RuntimeException(sprintf('No se puede resolver la raíz del proyecto: %s', $projectRoot));
        }

        $this->projectRoot = $resolvedRoot;
        $this->promptHandler = $promptHandler !== null
            ? \Closure::fromCallable($promptHandler)
            : null;
        $this->confirmationHandler = $confirmationHandler !== null
            ? \Closure::fromCallable($confirmationHandler)
            : null;
        $this->writeHandler = $writeHandler !== null
            ? \Closure::fromCallable($writeHandler)
            : null;
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        $options = $this->parseOptions($arguments);

        if ($options['help']) {
            $this->printHelp();
            return 0;
        }

        $this->assertCoreRepository();

        $branch = $this->gitOutput(['branch', '--show-current']);

        if ($branch !== self::RELEASE_BRANCH) {
            throw new \RuntimeException(sprintf(
                'Las releases deben publicarse desde %s; la rama actual es %s.',
                self::RELEASE_BRANCH,
                $branch !== '' ? $branch : '(detached HEAD)'
            ));
        }

        $this->assertCleanWorktree();

        $remote = self::DEFAULT_REMOTE;
        $remoteUrl = $this->gitOutput(['remote', 'get-url', $remote]);

        if (!$options['no_fetch']) {
            $this->write(sprintf('Actualizando referencias y etiquetas desde %s...', $remote));
            $this->runChecked(['git', 'fetch', '--tags', '--prune', $remote], 'No se pudieron actualizar las etiquetas.');
        }

        [$behind, $ahead] = $this->branchDistance($remote, $branch);

        if ($behind > 0) {
            throw new \RuntimeException(sprintf(
                'La rama local está %d commit(s) por detrás de %s/%s. Ejecuta git pull --ff-only antes de publicar.',
                $behind,
                $remote,
                $branch
            ));
        }

        $tags = $this->gitLines(['tag', '--list']);
        $latest = Version::latestFromTags($tags);
        $baseVersion = $latest['version'] ?? new Version(0, 0, 0);
        $suggestions = [
            'patch' => $baseVersion->bump('patch'),
            'minor' => $baseVersion->bump('minor'),
            'major' => $baseVersion->bump('major'),
        ];

        $this->printSuggestions($latest, $suggestions);

        $requestedVersion = $options['version'];

        if ($requestedVersion === null) {
            $defaultVersion = $suggestions[$options['bump']]->tag();

            if ($options['yes']) {
                $requestedVersion = $defaultVersion;
            } else {
                $requestedVersion = $this->prompt(
                    sprintf('Etiqueta a publicar [%s]: ', $defaultVersion),
                    $defaultVersion
                );
            }
        }

        $version = Version::fromTag($requestedVersion, true);

        if ($version === null) {
            throw new \RuntimeException(
                'La versión debe usar SemVer estable X.Y.Z sin ceros iniciales, por ejemplo v1.5.0 o 1.5.0.'
            );
        }

        $tag = $version->tag();

        if ($latest !== null && $version->compare($latest['version']) <= 0) {
            throw new \RuntimeException(sprintf(
                'La versión %s debe ser posterior a la última etiqueta %s.',
                $tag,
                $latest['tag']
            ));
        }

        foreach ($tags as $existingTag) {
            $existingVersion = Version::fromTag($existingTag, false);

            if ($existingVersion !== null && $version->compare($existingVersion) === 0) {
                throw new \RuntimeException(sprintf(
                    'La versión %s ya existe con la etiqueta %s.',
                    $tag,
                    $existingTag
                ));
            }
        }

        $headOid = $this->gitOutput(['rev-parse', 'HEAD']);
        $head = $this->gitOutput(['log', '-1', '--format=%h %s']);

        $this->write('');
        $this->write('Resumen de la release');
        $this->write(sprintf('  Rama:      %s (%d commit(s) por subir)', $branch, $ahead));
        $this->write(sprintf('  Commit:    %s', $head));
        $this->write(sprintf('  Remoto:    %s (%s)', $remote, $this->redactRemoteUrl($remoteUrl)));
        $this->write(sprintf('  Etiqueta:  %s', $tag));
        $this->write('');

        if ($ahead === 0) {
            $this->write(
                'AVISO: main ya está sincronizada; la nueva versión apuntará a un commit que ya existe en el remoto.'
            );
            $this->write('');
        }

        $this->runChecks($options['skip_tests']);
        $this->assertCleanWorktree();

        if ($options['dry_run']) {
            $this->write('');
            $this->write('Simulación completada: no se ha creado ni subido ninguna etiqueta.');
            $this->write(sprintf(
                'Comando previsto: git push --atomic %s HEAD:refs/heads/%s refs/tags/%s',
                $remote,
                $branch,
                $tag
            ));
            return 0;
        }

        if (!$options['yes']) {
            $confirmed = $this->confirm(sprintf(
                'Pulsa Enter para crear %s y subir rama + etiqueta; escribe "no" para cancelar: ',
                $tag
            ));

            if (!$confirmed) {
                $this->write('Release cancelada. No se ha modificado Git.');
                return 0;
            }
        }

        if (!$options['no_fetch']) {
            $this->runChecked(['git', 'fetch', '--tags', '--prune', $remote], 'Falló la comprobación final del remoto.');
            [$behind] = $this->branchDistance($remote, $branch);

            if ($behind > 0) {
                throw new \RuntimeException('El remoto cambió durante las validaciones. Integra los cambios y repite.');
            }
        }

        $this->assertReleaseHead($branch, $headOid);
        $this->assertCleanWorktree();
        $this->assertTagIsStillAvailable($tag);
        $this->runChecked(
            ['git', 'tag', '-a', $tag, $headOid, '-m', sprintf('Release %s', $tag)],
            'No se pudo crear la etiqueta.'
        );

        $push = $this->runProcess([
            'git',
            'push',
            '--atomic',
            $remote,
            sprintf('%s:refs/heads/%s', $headOid, $branch),
            sprintf('refs/tags/%s', $tag),
        ]);

        if ($push->exitCode !== 0) {
            $this->runProcess(['git', 'tag', '-d', $tag]);
            throw new \RuntimeException(sprintf(
                "El push atómico falló y se retiró la etiqueta local recién creada.\n%s",
                trim($push->output)
            ));
        }

        if (trim($push->output) !== '') {
            $this->write(trim($push->output));
        }

        $this->write('');
        $this->write(sprintf('Release %s publicada correctamente.', $tag));
        $this->write('Packagist recibirá el push mediante el webhook configurado en GitHub.');

        return 0;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{
     *     version: string|null,
     *     bump: string,
     *     yes: bool,
     *     dry_run: bool,
     *     no_fetch: bool,
     *     skip_tests: bool,
     *     help: bool
     * }
     */
    private function parseOptions(array $arguments): array
    {
        $options = [
            'version'    => null,
            'bump'       => 'patch',
            'yes'        => false,
            'dry_run'    => false,
            'no_fetch'   => false,
            'skip_tests' => false,
            'help'       => false,
        ];

        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = $arguments[$index];

            if ($argument === '--yes' || $argument === '-y') {
                $options['yes'] = true;
                continue;
            }

            if ($argument === '--dry-run') {
                $options['dry_run'] = true;
                continue;
            }

            if ($argument === '--no-fetch') {
                $options['no_fetch'] = true;
                continue;
            }

            if ($argument === '--skip-tests') {
                $options['skip_tests'] = true;
                continue;
            }

            if ($argument === '--help' || $argument === '-h') {
                $options['help'] = true;
                continue;
            }

            if (str_starts_with($argument, '--version=')) {
                $options['version'] = substr($argument, strlen('--version='));
                continue;
            }

            if ($argument === '--version' && isset($arguments[$index + 1])) {
                $options['version'] = $arguments[++$index];
                continue;
            }

            if (str_starts_with($argument, '--bump=')) {
                $options['bump'] = substr($argument, strlen('--bump='));
                continue;
            }

            if ($argument === '--bump' && isset($arguments[$index + 1])) {
                $options['bump'] = $arguments[++$index];
                continue;
            }

            throw new \RuntimeException(sprintf('Opción desconocida: %s', $argument));
        }

        if (!in_array($options['bump'], ['patch', 'minor', 'major'], true)) {
            throw new \RuntimeException('--bump debe ser patch, minor o major.');
        }

        return $options;
    }

    private function assertCoreRepository(): void
    {
        $composerPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'composer.json';
        $composerRaw = @file_get_contents($composerPath);
        $composer = is_string($composerRaw) ? json_decode($composerRaw, true) : null;

        if (!is_array($composer) || ($composer['name'] ?? null) !== self::PACKAGE_NAME) {
            throw new \RuntimeException(sprintf(
                'Este comando solo puede ejecutarse desde el repositorio %s.',
                self::PACKAGE_NAME
            ));
        }

        if ($this->gitOutput(['rev-parse', '--is-inside-work-tree']) !== 'true') {
            throw new \RuntimeException('La carpeta no es un repositorio Git.');
        }

        $gitRoot = realpath($this->gitOutput(['rev-parse', '--show-toplevel']));

        if ($gitRoot === false || !$this->pathsEqual($gitRoot, $this->projectRoot)) {
            throw new \RuntimeException('El script debe residir en la raíz real del repositorio CORE.');
        }
    }

    private function assertCleanWorktree(): void
    {
        $status = $this->gitOutput(['status', '--porcelain', '--untracked-files=normal']);

        if ($status !== '') {
            throw new \RuntimeException(
                "El árbol de trabajo no está limpio. Haz commit o guarda los cambios antes de publicar:\n" . $status
            );
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function branchDistance(string $remote, string $branch): array
    {
        $remoteReference = sprintf('refs/remotes/%s/%s', $remote, $branch);
        $referenceCheck = $this->runProcess(['git', 'show-ref', '--verify', '--quiet', $remoteReference]);

        if ($referenceCheck->exitCode !== 0) {
            throw new \RuntimeException(sprintf('No existe la referencia remota %s/%s.', $remote, $branch));
        }

        $distance = $this->gitOutput([
            'rev-list',
            '--left-right',
            '--count',
            sprintf('%s/%s...HEAD', $remote, $branch),
        ]);
        $parts = preg_split('/\s+/', trim($distance));

        if (!is_array($parts) || count($parts) !== 2) {
            throw new \RuntimeException(sprintf('No se pudo interpretar la distancia con %s/%s.', $remote, $branch));
        }

        return [(int) $parts[0], (int) $parts[1]];
    }

    /**
     * @param array{
     *     patch: Version,
     *     minor: Version,
     *     major: Version
     * } $suggestions
     * @param array{tag: string, version: Version}|null $latest
     */
    private function printSuggestions(?array $latest, array $suggestions): void
    {
        $this->write('');

        if ($latest === null) {
            $this->write('No se encontraron etiquetas estables previas.');
        } else {
            $normalized = $latest['version']->tag();
            $suffix = $normalized !== $latest['tag']
                ? sprintf(' (interpretada como %s)', $normalized)
                : '';
            $this->write(sprintf('Última etiqueta: %s%s', $latest['tag'], $suffix));
        }

        $this->write(sprintf('  Patch: %s', $suggestions['patch']->tag()));
        $this->write(sprintf('  Minor: %s', $suggestions['minor']->tag()));
        $this->write(sprintf('  Major: %s', $suggestions['major']->tag()));
        $this->write('');
    }

    private function runChecks(bool $skipTests): void
    {
        $this->write('Validando composer.json...');
        $this->runChecked(
            [...$this->composerCommand(), 'validate', '--strict', '--no-check-publish'],
            'composer validate ha fallado.',
            true
        );

        if ($skipTests) {
            $this->write('Pruebas omitidas expresamente mediante --skip-tests.');
            return;
        }

        if (!is_file($this->projectRoot . '/vendor/autoload.php')) {
            throw new \RuntimeException(
                'Faltan las dependencias de desarrollo. Ejecuta composer install una vez y repite la release.'
            );
        }

        $this->write('Ejecutando la suite de CORE...');
        $this->runChecked(
            [...$this->composerCommand(), 'test'],
            'La suite de CORE ha fallado.',
            true
        );
    }

    private function assertTagIsStillAvailable(string $tag): void
    {
        $localCheck = $this->runProcess(['git', 'show-ref', '--verify', '--quiet', sprintf('refs/tags/%s', $tag)]);

        if ($localCheck->exitCode === 0) {
            throw new \RuntimeException(sprintf('La etiqueta %s apareció mientras se preparaba la release.', $tag));
        }
    }

    private function assertReleaseHead(string $expectedBranch, string $expectedHead): void
    {
        $currentBranch = $this->gitOutput(['branch', '--show-current']);
        $currentHead = $this->gitOutput(['rev-parse', 'HEAD']);

        if ($currentBranch !== $expectedBranch || $currentHead !== $expectedHead) {
            throw new \RuntimeException(
                'La rama o el commit cambiaron durante las validaciones. Revisa el repositorio y repite la release.'
            );
        }
    }

    private function redactRemoteUrl(string $remoteUrl): string
    {
        $redacted = preg_replace(
            '#\A([a-z][a-z0-9+.-]*://)[^/@\s]+@#i',
            '$1***@',
            trim($remoteUrl)
        );

        return is_string($redacted) ? $redacted : '(url oculta)';
    }

    /**
     * @return list<string>
     */
    private function composerCommand(): array
    {
        $composerBinary = getenv('COMPOSER_BINARY');

        if (is_string($composerBinary) && $composerBinary !== '' && is_file($composerBinary)) {
            if (strtolower(pathinfo($composerBinary, PATHINFO_EXTENSION)) === 'bat') {
                $composerPhar = dirname($composerBinary) . DIRECTORY_SEPARATOR . 'composer.phar';

                if (is_file($composerPhar)) {
                    return [PHP_BINARY, $composerPhar];
                }
            }

            return strtolower(pathinfo($composerBinary, PATHINFO_EXTENSION)) === 'phar'
                ? [PHP_BINARY, $composerBinary]
                : [$composerBinary];
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = getenv('PATH');

            if (is_string($path)) {
                foreach (explode(PATH_SEPARATOR, $path) as $directory) {
                    $composerPhar = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'composer.phar';

                    if (is_file($composerPhar)) {
                        return [PHP_BINARY, $composerPhar];
                    }
                }
            }
        }

        return ['composer'];
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    private function gitLines(array $arguments): array
    {
        $output = $this->gitOutput($arguments);

        if ($output === '') {
            return [];
        }

        return preg_split('/\R/', $output) ?: [];
    }

    /**
     * @param list<string> $arguments
     */
    private function gitOutput(array $arguments): string
    {
        return trim($this->runChecked(['git', ...$arguments], 'Git ha devuelto un error.'));
    }

    /**
     * @param list<string> $command
     */
    private function runChecked(array $command, string $message, bool $showOutput = false): string
    {
        $result = $this->runProcess($command);

        if ($result->exitCode !== 0) {
            throw new \RuntimeException(sprintf("%s\n%s", $message, trim($result->output)));
        }

        if ($showOutput && trim($result->output) !== '') {
            $this->write(trim($result->output));
        }

        return $result->output;
    }

    /**
     * @param list<string> $command
     */
    private function runProcess(array $command): ProcessResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ];
        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->projectRoot,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            return new ProcessResult(1, sprintf('No se pudo ejecutar: %s', implode(' ', $command)));
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return new ProcessResult($exitCode, is_string($output) ? $output : '');
    }

    private function prompt(string $message, string $default): string
    {
        if ($this->promptHandler !== null) {
            $input = ($this->promptHandler)($message, $default);
        } else {
            fwrite(STDOUT, $message);
            $input = $this->readInputLine();
        }

        if ($input === false) {
            throw new \RuntimeException(
                'No se pudo leer la consola interactiva. En automatizaciones usa --version y --yes.'
            );
        }

        $input = trim($input);

        return $input !== '' ? $input : $default;
    }

    private function confirm(string $message): bool
    {
        if ($this->confirmationHandler !== null) {
            return ($this->confirmationHandler)($message);
        }

        fwrite(STDOUT, $message);
        $input = $this->readInputLine();

        if ($input === false) {
            throw new \RuntimeException(
                'No se pudo leer la confirmación. En automatizaciones usa --version y --yes.'
            );
        }

        $answer = strtolower(trim($input));

        return in_array($answer, ['', 's', 'si', 'sí', 'y', 'yes'], true);
    }

    private function readInputLine(): string|false
    {
        return fgets(STDIN);
    }

    private function pathsEqual(string $first, string $second): bool
    {
        $first = str_replace('\\', '/', rtrim($first, '/\\'));
        $second = str_replace('\\', '/', rtrim($second, '/\\'));

        if (DIRECTORY_SEPARATOR === '\\') {
            return strtolower($first) === strtolower($second);
        }

        return $first === $second;
    }

    private function write(string $message): void
    {
        if ($this->writeHandler !== null) {
            ($this->writeHandler)($message);
            return;
        }

        fwrite(STDOUT, $message . PHP_EOL);
    }

    private function printHelp(): void
    {
        $this->write('Publica una release estable y anotada de liquidstack/core.');
        $this->write('');
        $this->write('Uso:');
        $this->write('  composer release');
        $this->write('  composer release -- --bump=minor');
        $this->write('  composer release -- --version=v1.5.0');
        $this->write('');
        $this->write('Opciones:');
        $this->write('  --bump=patch|minor|major  Sugerencia inicial (patch por defecto).');
        $this->write('  --version=vX.Y.Z         Usa una versión concreta.');
        $this->write('  --dry-run                Valida y muestra el push sin crear el tag.');
        $this->write('  --skip-tests             Omite la suite; usar solo de forma excepcional.');
        $this->write('  --yes, -y                Confirma sin interacción.');
        $this->write('  --no-fetch               No actualiza referencias remotas.');
    }
}

function main(array $arguments): int
{
    try {
        return (new ReleaseCommand(dirname(__DIR__)))->run($arguments);
    } catch (\Throwable $exception) {
        fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main(array_slice($argv, 1)));
}
