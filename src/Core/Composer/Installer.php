<?php

namespace App\Core\Composer;

use App\Core\Support\Paths;
use Composer\Script\Event;
use Symfony\Component\Filesystem\Filesystem;

class Installer
{
    public static function postInstall(Event $event): void
    {
        self::syncProjectAssets($event);
        self::syncResources($event);
    }

    public static function postUpdate(Event $event): void
    {
        self::syncProjectAssets($event);
        self::syncResources($event);
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
                    $filesystem->mirror($source, $destination, null, [
                        'override' => true,
                        'delete'   => false,
                    ]);
                    $io->write(sprintf('<info>Synced resources to %s</info>', $destination));
                } catch (\Throwable $exception) {
                    $io->writeError(sprintf('<error>Failed to sync %s to %s: %s</error>', $source, $destination, $exception->getMessage()));
                }
            }
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
            ['path' => 'App/app/url.php', 'type' => 'file'],
            ['path' => 'App/controllers', 'type' => 'dir'],
            ['path' => 'App/templates', 'type' => 'dir'],
            ['path' => 'App/tools/build-sitemap.php', 'type' => 'file'],
            ['path' => 'App/tools/update-languages.php', 'type' => 'file'],
            ['path' => 'App/tools', 'type' => 'dir'],
        ];

        $filesystem = new Filesystem();

        foreach ($assets as $asset) {
            $assetPath = $asset['path'];
            $assetType = $asset['type'];

            $source = $stubsDir . '/' . $assetPath;
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

    private static function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
