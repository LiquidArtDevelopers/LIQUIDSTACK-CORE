<?php

namespace App\Core\Support {

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Fiber;
use Imagick;
use IntlDateFormatter;
use RuntimeException;

/**
 * Renderiza un template sustituyendo placeholders.
 *
 * @param string $tpl   Ruta al template HTML.
 * @param array  $vars  ['{placeholder}' => 'valor', …]
 * @return string       HTML final.
 */
function render(string $tpl, array $vars): string
{
    $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $tpl);

    $candidates = [];

    // Plantilla con ruta absoluta (Windows o Unix)
    if (str_starts_with($normalized, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $normalized) === 1) {
        $candidates[] = $normalized;
    } else {
        $cwd = getcwd();
        if ($cwd !== false) {
            $candidates[] = $cwd . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);
        }

        // Proyecto consumidor
        $candidates[] = Paths::projectRoot() . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);

        // Copia de stubs en stack-core (para cuando aún no se haya replicado a App/)
        $candidates[] = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);
    }

    $path = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $path = $candidate;
            break;
        }
    }

    if ($path === null) {
        throw new RuntimeException("Template $tpl no encontrado");
    }

    $html = file_get_contents($path)
        ?: throw new RuntimeException("Template $path no encontrado");

    return strtr($html, $vars);
}

/**
 * Devuelve la ruta relativa hacia la home según el idioma.
 *
 * Cuando el idioma solicitado coincide con el idioma por defecto y la
 * configuración marca la versión simplificada (`ES_SIMPLIFICADO=1`),
 * devolvemos la barra raíz "/". Para el resto de idiomas se antepone el
 * código correspondiente (p. ej. "/eu").
 */
function homePath(string $lang): string
{
    $defaultLang    = $_ENV['LANG_DEFAULT'] ?? 'es';
    $esSimplificado = $_ENV['ES_SIMPLIFICADO'] ?? '0';
    $normalizedLang = trim($lang, '/');

    if ($normalizedLang === '') {
        $normalizedLang = $defaultLang;
    }

    if ($esSimplificado === '1' && $normalizedLang === $defaultLang) {
        return '/';
    }

    return '/' . $normalizedLang;
}


/**
 * Devuelve la URL absoluta de la home para el idioma indicado.
 */
function homeUrl(string $lang): string
{
    $base = rtrim($_ENV['RAIZ'] ?? '', '/');
    return $base . homePath($lang);
}


/**
 * Construye una URL o ruta interna respetando el idioma actual.
 *
 * Si el valor recibido ya es absoluto (contiene esquema, comienza por "//",
 * "#" o "?") se devuelve sin modificaciones. Para valores relativos se
 * antepone el dominio configurado y, por defecto, el prefijo del idioma.
 *
 * @param string|null $href    Valor de href definido en el JSON de idiomas.
 * @param array{lang?:string|null,include_lang?:bool,absolute?:bool,leading_slash?:bool,base?:string|null} $options
 */
