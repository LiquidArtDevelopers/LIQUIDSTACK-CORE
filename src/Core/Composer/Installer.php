<?php

namespace App\Core\Composer;

use App\Core\Support\Paths;
use Composer\Script\Event;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Filesystem\Filesystem;

class Installer
{
    public static function postInstall(Event $event): void
    {
        self::syncProjectAssets($event);
        self::syncResources($event);
        self::syncFrontendDependencies($event);
    }

    public static function postUpdate(Event $event): void
    {
        self::syncProjectAssets($event);
        self::syncResources($event);
        self::syncFrontendDependencies($event);
    }

    public static function syncFrontendDependencies(Event $event): void
    {
        $io          = $event->getIO();
        $composer    = $event->getComposer();
        $vendorDir   = rtrim($composer->getConfig()->get('vendor-dir'), DIRECTORY_SEPARATOR);
        $projectRoot = dirname($vendorDir);
        $packageRoot = dirname(__DIR__, 3);

        $coreManifestPath  = $packageRoot . '/package.core.json';
        $projectPackagePath = $projectRoot . '/package.json';

        if (!is_file($coreManifestPath)) {
            $io->writeError(sprintf('<warning>Skipping frontend dependency sync: missing manifest %s</warning>', $coreManifestPath));
            return;
        }

        if (!is_file($projectPackagePath)) {
            $io->write(sprintf('<info>Skipping frontend dependency sync: missing %s</info>', $projectPackagePath));
            return;
        }

        $coreManifest = self::decodeJsonFile($coreManifestPath);
        if ($coreManifest === null) {
            $io->writeError(sprintf('<error>Skipping frontend dependency sync: invalid JSON in %s</error>', $coreManifestPath));
            return;
        }

        $projectPackage = self::decodeJsonFile($projectPackagePath);
        if ($projectPackage === null) {
            $io->writeError(sprintf('<error>Skipping frontend dependency sync: invalid JSON in %s</error>', $projectPackagePath));
            return;
        }

        $sections = ['dependencies', 'devDependencies'];
        $added    = [];

        foreach ($sections as $section) {
            $required = $coreManifest[$section] ?? null;
            if (!is_array($required) || $required === []) {
                continue;
            }

            $hadSection = array_key_exists($section, $projectPackage);
            if (!isset($projectPackage[$section]) || !is_array($projectPackage[$section])) {
                $projectPackage[$section] = [];
            }

            foreach ($required as $name => $version) {
                if (!is_string($name) || $name === '') {
                    continue;
                }

                if (self::dependencyExistsAnywhere($projectPackage, $name)) {
                    continue;
                }

                $normalizedVersion = is_string($version) && $version !== '' ? $version : '*';
                $projectPackage[$section][$name] = $normalizedVersion;
                $added[] = sprintf('%s@%s', $name, $normalizedVersion);
            }

            if ($projectPackage[$section] === [] && !$hadSection) {
                unset($projectPackage[$section]);
            }
        }

        if ($added === []) {
            $io->write('<info>Frontend dependencies already up to date in package.json</info>');
            return;
        }

        $encoded = json_encode($projectPackage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            $io->writeError('<error>Skipping frontend dependency sync: unable to encode merged package.json</error>');
            return;
        }

        $written = @file_put_contents($projectPackagePath, $encoded . PHP_EOL);
        if ($written === false) {
            $io->writeError(sprintf('<error>Failed to write merged dependencies to %s</error>', $projectPackagePath));
            return;
        }

        $io->write(sprintf('<info>Added frontend dependencies to package.json: %s</info>', implode(', ', $added)));
        $io->write('<comment>Run npm install/yarn install/pnpm install to fetch new packages.</comment>');
    }

