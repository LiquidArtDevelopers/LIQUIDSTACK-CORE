<?php

declare(strict_types=1);

/**
 * Router exclusivo del servidor integrado de PHP durante el desarrollo.
 *
 * Uso desde la raiz del proyecto:
 * php -S localhost:1309 -t public App/tools/php-dev-router.php
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);

    if (defined('STDERR')) {
        fwrite(STDERR, "php-dev-router.php solo puede ejecutarse con php -S.\n");
    }

    exit(1);
}

$projectRoot = realpath(dirname(__DIR__, 2));
$publicRoot = $projectRoot !== false
    ? realpath($projectRoot . DIRECTORY_SEPARATOR . 'public')
    : false;
$frontController = $publicRoot !== false
    ? realpath($publicRoot . DIRECTORY_SEPARATOR . 'index.php')
    : false;
$documentRoot = isset($_SERVER['DOCUMENT_ROOT'])
    && is_string($_SERVER['DOCUMENT_ROOT'])
    ? realpath($_SERVER['DOCUMENT_ROOT'])
    : false;

$normalizeCanonicalPath = static function (string $path): string {
    $normalized = rtrim(
        str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path),
        DIRECTORY_SEPARATOR
    );

    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
};

$isCanonicalChild = static function (
    string $candidate,
    string $directory
) use ($normalizeCanonicalPath): bool {
    $candidate = $normalizeCanonicalPath($candidate);
    $directory = $normalizeCanonicalPath($directory);

    return $candidate !== $directory
        && str_starts_with($candidate, $directory . DIRECTORY_SEPARATOR);
};

if (
    $publicRoot === false
    || !is_dir($publicRoot)
    || $documentRoot === false
    || $normalizeCanonicalPath($documentRoot)
        !== $normalizeCanonicalPath($publicRoot)
    || $frontController === false
    || !is_file($frontController)
    || !$isCanonicalChild($frontController, $publicRoot)
) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Development server configuration error.\n";
    exit(1);
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = null;

if (is_string($requestUri) && $requestUri !== '') {
    try {
        $parsedPath = parse_url($requestUri, PHP_URL_PATH);
        if (is_string($parsedPath) && !str_contains($parsedPath, "\0")) {
            $requestPath = rawurldecode($parsedPath);
        }
    } catch (Throwable) {
        // Una URI no interpretable nunca habilita el servicio nativo de ficheros.
    }
}

if (is_string($requestPath) && !str_contains($requestPath, "\0")) {
    $relativePath = ltrim(
        str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $requestPath),
        DIRECTORY_SEPARATOR
    );

    try {
        $candidate = realpath(
            $publicRoot . DIRECTORY_SEPARATOR . $relativePath
        );
    } catch (Throwable) {
        $candidate = false;
    }

    if (
        $candidate !== false
        && is_file($candidate)
        && $isCanonicalChild($candidate, $publicRoot)
    ) {
        return false;
    }
}

require $frontController;

return true;
