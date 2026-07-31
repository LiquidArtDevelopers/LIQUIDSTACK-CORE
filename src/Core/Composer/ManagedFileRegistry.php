<?php

declare(strict_types=1);

namespace App\Core\Composer;

final class ManagedFileRegistry
{
    public const POLICY_IGNORE = 'ignore';
    public const POLICY_MANAGED = 'managed_hash';
    public const POLICY_INSTALL_IF_MISSING = 'install_if_missing';
    public const POLICY_MERGE_JSON_ADDITIVE = 'merge_json_additive';

    /**
     * @var list<string>
     */
    private const INSTALL_IF_MISSING_FILES = [
        'resources/js/_terminos.js',
        'resources/scss/_moduleTerminos.scss',
        'stubs/App/app/_phpmailer.php',
        'stubs/App/app/formContact.php',
        'stubs/App/class/_comprobaciones.php',
        'stubs/App/controllers/footerInfo01.php',
        'stubs/App/templates/_footerInfo01.html',
        'stubs/App/templates/_formContactAdmin.html',
        'stubs/App/templates/_formContactUser.html',
    ];

    /**
     * @var list<string>
     */
    private const INSTALL_IF_MISSING_PREFIXES = [
        'resources/img/logos/',
        'stubs/App/config/languages/_email/',
    ];

    /**
     * @var list<string>
     */
    private const DISTRIBUTED_FILES = [
        'src/js/templates.js',
        'src/scss/templates.scss',
        'stubs/App/app/_phpmailer.php',
        'stubs/App/app/formContact.php',
        'stubs/App/app/updateLanguage.php',
        'stubs/App/app/url.php',
        'stubs/App/class/_comprobaciones.php',
        'stubs/App/config/helpers.php',
        'stubs/public/index.php',
        'stubs/tools/liquidstack/vite/update-languages-plugin.mjs',
    ];

    /**
     * @var list<string>
     */
    private const DISTRIBUTED_PREFIXES = [
        'resources/img/',
        'resources/js/',
        'resources/scss/',
        'resources/video/',
        'src/js/showroom/',
        'src/scss/showroom/',
        'stubs/App/config/languages/',
        'stubs/App/controllers/',
        'stubs/App/templates/',
        'stubs/App/tools/',
        'stubs/App/views/',
    ];

    /**
     * @var list<string>
     */
    private const TEXT_EXTENSIONS = [
        'cjs',
        'css',
        'html',
        'js',
        'json',
        'md',
        'mjs',
        'php',
        'scss',
        'svg',
        'toml',
        'ts',
        'txt',
        'vtt',
        'xml',
        'yaml',
        'yml',
    ];

    public static function policyForSource(string $sourceId): string
    {
        $sourceId = self::normalizePath($sourceId);

        if (!self::isDistributedSource($sourceId)) {
            return self::POLICY_IGNORE;
        }

        if (
            in_array($sourceId, self::INSTALL_IF_MISSING_FILES, true)
            || self::matchesPrefix($sourceId, self::INSTALL_IF_MISSING_PREFIXES)
        ) {
            return self::POLICY_INSTALL_IF_MISSING;
        }

        if (
            str_starts_with(
                $sourceId,
                'stubs/App/config/languages/'
            )
            && str_ends_with(strtolower($sourceId), '.json')
        ) {
            return self::POLICY_MERGE_JSON_ADDITIVE;
        }

        return self::POLICY_MANAGED;
    }

    public static function groupForSource(string $sourceId): ?string
    {
        $sourceId = self::normalizePath($sourceId);

        if (in_array($sourceId, [
            'resources/js/_traducciones.js',
            'src/js/templates.js',
            'src/scss/templates.scss',
            'stubs/App/views/_showroom.php',
            'stubs/App/views/_templates.php',
        ], true)) {
            return 'catalog:showroom';
        }

        if (
            str_starts_with($sourceId, 'src/js/showroom/')
            || str_starts_with($sourceId, 'src/scss/showroom/')
            || str_starts_with($sourceId, 'stubs/App/views/showroom/')
        ) {
            return 'catalog:showroom';
        }

        if (in_array($sourceId, [
            'resources/js/_inlineEditor.js',
            'stubs/App/app/updateLanguage.php',
        ], true)) {
            return 'runtime:inline-editor';
        }

        if (in_array($sourceId, [
            'stubs/App/app/url.php',
            'stubs/App/config/helpers.php',
            'stubs/public/index.php',
        ], true)) {
            return 'runtime:bootstrap';
        }

        $patterns = [
            '~^stubs/App/controllers/_?([^/]+)\.php$~',
            '~^stubs/App/templates/_([^/]+)\.html$~',
            '~^resources/js/_([^/]+)\.js$~',
            '~^resources/scss/_([^/]+)\.scss$~',
            '~^resources/img/resources/([^/]+)/~',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sourceId, $matches) !== 1) {
                continue;
            }

            $resourceName = $matches[1] ?? '';

            if ($resourceName !== '') {
                return 'resource:' . $resourceName;
            }
        }

        if (
            preg_match(
                '~^resources/img/([^/]+)/~',
                $sourceId,
                $matches
            ) === 1
            && !in_array(
                $matches[1],
                ['dummy', 'logos', 'resources', 'system'],
                true
            )
        ) {
            return 'resource:' . $matches[1];
        }

        return null;
    }

    public static function isDistributedSource(string $sourceId): bool
    {
        $sourceId = self::normalizePath($sourceId);

        if (basename($sourceId) === '.gitkeep') {
            return false;
        }

        return in_array($sourceId, self::DISTRIBUTED_FILES, true)
            || self::matchesPrefix($sourceId, self::DISTRIBUTED_PREFIXES);
    }

    /**
     * @return list<string>
     */
    public static function fingerprintFile(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf(
                'No se pudo leer el fichero para calcular su huella: %s',
                $path
            ));
        }

        return self::fingerprintContents($path, $contents);
    }

    /**
     * @return list<string>
     */
    public static function fingerprintContents(
        string $path,
        string $contents
    ): array {
        $fingerprints = [
            'sha256:' . hash('sha256', $contents),
        ];

        if (self::isTextPath($path)) {
            $normalized = str_replace(["\r\n", "\r"], "\n", $contents);
            $fingerprints[] = 'sha256:' . hash('sha256', $normalized);

            // Mantener las huellas anteriores y añadir una comparación
            // canónica que ignore únicamente el whitespace del EOF. Así, la
            // ausencia de salto final o varias líneas vacías finales no
            // convierten una copia intacta en una personalización local.
            $normalizedEof = rtrim($normalized, " \t\n") . "\n";
            $fingerprints[] = 'sha256:' . hash(
                'sha256',
                $normalizedEof
            );
        }

        return array_values(array_unique($fingerprints));
    }

    public static function normalizePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param list<string> $prefixes
     */
    private static function matchesPrefix(
        string $path,
        array $prefixes
    ): bool {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isTextPath(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::TEXT_EXTENSIONS, true);
    }
}
