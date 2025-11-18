#!/usr/bin/env php
<?php

use App\Core\Support\Paths;
use Dotenv\Dotenv;

require_once __DIR__ . '/../../vendor/autoload.php';

Paths::setProjectRoot(dirname(__DIR__, 2));

$dotenv = Dotenv::createImmutable(Paths::projectRoot());
$dotenv->safeLoad();

$arrayRutasGet = require Paths::appPath() . '/config/routes/get.php';

// 2) Verificar que $arrayRutasGet tenga datos válidos
if (empty($arrayRutasGet) || !isset($arrayRutasGet[$_ENV['LANG_DEFAULT'] ?? 'es'])) {
    die("Error: No se encontraron rutas válidas en el idioma por defecto.\n");
}

// 3) Genera el XML con alternates multilingües
generarSitemapMultilingue($arrayRutasGet, Paths::publicPath() . '/');