function resolve_localized_href(?string $href, array $options = []): string
{
    $value = trim((string) ($href ?? ''));

    $absolute     = $options['absolute']      ?? true;
    $includeLang  = $options['include_lang']  ?? true;
    $leadingSlash = $options['leading_slash'] ?? true;
    $baseUrl      = $options['base']          ?? ($_ENV['RAIZ'] ?? '');
    $langValue    = $options['lang']          ?? ($GLOBALS['lang'] ?? null);

    if ($value === '') {
        $langSegment = '';
        if ($includeLang && $langValue !== null && $langValue !== '') {
            $langSegment = trim((string) $langValue, '/');
        }

        if ($absolute) {
            $base = rtrim((string) $baseUrl, '/');
            $suffix = $langSegment !== '' ? '/' . $langSegment : '';
            if ($base === '') {
                return $suffix === '' ? '/' : $suffix . '/';
            }
            return $base . $suffix . '/';
        }

        if ($langSegment === '') {
            return $leadingSlash ? '/' : '';
        }

        return ($leadingSlash ? '/' : '') . $langSegment . '/';
    }

    $firstChar = $value[0];
    if ($firstChar === '#' || $firstChar === '?') {
        return $value;
    }

    if (strpos($value, '//') === 0) {
        return $value;
    }

    $scheme = parse_url($value, PHP_URL_SCHEME);
    if ($scheme !== null) {
        return $value;
    }

    $langSegment = '';
    if ($includeLang && $langValue !== null && $langValue !== '') {
        $langSegment = trim((string) $langValue, '/');
    }

    $path = ltrim($value, '/');
    $segments = [];
    if ($langSegment !== '') {
        $segments[] = $langSegment;
    }
    if ($path !== '') {
        $segments[] = $path;
    }

    $joined = implode('/', $segments);

    if ($absolute) {
        $base = rtrim((string) $baseUrl, '/');
        if ($base === '') {
            return $joined === '' ? '/' : '/' . $joined;
        }
        if ($joined === '') {
            return $base . '/';
        }
        return $base . '/' . $joined;
    }

    if ($joined === '') {
        return $leadingSlash ? '/' : '';
    }

    return ($leadingSlash ? '/' : '') . $joined;
}


/**
 * Determina el nivel del encabezado principal y el de los secundarios.
 *
 * @param array       $params          Parámetros recibidos por el controlador.
 * @param string      $placeholderKey  Clave del placeholder del encabezado principal.
 * @param int         $defaultLevel    Nivel por defecto cuando no se pueda deducir.
 * @param string|null $fallbackMarkup  Marcado alternativo para inspeccionar (opcional).
 *
 * @return array{base:int,child:int}
 */
function resolve_header_levels(array &$params, string $placeholderKey, int $defaultLevel = 3, ?string $fallbackMarkup = null): array
{
    $level = null;

    if (array_key_exists('header_level', $params)) {
        $levelInput = $params['header_level'];
        unset($params['header_level']);

        if (is_int($levelInput) || is_numeric($levelInput)) {
            $level = (int)$levelInput;
        } elseif (is_string($levelInput) && preg_match('/([1-6])/', $levelInput, $matches)) {
            $level = (int)$matches[1];
        }
    }

    if ($level === null) {
        $markup = $params[$placeholderKey] ?? $fallbackMarkup;
        if (is_string($markup) && preg_match('/<h([1-6])\b/i', $markup, $matches)) {
            $level = (int)$matches[1];
        }
    }

    if ($level === null) {
        $level = $defaultLevel;
    }

    $level = max(1, min(6, (int) $level));

    return [
        'base'  => $level,
        'child' => min($level + 1, 6),
    ];
}


function debuguear($data){
    echo "<pre>";
    var_dump($data);
    echo "<pre>";
    exit;
}

function controller(string $name, int $index = 0, array $params = []): string
{
    $projectFile = Paths::appPath() . "/controllers/{$name}.php";
    $coreFile    = dirname(__DIR__, 3) . "/stubs/App/controllers/{$name}.php";

    $file = file_exists($projectFile) ? $projectFile : $coreFile;

    if (!file_exists($file)) {
        throw new RuntimeException("Controller $name not found");
    }

    $cwd         = getcwd();
    $targetDir   = dirname($file);
    $changedCwd  = $targetDir !== '' && is_dir($targetDir) ? chdir($targetDir) : false;

    try {
        require_once $file;
        $func = "controller_{$name}";
        if (!function_exists($func)) {
            throw new RuntimeException("Function $func not defined in $file");
        }
        return $func($index, $params);
    } finally {
        if ($changedCwd && $cwd !== false) {
            chdir($cwd);
        }
    }
}

