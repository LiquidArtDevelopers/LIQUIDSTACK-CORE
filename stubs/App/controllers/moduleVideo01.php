<?php
/**
 * Directrices de contenido para moduleVideo01:
 * - Title del vídeo: 4-10 palabras que describan con precisión la pieza.
 * - Mensaje de consentimiento: 12-25 palabras y una instrucción comprensible.
 * - Pista local opcional: etiqueta breve de 1-3 palabras.
 * - YouTube usa fachada ligera; el iframe solo se crea al pulsar reproducir.
 * - Sin `type`, el proveedor se edita por idioma mediante la clave settings.
 * - Con `type => youtube|local`, PHP fija el proveedor y el editor no lo cambia.
 */
function controller_moduleVideo01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $hasTypeOverride = array_key_exists('type', $params);
    $requestedType = strtolower(trim((string) ($params['type'] ?? 'youtube')));
    unset($params['type']);

    if (!in_array($requestedType, ['youtube', 'local'], true)) {
        $requestedType = 'youtube';
    }

    $currentLang = (string) ($GLOBALS['lang'] ?? $_ENV['LANG_DEFAULT'] ?? 'es');
    $currentLang = preg_match('/^[A-Za-z0-9_-]+$/', $currentLang) === 1
        ? $currentLang
        : 'es';

    $getTemplateLang = static function (
        string $languageKey,
        string $fallbackKey
    ) use ($currentLang) {
        static $templateLang = null;

        if ($templateLang === null) {
            $file         = __DIR__ . '/../config/languages/templates/' . $currentLang . '.json';
            $json         = is_readable($file) ? file_get_contents($file) : '{}';
            $decoded      = json_decode($json);
            $templateLang = is_object($decoded) ? $decoded : new stdClass();
        }

        if (isset($templateLang->{$languageKey})) {
            return $templateLang->{$languageKey};
        }

        return $fallbackKey !== ''
            ? ($templateLang->{$fallbackKey} ?? null)
            : null;
    };

    $readEntry = static function (
        string $key,
        string $fallbackKey
    ) use ($getTemplateLang) {
        return $GLOBALS[$key] ?? $getTemplateLang($key, $fallbackKey);
    };

    $readField = static function ($entry, string $field): string {
        if (is_object($entry) && isset($entry->{$field})) {
            return (string) $entry->{$field};
        }

        if (is_array($entry) && isset($entry[$field])) {
            return (string) $entry[$field];
        }

        return '';
    };

    $escapeAttr = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $normalizeYoutubeUrl = static function (string $input): string {
        $value = trim(html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '') {
            return '';
        }

        $videoId = '';

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $value) === 1) {
            $videoId = $value;
        } else {
            if (str_starts_with($value, '//')) {
                $value = 'https:' . $value;
            } elseif (preg_match('#^https?://#i', $value) !== 1) {
                $value = 'https://' . ltrim($value, '/');
            }

            $parts = parse_url($value);
            if (
                !is_array($parts)
                || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['port'])
            ) {
                return '';
            }

            $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
            foreach (['www.', 'm.'] as $prefix) {
                if (str_starts_with($host, $prefix)) {
                    $host = substr($host, strlen($prefix));
                    break;
                }
            }

            $path     = trim((string) ($parts['path'] ?? ''), '/');
            $segments = $path === '' ? [] : explode('/', $path);

            if ($host === 'youtu.be') {
                $videoId = (string) ($segments[0] ?? '');
            } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
                if (($segments[0] ?? '') === 'watch') {
                    parse_str((string) ($parts['query'] ?? ''), $query);
                    $videoId = is_string($query['v'] ?? null) ? $query['v'] : '';
                } elseif (
                    in_array(
                        (string) ($segments[0] ?? ''),
                        ['embed', 'shorts', 'live'],
                        true
                    )
                ) {
                    $videoId = (string) ($segments[1] ?? '');
                }
            }
        }

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) {
            return '';
        }

        return 'https://www.youtube-nocookie.com/embed/' . $videoId;
    };

    $localAssetUrl = static function (
        string $input,
        array $allowedExtensions
    ): string {
        $value   = trim($input);
        $decoded = $value;

        for ($decodePass = 0; $decodePass < 5; $decodePass++) {
            $nextDecoded = rawurldecode($decoded);
            if ($nextDecoded === $decoded) {
                break;
            }

            $decoded = $nextDecoded;
        }

        if (
            $value === ''
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $decoded) === 1
            || preg_match('/%(?:00|2e|2f|5c)/i', $decoded) === 1
            || str_contains($decoded, '..')
            || str_starts_with($decoded, '//')
            || parse_url($decoded, PHP_URL_SCHEME) !== null
        ) {
            return '';
        }

        $path      = ltrim($decoded, '/');
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            return '';
        }

        $rootUrl = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');
        return ($rootUrl !== '' ? $rootUrl : '') . '/' . $path;
    };

    $settingsKey = "moduleVideo01_{$pad}_settings";
    $settings    = $hasTypeOverride
        ? null
        : ($GLOBALS[$settingsKey] ?? $getTemplateLang($settingsKey, ''));
    $storedType  = strtolower(trim($readField($settings, 'type')));
    $type        = !$hasTypeOverride
        && in_array($storedType, ['youtube', 'local'], true)
            ? $storedType
            : $requestedType;

    $devMode = filter_var(
        $_ENV['DEV_MODE'] ?? getenv('DEV_MODE') ?? false,
        FILTER_VALIDATE_BOOLEAN
    );
    $renderDynamicProviders = $devMode || !$hasTypeOverride;

    $typeFieldMeta = $escapeAttr((string) json_encode([
        'type' => [
            'label'       => 'Tipo de vídeo',
            'controlType' => 'select',
            'options'     => [
                ['label' => 'YouTube', 'value' => 'youtube'],
                ['label' => 'Vídeo local', 'value' => 'local'],
            ],
            'helpText' => 'Elige el proveedor para el idioma actual y completa también sus campos asociados.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $typeAttributeTargets = $escapeAttr((string) json_encode([
        'type' => 'data-video-type',
    ], JSON_UNESCAPED_SLASHES));

    $editorAttributes = $devMode
        ? ' data-inline-group="moduleVideo01_' . $pad . '"'
            . ' data-inline-video'
            . ' data-inline-field-meta="' . $typeFieldMeta . '"'
            . ' data-inline-attribute-targets="' . $typeAttributeTargets . '"'
        : '';
    $editorHandle = $devMode
        ? '<button class="moduleVideo01-editorHandle" type="button"'
            . ' aria-label="Editar moduleVideo01 con Control y doble clic">'
            . 'Ctrl + doble clic: editar vídeo</button>'
        : '';
    $settingsAttribute = !$hasTypeOverride
        ? ' data-lang="' . $escapeAttr($settingsKey) . '"'
        : '';

    $youtubeKey      = "moduleVideo01_{$pad}_youtube";
    $youtubeFallback = 'moduleVideo01_00_youtube';
    $consentKey      = "moduleVideo01_{$pad}_consent";
    $consentFallback = 'moduleVideo01_00_consent';
    $videoKey        = "moduleVideo01_{$pad}_video";
    $webmKey         = "moduleVideo01_{$pad}_webm";
    $mp4Key          = "moduleVideo01_{$pad}_mp4";
    $captionKey      = "moduleVideo01_{$pad}_captions";

    $youtube = $readEntry($youtubeKey, $youtubeFallback);
    $consent = $readEntry($consentKey, $consentFallback);
    $video   = $readEntry($videoKey, 'moduleVideo01_01_video');
    $webm    = $readEntry($webmKey, 'moduleVideo01_01_webm');
    $mp4     = $readEntry($mp4Key, 'moduleVideo01_01_mp4');
    $captions = $readEntry($captionKey, 'moduleVideo01_01_captions');

    $youtubeUrl   = $normalizeYoutubeUrl($readField($youtube, 'src'));
    $youtubeTitle = $readField($youtube, 'title');
    $youtubePlayLabel = $readField($youtube, 'playLabel');
    $consentText  = $readField($consent, 'text');
    $posterUrl    = $localAssetUrl(
        $readField($video, 'poster'),
        ['avif', 'gif', 'jpeg', 'jpg', 'png', 'webp']
    );
    $webmUrl  = $localAssetUrl($readField($webm, 'src'), ['webm']);
    $mp4Url   = $localAssetUrl($readField($mp4, 'src'), ['mp4']);
    $trackUrl = $localAssetUrl($readField($captions, 'src'), ['vtt']);

    $trackKind = strtolower($readField($captions, 'kind'));
    if (!in_array(
        $trackKind,
        ['captions', 'subtitles', 'descriptions', 'chapters', 'metadata'],
        true
    )) {
        $trackKind = 'captions';
    }

    $trackLang = $readField($captions, 'srclang');
    if (preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $trackLang) !== 1) {
        $trackLang = $currentLang;
    }

    $optionalPoster = $posterUrl !== ''
        ? ' poster="' . $escapeAttr($posterUrl) . '"'
        : '';
    $optionalWebm = $webmUrl !== ''
        ? ' src="' . $escapeAttr($webmUrl) . '"'
        : '';
    $optionalMp4 = $mp4Url !== ''
        ? ' src="' . $escapeAttr($mp4Url) . '"'
        : '';
    $optionalTrack = $trackUrl !== ''
        ? ' src="' . $escapeAttr($trackUrl) . '"'
        : '';

    $youtubeFieldMeta = $escapeAttr((string) json_encode([
        'src' => [
            'label'    => 'URL o ID de YouTube',
            'helpText' => 'Se normaliza al dominio youtube-nocookie.com.',
        ],
        'title' => [
            'label'    => 'Título accesible del vídeo',
            'helpText' => 'Describe el contenido del vídeo, no la acción del botón.',
        ],
        'playLabel' => [
            'label'    => 'Texto accesible de reproducción',
            'helpText' => 'Acción breve y traducida, por ejemplo: Reproducir vídeo.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $youtubeAttributeTargets = $escapeAttr((string) json_encode([
        'playLabel' => 'data-video-play-label',
    ], JSON_UNESCAPED_SLASHES));
    $youtubeEditorRules = $devMode
        ? ' data-inline-src-target="data-video-src"'
            . ' data-inline-youtube-url="true"'
            . ' data-inline-field-meta="' . $youtubeFieldMeta . '"'
            . ' data-inline-attribute-targets="' . $youtubeAttributeTargets . '"'
        : '';
    $videoEditorRules = $devMode
        ? ' data-inline-local-extensions="avif,gif,jpeg,jpg,png,webp"'
        : '';
    $webmEditorRules = $devMode
        ? ' data-inline-local-extensions="webm"'
        : '';
    $mp4EditorRules = $devMode
        ? ' data-inline-local-extensions="mp4"'
        : '';
    $trackFieldMeta = $escapeAttr((string) json_encode([
        'kind' => [
            'label'       => 'Tipo de pista',
            'controlType' => 'select',
            'options'     => [
                ['label' => 'Subtítulos descriptivos', 'value' => 'captions'],
                ['label' => 'Subtítulos', 'value' => 'subtitles'],
                ['label' => 'Descripciones', 'value' => 'descriptions'],
                ['label' => 'Capítulos', 'value' => 'chapters'],
                ['label' => 'Metadatos', 'value' => 'metadata'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $trackEditorRules = $devMode
        ? ' data-inline-local-extensions="vtt"'
            . ' data-inline-field-meta="' . $trackFieldMeta . '"'
        : '';

    $sourcesHtml = '';
    if ($webmUrl !== '' || $renderDynamicProviders) {
        $sourcesHtml .= '<source data-lang="' . $escapeAttr($webmKey) . '"'
            . $webmEditorRules
            . $optionalWebm . ' type="video/webm">';
    }
    if ($mp4Url !== '' || $renderDynamicProviders) {
        $sourcesHtml .= '<source data-lang="' . $escapeAttr($mp4Key) . '"'
            . $mp4EditorRules
            . $optionalMp4 . ' type="video/mp4">';
    }

    $trackHtml = '';
    if ($trackUrl !== '' || $renderDynamicProviders) {
        $trackHtml = '<track data-lang="' . $escapeAttr($captionKey) . '"'
            . $trackEditorRules
            . $optionalTrack
            . ' kind="' . $escapeAttr($trackKind) . '"'
            . ' srclang="' . $escapeAttr($trackLang) . '"'
            . ' label="' . $escapeAttr($readField($captions, 'label')) . '"'
            . ' default>';
    }

    $youtubeHidden = $type === 'youtube' ? '' : ' hidden';
    $localHidden   = $type === 'local' ? '' : ' hidden';
    $localPreload  = $type === 'local' ? 'metadata' : 'none';
    $accessiblePlayLabel = trim($youtubePlayLabel) !== ''
        ? trim($youtubePlayLabel . ': ' . $youtubeTitle)
        : $youtubeTitle;

    $youtubeHtml = '<div class="moduleVideo01-youtubeSlot"'
        . ' data-module-video-youtube'
        . ' data-lang="' . $escapeAttr($youtubeKey) . '"'
        . ' data-video-src="' . $escapeAttr($youtubeUrl) . '"'
        . ' data-video-play-label="' . $escapeAttr($youtubePlayLabel) . '"'
        . $youtubeEditorRules
        . ' title="' . $escapeAttr($youtubeTitle) . '"'
        . $youtubeHidden . '>'
        . '<div class="moduleVideo01-consent" data-module-video-consent>'
        . '<p data-lang="' . $escapeAttr($consentKey) . '">'
        . $consentText
        . '</p>'
        . '</div>'
        . '<button class="moduleVideo01-lite" type="button"'
        . ' data-module-video-play hidden'
        . ' aria-label="' . $escapeAttr($accessiblePlayLabel) . '">'
        . '<img class="moduleVideo01-thumbnail"'
        . ' data-module-video-thumbnail alt=""'
        . ' loading="lazy" decoding="async" referrerpolicy="no-referrer">'
        . '<span class="moduleVideo01-playIcon" aria-hidden="true"></span>'
        . '</button>'
        . '</div>';

    $localHtml = '<video class="moduleVideo01-video"'
        . ' data-module-video-local'
        . ' data-lang="' . $escapeAttr($videoKey) . '"'
        . $videoEditorRules
        . $optionalPoster
        . ' title="' . $escapeAttr($readField($video, 'title')) . '"'
        . ' controls playsinline preload="' . $localPreload . '"'
        . $localHidden . '>'
        . $sourcesHtml
        . $trackHtml
        . '</video>';

    // Sin override se conservan ambas ramas para poder alternar el proveedor
    // al cambiar de idioma. Con un `type` fijo, producción solo necesita la
    // rama activa; en DEV se mantienen ambas para editar todos sus campos.
    $mediaHtml = $renderDynamicProviders
        ? $youtubeHtml . $localHtml
        : ($type === 'local' ? $localHtml : $youtubeHtml);

    $vars = [
        '{classVar}'          => "moduleVideo01_{$pad}_classVar",
        '{type-class}'        => 'moduleVideo01--' . $type,
        '{settings-attribute}' => $settingsAttribute,
        '{video-type}'        => $escapeAttr($type),
        '{editor-attributes}' => $editorAttributes,
        '{editor-handle}'     => $editorHandle,
        '{media}'             => $mediaHtml,
    ];

    return render(
        'App/templates/_moduleVideo01.html',
        array_replace($vars, $params)
    );
}
?>
