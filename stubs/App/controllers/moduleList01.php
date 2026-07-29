<?php
/**
 * Directrices de copy para moduleList01:
 * - Ítems: 4-12 palabras, paralelos entre sí y centrados en beneficios concretos.
 */
function controller_moduleList01(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = isset($params['items']) ? (int) $params['items'] : 4;
    $itemsCount = max(0, min($itemsCount, count($letters)));
    unset($params['items']);

    $currentLang = (string) ($GLOBALS['lang'] ?? $_ENV['LANG_DEFAULT'] ?? 'es');
    $currentLang = preg_match('/^[A-Za-z0-9_-]+$/', $currentLang) === 1
        ? $currentLang
        : 'es';

    $getTemplateLang = static function (string $languageKey) use ($currentLang) {
        static $templateLang = null;

        if ($templateLang === null) {
            $file         = __DIR__ . '/../config/languages/templates/' . $currentLang . '.json';
            $json         = is_readable($file) ? file_get_contents($file) : '{}';
            $decoded      = json_decode($json);
            $templateLang = is_object($decoded) ? $decoded : new stdClass();
        }

        $templateKey = preg_replace(
            '/^moduleList01_\d{2}_/',
            'moduleList01_00_',
            $languageKey
        );

        return $templateLang->{$languageKey}
            ?? ($templateKey !== null ? ($templateLang->{$templateKey} ?? null) : null);
    };

    $markerIcons = [
        'default' => [
            'label' => 'Predeterminado del recurso',
            'src'   => '',
        ],
        'check' => [
            'label' => 'Check',
            'src'   => 'assets/img/system/check-OK.svg',
        ],
        'shield' => [
            'label' => 'Escudo',
            'src'   => 'assets/img/system/shield-checkmark-outline.svg',
        ],
        'compass' => [
            'label' => 'Brújula',
            'src'   => 'assets/img/system/compass-outline.svg',
        ],
        'people' => [
            'label' => 'Personas',
            'src'   => 'assets/img/system/people.svg',
        ],
        'star' => [
            'label' => 'Estrella',
            'src'   => 'assets/img/system/star-outline.svg',
        ],
        'ribbon' => [
            'label' => 'Distintivo',
            'src'   => 'assets/img/system/ribbon-outline.svg',
        ],
        'book' => [
            'label' => 'Libro',
            'src'   => 'assets/img/system/book-outline.svg',
        ],
        'school' => [
            'label' => 'Formación',
            'src'   => 'assets/img/system/school-outline.svg',
        ],
        'chart' => [
            'label' => 'Gráfico',
            'src'   => 'assets/img/system/stats-chart-outline.svg',
        ],
        'speedometer' => [
            'label' => 'Indicador',
            'src'   => 'assets/img/system/speedometer-outline.svg',
        ],
        'sparkles' => [
            'label' => 'Destacado',
            'src'   => 'assets/img/system/sparkles-outline.svg',
        ],
        'time' => [
            'label' => 'Tiempo',
            'src'   => 'assets/img/system/time.svg',
        ],
        'none' => [
            'label' => 'Sin icono',
            'src'   => '',
        ],
    ];

    $markerKey   = "moduleList01_{$pad}_marker_icon";
    $markerValue = $GLOBALS[$markerKey] ?? $getTemplateLang($markerKey) ?? 'default';
    if (is_object($markerValue)) {
        $markerValue = $markerValue->value ?? $markerValue->text ?? 'default';
    }

    $markerToken = strtolower(trim((string) $markerValue));
    if (!array_key_exists($markerToken, $markerIcons)) {
        $markerToken = 'default';
    }

    $escapeAttr = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $rootUrl   = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');
    $devMode   = filter_var(
        $_ENV['DEV_MODE'] ?? getenv('DEV_MODE') ?? false,
        FILTER_VALIDATE_BOOLEAN
    );
    $markerSrc = (string) ($markerIcons[$markerToken]['src'] ?? '');
    $markerUrl = $markerSrc === ''
        ? ''
        : ($rootUrl !== '' ? $rootUrl . '/' : '/') . ltrim($markerSrc, '/');

    $markerStyle = $markerUrl === ''
        ? ''
        : ' style="--moduleList01-marker-mask: url(\''
            . $escapeAttr($markerUrl) . '\')"';

    $editorAttributes = 'data-marker-icon="' . $escapeAttr($markerToken) . '"'
        . $markerStyle;

    if ($devMode) {
        $iconOptions = [];
        foreach ($markerIcons as $token => $icon) {
            $src = (string) ($icon['src'] ?? '');
            $iconOptions[] = [
                'value'   => $token,
                'label'   => (string) ($icon['label'] ?? $token),
                'preview' => $src === ''
                    ? ''
                    : ($rootUrl !== '' ? $rootUrl . '/' : '/') . ltrim($src, '/'),
            ];
        }

        $optionsJson = json_encode(
            $iconOptions,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '[]';

        $editorAttributes .= ' data-inline-collection="lines"'
            . ' data-inline-collection-key="moduleList01_' . $pad . '"'
            . ' data-inline-icon-key="' . $escapeAttr($markerKey) . '"'
            . ' data-inline-icon-options="' . $escapeAttr($optionsJson) . '"';
    }

    $itemEditorAttribute = $devMode ? ' data-inline-collection-item' : '';

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $key    = "moduleList01_{$pad}_{$letter}_li_text";
        $item   = $GLOBALS[$key] ?? $getTemplateLang($key);
        $text   = is_object($item) ? ($item->text ?? '') : '';

        $itemsHtml .= '<li class="moduleList01-item">'
            . '<span class="moduleList01-marker" aria-hidden="true"></span>'
            . '<span' . $itemEditorAttribute . ' data-lang="' . $key . '">' . $text . '</span>'
            . '</li>';
    }

    $vars = [
        '{classVar}'          => "moduleList01_{$pad}_classVar",
        '{editor-attributes}' => $editorAttributes,
        '{items}'            => $itemsHtml,
    ];

    return render(
        'App/templates/_moduleList01.html',
        array_replace($vars, $params)
    );
}
?>
