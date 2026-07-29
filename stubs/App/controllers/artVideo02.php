<?php
/**
 * Directrices de copy para artVideo02:
 * - Encabezado principal: 4-10 palabras e identificador del recurso en showroom.
 * - Contenido inyectado: 25-70 palabras o una lista de 3-6 puntos.
 * - CTA opcional: 2-5 palabras con una acción clara.
 */
function controller_artVideo02(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

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
            '/^artVideo02_\d{2}_/',
            'artVideo02_00_',
            $languageKey
        );

        return $templateLang->{$languageKey}
            ?? ($templateKey !== null ? ($templateLang->{$templateKey} ?? null) : null);
    };

    $getText = static function (string $key) use ($getTemplateLang): string {
        $value = $GLOBALS[$key] ?? $getTemplateLang($key);

        if (is_object($value) && isset($value->text)) {
            return (string) $value->text;
        }

        if (is_array($value) && isset($value['text'])) {
            return (string) $value['text'];
        }

        return '';
    };

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];

    $contentHtml = isset($params['{content}'])
        ? trim((string) $params['{content}'])
        : '';
    $videoHtml = isset($params['{video}'])
        ? trim((string) $params['{video}'])
        : '';
    $buttonHtml = isset($params['{button-primary}'])
        ? trim((string) $params['{button-primary}'])
        : '';

    unset(
        $params['{content}'],
        $params['{video}'],
        $params['{button-primary}']
    );

    $headerKey = "artVideo02_{$pad}_headerPrimary";

    $vars = [
        '{classVar}'       => "artVideo02_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel
            . ' class="artVideo02-title" data-lang="' . $headerKey . '">'
            . $getText($headerKey)
            . '</h' . $baseLevel . '>',
        '{content}'        => $contentHtml !== ''
            ? '<div class="artVideo02-copy">' . $contentHtml . '</div>'
            : '',
        '{cta}'            => $buttonHtml !== ''
            ? '<div class="artVideo02-cta">' . $buttonHtml . '</div>'
            : '',
        '{media}'          => $videoHtml !== ''
            ? '<div class="artVideo02-media">' . $videoHtml . '</div>'
            : '',
    ];

    return render(
        'App/templates/_artVideo02.html',
        array_replace($vars, $params)
    );
}
?>
