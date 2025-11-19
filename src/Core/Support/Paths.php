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
        $documentRoot = self::resolveDocumentRoot();
        if ($documentRoot !== null) {
            return $documentRoot;
        }

        $envPath = self::resolveCustomPath('STACK_LIQUID_CORE_PUBLIC_PATH', 'STACK_CORE_PUBLIC_PATH');
        if ($envPath !== null) {
            return $envPath;
        }

        $discovered = self::discoverPublicDirectory();
        if ($discovered !== null) {
            return $discovered;
        }

        return rtrim(self::projectRoot() . DIRECTORY_SEPARATOR . 'public', DIRECTORY_SEPARATOR);
    }

    private static function discoverPublicDirectory(): ?string
    {
        $projectRoot = self::projectRoot();
        $roots       = [$projectRoot];

        $parent = dirname($projectRoot);
        if ($parent !== '' && $parent !== $projectRoot) {
            $roots[] = $parent;
        }

        $names = [
            'public',
            'Public',
            'public_html',
            'public-html',
            'www',
            'web',
            'htdocs',
            'httpdocs',
        ];

        foreach ($roots as $root) {
            foreach ($names as $name) {
                $candidate = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
                if (!is_dir($candidate)) {
                    continue;
                }

                $resolved = realpath($candidate) ?: $candidate;

                return rtrim($resolved, DIRECTORY_SEPARATOR);
            }
        }

        return null;
    }

    private static function resolveDocumentRoot(): ?string
    {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? ($_ENV['DOCUMENT_ROOT'] ?? null);
        if (!is_string($docRoot) || $docRoot === '') {
            return null;
        }

        $resolved = realpath($docRoot) ?: $docRoot;

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private static function resolveCustomPath(string $primary, string $legacy): ?string
    {
        foreach ([$primary, $legacy] as $var) {
            $value = getenv($var);
            if (!is_string($value) || $value === '') {
                continue;
            }

            $path = self::isAbsolutePath($value)
                ? $value
                : self::projectRoot() . DIRECTORY_SEPARATOR . $value;

            $path = realpath($path) ?: $path;

            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        return null;
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === DIRECTORY_SEPARATOR) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:\\\\/', $path);
    }
}