    public static function syncResources(Event $event): void
    {
        $io         = $event->getIO();
        $composer   = $event->getComposer();
        $vendorDir  = rtrim($composer->getConfig()->get('vendor-dir'), DIRECTORY_SEPARATOR);
        $projectRoot = dirname($vendorDir);

        Paths::setProjectRoot($projectRoot);

        $packageRoot  = dirname(__DIR__, 3);
        $resourcesDir = $packageRoot . '/resources';

        if (!is_dir($resourcesDir)) {
            $io->writeError(sprintf('<warning>Resources directory not found: %s</warning>', $resourcesDir));
            return;
        }

        $targets = self::resolveResourceTargets($projectRoot);
        $filesystem = new Filesystem();

        foreach ($targets as $target) {
            $pairs = [
                $resourcesDir . '/js'   => $target['js'],
                $resourcesDir . '/scss' => $target['scss'],
            ];

            foreach ($pairs as $source => $destination) {
                if (!is_dir($source)) {
                    $io->writeError(sprintf('<warning>Skipping missing resources dir: %s</warning>', $source));
                    continue;
                }

                try {
                    self::mirrorWithoutDeletion($filesystem, $source, $destination);
                    $io->write(sprintf('<info>Synced resources to %s</info>', $destination));
                } catch (\Throwable $exception) {
                    $io->writeError(sprintf('<error>Failed to sync %s to %s: %s</error>', $source, $destination, $exception->getMessage()));
                }
            }
        }

        $imagesSource      = $resourcesDir . '/img';
        $imagesDestination = self::resolveImageResourceTarget($projectRoot);

        if (!is_dir($imagesSource)) {
            return;
        }

        try {
            self::mirrorWithoutDeletion($filesystem, $imagesSource, $imagesDestination);
            $io->write(sprintf('<info>Synced resources to %s</info>', $imagesDestination));
        } catch (\Throwable $exception) {
            $io->writeError(sprintf('<error>Failed to sync %s to %s: %s</error>', $imagesSource, $imagesDestination, $exception->getMessage()));
        }
    }

