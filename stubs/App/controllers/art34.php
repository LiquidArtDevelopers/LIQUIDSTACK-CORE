<?php
/**
 * Directrices de copy para art34:
 * - Encabezado principal: 6-12 palabras que presenten el bloque.
 * - Encabezados de ficha: 4-9 palabras orientadas a un beneficio.
 * - Contenido: se inyecta mediante {a-content}, {b-content}, etc.; usar
 *   moduleParrafo01 para párrafos y moduleList01 para listas editables.
 * - CTA por ficha opcional: {a-button-primary}, {b-button-primary}, etc.
 * - CTA general opcional: {button-primary}; queda dentro del article.
 * - La raíz es article (h3 natural) y cada ficha es div (h4 natural).
 */
function controller_art34(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = isset($params['items']) ? (int) $params['items'] : 2;
    $itemsCount = max(1, min($itemsCount, count($letters)));
    unset($params['items']);

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $currentLang = (string) ($GLOBALS['lang'] ?? $_ENV['LANG_DEFAULT'] ?? 'es');
    $currentLang = preg_match('/^[A-Za-z0-9_-]+$/', $currentLang) === 1
        ? $currentLang
        : 'es';

    $getLanguageValue = static function (string $key) use ($currentLang) {
        static $catalogs = [];

        if (!array_key_exists($currentLang, $catalogs)) {
            $file    = __DIR__ . '/../config/languages/templates/' . $currentLang . '.json';
            $json    = is_readable($file) ? file_get_contents($file) : '{}';
            $decoded = json_decode((string) $json);
            $catalogs[$currentLang] = is_object($decoded)
                ? $decoded
                : new stdClass();
        }

        $templateKey = preg_replace(
            '/^art34_\d{2}_/',
            'art34_00_',
            $key
        );

        return $GLOBALS[$key]
            ?? $catalogs[$currentLang]->{$key}
            ?? ($templateKey !== null
                ? ($catalogs[$currentLang]->{$templateKey} ?? null)
                : null);
    };

    $readText = static function ($value): string {
        return is_object($value) ? (string) ($value->text ?? '') : '';
    };

    $itemTemplate = <<<'HTML'
        <div class="art34-item art34-item--{X-letter}">
            {X-header-secondary}
            {X-content}
            {X-button-primary}
        </div>
    HTML;

    $itemsHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter    = $letters[$j];
        $headerKey = "art34_{$pad}_headerSecondary_{$letter}";
        $headerObj = $getLanguageValue($headerKey);

        if (!is_object($headerObj)) {
            $headerObj = $getLanguageValue("art34_{$pad}_headerSecondary_a");
        }

        $contentKey  = '{' . $letter . '-content}';
        $content     = trim((string) ($params[$contentKey] ?? ''));
        $contentHtml = $content === ''
            ? ''
            : '<div class="art34-itemContent">' . $content . '</div>';
        unset($params[$contentKey]);

        $buttonKey  = '{' . $letter . '-button-primary}';
        $button     = trim((string) ($params[$buttonKey] ?? ''));
        $buttonHtml = $button === ''
            ? ''
            : '<div class="art34-itemCta">' . $button . '</div>';
        unset($params[$buttonKey]);

        $itemsHtml .= str_replace(
            [
                '{X-letter}',
                '{X-header-secondary}',
                '{X-content}',
                '{X-button-primary}',
            ],
            [
                $letter,
                '<h' . $itemLevel . ' class="art34-itemTitle" data-lang="'
                    . $headerKey . '">' . $readText($headerObj)
                    . '</h' . $itemLevel . '>',
                $contentHtml,
                $buttonHtml,
            ],
            $itemTemplate
        );
    }

    $headerKey      = "art34_{$pad}_headerPrimary";
    $externalHeader = trim((string) ($params['{header-primary}'] ?? ''));
    unset($params['{header-primary}']);

    $headerHtml = $externalHeader !== ''
        ? $externalHeader
        : '<h' . $baseLevel . ' class="art34-title" data-lang="'
            . $headerKey . '">' . $readText($getLanguageValue($headerKey))
            . '</h' . $baseLevel . '>';

    $rootButton = trim((string) ($params['{button-primary}'] ?? ''));
    unset($params['{button-primary}']);
    $rootButtonHtml = $rootButton === ''
        ? ''
        : '<div class="art34-ctaSlot">' . $rootButton . '</div>';

    $vars = [
        '{classVar}'       => "art34_{$pad}_classVar",
        '{items-class}'    => 'art34--items-' . $itemsCount,
        '{header-primary}' => $headerHtml,
        '{items}'          => $itemsHtml,
        '{button-primary}' => $rootButtonHtml,
    ];

    return render(
        'App/templates/_art34.html',
        array_replace($vars, $params)
    );
}
?>
