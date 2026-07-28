<?php
/**
 * Directrices de copy para moduleParrafo01:
 * - Párrafo: 25-55 palabras con una idea completa y lenguaje comprensible.
 */
function controller_moduleParrafo01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $key = "moduleParrafo01_{$pad}_p_text";

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
            '/^moduleParrafo01_\d{2}_/',
            'moduleParrafo01_00_',
            $languageKey
        );

        return $templateLang->{$languageKey}
            ?? ($templateKey !== null ? ($templateLang->{$templateKey} ?? null) : null);
    };

    $paragraph = $GLOBALS[$key] ?? $getTemplateLang($key);

    $vars = [
        '{classVar}' => "moduleParrafo01_{$pad}_classVar",
        '{p-dl}'     => $key,
        '{p-text}'   => is_object($paragraph) ? ($paragraph->text ?? '') : '',
    ];

    return render(
        'App/templates/_moduleParrafo01.html',
        array_replace($vars, $params)
    );
}
?>
