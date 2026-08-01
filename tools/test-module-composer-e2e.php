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
$e2eSecurityKey = rtrim(strtr(
    base64_encode(str_repeat('E', 32)),
    '+/',
    '-_'
), '=');
$filesystem = new Filesystem();
$composerFilesystem = new ComposerFilesystem();
$filesystem->mkdir($temporaryRoot);
$filesystem->mkdir([
    $temporaryRoot . '/App/config',
    $temporaryRoot . '/src/scss',
]);
$filesystem->dumpFile(
    $temporaryRoot . '/App/config/config.php',
    "<?php\n"
);
$filesystem->dumpFile(
    $temporaryRoot . '/App/config/langs.php',
    "<?php\nreturn ['es', 'en'];\n"
);
$filesystem->dumpFile(
    $temporaryRoot . '/src/scss/_config.scss',
    '$color00: #fff;' . PHP_EOL
);
$filesystem->dumpFile(
    $temporaryRoot . '/.env',
    "BBDD_SERVER=localhost\n"
        . "BBDD_USER=e2e\n"
        . "BBDD_PASS=module-e2e-secret\n"
        . "BBDD_NAME=liquidstack_e2e\n"
        . "LIQUIDSTACK_WEBADMIN_SECURITY_KEY={$e2eSecurityKey}\n"
        . "LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN=https://module-e2e.example.test\n"
);

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

/**
 * @param list<string> $arguments
 */
$runComposerExpectingFailure = static function (array $arguments) use (
    $temporaryRoot
): string {
    $process = new Process(
        array_merge(['composer'], $arguments),
        $temporaryRoot
    );
    $process->setTimeout(180);
    $process->run(static function (string $type, string $buffer): void {
        echo $buffer;
    });

    if ($process->isSuccessful()) {
        throw new RuntimeException(sprintf(
            'Se esperaba que `%s` fallara de forma segura.',
            $process->getCommandLine()
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

    $commandList = json_decode(
        trim($runComposer([
            'list',
            '--format=json',
            '--no-interaction',
        ])),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $commandNames = array_map(
        static fn (array $command): mixed => $command['name'] ?? null,
        is_array($commandList['commands'] ?? null)
            ? $commandList['commands']
            : []
    );
    if (!in_array(
        'liquidstack:webadmin:bootstrap',
        $commandNames,
        true
    )) {
        throw new RuntimeException(
            'El consumidor no recibió el comando de bootstrap WebAdmin.'
        );
    }

    $snapshotProject = static function (string $root): array {
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr(
                $path,
                strlen(str_replace('\\', '/', $root)) + 1
            );
            if (str_starts_with($relative, 'vendor/')) {
                continue;
            }

            $hashes[$relative] = hash_file('sha256', $file->getPathname())
                ?: '';
        }
        ksort($hashes);

        return $hashes;
    };
    $beforeReadOnlyCommands = $snapshotProject($temporaryRoot);

    $doctorOutput = trim($runComposerExpectingFailure([
        'liquidstack:doctor',
        '--format=json',
        '--no-interaction',
    ]));
    $doctor = json_decode(
        $doctorOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($doctor['ok'] ?? null) !== false
        || ($doctor['modules']['requested'] ?? null) !== ['blog']
        || ($doctor['modules']['enabled'] ?? null) !== ['webadmin', 'blog']
        || ($doctor['migrations']['read_only'] ?? false) !== true
        || ($doctor['module_diagnostics']['webadmin']['readiness']['database_connection'] ?? null)
            !== 'unavailable'
        || ($doctor['module_diagnostics']['webadmin']['readiness']['runtime_ready'] ?? null)
            !== false
        || ($doctor['module_diagnostics']['blog']['configuration']['ready'] ?? null)
            !== true
        || ($doctor['module_diagnostics']['blog']['environment']['public_origin']['ready'] ?? null)
            !== true
        || ($doctor['module_diagnostics']['blog']['readiness']['blog_ready'] ?? null)
            !== false
        || str_contains($doctorOutput, 'module-e2e-secret')
        || str_contains($doctorOutput, $e2eSecurityKey)
    ) {
        throw new RuntimeException(
            'LiquidStack doctor no devolvió el diagnóstico seguro esperado.'
        );
    }

    $migrationOutput = trim($runComposer([
        'liquidstack:migrate',
        '--plan',
        '--format=json',
        '--no-interaction',
    ]));
    $migrationPlan = json_decode(
        $migrationOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($migrationPlan['ok'] ?? false) !== true
        || ($migrationPlan['operation'] ?? null) !== 'migrate-plan'
        || ($migrationPlan['migrations']['read_only'] ?? false) !== true
        || ($migrationPlan['migrations']['database_state'] ?? null)
            !== 'not_evaluated'
        || ($migrationPlan['migrations']['count'] ?? null) !== 3
        || ($migrationPlan['migrations']['entries'][0]['module'] ?? null)
            !== 'webadmin'
        || ($migrationPlan['migrations']['entries'][1]['module'] ?? null)
            !== 'blog'
        || ($migrationPlan['migrations']['entries'][1]['id'] ?? null)
            !== '0001_blog_posts'
        || ($migrationPlan['migrations']['entries'][2]['module'] ?? null)
            !== 'blog'
        || ($migrationPlan['migrations']['entries'][2]['id'] ?? null)
            !== '0002_blog_capabilities'
        || str_contains($migrationOutput, $e2eSecurityKey)
    ) {
        throw new RuntimeException(
            'El plan de migraciones no respetó el contrato read-only.'
        );
    }

    $bootstrapWithoutConfirmationOutput = trim(
        $runComposerExpectingFailure([
            'liquidstack:webadmin:bootstrap',
            '--format=json',
            '--no-interaction',
        ])
    );
    $bootstrapWithoutConfirmation = json_decode(
        $bootstrapWithoutConfirmationOutput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($bootstrapWithoutConfirmation['ok'] ?? null) !== false
        || ($bootstrapWithoutConfirmation['error']['code'] ?? null)
            !== 'webadmin.bootstrap.json_requires_yes'
        || str_contains(
            $bootstrapWithoutConfirmationOutput,
            'module-e2e-secret'
        )
    ) {
        throw new RuntimeException(
            'El bootstrap no respetó su gate de confirmación seguro.'
        );
    }

    if ($beforeReadOnlyCommands !== $snapshotProject($temporaryRoot)) {
        throw new RuntimeException(
            'Los comandos de diagnóstico o sus gates modificaron el consumidor.'
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

    $coreOnlyDoctor = json_decode(
        trim($runComposer([
            'liquidstack:doctor',
            '--format=json',
            '--no-interaction',
        ])),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (
        ($coreOnlyDoctor['modules']['requested'] ?? null) !== []
        || ($coreOnlyDoctor['modules']['enabled'] ?? null) !== []
        || isset($coreOnlyDoctor['module_diagnostics']['webadmin'])
    ) {
        throw new RuntimeException(
            'Doctor no volvió al estado Core-only tras retirar Blog.'
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
