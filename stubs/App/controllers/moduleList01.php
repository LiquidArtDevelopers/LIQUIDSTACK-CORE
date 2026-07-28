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

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $key    = "moduleList01_{$pad}_{$letter}_li_text";
        $item   = $GLOBALS[$key] ?? $getTemplateLang($key);
        $text   = is_object($item) ? ($item->text ?? '') : '';

        $itemsHtml .= '<li class="moduleList01-item">'
            . '<span class="moduleList01-marker" aria-hidden="true"></span>'
            . '<span data-lang="' . $key . '">' . $text . '</span>'
            . '</li>';
    }

    $vars = [
        '{classVar}' => "moduleList01_{$pad}_classVar",
        '{items}'    => $itemsHtml,
    ];

    return render(
        'App/templates/_moduleList01.html',
        array_replace($vars, $params)
    );
}
?>
