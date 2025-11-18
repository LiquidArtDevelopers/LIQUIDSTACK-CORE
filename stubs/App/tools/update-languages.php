#!/usr/bin/env php
<?php

use App\Core\Support\Paths;

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
}

Paths::setProjectRoot(dirname(__DIR__, 2));
$appPath    = Paths::appPath();
$publicPath = Paths::publicPath();

// Load available languages
$langs = require $appPath . '/config/langs.php';
$routes = require $appPath . '/config/routes/get.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/update-languages.php <slug>\n");
    exit(1);
}

$slug = $argv[1];

function collect_views(array $routes): array {
    global $publicPath, $appPath;
    $out = [];
    $base = realpath($publicPath);
    foreach ($routes as $langRoutes) {
        foreach ($langRoutes as $info) {
            if (!isset($info['content'], $info['view'])) {
                continue;
            }
            $viewPath = $info['view'];
            if ($base !== false) {
                $viewPath = realpath($base . '/' . $viewPath);
            } else {
                $viewPath = realpath($viewPath);
            }
            if ($viewPath !== false && file_exists($viewPath)) {
                $out[$info['content']] = $viewPath;
            }
        }
    }

    $viewsDir = realpath($appPath . '/views');
    if ($viewsDir !== false) {
        $files = glob($viewsDir . '/*.php');
        if ($files !== false) {
            foreach ($files as $file) {
                $basename = basename($file, '.php');
                if ($basename === '') {
                    continue;
                }
                if ($basename[0] === '_') {
                    continue;
                }
                if (!isset($out[$basename])) {
                    $real = realpath($file);
                    if ($real !== false) {
                        $out[$basename] = $real;
                    }
                }
            }
        }
    }

    return $out;
}

$routeContents = [];
foreach ($routes as $langRoutes) {
    foreach ($langRoutes as $info) {
        if (!isset($info['content'], $info['view'])) {
            continue;
        }
        $routeContents[$info['content']] = true;
    }
}

$views = collect_views($routes);
$templateMap = load_template_map($appPath . '/config/languages/templates/es.json');

function match_slugs_by_view(string $slug, array $views): array {
    $normalized = basename($slug);
    $filename = pathinfo($normalized, PATHINFO_FILENAME);

    $matches = [];
    $fallback = [];
    foreach ($views as $candidate => $path) {
        $base = basename($path);
        $name = pathinfo($base, PATHINFO_FILENAME);
        if ($normalized === $base || ($filename !== '' && $filename === $name)) {
            if ($filename !== '' && $candidate === $name) {
                $fallback[] = $candidate;
                continue;
            }
            $matches[] = $candidate;
        }
    }

    if ($matches !== []) {
        return array_values(array_unique($matches));
    }

    return array_values(array_unique(array_merge($matches, $fallback)));
}

function is_non_translatable_key(string $key): bool
{
    return substr($key, -9) === '_classVar';
}

// --- Collect controllers from view (and global includes when needed) ---
// Map of slug => list of controller calls
$controllersBySlug = [];
// Map of controller (name#index) => slugs that use it via global includes
$globalUsage = [];
$requestedSlugs = [];
// Inline language keys discovered within files grouped by slug
$inlineKeysBySlug = [];
// Usage map for inline keys found inside global includes
$inlineGlobalUsage = [];

/**
 * Recursively parse a PHP file to collect controller calls.
 *
 * @param string $file     Path to the PHP file
 * @param string $target   Slug to which discovered controllers belong
 * @param array  $map      Accumulator of slug => controller[]
 * @param array  $visited  Tracks processed files to avoid loops
 */
function resolve_include_path(string $include, string $currentFile): ?string {
    global $publicPath;
    $candidates = [
        $include,
        dirname($currentFile) . '/' . $include,
        $publicPath . '/' . $include,
    ];
    foreach ($candidates as $p) {
        $real = realpath($p);
        if ($real !== false) {
            return $real;
        }
    }
    return null;
}