function matchQueryRoute(&$requestedUrl, $routes){
    foreach ($routes as $routePattern) {
        // Separar la ruta base de la query string esperada
        $parts = explode('?', $routePattern, 2);
        $expectedPath = $parts[0];
        $expectedQuery = $parts[1] ?? '';

        // Separar la ruta real en base y query string
        $requestedParts = explode('?', $requestedUrl, 2);
        $requestedPath = $requestedParts[0];
        $requestedQuery = $requestedParts[1] ?? '';

        // Validar que la ruta base coincida exactamente
        if ($expectedPath !== $requestedPath) {
            continue;
        }

        // Convertir los parámetros dinámicos en una expresión regular
        $regexPattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^&]+)', $expectedQuery);
        $regexPattern = "#^" . $regexPattern . "$#";

        // Verificar si la query string coincide con el patrón
        if (preg_match($regexPattern, $requestedQuery, $matches)) {
            // Eliminar el primer índice (coincidencia completa)
            array_shift($matches);
            //Cambiaomos la url a una admitida
            $requestedUrl = $routePattern;
            /*  return ['file' => $file, 'params' => $matches]; */
            return true;
        }
    }
    $requestedUrl = parse_url($requestedUrl, PHP_URL_PATH); // Limpiamos los query params
    return in_array($requestedUrl, $routes); //Verificamos si existe una ruta si esa query params
}


//Función con expresiones regulares para extraer número (que será el ID del distribuidor)
function extract_numbers($filename){
    preg_match('/\d+/', $filename, $matches);
    return $matches[0] ?? null; // Devuelve el número o null si no encuentra número en el nombre. deberían tener todos, sino se rechaza el archivo
}

function formattedDate($date){
    // Verificar si es un timestamp o string, convertirlo a DateTime
    if (!($date instanceof DateTime)) {
        $date = new DateTime($date);
    }

    // Crear un formateador de fecha en español
    $formatter = new IntlDateFormatter(
        'es_ES', // Locale español
        IntlDateFormatter::LONG, // Fecha en formato largo
        IntlDateFormatter::MEDIUM // Hora en formato medio
    );

    return $formatter->format($date);
}

function imgConvert($inputPath, $outputPath, $size = 1920, $quality = 80){
    if (!file_exists($inputPath)) {
        throw new Exception("El archivo no existe: $inputPath");
    }

    $image = new Imagick($inputPath);
    $extension = pathinfo($outputPath, PATHINFO_EXTENSION);

    // Verificar si el formato está soportado
    if (!in_array(strtoupper($extension), $image->queryFormats())) {
        throw new Exception("Imagick no soporta $extension.");
    }

    // Obtener dimensiones originales de la imagen
    $originalWidth = $image->getImageWidth();
    $originalHeight = $image->getImageHeight();

    if ($originalHeight < $originalWidth) {
        // Calcular el alto proporcional
        $height = (int)(($size / $originalWidth) * $originalHeight);
        $width = $size;
    } else {
        // Calcular el ancho proporcional
        $width = (int)(($size / $originalHeight) * $originalWidth);
        $height = $size;
    }

    // Redimensionar la imagen
    $image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);

    // Eliminar metadatos
    $image->stripImage();

    // Ajustar calidad y formato
    $image->setImageCompressionQuality($quality);
    //  $image->setImageFormat("avif");

    // Guardar la imagen
    $success = $image->writeImage($outputPath);

    // Liberar memoria
    $image->clear();

    return $success;

    // limpiar el input y el output al terminar de enviar el correo.
}

function devolver_respuesta($mensaje, $fallo, $campo, $code = 400, $data_lang = null){
    $arrayRespuesta = array(
        'mensaje' => $mensaje,
        'fallo' => $fallo,
        'campo' => $campo,
        'data_lang' => $data_lang
    );
    $jsonDelArray = json_encode($arrayRespuesta);
    if ($fallo) {
        http_response_code($code);
    }
    echo $jsonDelArray;
    die;
}