    private static function syncProjectAssets(Event $event): void
    {
        $io        = $event->getIO();
        $composer  = $event->getComposer();
        $vendorDir = rtrim($composer->getConfig()->get('vendor-dir'), DIRECTORY_SEPARATOR);
        $projectRoot = dirname($vendorDir);

        Paths::setProjectRoot($projectRoot);

        $packageRoot = dirname(__DIR__, 3);
        $stubsDir    = $packageRoot . '/stubs';

        $assets = [
            ['path' => 'public/index.php', 'type' => 'file'],
            ['path' => 'App/config/helpers.php', 'type' => 'file'],
            ['path' => 'App/config/languages', 'type' => 'dir'],
            ['path' => 'App/app/url.php', 'type' => 'file'],
            ['path' => 'App/controllers', 'type' => 'dir'],
            ['path' => 'App/templates', 'type' => 'dir'],
            ['path' => 'App/views', 'type' => 'dir'],
            ['path' => 'App/tools/build-sitemap.php', 'type' => 'file'],
            ['path' => 'App/tools/update-languages.php', 'type' => 'file'],
            ['path' => 'App/tools', 'type' => 'dir'],
            ['path' => 'src/js/templates.js', 'type' => 'file', 'base' => $packageRoot],
            ['path' => 'src/scss/templates.scss', 'type' => 'file', 'base' => $packageRoot],
        ];

        $filesystem = new Filesystem();

        foreach ($assets as $asset) {
            $assetPath = $asset['path'];
            $assetType = $asset['type'];
            $assetBase = $asset['base'] ?? $stubsDir;

            $source = $assetBase . '/' . $assetPath;
            $target = $projectRoot . '/' . $assetPath;

            if ($assetType === 'file' && !is_file($source)) {
                $io->writeError(sprintf('<warning>Skipping missing asset: %s</warning>', $source));
                continue;
            }

            if ($assetType === 'dir' && !is_dir($source)) {
                $io->writeError(sprintf('<warning>Skipping missing directory: %s</warning>', $source));
                continue;
            }

            $filesystem->mkdir(dirname($target), 0775);

            try {
                if ($assetType === 'dir') {
                    $filesystem->mirror($source, $target, null, [
                        'override' => true,
                        'delete'   => false,
                    ]);
                } else {
                    $filesystem->copy($source, $target, true);
                }

                $io->write(sprintf('<info>Synced %s</info>', $assetPath));
            } catch (\Throwable $exception) {
                $io->writeError(sprintf('<error>Failed to copy %s to %s: %s</error>', $source, $target, $exception->getMessage()));
            }
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return self::startsWith($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || self::startsWith($path, '\\\\');
    }

    /**
     * Obtiene los destinos a los que se replicarán los assets front.
     *
     * Por defecto se copian a `src/js/resources` y `src/scss/resources` para
     * que Vite recomponga cualquier archivo eliminado y, además, se mantiene
     * una copia en `vendor/liquidstack/core/resources` para importaciones
     * directas. Si se define la variable de entorno
     * STACK_CORE_RESOURCES_TARGET, se tomará como raíz (absoluta o
     * relativa al proyecto) y se crearán las carpetas `js` y `scss` bajo dicha
     * ruta. Se mantiene compatibilidad con STACK_LIQUID_CORE_RESOURCES_TARGET
     * como alias heredado.
     */
    private static function resolveResourceTargets(string $projectRoot): array
    {
        $configured = getenv('STACK_CORE_RESOURCES_TARGET');

        if (!is_string($configured) || $configured === '') {
            $configured = getenv('STACK_LIQUID_CORE_RESOURCES_TARGET');
        }

        if (is_string($configured) && $configured !== '') {
            $base = self::isAbsolutePath($configured)
                ? rtrim($configured, DIRECTORY_SEPARATOR)
                : $projectRoot . DIRECTORY_SEPARATOR . ltrim($configured, DIRECTORY_SEPARATOR);

            return [[
                'js'   => $base . DIRECTORY_SEPARATOR . 'js',
                'scss' => $base . DIRECTORY_SEPARATOR . 'scss',
            ]];
        }

        return [
            [
                'js'   => $projectRoot . '/src/js/resources',
                'scss' => $projectRoot . '/src/scss/resources',
            ],
            [
                'js'   => $projectRoot . '/vendor/liquidstack/core/resources/js',
                'scss' => $projectRoot . '/vendor/liquidstack/core/resources/scss',
            ],
        ];
    }

    /**
     * Obtiene el destino donde se replicarÃ¡n las imÃ¡genes de recursos.
     *
     * Por defecto se copian en `public/assets/img/resources`. Si se define
     * STACK_CORE_RESOURCES_IMG_TARGET, se tomarÃ¡ como ruta base absoluta o
     * relativa al proyecto. Se mantiene STACK_LIQUID_CORE_RESOURCES_IMG_TARGET
     * como alias heredado.
     */
    private static function resolveImageResourceTarget(string $projectRoot): string
    {
        $configured = getenv('STACK_CORE_RESOURCES_IMG_TARGET');

        if (!is_string($configured) || $configured === '') {
            $configured = getenv('STACK_LIQUID_CORE_RESOURCES_IMG_TARGET');
        }

        if (is_string($configured) && $configured !== '') {
            return self::isAbsolutePath($configured)
                ? rtrim($configured, DIRECTORY_SEPARATOR)
                : $projectRoot . DIRECTORY_SEPARATOR . ltrim($configured, DIRECTORY_SEPARATOR);
        }

        return $projectRoot . DIRECTORY_SEPARATOR . 'public/assets/img/resources';
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    private static function decodeJsonFile(string $path): ?array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function dependencyExistsAnywhere(array $package, string $name): bool
    {
        foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $section) {
            if (!isset($package[$section]) || !is_array($package[$section])) {
                continue;
            }

            if (array_key_exists($name, $package[$section])) {
                return true;
            }
        }

        return false;
    }

    private static function mirrorWithoutDeletion(
        Filesystem $filesystem,
        string $source,
        string $destination
    ): void {
        $filesystem->mkdir($destination, 0775);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $targetPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();

            if ($item->isDir()) {
                $filesystem->mkdir($targetPath, 0775);
                continue;
            }

            $filesystem->mkdir(dirname($targetPath), 0775);

            $shouldCopy = !is_file($targetPath)
                || md5_file($item->getPathname()) !== md5_file($targetPath);

            if ($shouldCopy) {
                $filesystem->copy($item->getPathname(), $targetPath, true);
            }
        }
    }
}
