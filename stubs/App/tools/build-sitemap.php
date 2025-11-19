#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Support\Paths;
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
    $pattern = '/^sitemap:\s*[^\n]*sitemap\.xml[^\n]*$/im';

    if (preg_match($pattern, $robotsContent)) {
        $robotsContent = (string) preg_replace($pattern, $sitemapLine, $robotsContent);
    } elseif (!str_contains(strtolower($robotsContent), strtolower($sitemapLine))) {
        $robotsContent = rtrim($robotsContent);
        if ($robotsContent !== '') {
            $robotsContent .= PHP_EOL . PHP_EOL;
        }
        $robotsContent .= $sitemapLine . PHP_EOL;
    }

    $robotsContent = rtrim($robotsContent) . PHP_EOL;
    file_put_contents($robotsPath, $robotsContent);
}

function normalizeLineEndings(string $content): string
{
    return str_replace(["\r\n", "\r"], PHP_EOL, $content);
}
