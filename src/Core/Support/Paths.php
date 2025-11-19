<?php

namespace App\Core\Support;

class Paths
{
    private static ?string $projectRoot = null;

    public static function setProjectRoot(string $path): void
    {
        $real = realpath($path) ?: $path;
        self::$projectRoot = rtrim($real, DIRECTORY_SEPARATOR);
    }

    public static function projectRoot(): string
    {
        if (self::$projectRoot !== null) {
            return self::$projectRoot;
        }

        $envRoot = getenv('STACK_LIQUID_CORE_PROJECT_ROOT');
        if (!is_string($envRoot) || $envRoot === '') {
            $envRoot = getenv('STACK_CORE_PROJECT_ROOT');
        }

        if (is_string($envRoot) && $envRoot !== '') {
            return self::$projectRoot = rtrim($envRoot, DIRECTORY_SEPARATOR);
        }

        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            $composerPath = $dir . DIRECTORY_SEPARATOR . 'composer.json';
            $isVendorPath = str_contains($dir, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);

            if (is_file($composerPath) && !$isVendorPath) {
                return self::$projectRoot = rtrim(realpath($dir) ?: $dir, DIRECTORY_SEPARATOR);
            }

            $dir = dirname($dir);
        }

        // Fallback to the ancestor outside vendor if no composer.json without vendor was found.
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            $dir = dirname($dir);
            if (!str_contains($dir, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                break;
            }
        }

        return self::$projectRoot = rtrim($dir, DIRECTORY_SEPARATOR);
    }

    public static function appPath(): string
    {
        return self::projectRoot() . DIRECTORY_SEPARATOR . 'App';
    }

    public static function publicPath(): string
    {
        return self::projectRoot() . DIRECTORY_SEPARATOR . 'public';
    }
}
