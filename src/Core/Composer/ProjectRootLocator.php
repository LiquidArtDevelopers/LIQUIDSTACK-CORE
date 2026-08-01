<?php

declare(strict_types=1);

namespace App\Core\Composer;

use Composer\Factory;
use RuntimeException;

final class ProjectRootLocator
{
    public static function fromComposerContext(): string
    {
        $composerFile = Factory::getComposerFile();

        if (!self::isAbsolutePath($composerFile)) {
            $cwd = getcwd();
            if (!is_string($cwd) || $cwd === '') {
                throw new RuntimeException(
                    'No se pudo resolver el directorio de trabajo de Composer.'
                );
            }

            $composerFile = $cwd . DIRECTORY_SEPARATOR . $composerFile;
        }

        $composerFile = realpath($composerFile) ?: $composerFile;

        return rtrim(dirname($composerFile), '/\\');
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