function merge_inline_key_sets(array &$target, array $incoming): void {
    foreach ($incoming as $key => $props) {
        if (!isset($target[$key])) {
            $target[$key] = is_array($props) ? array_values(array_unique($props)) : [];
            continue;
        }

        $existing = $target[$key];
        if (!is_array($existing)) {
            $existing = [];
        }

        if (!is_array($props) || $props === []) {
            $target[$key] = $existing;
            continue;
        }

        $target[$key] = array_values(array_unique(array_merge($existing, $props)));
    }
}

function extract_inline_lang_keys(string $content): array {
    $keys = [];

    $patterns = [
        '/data-lang\s*=\s*"([^"]+)"/',
        "/data-lang\s*=\s*'([^']+)'/",
    ];

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $key) {
                if ($key === '' || strpos($key, '{$pad}') !== false) {
                    continue;
                }
                if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
                    continue;
                }
                if (substr($key, -1) === '_') {
                    continue;
                }
                if (!isset($keys[$key])) {
                    $keys[$key] = [];
                }
            }
        }
    }

    $translatableProps = [
        'text' => true,
        'title' => true,
        'content' => true,
        'alt' => true,
        'placeholder' => true,
        'value' => true,
        'href' => true,
        'src' => true,
        'label' => true,
        'legend' => true,
        'description' => true,
        'subtitle' => true,
        'copy' => true,
        'button' => true,
        'btn' => true,
        'url' => true,
        'target' => true,
        'rel' => true,
        'icon' => true,
        'image' => true,
        'aria_label' => true,
        'aria-labelledby' => true,
        'aria_labelledby' => true,
        'aria-describedby' => true,
        'aria_describedby' => true,
        'aria-hidden' => true,
        'aria_hidden' => true,
        'aria-expanded' => true,
        'aria_expanded' => true,
        'aria-controls' => true,
        'aria_controls' => true,
        'aria-current' => true,
        'aria_current' => true,
        'aria-pressed' => true,
        'aria_pressed' => true,
        'aria-live' => true,
        'aria_live' => true,
        'aria-role' => true,
        'aria_role' => true,
        'aria-selected' => true,
        'aria_selected' => true,
        'aria-sort' => true,
        'aria_sort' => true,
        'aria-checked' => true,
        'aria_checked' => true,
        'aria-owns' => true,
        'aria_owns' => true,
        'aria-haspopup' => true,
        'aria_haspopup' => true,
        'aria-modal' => true,
        'aria_modal' => true,
        'aria-level' => true,
        'aria_level' => true,
        'aria-atomic' => true,
        'aria_atomic' => true,
        'aria-relevant' => true,
        'aria_relevant' => true,
        'aria-setsize' => true,
        'aria_setsize' => true,
        'aria-posinset' => true,
        'aria_posinset' => true,
        'aria-valuemin' => true,
        'aria_valuemin' => true,
        'aria-valuemax' => true,
        'aria_valuemax' => true,
        'aria-valuenow' => true,
        'aria_valuenow' => true,
        'aria-valuetext' => true,
        'aria_valuetext' => true,
        'aria-orientation' => true,
        'aria_orientation' => true,
        'aria-placeholder' => true,
        'aria_placeholder' => true,
        'aria-roledescription' => true,
        'aria_roledescription' => true,
        'aria-details' => true,
        'aria_details' => true,
        'aria-keyshortcuts' => true,
        'aria_keyshortcuts' => true,
        'aria-errormessage' => true,
        'aria_errormessage' => true,
        'aria-flowto' => true,
        'aria_flowto' => true,
        'aria-autocomplete' => true,
        'aria_autocomplete' => true,
        'aria-multiline' => true,
        'aria_multiline' => true,
        'aria-required' => true,
        'aria_required' => true,
        'aria-readonly' => true,
        'aria_readonly' => true,
        'aria-busy' => true,
        'aria_busy' => true,
        'aria-activedescendant' => true,
        'aria_activedescendant' => true,
        'aria-dropeffect' => true,
        'aria_dropeffect' => true,
        'aria-grabbed' => true,
        'aria_grabbed' => true,
        'aria-invalid' => true,
        'aria_invalid' => true,
        'aria-colcount' => true,
        'aria_colcount' => true,
        'aria-colindex' => true,
        'aria_colindex' => true,
        'aria-colspan' => true,
        'aria_colspan' => true,
        'aria-rowcount' => true,
        'aria_rowcount' => true,
        'aria-rowindex' => true,
        'aria_rowindex' => true,
        'aria-rowspan' => true,
        'aria_rowspan' => true,
        'meta' => true,
        'og' => true,
        'twitter' => true,
    ];

    if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)->([A-Za-z_][A-Za-z0-9_]*)/', $content, $props, PREG_SET_ORDER)) {
        $skip = [
            'GLOBALS' => true,
            'this' => true,
            '_GET' => true,
            '_POST' => true,
            '_REQUEST' => true,
            '_SERVER' => true,
            '_COOKIE' => true,
            '_SESSION' => true,
            '_FILES' => true,
            '_ENV' => true,
        ];

        foreach ($props as $match) {
            $var = $match[1];
            if (isset($skip[$var])) {
                continue;
            }

            $key = $var;
            if (!isset($keys[$key])) {
                $prop = strtolower($match[2]);
                $isAria = strncmp($prop, 'aria', 4) === 0;
                $looksTranslatable = $isAria
                    || isset($translatableProps[$prop])
                    || str_contains($prop, 'title')
                    || str_contains($prop, 'text')
                    || str_contains($prop, 'label');

                if (!$looksTranslatable) {
                    continue;
                }

                if (!preg_match('/^[A-Za-z0-9_]+$/', $key)) {
                    continue;
                }

                $keys[$key] = [];
            }

            if (!isset($keys[$key])) {
                continue;
            }

            $keys[$key][$match[2]] = true;
        }
    }

    if ($keys === []) {
        return [];
    }

    foreach ($keys as $key => $map) {
        $keys[$key] = array_keys($map);
    }

    return $keys;
}

