<?php
/**
 * Directrices de copy para art25:
 * - Encabezado principal: 5-9 palabras con una propuesta de valor concreta.
 * - Primer párrafo: 18-30 palabras para presentar el contexto o necesidad.
 * - Segundo párrafo: 18-30 palabras para explicar el enfoque o beneficio.
 * - CTA inyectable: 2-5 palabras orientadas a una acción clara.
 */
function controller_art25(int $i = 0, array $params = []): string
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

    $headerKey = "art25_{$pad}_headerPrimary";
    $p01Key    = "art25_{$pad}_p01";
    $p02Key    = "art25_{$pad}_p02";

    $vars = [
        '{classVar}'       => "art25_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' class="art25-title" data-lang="' . $headerKey . '">' . $getText($headerKey) . '</h' . $baseLevel . '>',
        '{p01-dl}'         => $p01Key,
        '{p01-text}'       => $getText($p01Key),
        '{p02-dl}'         => $p02Key,
        '{p02-text}'       => $getText($p02Key),
        '{button-primary}' => '',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art25.html', $vars);
}
?>
