<?php
/**
 * Directrices de copy para art11:
 * - Nota opcional: hasta 18 palabras con promesa de servicio.
 * - Labels de indicadores: 6‑12 palabras, descriptivas y sin tecnicismos complejos.
 * - Evita repetir cifras; enfoca en cobertura, tiempos o ámbitos clave.
 */
function controller_art11(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = max(0, (int) ($params['items'] ?? 3));

    $itemTpl = <<<HTML
        <div>
            <span class="stat-number"
                  data-target="%s"
                  data-suffix="%s">0</span>
            <span class="stat-label"
                  data-lang="%s">%s</span>
        </div>
    HTML;

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j] ?? null;

        if ($letter === null) {
            break;
        }

        $targetKey = "art11_{$pad}_{$letter}_target";
        $suffixKey = "art11_{$pad}_{$letter}_suffix";
        $labelKey  = "art11_{$pad}_{$letter}_label";

        $targetObj = $GLOBALS["art11_{$pad}_{$letter}_target"] ?? null;
        $suffixObj = $GLOBALS["art11_{$pad}_{$letter}_suffix"] ?? null;
        $labelObj  = $GLOBALS["art11_{$pad}_{$letter}_label"] ?? null;

        $targetValue = is_object($targetObj) && isset($targetObj->value) ? (string) $targetObj->value : '';
        $suffixText  = is_object($suffixObj) && isset($suffixObj->text) ? (string) $suffixObj->text : '';
        $labelText   = is_object($labelObj) && isset($labelObj->text) ? (string) $labelObj->text : '';

        $itemsHtml .= sprintf(
            $itemTpl,
            htmlspecialchars($targetValue, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($suffixText, ENT_QUOTES, 'UTF-8'),
            $labelKey,
            $labelText
        ) . PHP_EOL;
    }

    $headerKey  = "art11_{$pad}_headerPrimary";
    // Mantener la clave literal en $GLOBALS[...] permite que el updater
    // detecte correctamente las subpropiedades (p. ej. ->text) al escanear
    // el controlador. Si usamos $GLOBALS[$headerKey], el script interpreta
    // la entrada como un string plano y elimina la estructura {"text": ""}.
    $headerObj  = $GLOBALS["art11_{$pad}_headerPrimary"] ?? null;
    $headerText = is_object($headerObj) && isset($headerObj->text) ? $headerObj->text : '';

    $noteKey  = "art11_{$pad}_span_optional";
    $noteObj  = $GLOBALS["art11_{$pad}_span_optional"] ?? null;
    $noteText = is_object($noteObj) && isset($noteObj->text) ? $noteObj->text : '';
    $noteHtml = $noteText !== ''
        ? '<span data-lang="' . $noteKey . '">' . $noteText . '</span>'
        : '';

    $vars = [
        '{header-primary}' => '<h3 data-lang="' . $headerKey . '">' . $headerText . '</h3>',
        '{items}'          => $itemsHtml,
        '{note}'           => $noteHtml,
        '{classVar}'       => "art11_{$pad}_classVar",
    ];

    unset($params['items']);

    $vars = array_replace($vars, $params);

    return render('App/templates/_art11.html', $vars);
}
?>