function parse_file(string $file, string $target, array &$map, array &$visited, string $owner, array &$usage, array &$inlineMap, array &$inlineUsage): void {
    if (isset($visited[$file]) || !file_exists($file)) {
        return;
    }
    $visited[$file] = true;
    $contents = file_get_contents($file);

    $inlineKeys = extract_inline_lang_keys($contents);
    if ($inlineKeys !== []) {
        if (!isset($inlineMap[$target])) {
            $inlineMap[$target] = [];
        }
        merge_inline_key_sets($inlineMap[$target], $inlineKeys);
        if ($target === 'global') {
            foreach (array_keys($inlineKeys) as $key) {
                $inlineUsage[$key][$owner] = true;
            }
        }
    }

    // Find include/require statements
    if (preg_match_all("/(?:include|require)(?:_once)?\s*(?:\(|\s)\s*['\"]([^'\"]+)['\"]\s*\)?/", $contents, $inc)) {
        $needle = DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR;
        foreach ($inc[1] as $incPath) {
            $resolved = resolve_include_path($incPath, $file);
            if (!$resolved) {
                continue;
            }
            $isGlobal = str_contains($resolved, $needle);
            $slugHint = $isGlobal ? 'global' : $target;
            parse_file($resolved, $slugHint, $map, $visited, $owner, $usage, $inlineMap, $inlineUsage);
        }
    }

    // Find controller calls with optional params
    if (preg_match_all("/controller\(\s*['\"]([^'\"]+)['\"]\s*,\s*(\d+)\s*(?:,\s*([^)]*))?\)/", $contents, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $params = [];
            if (!empty($match[3])) {
                if (preg_match("/['\"]items['\"]\s*=>\s*(\d+)/", $match[3], $pm)) {
                    $params['items'] = (int)$pm[1];
                }
            }
            $ctrl = ['name' => $match[1], 'index' => (int)$match[2], 'params' => $params];
            $map[$target][] = $ctrl;
            if ($target === 'global') {
                $usage[$match[1] . '#' . (int)$match[2]][$owner] = true;
            }
        }
    }
}

