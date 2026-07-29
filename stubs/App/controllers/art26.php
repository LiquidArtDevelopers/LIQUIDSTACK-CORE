<?php
/**
 * Directrices de copy para art26:
 * - Encabezado principal: 5-10 palabras con una idea diferencial.
 * - Párrafo: 24-40 palabras que desarrollen la propuesta y su resultado.
 * - CTA inyectable: 2-5 palabras con una acción inequívoca.
 */
function controller_art26(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $getText = static function (string $key): string {
        $value = $GLOBALS[$key] ?? null;

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

    $headerKey = "art26_{$pad}_headerPrimary";
    $pKey      = "art26_{$pad}_p";
    $buttonPrimary = isset($params['{button-primary}'])
        ? trim((string) $params['{button-primary}'])
        : '';
    unset($params['{button-primary}']);
    $hasCta = $buttonPrimary !== '';

    $vars = [
        '{classVar}'       => "art26_{$pad}_classVar",
        '{content-class}'  => $hasCta ? ' art26-content--has-cta' : '',
        '{header-primary}' => '<h' . $baseLevel . ' class="art26-title" data-lang="' . $headerKey . '">' . $getText($headerKey) . '</h' . $baseLevel . '>',
        '{p-dl}'           => $pKey,
        '{p-text}'         => $getText($pKey),
        '{cta}'            => $hasCta
            ? '<div class="art26-cta">' . $buttonPrimary . '</div>'
            : '',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art26.html', $vars);
}
?>
