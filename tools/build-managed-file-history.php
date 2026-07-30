<?php

declare(strict_types=1);

use App\Core\Composer\ManagedFileRegistry;

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

try {
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

        foreach (explode("\0", rtrim($tree, "\0")) as $record) {
            if (
                preg_match(
                    '/\A[0-9]+ blob ([a-f0-9]+)\t(.+)\z/s',
                    $record,
                    $matches
                ) !== 1
            ) {
                continue;
            }

            $objectId = $matches[1];
            $sourceId = ManagedFileRegistry::normalizePath($matches[2]);

            if (
                ManagedFileRegistry::policyForSource($sourceId)
                    !== ManagedFileRegistry::POLICY_MANAGED
            ) {
                continue;
            }

            $objectPaths[$objectId][$sourceId] = true;
        }
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
