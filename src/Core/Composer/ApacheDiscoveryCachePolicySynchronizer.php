<?php

declare(strict_types=1);

namespace App\Core\Composer;

use Composer\IO\IOInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Adds one CORE-owned cache-policy block without taking ownership of the
 * project's Apache configuration.
 */
final class ApacheDiscoveryCachePolicySynchronizer
{
    public const TARGET = 'public/.htaccess';

    private const OPEN_MARKER =
        '# <liquidstack-core:discovery-cache-policy>';
    private const CLOSE_MARKER =
        '# </liquidstack-core:discovery-cache-policy>';
    private const MAX_FILE_BYTES = 1048576;

    private Filesystem $filesystem;

    public function __construct(
        private readonly IOInterface $io
    ) {
        $this->filesystem = new Filesystem();
    }

    public function sync(string $projectRoot): bool
    {
        $path = $this->safeTargetPath($projectRoot);
        if ($path === null) {
            $this->warn(sprintf(
                'Se preservó la configuración Apache porque %s no es un '
                    . 'destino regular y seguro dentro del proyecto.',
                rtrim($projectRoot, '/\\') . '/' . self::TARGET
            ));

            return false;
        }

        $existed = is_file($path);
        $contents = '';

        if ($existed) {
            $size = @filesize($path);
            if (!is_int($size) || $size > self::MAX_FILE_BYTES) {
                $this->warn(sprintf(
                    'Se preservó la configuración Apache porque %s no se '
                        . 'puede inspeccionar de forma acotada.',
                    $path
                ));

                return false;
            }

            $loaded = @file_get_contents($path);
            if (!is_string($loaded)
                || strlen($loaded) > self::MAX_FILE_BYTES
            ) {
                $this->warn(sprintf(
                    'Se preservó la configuración Apache porque %s no se '
                        . 'pudo leer de forma segura.',
                    $path
                ));

                return false;
            }

            $contents = $loaded;
        }

        $updated = $this->withCanonicalPolicy($contents, $path);
        if ($updated === null) {
            return false;
        }
        if (strlen($updated) > self::MAX_FILE_BYTES) {
            $this->warn(sprintf(
                'Se preservó la configuración Apache porque integrar el '
                    . 'bloque de caché superaría el tamaño máximo: %s',
                $path
            ));

            return false;
        }

        if (hash_equals($contents, $updated)) {
            $this->io->write(
                '<info>La política de revalidación de sitemap.xml y '
                    . 'robots.txt ya está integrada en '
                    . self::TARGET
                    . '.</info>'
            );

            return true;
        }

        $verifiedPath = $this->safeTargetPath($projectRoot);
        if ($verifiedPath === null || $verifiedPath !== $path) {
            $this->warn(sprintf(
                'Se preservó la configuración Apache porque %s cambió '
                    . 'mientras se inspeccionaba.',
                $path
            ));

            return false;
        }

        if ($existed) {
            $current = @file_get_contents($verifiedPath);
            if (!is_string($current) || !hash_equals($contents, $current)) {
                $this->warn(sprintf(
                    'Se preservó la configuración Apache porque %s cambió '
                        . 'mientras se inspeccionaba.',
                    $path
                ));

                return false;
            }
        } elseif (file_exists($verifiedPath) || is_link($verifiedPath)) {
            $this->warn(sprintf(
                'Se preservó la configuración Apache porque %s apareció '
                    . 'mientras se inspeccionaba.',
                $path
            ));

            return false;
        }

        try {
            $this->filesystem->dumpFile($verifiedPath, $updated);
        } catch (\Throwable $exception) {
            $this->warn(sprintf(
                'No se pudo integrar la política de revalidación en %s: %s',
                $path,
                $exception->getMessage()
            ));

            return false;
        }

        $this->io->write(
            '<info>Integrada la política de revalidación de sitemap.xml y '
                . 'robots.txt en '
                . self::TARGET
                . ' sin reemplazar la configuración del proyecto.</info>'
        );

        return true;
    }

