<?php
/**
 * Directrices de copy para artVideo01:
 * - Encabezado principal: 4-10 palabras e identificador del recurso en showroom.
 * - Contenido inyectado: 25-70 palabras o una lista de 3-6 puntos.
 * - CTA opcional: 2-5 palabras con una acción clara.
 */
function controller_artVideo01(int $i = 0, array $params = []): string
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
            '/^artVideo01_\d{2}_/',
            'artVideo01_00_',
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

    $mediaPosition = strtolower(trim((string) ($params['media_position'] ?? 'end')));
    unset($params['media_position']);

    if (!in_array($mediaPosition, ['start', 'end'], true)) {
        $mediaPosition = 'end';
    }

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

    $headerKey = "artVideo01_{$pad}_headerPrimary";

    $vars = [
        '{classVar}'       => "artVideo01_{$pad}_classVar",
        '{position-class}' => 'artVideo01--media-' . $mediaPosition,
        '{header-primary}' => '<h' . $baseLevel
            . ' class="artVideo01-title" data-lang="' . $headerKey . '">'
            . $getText($headerKey)
            . '</h' . $baseLevel . '>',
        '{content}'        => $contentHtml !== ''
            ? '<div class="artVideo01-copy">' . $contentHtml . '</div>'
            : '',
        '{cta}'            => $buttonHtml !== ''
            ? '<div class="artVideo01-cta">' . $buttonHtml . '</div>'
            : '',
        '{media}'          => $videoHtml !== ''
            ? '<div class="artVideo01-media">' . $videoHtml . '</div>'
            : '',
    ];

    return render(
        'App/templates/_artVideo01.html',
        array_replace($vars, $params)
    );
}
?>