if ($slug === 'global') {
    $dir = $appPath . '/includes';
    $files = glob($dir . '/*.php');
    foreach ($files as $file) {
        $visited = [];
        parse_file($file, 'global', $controllersBySlug, $visited, 'global', $globalUsage, $inlineKeysBySlug, $inlineGlobalUsage);
    }
    $requestedSlugs = ['global'];
} else {
    if (isset($views[$slug]) && isset($routeContents[$slug])) {
        $requestedSlugs = [$slug];
    } else {
        $requestedSlugs = match_slugs_by_view($slug, $views);
        if ($requestedSlugs === []) {
            fwrite(STDERR, "View for slug '$slug' not found\n");
            exit(1);
        }
    }

    foreach ($requestedSlugs as $viewSlug) {
        $visited = [];
        parse_file($views[$viewSlug], $viewSlug, $controllersBySlug, $visited, $viewSlug, $globalUsage, $inlineKeysBySlug, $inlineGlobalUsage);
    }
}

function key_group(string $key): string {
    if (preg_match('/^(.*?_\d{2})_/', $key, $m)) {
        return $m[1];
    }
    return strtok($key, '_');
}

function encode_json_blocks(array $data): string {
    $indent = '    ';
    $out = "{\n\n"; // match existing style with a blank line after '{'
    $first = true;
    $prev = null;
    foreach ($data as $k => $v) {
        $group = key_group($k);
        if (!$first) {
            $out .= ",\n";
            if ($group !== $prev) {
                $out .= "\n";
            }
        }
        $json = json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = str_replace("\n", "\n$indent", $json);
        $out .= $indent . "\"$k\": " . ltrim($json, ' ');
        $prev = $group;
        $first = false;
    }
    $out .= "\n}\n";
    return $out;
}

function template_key(string $key): string {
    if (preg_match('/^(.*?_)(\d{2})(_.+)$/', $key, $m)) {
        return $m[1] . '00' . $m[3];
    }
    return $key;
}

function load_template_map(string $file): array {
    $data = json_decode(file_get_contents($file), true) ?: [];
    $map = [];
    foreach ($data as $k => $v) {
        if (is_non_translatable_key($k)) {
            continue;
        }
        if (preg_match('/^([A-Za-z0-9_]+_\d{2})_/', $k, $m)) {
            $map[$m[1]][$k] = is_array($v) ? array_keys($v) : null;
        }
    }
    return $map;
}

function template_value_has_content($value): bool {
    if (is_array($value)) {
        foreach ($value as $item) {
            if (template_value_has_content($item)) {
                return true;
            }
        }
        return false;
    }

    return $value !== null && $value !== '';
}

function merge_template_arrays(array $primary, array $fallback): array {
    $result = $primary;
    foreach ($fallback as $key => $value) {
        if (!array_key_exists($key, $result)) {
            $result[$key] = $value;
            continue;
        }

        $current = $result[$key];
        if (is_array($current) && is_array($value)) {
            $result[$key] = merge_template_arrays($current, $value);
            continue;
        }

        if (!template_value_has_content($current) && template_value_has_content($value)) {
            $result[$key] = $value;
        }
    }

    return $result;
}

function load_language_templates(array $langs): array {
    global $appPath;
    $templates = [];
    foreach ($langs as $lang) {
        $path = $appPath . '/config/languages/templates/' . $lang . '.json';
        if (!file_exists($path)) {
            $templates[$lang] = [];
            continue;
        }

        $decoded = json_decode(file_get_contents($path), true);
        $templates[$lang] = is_array($decoded) ? $decoded : [];
    }

    return $templates;
}