    private function withCanonicalPolicy(
        string $contents,
        string $path
    ): ?string {
        $openPositions = $this->exactMarkerPositions(
            $contents,
            self::OPEN_MARKER
        );
        $closePositions = $this->exactMarkerPositions(
            $contents,
            self::CLOSE_MARKER
        );
        $openCount = count($openPositions);
        $closeCount = count($closePositions);
        $openPosition = $openPositions[0] ?? null;
        $closePosition = $closePositions[0] ?? null;

        if (
            ($openCount !== 0 || $closeCount !== 0)
            && (
                $openCount !== 1
                || $closeCount !== 1
                || $openPosition === null
                || $closePosition === null
                || $closePosition < $openPosition
            )
        ) {
            $this->warn(sprintf(
                'Se preservó la configuración Apache porque el bloque de '
                    . 'caché de CORE tiene marcadores incompletos, '
                    . 'duplicados o desordenados: %s',
                $path
            ));

            return null;
        }

        $lineEnding = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $policy = $this->canonicalPolicy($lineEnding);

        if ($openCount === 1
            && $openPosition !== null
            && $closePosition !== null
        ) {
            $length = $closePosition
                + strlen(self::CLOSE_MARKER)
                - $openPosition;

            return substr_replace(
                $contents,
                $policy,
                $openPosition,
                $length
            );
        }

        if ($contents === '') {
            return $policy . $lineEnding;
        }

        $separator = str_ends_with($contents, "\n")
            || str_ends_with($contents, "\r")
                ? $lineEnding
                : $lineEnding . $lineEnding;

        return $contents . $separator . $policy . $lineEnding;
    }

    /** @return list<int> */
    private function exactMarkerPositions(
        string $contents,
        string $marker
    ): array {
        $matches = [];
        $matched = preg_match_all(
            '/^' . preg_quote($marker, '/') . '(?=\r?$)/m',
            $contents,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        if ($matched === false) {
            throw new \RuntimeException(
                'No se pudieron inspeccionar los marcadores de caché.'
            );
        }

        return array_map(
            static fn (array $match): int => (int) $match[1],
            $matches[0] ?? []
        );
    }

    private function canonicalPolicy(string $lineEnding): string
    {
        return implode($lineEnding, [
            self::OPEN_MARKER,
            '# Los documentos de descubrimiento se pueden almacenar, pero',
            '# deben revalidarse para no heredar la caché general de activos.',
            '<IfModule mod_expires.c>',
            '    <FilesMatch "^(?:sitemap\\.xml|robots\\.txt)$">',
            '        ExpiresActive Off',
            '    </FilesMatch>',
            '</IfModule>',
            '',
            '<IfModule mod_headers.c>',
            '    <FilesMatch "^(?:sitemap\\.xml|robots\\.txt)$">',
            '        Header set Cache-Control "public, no-cache, must-revalidate"',
            '        Header unset Expires',
            '    </FilesMatch>',
            '</IfModule>',
            self::CLOSE_MARKER,
        ]);
    }

    private function safeTargetPath(string $projectRoot): ?string
    {
        if ($projectRoot === '' || is_link($projectRoot)) {
            return null;
        }

        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            return null;
        }

        $public = $root . DIRECTORY_SEPARATOR . 'public';
        if (is_link($public)) {
            return null;
        }

        $resolvedPublic = realpath($public);
        if ($resolvedPublic === false
            || !is_dir($resolvedPublic)
            || !$this->pathIsWithinRoot($root, $resolvedPublic)
        ) {
            return null;
        }

        $target = $resolvedPublic . DIRECTORY_SEPARATOR . '.htaccess';
        if (is_link($target)) {
            return null;
        }

        if (!file_exists($target)) {
            return $target;
        }

        $resolvedTarget = realpath($target);

        return $resolvedTarget !== false
            && is_file($resolvedTarget)
            && $this->pathIsWithinRoot($root, $resolvedTarget)
                ? $resolvedTarget
                : null;
    }

    private function pathIsWithinRoot(string $root, string $path): bool
    {
        $normalize = static function (string $value): string {
            $value = str_replace('\\', '/', rtrim($value, '/\\'));

            return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
        };
        $root = $normalize($root);
        $path = $normalize($path);

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function warn(string $message): void
    {
        $this->io->writeError('<warning>' . $message . '</warning>');
    }
}
