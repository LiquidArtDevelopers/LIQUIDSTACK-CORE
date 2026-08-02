#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Modules\ModuleRegistry;
use Dotenv\Dotenv;

require_once __DIR__ . '/../../vendor/autoload.php';

Paths::setProjectRoot(dirname(__DIR__, 2));

$dotenv = Dotenv::createImmutable(Paths::projectRoot());
$dotenv->safeLoad();

$arrayRutasGet = require Paths::appPath() . '/config/routes/get.php';

if (empty($arrayRutasGet) || !isset($arrayRutasGet[$_ENV['LANG_DEFAULT'] ?? 'es'])) {
    fwrite(STDERR, "Error: No se encontraron rutas válidas en el idioma por defecto.\n");
    exit(1);
}

$publicPath = Paths::publicPath() . '/';
generarSitemapMultilingue($arrayRutasGet, $publicPath);

$productionHost = getProductionHost();
if ($productionHost === '') {
    fwrite(STDERR, "Error: Define el host de producción en las variables de entorno (por ejemplo RAIZ).\n");
    exit(1);
}

$sitemapUrl = $productionHost . '/sitemap.xml';
$robotsPath = Paths::publicPath() . '/robots.txt';
ensureRobotsTxtHasSitemap($robotsPath, $sitemapUrl);

try {
    $moduleRegistry = ModuleRegistry::forProject(Paths::projectRoot());
    if ($moduleRegistry->isEnabled('blog')) {
        $blogConfig = (new BlogConfigLoader())->load(
            Paths::projectRoot(),
            array_values(array_filter(
                array_keys($arrayRutasGet),
                static fn (mixed $language): bool => is_string($language)
            ))
        );
        ensureRobotsTxtHasSitemap(
            $robotsPath,
            $productionHost . $blogConfig->sitemapPath()
        );
    }
} catch (Throwable) {
    fwrite(
        STDERR,
        "Error: No se pudo validar la declaración del sitemap de Blog. Ejecuta composer liquidstack:doctor.\n"
    );
    exit(1);
}

echo "Sitemap generado y robots.txt actualizado.\n";

function getProductionHost(): string
{
    $candidates = [
        $_ENV['HOST_PRODUCCION'] ?? null,
        $_ENV['HOST_PRODUCTION'] ?? null,
        $_ENV['PRODUCTION_HOST'] ?? null,
        $_ENV['RAIZ'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        $trimmed = trim($candidate);
        if ($trimmed !== '') {
            return rtrim($trimmed, '/');
        }
    }

    return '';
}

function ensureRobotsTxtHasSitemap(string $robotsPath, string $sitemapUrl): void
{
    $directory = dirname($robotsPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("No se pudo crear el directorio para robots.txt: {$directory}");
    }

    $robotsContent = '';
    if (is_file($robotsPath)) {
        $robotsContent = (string) file_get_contents($robotsPath);
    } else {
        $robotsContent = "User-agent: *" . PHP_EOL . "Allow: /" . PHP_EOL . PHP_EOL;
    }

    $robotsContent = normalizeLineEndings($robotsContent);
    $sitemapLine = 'Sitemap: ' . $sitemapUrl;
    $targetPath = parse_url($sitemapUrl, PHP_URL_PATH);
    if (!is_string($targetPath) || $targetPath === '') {
        throw new RuntimeException('La URL del sitemap no contiene una ruta válida.');
    }

    $lines = explode(PHP_EOL, rtrim($robotsContent));
    $normalizedLines = [];
    $targetWritten = false;
    foreach ($lines as $line) {
        if (preg_match('/\Asitemap:\s*(\S+)\s*\z/i', $line, $matches) === 1) {
            $existingPath = parse_url($matches[1], PHP_URL_PATH);
            if (is_string($existingPath) && $existingPath === $targetPath) {
                if (!$targetWritten) {
                    $normalizedLines[] = $sitemapLine;
                    $targetWritten = true;
                }
                continue;
            }
        }
        $normalizedLines[] = $line;
    }

    if (!$targetWritten) {
        while ($normalizedLines !== [] && end($normalizedLines) === '') {
            array_pop($normalizedLines);
        }
        if ($normalizedLines !== []) {
            $normalizedLines[] = '';
        }
        $normalizedLines[] = $sitemapLine;
    }

    $robotsContent = rtrim(implode(PHP_EOL, $normalizedLines)) . PHP_EOL;
    file_put_contents($robotsPath, $robotsContent);
}

function normalizeLineEndings(string $content): string
{
    return (string) preg_replace('/\r\n|\r|\n/', PHP_EOL, $content);
}