function build_template_fallback(array $templates, array $langs): array {
    $fallback = [];
    foreach ($langs as $lang) {
        $template = $templates[$lang] ?? [];
        foreach ($template as $key => $value) {
            if (!array_key_exists($key, $fallback)) {
                $fallback[$key] = $value;
                continue;
            }

            $current = $fallback[$key];
            if (is_array($current) && is_array($value)) {
                $fallback[$key] = merge_template_arrays($current, $value);
                continue;
            }

            if (!template_value_has_content($current) && template_value_has_content($value)) {
                $fallback[$key] = $value;
            }
        }
    }

    return $fallback;
}

function resolve_template_value(array $template, array $fallbackMap, string $key)
{
    $value = $template[$key] ?? null;
    $fallback = $fallbackMap[$key] ?? null;

    if (is_array($value) && is_array($fallback)) {
        $value = merge_template_arrays($value, $fallback);
    }

    if (template_value_has_content($value)) {
        return $value;
    }

    if (is_array($fallback)) {
        return $fallback;
    }

    if (template_value_has_content($fallback)) {
        return $fallback;
    }

    return $value;
}

function reuse_existing(array $ordered, string $key) {
    $len = strlen($key);
    for ($i = 0; $i < $len; $i++) {
        $char = $key[$i];
        if ($char < 'a' || $char > 'z') {
            continue;
        }
        $prefix = substr($key, 0, $i);
        $suffix = substr($key, $i + 1);
        $found = [];
        foreach ($ordered as $k => $v) {
            if ($k === $key || strlen($k) !== $len) {
                continue;
            }
            if (strncmp($k, $prefix, $i) === 0 && substr($k, $i + 1) === $suffix) {
                $found[$k[$i]] = $v;
            }
        }
        if ($found) {
            ksort($found);
            $letters = array_keys($found);
            $idx = ord($char) - ord('a');
            $ref = $letters[$idx % count($letters)];
            return $found[$ref];
        }
    }
    return null;
}

function update_lang_file(string $dir, string $lang, array $keyMap, array $template, bool $removeMissing, array $fallback): void {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $file = "$dir/$lang.json";
    $data = [];
    $isNew = !file_exists($file);
    if (!$isNew) {
        $data = json_decode(file_get_contents($file), true) ?: [];
    }

    $existing = $data;
    if ($removeMissing) {
        $ordered = [];
        $baseKeep = [
            'example' => true,
            'title' => true,
            'description' => true,
            'robots' => true,
        ];
        // Preserve only keys present in the new map or explicitly whitelisted base entries.
        foreach ($data as $k => $v) {
            if (isset($keyMap[$k])) {
                $ordered[$k] = $v;
                continue;
            }
            if (isset($baseKeep[$k])) {
                $ordered[$k] = $v;
            }
        }
    } else {
        // Keep the entire existing map when missing entries should be preserved (global files).
        $ordered = $data;
    }

    foreach (array_keys($ordered) as $existingKey) {
        if (is_non_translatable_key($existingKey)) {
            unset($ordered[$existingKey]);
        }
    }

    if ($isNew) {
        foreach (['example', 'title', 'description', 'robots'] as $baseKey) {
            if (isset($ordered[$baseKey])) {
                continue;
            }
            $resolved = resolve_template_value($template, $fallback, $baseKey);
            if ($resolved !== null) {
                $ordered[$baseKey] = $resolved;
            }
        }
    }

    foreach ($keyMap as $k => $props) {
        if (is_non_translatable_key($k)) {
            continue;
        }
        $tmplKey = template_key($k);
        $baseVal = resolve_template_value($template, $fallback, $tmplKey);
        $existing = $ordered[$k] ?? null;

        if ($props === [] || $props === null) {
            $newVal = $existing;
            $shouldPopulate = !array_key_exists($k, $ordered) || is_array($existing) || $existing === "";
            if ($shouldPopulate) {
                if (!is_array($baseVal)) {
                    if ($baseVal === null) {
                        $copy = reuse_existing($ordered, $k);
                        if (!is_array($copy) && $copy !== null) {
                            $newVal = $copy;
                        } else {
                            $newVal = "";
                        }
                    } else {
                        $newVal = $baseVal;
                    }
                } else {
                    $newVal = "";
                }
            }

            if (!array_key_exists($k, $ordered) || $ordered[$k] !== $newVal) {
                $ordered[$k] = $newVal;
            }
            continue;
        }

        $existingArr = is_array($existing) ? $existing : [];
        $base = is_array($baseVal) ? $baseVal : [];
        if ($base === []) {
            $reuse = reuse_existing($ordered, $k);
            if (is_array($reuse)) {
                $base = $reuse;
            }
        }

        $dummy = resolve_template_value($template, $fallback, 'example');
        $dummy = is_array($dummy) ? $dummy : [];

        $obj = $existingArr;
        foreach ($props as $p) {
            if (array_key_exists($p, $existingArr) && $existingArr[$p] !== "") {
                $obj[$p] = $existingArr[$p];
                continue;
            }

            if (array_key_exists($p, $base) && $base[$p] !== "") {
                $obj[$p] = $base[$p];
                continue;
            }

            if (array_key_exists($p, $dummy) && $dummy[$p] !== "") {
                $obj[$p] = $dummy[$p];
                continue;
            }

            $obj[$p] = $existingArr[$p] ?? "";
        }

        if (!array_key_exists($k, $ordered) || $ordered[$k] !== $obj) {
            $ordered[$k] = $obj;
        }
    }

    if ($ordered !== $existing) {
        file_put_contents($file, encode_json_blocks($ordered));
        echo "Updated $file\n";
    }
}

