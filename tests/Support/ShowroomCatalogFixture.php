<?php

declare(strict_types=1);

final class ShowroomCatalogFixture
{
    public static function php(string $root): string
    {
        return self::joinFiles(
            $root . '/App/views/_showroom.php',
            $root . '/App/views/showroom/*.php'
        );
    }

    public static function corePhp(string $coreRoot): string
    {
        return self::joinFiles(
            $coreRoot . '/stubs/App/views/_showroom.php',
            $coreRoot . '/stubs/App/views/showroom/*.php'
        );
    }

    public static function scss(string $root): string
    {
        return self::joinFiles(
            $root . '/src/scss/templates.scss',
            $root . '/src/scss/showroom/*.scss'
        );
    }

    public static function javascript(string $root): string
    {
        return self::joinFiles(
            $root . '/src/js/templates.js',
            $root . '/src/js/showroom/*.js'
        );
    }

    private static function joinFiles(string $entrypoint, string $pattern): string
    {
        $contents = (string) file_get_contents($entrypoint);
        $files = glob($pattern);

        if (is_array($files)) {
            sort($files);
            foreach ($files as $file) {
                $contents .= "\n" . (string) file_get_contents($file);
            }
        }

        return $contents;
    }
}