// Función peticiones asíncronas a una API
function fetchData(string $url, $method = "get", $headers = [], $body = null){
    //Retorna un Fiber donde su ejecución estará supendida hasta llamar a su metodo start, así podemos hacer peticones múltiples y asíncronas
    return new Fiber(function () use ($url, $method, $headers, $body) {
        $ch = curl_init(); // Iniciamos CURL para la petición
        $method = $method === "get" ? null : match ($method) { // Obtenemos la constante de configuración de curl según el metodo de petición
            "put" => CURLOPT_PUT,
            "post" => CURLOPT_POST,
        };
        curl_setopt($ch, CURLOPT_URL, "$url"); // Establecemos la url al endpoitn
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Para recibir la respuesta
        curl_setopt($ch, CURLOPT_FAILONERROR, true); // Tratar errores HTTP como errores de cURL

        if ($method) {
            curl_setopt($ch, $method, true); // Si es un metodo diferente de GET lo configuramos
        }

        if (count($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); // Si eixsten cabeceras lo configuramos
        }
        if ($method === CURLOPT_POST && is_array($body)) { // Si es una petición post y existe un cuerpo par ala petición lo configuramos
            if (array_search("Content-Type: application/json", $headers)) {
                $body = json_encode($body);  // Convertir a JSON si el header lo requiere
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        // Ejecutar la solicitud y guardar la ejecución
        $response = curl_exec($ch); // Ejecutamos la petición

        // Manejar errores de cURL
        if (curl_errno($ch)) {
            $error = 'Error en la petición: ' . curl_error($ch);
            curl_close($ch);
            throw new Exception($error);
        }
        // Cerrar cURL
        curl_close($ch);
        // Suspender la ejecución del Fiber y devolver la respuesta
        Fiber::suspend(json_decode($response));
    });
}


function getMatchRouteByLang($route, $lang){
    $arrayRoutes = require Paths::appPath() . "/config/routes/get.php";
    foreach ($arrayRoutes as $langSelected => $options_routes) {
        $routeIndex = array_search($route, array_keys($options_routes), true);
        if ($routeIndex !== false) {
            $routes_for_lang = isset($arrayRoutes[$lang]) ? array_keys($arrayRoutes[$lang]) : null;
            $route_lang = $routes_for_lang[$routeIndex] ?? null;
            return $route_lang;
        }
    }
    return null;
}



/* ------------------------------------------------------------------
 * helpers.php
 * ------------------------------------------------------------------
 *  Devuelve las etiquetas <link rel="alternate" …> para el <head>
 *  $lang → idioma de la página actual  (p. ej. 'es' | 'eu')
 *  $url  → ruta actual con slash inicial (p. ej. '/es/bilbao')
 * ------------------------------------------------------------------ */
function hreflangAlternates(string $lang, string $url): string
{
    global $arrayRutasGet;                      // mapa de rutas
    $raiz = rtrim($_ENV['RAIZ'] ?? '', '/');    // https://jaramaautoescuela.com

    /* 1. La URL no existe en el idioma actual → no se imprime nada */
    if (!isset($arrayRutasGet[$lang][$url])) {
        return '';
    }

    /* 2. Obtenemos su posición dentro del array de ese idioma       */
    $indice = array_search($url, array_keys($arrayRutasGet[$lang]), true);

    /* 3. Obtenemos el “content” de esa ruta (clave común entre idiomas) */
    $contentKey = $arrayRutasGet[$lang][$url]['content'] ?? null;

    /* 4. Generamos links alternates para todos los idiomas --------- */
    $links = [];
    foreach ($arrayRutasGet as $langCode => $routes) {
        $keys = array_keys($routes);
        if (isset($keys[$indice])) {            // homóloga por posición
            $links[] = '<link rel="alternate" hreflang="' . $langCode .
                       '" href="' . $raiz . $keys[$indice] . '">';
        }
    }

    /* 5. Etiqueta x-default → versión ES de ESTA página */
    $defaultLang = $_ENV['LANG_DEFAULT'];
    $defaultHref = $raiz . $url;               // fallback: canonical actual

    if ($contentKey && isset($arrayRutasGet[$defaultLang])) {
        foreach ($arrayRutasGet[$defaultLang] as $esUrl => $meta) {
            if (($meta['content'] ?? null) === $contentKey) {
                $defaultHref = $raiz . $esUrl;
                break;
            }
        }
    }

    $links[] = '<link rel="alternate" hreflang="x-default" href="' . $defaultHref . '">';

    /* 6. Devolvemos todas las etiquetas separadas por salto de línea */
    return implode(PHP_EOL, $links);
}



/* ------------------------------------------------------------------
 * helpers.php
 * ------------------------------------------------------------------
 * Devuelve el <script type="application/ld+json"> con el esquema
 * de la página actual, incluyendo accesibilidad WCAG 2.0/2.1
 * ------------------------------------------------------------------ */
function schemaWebPageAccessibility( string $lang, string $url, string $title, string $description ): string
{
    $raiz = rtrim( $_ENV['RAIZ'] ?? '', '/' );            // dominio
    $fullUrl = $raiz . $url;                               // URL canónica

    /* ==== propiedades base comunes a todas las páginas ============= */
    $schema = [
        '@context'            => 'https://schema.org',
        '@type'               => 'WebPage',
        '@id'                 => $fullUrl . '#webpage',
        'url'                 => $fullUrl,
        'inLanguage'          => $lang,
        'name'                => $title,
        'description'         => $description,

        /* ==== Accesibilidad WCAG 2.x ================================ */
        'accessibilityAPI'    => 'ARIA',
        'accessibilityControl'=> [
            'fullKeyboardControl',          // navegación 100 % por teclado
            'fullMouseControl'              // y ratón
        ],
        'accessibilityFeature'=> [
            'highContrast',                 // colores accesibles
            'longDescription',              // descripciones de imágenes
            'ARIA'                          // marcado ARIA
        ],
        'accessibilityHazard' => [
            'noFlashingHazard',             // sin destellos
            'noMotionSimulationHazard'      // sin movimiento brusco
        ],
        'accessibilitySummary'=> 'El contenido cumple las directrices WCAG 2.1 nivel AA: contraste suficiente, navegación por teclado completa y marcado ARIA para lectores de pantalla.'
    ];

    /* ==== Alternates hreflang (opcional en el schema) =============== */
    global $arrayRutasGet;
    $alternates = [];

    $indice = array_search( $url, array_keys( $arrayRutasGet[$lang] ), true );
    foreach ( $arrayRutasGet as $langCode => $routes ) {
        $keys = array_keys( $routes );
        if ( isset( $keys[$indice] ) ) {
            $alternates[] = [
                '@type'     => 'WebPage',
                '@id'       => $raiz . $keys[$indice],
                'url'       => $raiz . $keys[$indice],
                'inLanguage'=> $langCode
            ];
        }
    }
    if ( $alternates ) {
        $schema['hasPart'] = $alternates;   // vincula las variantes lingüísticas
    }

    /* ==== Devolvemos el bloque listo para el <head> ================= */
    return '<script type="application/ld+json">' . PHP_EOL
         . json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
         . PHP_EOL . '</script>';
}




/* helpers/sitemap.php
 * ---------------------------------------------------------------
 *  • $arrayRutasGet  → el array multilingüe que ya tienes cargado
 *  • $_ENV['RAIZ']   → dominio principal (p. ej. https://jaramaautoescuela.com)
 *  • $outputDir      → carpeta donde quieres soltar los XML (raíz pública)
 * --------------------------------------------------------------- */

function generarSitemapMultilingue(array $rutas, string $outputDir): void
{
    if (empty($rutas)) {
        return;
    }

    $raiz        = rtrim($_ENV['RAIZ'] ?? '', '/');
    $defaultLang = $_ENV['LANG_DEFAULT'] ?? array_key_first($rutas);
    if (!$defaultLang || !isset($rutas[$defaultLang])) {
        $defaultLang = array_key_first($rutas);
    }

    if (!$defaultLang || !isset($rutas[$defaultLang])) {
        return;
    }

    $mapaContenido = [];
    foreach ($rutas as $lang => $tablaUrls) {
        foreach ($tablaUrls as $url => $meta) {
            if (!is_array($meta)) {
                $meta = [];
            }

            if (shouldExcludeFromSitemap($url, $meta)) {
                continue;
            }

            $contentKey = $meta['content'] ?? null;
            if (!$contentKey) {
                continue; // rutas técnicas (descargas, etc.)
            }

            if (!isset($mapaContenido[$contentKey])) {
                $mapaContenido[$contentKey] = [];
            }

            $mapaContenido[$contentKey][$lang] = $url;
        }
    }

    if (empty($mapaContenido)) {
        return;
    }

    $hoy = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->format('Y-m-d\TH:i:sP'); // 2025-07-17T09:42:00+00:00

    $xml   = [];
    $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
        . 'xmlns:xhtml="http://www.w3.org/1999/xhtml">';

    $ordenContenido = [];
    foreach ($rutas[$defaultLang] as $defaultUrl => $meta) {
        if (!is_array($meta)) {
            $meta = [];
        }

        if (shouldExcludeFromSitemap($defaultUrl, $meta)) {
            continue;
        }

        $contentKey = $meta['content'] ?? null;
        if (!$contentKey || !isset($mapaContenido[$contentKey])) {
            continue;
        }

        $ordenContenido[] = $contentKey;
    }

    foreach (array_keys($mapaContenido) as $contentKey) {
        if (!in_array($contentKey, $ordenContenido, true)) {
            $ordenContenido[] = $contentKey;
        }
    }

    foreach ($ordenContenido as $contentKey) {
        if (!isset($mapaContenido[$contentKey])) {
            continue;
        }

        $pathsPorIdioma = $mapaContenido[$contentKey];
        $alternates      = [];

        foreach ($pathsPorIdioma as $lang => $path) {
            $alternates[$lang] = $raiz . $path;
        }

        if (empty($alternates)) {
            continue;
        }

        $xDefaultLang = isset($alternates[$defaultLang])
            ? $defaultLang
            : array_key_first($alternates);
        $xDefaultHref = $alternates[$xDefaultLang];

        foreach ($pathsPorIdioma as $lang => $path) {
            $canonicalHref = $alternates[$lang];

            $priority = ($path === '/' || $path === "/{$lang}")
                ? '1.0'
                : '0.8';

            $xml[] = '  <url>';
            $xml[] = '    <loc>' . htmlspecialchars($canonicalHref, ENT_QUOTES | ENT_XML1) . '</loc>';
            $xml[] = '    <lastmod>' . $hoy . '</lastmod>';
            $xml[] = '    <changefreq>monthly</changefreq>';
            $xml[] = '    <priority>' . $priority . '</priority>';

            foreach ($alternates as $alternateLang => $href) {
                $xml[] = '    <xhtml:link rel="alternate" hreflang="' . $alternateLang
                    . '" href="' . htmlspecialchars($href, ENT_QUOTES | ENT_XML1) . '" />';
            }

            $xml[] = '    <xhtml:link rel="alternate" hreflang="x-default" href="'
                . htmlspecialchars($xDefaultHref, ENT_QUOTES | ENT_XML1) . '" />';
            $xml[] = '  </url>';
        }
    }

    $xml[] = '</urlset>';

    file_put_contents($outputDir . '/sitemap.xml', implode(PHP_EOL, $xml));

    // Eliminamos sitemaps legacy si siguen presentes
    foreach (['sitemap-es.xml', 'sitemap-eu.xml', 'sitemap-fr.xml', 'sitemap_index.xml'] as $legacy) {
        $rutaLegacy = $outputDir . '/' . $legacy;
        if (is_file($rutaLegacy)) {
            @unlink($rutaLegacy);
        }
    }
}

function shouldExcludeFromSitemap(string $url, array $meta): bool
{
    if (str_contains($url, '/templates') || str_contains($url, '/descargar')) {
        return true;
    }

    $content   = $meta['content']   ?? null;
    $resources = $meta['resources'] ?? null;

    if ($content === 'showroom' || $resources === 'templates') {
        return true;
    }

    return false;
}
}

namespace {
    use function App\Core\Support\controller as core_controller;
    use function App\Core\Support\debuguear as core_debuguear;
    use function App\Core\Support\devolver_respuesta as core_devolver_respuesta;
    use function App\Core\Support\extract_numbers as core_extract_numbers;
    use function App\Core\Support\fetchData as core_fetchData;
    use function App\Core\Support\formattedDate as core_formattedDate;
    use function App\Core\Support\generarSitemapMultilingue as core_generarSitemapMultilingue;
    use function App\Core\Support\getMatchRouteByLang as core_getMatchRouteByLang;
    use function App\Core\Support\homePath as core_homePath;
    use function App\Core\Support\homeUrl as core_homeUrl;
    use function App\Core\Support\hreflangAlternates as core_hreflangAlternates;
    use function App\Core\Support\imgConvert as core_imgConvert;
    use function App\Core\Support\matchQueryRoute as core_matchQueryRoute;
    use function App\Core\Support\render as core_render;
    use function App\Core\Support\resolve_header_levels as core_resolve_header_levels;
    use function App\Core\Support\resolve_localized_href as core_resolve_localized_href;
    use function App\Core\Support\schemaWebPageAccessibility as core_schemaWebPageAccessibility;
    use function App\Core\Support\shouldExcludeFromSitemap as core_shouldExcludeFromSitemap;

    function render(string $tpl, array $vars): string
    {
        return core_render($tpl, $vars);
    }

    function homePath(string $lang): string
    {
        return core_homePath($lang);
    }

    function homeUrl(string $lang): string
    {
        return core_homeUrl($lang);
    }

    function resolve_localized_href(?string $href, array $options = []): string
    {
        return core_resolve_localized_href($href, $options);
    }

    function resolve_header_levels(array &$params, string $placeholderKey, int $defaultLevel = 3, ?string $fallbackMarkup = null): array
    {
        return core_resolve_header_levels($params, $placeholderKey, $defaultLevel, $fallbackMarkup);
    }

    function debuguear($data)
    {
        return core_debuguear($data);
    }

    function controller(string $name, int $index = 0, array $params = []): string
    {
        return core_controller($name, $index, $params);
    }

    function matchQueryRoute(&$requestedUrl, $routes)
    {
        return core_matchQueryRoute($requestedUrl, $routes);
    }

    function extract_numbers($filename)
    {
        return core_extract_numbers($filename);
    }

    function formattedDate($date)
    {
        return core_formattedDate($date);
    }

    function imgConvert($inputPath, $outputPath, $size = 1920, $quality = 80)
    {
        return core_imgConvert($inputPath, $outputPath, $size, $quality);
    }

    function devolver_respuesta($mensaje, $fallo, $campo, $code = 400, $data_lang = null)
    {
        return core_devolver_respuesta($mensaje, $fallo, $campo, $code, $data_lang);
    }

    function fetchData(string $url, $method = "get", $headers = [], $body = null)
    {
        return core_fetchData($url, $method, $headers, $body);
    }

    function getMatchRouteByLang($route, $lang)
    {
        return core_getMatchRouteByLang($route, $lang);
    }

    function hreflangAlternates(string $lang, string $url): string
    {
        return core_hreflangAlternates($lang, $url);
    }

    function schemaWebPageAccessibility(string $lang, string $url, string $title, string $description): string
    {
        return core_schemaWebPageAccessibility($lang, $url, $title, $description);
    }

    function generarSitemapMultilingue(array $rutas, string $outputDir): void
    {
        core_generarSitemapMultilingue($rutas, $outputDir);
    }

    function shouldExcludeFromSitemap(string $url, array $meta): bool
    {
        return core_shouldExcludeFromSitemap($url, $meta);
    }
}