// --- Extract language keys from controllers ---
function extract_keys(string $name, int $index): array {
    global $appPath;
    $file = $appPath . '/controllers/' . $name . '.php';
    if (!file_exists($file)) {
        return [];
    }

    $pad = sprintf('%02d', $index);
    $content = file_get_contents($file);
    $keys = [];

    // collect simple prefix variables like $pref = "navMegamenu01_{$pad}_";
    $prefixes = [];
    if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*"([^"]*\{\$pad\}[^"]*)"\s*;/', $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $prefixes[$match[1]] = str_replace('{$pad}', $pad, $match[2]);
        }
    }

    $map = [];

    $expand = function(string $raw) use ($pad, $prefixes) {
        $raw = preg_replace_callback('/\{\$([A-Za-z_][A-Za-z0-9_]*)\}/', function($v) use ($prefixes) {
            return $prefixes[$v[1]] ?? $v[0];
        }, $raw);
        return str_replace('{$pad}', $pad, $raw);
    };

    // $GLOBALS['foo']->prop
    if (preg_match_all('/\$GLOBALS\[("|\')([^"\']+)\1\]\s*->\s*([A-Za-z_][A-Za-z0-9_]*)/', $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $g) {
            $key = $expand($g[2]);
            $prop = $g[3];
            if (strpos($key, '{') !== false || strpos($key, '}') !== false) continue;
            if ($key === 'lang') continue;
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) continue;
            if (substr($key, -1) === '_') continue;
            $map[$key][$prop] = true;
        }
    }

    $globalVars = [];

    // $GLOBALS['foo'] without property
    if (preg_match_all('/\$GLOBALS\[("|\')([^"\']+)\1\]/', $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $g) {
            $key = $expand($g[2]);
            if (strpos($key, '{') !== false || strpos($key, '}') !== false) continue;
            if ($key === 'lang') continue;
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) continue;
            if (substr($key, -1) === '_') continue;
            if (!isset($map[$key])) {
                $map[$key] = [];
            }
        }
    }

    // $var = $GLOBALS['foo']; track property usage through $var->prop
    if (preg_match_all('/\$(?<var>[A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$GLOBALS\[("|\')([^"\']+)\2\]/', $content, $assigns, PREG_SET_ORDER)) {
        foreach ($assigns as $assign) {
            $key = $expand($assign[3]);
            if (strpos($key, '{') !== false || strpos($key, '}') !== false) continue;
            if ($key === 'lang') continue;
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) continue;
            if (substr($key, -1) === '_') continue;
            $globalVars[$assign['var']] = $key;
            if (!isset($map[$key])) {
                $map[$key] = [];
            }
        }
    }

    if ($globalVars && preg_match_all('/\$(?<var>[A-Za-z_][A-Za-z0-9_]*)->(?<prop>[A-Za-z_][A-Za-z0-9_]*)/', $content, $props, PREG_SET_ORDER)) {
        foreach ($props as $propMatch) {
            $var = $propMatch['var'];
            if (!isset($globalVars[$var])) {
                continue;
            }
            $key = $globalVars[$var];
            $prop = $propMatch['prop'];
            $map[$key][$prop] = true;
        }
    }

    // quoted token containing {$pad}
    if (preg_match_all('/["\']([A-Za-z0-9_-]*\{\$pad\}[A-Za-z0-9_-]*)["\']/', $content, $m)) {
        foreach ($m[1] as $raw) {
            $key = $expand($raw);
            if (strpos($key, '{') !== false || strpos($key, '}') !== false) continue;
            if ($key === 'lang') continue;
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) continue;
            if (substr($key, -1) === '_') continue;
            if (!isset($map[$key])) {
                $map[$key] = [];
            }
        }
    }

    // data-lang="foo_{$pad}_bar"
    if (preg_match_all('/data-lang="([^"]*\{\$pad\}[^"]*)"/', $content, $m)) {
        foreach ($m[1] as $raw) {
            $key = $expand($raw);
            if (strpos($key, '{') !== false || strpos($key, '}') !== false) continue;
            if ($key === 'lang') continue;
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) continue;
            if (substr($key, -1) === '_') continue;
            if (!isset($map[$key])) {
                $map[$key] = [];
            }
        }
    }

    $result = [];
    foreach ($map as $k => $props) {
        if (is_non_translatable_key($k)) {
            continue;
        }
        $result[$k] = array_keys($props);
    }

    return $result;
}

