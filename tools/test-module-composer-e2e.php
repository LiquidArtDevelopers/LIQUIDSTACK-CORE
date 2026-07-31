<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Composer\Util\Filesystem as ComposerFilesystem;

require dirname(__DIR__) . '/vendor/autoload.php';

$coreRoot = dirname(__DIR__);
$cleanupPrefix = '--cleanup-path=';
$cleanupArgument = $argv[1] ?? null;
if (
    is_string($cleanupArgument)
    && str_starts_with($cleanupArgument, $cleanupPrefix)
) {
    $cleanupPath = substr($cleanupArgument, strlen($cleanupPrefix));
    $resolvedCleanupPath = realpath($cleanupPath);
    $resolvedSystemTemp = realpath(sys_get_temp_dir());
    $cleanupName = is_string($resolvedCleanupPath)
        ? basename($resolvedCleanupPath)
        : '';

    if (
        !is_string($resolvedCleanupPath)
        || !is_string($resolvedSystemTemp)
        || !str_starts_with(
            strtolower(str_replace('\\', '/', $resolvedCleanupPath)),
            rtrim(
                strtolower(str_replace('\\', '/', $resolvedSystemTemp)),
                '/'
            ) . '/'
        )
        || preg_match(
            '/\A(?:liquidstack-module-e2e-[a-f0-9]{16}|\.!![A-Za-z0-9+_-]+)\z/',
            $cleanupName
        ) !== 1
    ) {
        throw new RuntimeException('Ruta de limpieza temporal no segura.');
    }

    if (!(new ComposerFilesystem())->removeDirectory($resolvedCleanupPath)) {
        throw new RuntimeException(sprintf(
            'No se pudo retirar el consumidor temporal %s.',
            $resolvedCleanupPath
        ));
    }

    fwrite(STDOUT, "TEMP_CLEANED" . PHP_EOL);
    exit(0);
}

$temporaryRoot = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'liquidstack-module-e2e-'
    . bin2hex(random_bytes(8));
$filesystem = new Filesystem();
$composerFilesystem = new ComposerFilesystem();
$filesystem->mkdir($temporaryRoot);

/**
 * @param list<string> $arguments
 */
$runComposer = static function (array $arguments) use ($temporaryRoot): string {
    $process = new Process(
        array_merge(['composer'], $arguments),
        $temporaryRoot
    );
    $process->setTimeout(180);
    $process->run(static function (string $type, string $buffer): void {
        echo $buffer;
    });

    if (!$process->isSuccessful()) {
        throw new RuntimeException(sprintf(
            'Falló `%s` con código %d.',
            $process->getCommandLine(),
            $process->getExitCode()
        ));
    }

    return $process->getOutput() . $process->getErrorOutput();
};

try {
    $runComposer([
        'init',
        '--name=liquidstack/module-e2e',
        '--type=project',
        '--require=liquidstack/core:dev-main',
        '--no-interaction',
    ]);
    $repository = json_encode([
        'type' => 'path',
        'url' => $coreRoot,
        'options' => ['symlink' => false],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $runComposer([
        'config',
        '--json',
        'repositories.liquidstack-core',
        $repository,
    ]);
    $runComposer([
        'config',
        'allow-plugins.liquidstack/core',
        'true',
    ]);
    $runComposer([
        'install',
        '--no-interaction',
        '--no-progress',
    ]);
    $requireOutput = $runComposer([
        'require',
        'liquidstack/blog',
        '--no-interaction',
        '--no-progress',
        '--no-audit',
    ]);
    if (!str_contains(
        $requireOutput,
        'Módulos LiquidStack activos: core, webadmin, blog.'
    )) {
        throw new RuntimeException(
            'El hook post-update no resolvió Blog y WebAdmin sin config SCSS.'
        );
    }

    $composerPath = $temporaryRoot . '/composer.json';
    $composer = json_decode(
        (string) file_get_contents($composerPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (($composer['require']['liquidstack/blog'] ?? null) !== '*') {
        throw new RuntimeException(
            'El selector Blog no quedó registrado con constraint `*`.'
        );
    }

    $lockedPackages = $runComposer(['show', '--locked', '--name-only']);
    if (!str_contains($lockedPackages, 'liquidstack/core')) {
        throw new RuntimeException('CORE no figura en composer.lock.');
    }
    if (str_contains($lockedPackages, 'liquidstack/blog')) {
        throw new RuntimeException(
            'Blog apareció como paquete físico en composer.lock.'
        );
    }

    $runComposer([
        'remove',
        'liquidstack/blog',
        '--no-interaction',
        '--no-progress',
        '--no-audit',
    ]);
    $composer = json_decode(
        (string) file_get_contents($composerPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        isset($composer['require']['liquidstack/blog'])
        || !isset($composer['require']['liquidstack/core'])
    ) {
        throw new RuntimeException(
            'Retirar Blog no conservó el contrato de CORE.'
        );
    }

    fwrite(STDOUT, PHP_EOL . "MODULE_COMPOSER_E2E_OK" . PHP_EOL);
} finally {
    $resolvedTemporaryRoot = realpath($temporaryRoot);
    $resolvedSystemTemp = realpath(sys_get_temp_dir());

    if (
        is_string($resolvedTemporaryRoot)
        && is_string($resolvedSystemTemp)
        && str_starts_with(
            strtolower(str_replace('\\', '/', $resolvedTemporaryRoot)),
            rtrim(
                strtolower(str_replace('\\', '/', $resolvedSystemTemp)),
                '/'
            ) . '/liquidstack-module-e2e-'
        )
    ) {
        if (!$composerFilesystem->removeDirectory($resolvedTemporaryRoot)) {
            throw new RuntimeException(sprintf(
                'No se pudo retirar el consumidor temporal %s.',
                $resolvedTemporaryRoot
            ));
        }
    }
}
