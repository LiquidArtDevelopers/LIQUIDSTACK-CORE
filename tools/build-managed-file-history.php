<?php

declare(strict_types=1);

use App\Core\Composer\ManagedFileRegistry;
use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModulePublishedSourceFinder;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(
        STDERR,
        "ERROR: Ejecuta composer install antes de generar el historial.\n"
    );
    exit(1);
}

require $autoload;

$checkOnly = in_array('--check', $argv, true);
$manifestPath = $root . '/manifests/managed-file-history.json';
$legacyBaselinesPath = $root
    . '/manifests/managed-file-legacy-baselines.json';

try {
    $currentModuleManagedFiles =
        ModulePublishedSourceFinder::currentManagedFiles(
            ModuleCatalog::fromCoreRoot($root)
        );

    $tagsRaw = runGit($root, [
        'tag',
        '--list',
        'v*',
        '--sort=version:refname',
    ]);
    $tags = array_values(array_filter(
        preg_split('/\R/', trim($tagsRaw)) ?: [],
        static fn (string $tag): bool => $tag !== ''
    ));

    /**
     * @var array<string, array<string, true>>
     */
    $objectPaths = [];

    foreach ($tags as $tag) {
        $tree = runGit($root, ['ls-tree', '-r', '-z', $tag]);
        $tagFiles = [];

        foreach (explode("\0", rtrim($tree, "\0")) as $record) {
            if (
                preg_match(
                    '/\A([0-9]+) blob ([a-f0-9]+)\t(.+)\z/s',
                    $record,
                    $matches
                ) !== 1
            ) {
                continue;
            }

            $mode = $matches[1];
            $objectId = $matches[2];
            $sourceId = ManagedFileRegistry::normalizePath($matches[3]);
            $tagFiles[$sourceId] = [
                'mode' => $mode,
                'object' => $objectId,
            ];

            if ($mode === '120000') {
                continue;
            }

            if (
                ManagedFileRegistry::policyForSource($sourceId)
                    !== ManagedFileRegistry::POLICY_MANAGED
            ) {
                continue;
            }

            $objectPaths[$objectId][$sourceId] = true;
        }

        addTaggedModuleSources($root, $tagFiles, $objectPaths);
    }

    /**
     * @var array<string, array<string, true>>
     */
    $fingerprintsBySource = [];

    foreach ($objectPaths as $objectId => $paths) {
        $contents = runGit($root, ['cat-file', 'blob', $objectId]);

        foreach (array_keys($paths) as $sourceId) {
            foreach (
                ManagedFileRegistry::fingerprintContents(
                    $sourceId,
                    $contents
                ) as $fingerprint
            ) {
                $fingerprintsBySource[$sourceId][$fingerprint] = true;
            }
        }
    }

    $workingTreeRaw = runGit($root, [
        'ls-files',
        '--cached',
        '--others',
        '--exclude-standard',
        '-z',
    ]);

    foreach (explode("\0", rtrim($workingTreeRaw, "\0")) as $sourceId) {
        $sourceId = ManagedFileRegistry::normalizePath($sourceId);

        if (
            $sourceId === ''
            || ManagedFileRegistry::policyForSource($sourceId)
                !== ManagedFileRegistry::POLICY_MANAGED
        ) {
            continue;
        }

        $sourcePath = $root
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $sourceId);

        if (!is_file($sourcePath) || is_link($sourcePath)) {
            continue;
        }

        foreach (
            ManagedFileRegistry::fingerprintFile($sourcePath)
                as $fingerprint
        ) {
            $fingerprintsBySource[$sourceId][$fingerprint] = true;
        }
    }

    foreach ($currentModuleManagedFiles as $sourceId => $sourcePath) {
        foreach (
            ManagedFileRegistry::fingerprintFile($sourcePath)
                as $fingerprint
        ) {
            $fingerprintsBySource[$sourceId][$fingerprint] = true;
        }
    }

    if (is_file($legacyBaselinesPath)) {
        $legacyBaselines = json_decode(
            (string) file_get_contents($legacyBaselinesPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (
            ($legacyBaselines['schema'] ?? null) !== 1
            || !is_array($legacyBaselines['files'] ?? null)
        ) {
            throw new RuntimeException(
                'El manifiesto de baselines legacy no tiene un contrato válido.'
            );
        }

        foreach ($legacyBaselines['files'] as $sourceId => $fingerprints) {
            $sourceId = ManagedFileRegistry::normalizePath((string) $sourceId);

            $isBaseManaged = ManagedFileRegistry::policyForSource($sourceId)
                === ManagedFileRegistry::POLICY_MANAGED;
            $isDeclaredModuleManaged = array_key_exists(
                $sourceId,
                $currentModuleManagedFiles
            );

            if (
                (!$isBaseManaged && !$isDeclaredModuleManaged)
                || !is_array($fingerprints)
            ) {
                throw new RuntimeException(
                    "Baseline legacy no gestionado o inválido: {$sourceId}"
                );
            }

            foreach ($fingerprints as $fingerprint) {
                if (
                    !is_string($fingerprint)
                    || preg_match('/\Asha256:[a-f0-9]{64}\z/', $fingerprint)
                        !== 1
                ) {
                    throw new RuntimeException(
                        "Huella legacy inválida para {$sourceId}"
                    );
                }

                $fingerprintsBySource[$sourceId][$fingerprint] = true;
            }
        }
    }

    ksort($fingerprintsBySource, SORT_STRING);
    $files = [];

    foreach ($fingerprintsBySource as $sourceId => $fingerprints) {
        $values = array_keys($fingerprints);
        sort($values, SORT_STRING);
        $files[$sourceId] = $values;
    }

    $manifest = [
        'schema' => 1,
        'algorithm' => 'sha256-eol-lf-v1',
        'files' => $files,
    ];
    $encoded = json_encode(
        $manifest,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    if ($checkOnly) {
        $current = is_file($manifestPath)
            ? file_get_contents($manifestPath)
            : false;

        if ($current !== $encoded) {
            fwrite(
                STDERR,
                "ERROR: El historial gestionado está desactualizado. "
                    . "Ejecuta php tools/build-managed-file-history.php\n"
            );
            exit(1);
        }

        fwrite(STDOUT, "Historial gestionado actualizado.\n");
        exit(0);
    }

    if (!is_dir(dirname($manifestPath))) {
        mkdir(dirname($manifestPath), 0775, true);
    }

    if (file_put_contents($manifestPath, $encoded) === false) {
        throw new RuntimeException(
            'No se pudo escribir ' . $manifestPath
        );
    }

    fwrite(
        STDOUT,
        sprintf(
            "Historial generado: %d rutas gestionadas en %s\n",
            count($files),
            $manifestPath
        )
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @param list<string> $arguments
 */
function runGit(string $workingDirectory, array $arguments): string
{
    $command = array_merge(['git'], $arguments);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        $command,
        $descriptorSpec,
        $pipes,
        $workingDirectory,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo ejecutar Git.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $stdout === false) {
        throw new RuntimeException(
            trim((string) $stderr)
                ?: 'Git terminó con código ' . $exitCode
        );
    }

    return $stdout;
}

/**
 * @param array<string, array{mode: string, object: string}> $tagFiles
 * @param array<string, array<string, true>> $objectPaths
 */
function addTaggedModuleSources(
    string $root,
    array $tagFiles,
    array &$objectPaths
): void {
    foreach ($tagFiles as $manifestPath => $manifestBlob) {
        if (
            preg_match(
                '~\Amodules/([a-z][a-z0-9-]*)/module\.json\z~',
                $manifestPath,
                $matches
            ) !== 1
        ) {
            continue;
        }

        $moduleId = $matches[1];
        $manifest = json_decode(
            runGit($root, ['cat-file', 'blob', $manifestBlob['object']]),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (
            !is_array($manifest)
            || ($manifest['id'] ?? null) !== $moduleId
            || !is_array($manifest['project_files'] ?? null)
        ) {
            throw new RuntimeException(sprintf(
                'Manifiesto modular histórico inválido: %s.',
                $manifestPath
            ));
        }

        foreach ($manifest['project_files'] as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException(sprintf(
                    'project_files histórico inválido en %s.',
                    $manifestPath
                ));
            }
            if (($entry['policy'] ?? 'managed_hash') !== 'managed_hash') {
                continue;
            }

            $source = normalizeTaggedModuleSource(
                $entry['source'] ?? null,
                $manifestPath
            );
            $sourceId = 'modules/' . $moduleId . '/' . $source;
            $type = $entry['type'] ?? 'file';

            if ($type === 'file') {
                $file = $tagFiles[$sourceId] ?? null;
                if (!is_array($file) || $file['mode'] === '120000') {
                    throw new RuntimeException(sprintf(
                        'Falta el origen modular histórico %s.',
                        $sourceId
                    ));
                }
                $objectPaths[$file['object']][$sourceId] = true;
                continue;
            }
            if ($type !== 'dir') {
                throw new RuntimeException(sprintf(
                    'Tipo modular histórico inválido en %s.',
                    $manifestPath
                ));
            }

            $prefix = $sourceId . '/';
            foreach ($tagFiles as $path => $file) {
                if (
                    $file['mode'] !== '120000'
                    && str_starts_with($path, $prefix)
                ) {
                    $objectPaths[$file['object']][$path] = true;
                }
            }
        }
    }
}

function normalizeTaggedModuleSource(
    mixed $source,
    string $manifestPath
): string {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException(sprintf(
            'Origen modular histórico inválido en %s.',
            $manifestPath
        ));
    }

    $source = str_replace('\\', '/', $source);
    $segments = explode('/', $source);
    if (
        str_starts_with($source, '/')
        || in_array('', $segments, true)
        || in_array('.', $segments, true)
        || in_array('..', $segments, true)
        || preg_match('/[\x00-\x1F\x7F:]/', $source) === 1
    ) {
        throw new RuntimeException(sprintf(
            'Origen modular histórico inseguro en %s.',
            $manifestPath
        ));
    }

    return $source;
}