function merge_key_props(array &$target, string $key, $props): void {
    if (!isset($target[$key]) || $target[$key] === null || $target[$key] === []) {
        $target[$key] = $props ?? [];
        return;
    }

    if (!is_array($props) || $props === []) {
        return;
    }

    $current = $target[$key];
    if (!is_array($current)) {
        $target[$key] = $props;
        return;
    }

    $target[$key] = array_values(array_unique(array_merge($current, $props)));
}

function collect_key_map(array $controllers, array $templateMap): array {
    $uniq = [];
    foreach ($controllers as $c) {
        $key = $c['name'].'#'.$c['index'];
        if (!isset($uniq[$key])) {
            $uniq[$key] = $c;
        } else {
            // Keep max items when same controller/index appears multiple times
            $existing = $uniq[$key]['params']['items'] ?? 0;
            $newVal   = $c['params']['items'] ?? 0;
            if ($newVal > $existing) {
                $uniq[$key]['params']['items'] = $newVal;
            }
        }
    }
    $langKeys = [];
    foreach ($uniq as $c) {
        $pad = sprintf('%02d', $c['index']);
        $extracted = extract_keys($c['name'], $c['index']);
        $tmplGroup = $c['name'] . '_00';
        if (isset($templateMap[$tmplGroup])) {
            $tmplKeys = $templateMap[$tmplGroup];
            $items = $c['params']['items'] ?? null;
            if ($items !== null) {
                $letters = range('a','z');
                $itemPatterns = [];
                foreach ($tmplKeys as $tKey => $props) {
                    $parts = explode('_', $tKey);
                    $last  = end($parts);
                    if (strlen($last) === 1 && ctype_lower($last) && $last !== 'p') {
                        $pref = substr($tKey, 0, -2) . '_';
                        $itemPatterns[] = [$pref, '', $props];
                    } elseif (preg_match('/^(.*?_00_[^_]*?)([a-z])(_.*)$/', $tKey, $m)) {
                        if ($m[2] === 'a') {
                            $itemPatterns[] = [$m[1], $m[3], $props];
                        } else {
                            $k = str_replace('_00_', '_' . $pad . '_', $tKey);
                            merge_key_props($extracted, $k, $props);
                        }
                    } else {
                        $k = str_replace('_00_', '_' . $pad . '_', $tKey);
                        merge_key_props($extracted, $k, $props);
                    }
                }
                $limit = min($items, count($letters));
                for ($j = 0; $j < $limit; $j++) {
                    $letter = $letters[$j];
                    foreach ($itemPatterns as [$pref, $suf, $props]) {
                        $k = str_replace('_00_', '_' . $pad . '_', $pref) . $letter . $suf;
                        merge_key_props($extracted, $k, $props);
                    }
                }
            } else {
                foreach ($tmplKeys as $tKey => $props) {
                    $k = str_replace('_00_', '_' . $pad . '_', $tKey);
                    merge_key_props($extracted, $k, $props);
                }
            }
        }
        foreach ($extracted as $k => $props) {
            if (is_non_translatable_key($k)) {
                continue;
            }
            if (isset($langKeys[$k])) {
                $langKeys[$k] = array_unique(array_merge($langKeys[$k], $props));
            } else {
                $langKeys[$k] = $props;
            }
        }
    }
    foreach (array_keys($langKeys) as $key) {
        if (is_non_translatable_key($key)) {
            unset($langKeys[$key]);
        }
    }

    return $langKeys;
}

$langTemplates = load_language_templates($langs);
$templateFallback = build_template_fallback($langTemplates, $langs);

$slugsToProcess = $requestedSlugs;
if (!in_array('global', $slugsToProcess, true) && !empty($controllersBySlug['global'])) {
    $slugsToProcess[] = 'global';
}
$primarySlugs = array_values(array_filter($requestedSlugs, fn($s) => $s !== 'global'));

foreach ($slugsToProcess as $targetSlug) {
    if ($targetSlug === 'global') {
        $candidates = $controllersBySlug['global'] ?? [];
        if ($primarySlugs === []) {
            $list = $candidates;
        } else {
            $list = [];
            foreach ($candidates as $ctrl) {
                $key = $ctrl['name'] . '#' . $ctrl['index'];
                foreach ($primarySlugs as $originSlug) {
                    if (isset($globalUsage[$key][$originSlug])) {
                        $list[] = $ctrl;
                        break;
                    }
                }
            }
        }
    } else {
        $list = $controllersBySlug[$targetSlug] ?? [];
    }

    if ($targetSlug === 'global' && empty($list)) {
        continue;
    }

    $langKeys = collect_key_map($list, $templateMap);

    $inlineKeys = $inlineKeysBySlug[$targetSlug] ?? [];
    if ($targetSlug === 'global' && $primarySlugs !== []) {
        $filtered = [];
        foreach ($inlineKeys as $key => $props) {
            foreach ($primarySlugs as $originSlug) {
                if (isset($inlineGlobalUsage[$key][$originSlug])) {
                    $filtered[$key] = $props;
                    break;
                }
            }
        }
        $inlineKeys = $filtered;
    }

    merge_inline_key_sets($langKeys, $inlineKeys);

    foreach ($langs as $lang) {
        $dir = $appPath . '/config/languages/' . $targetSlug;
        $template = $langTemplates[$lang] ?? [];
        $removeMissing = ($targetSlug !== 'global');
        $file = "$dir/$lang.json";
        if (!$langKeys && !file_exists($file)) {
            continue;
        }
        update_lang_file($dir, $lang, $langKeys, $template, $removeMissing, $templateFallback);
    }
}
