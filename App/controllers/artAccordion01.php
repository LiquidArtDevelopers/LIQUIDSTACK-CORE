<?php
/**
 * Directrices de copy para artAccordion01:
 * - Encabezado principal: 3-6 palabras abordando la duda o servicio clave.
 * - Títulos de cada ítem: 4-7 palabras, preferiblemente en formato pregunta directa.
 * - Cuerpo de cada ítem: 28-44 palabras detallando requisitos, procesos o condiciones.
 */
function controller_artAccordion01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $levels    = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel = $levels['base'];
    $itemLevel = $levels['child'];
    $baseTag   = 'h' . $baseLevel;
    $itemTag   = 'h' . $itemLevel;
    $svgArrowIcon = <<<SVG
    <svg width="24" height="24" xmlns="http://www.w3.org/2000/svg" fill-rule="evenodd" clip-rule="evenodd">
        <path d="M12 0c6.623 0 12 5.377 12 12s-5.377 12-12 12-12-5.377-12-12 5.377-12 12-12zm0 1c6.071 0 11 4.929 11 11s-4.929 11-11 11-11-4.929-11-11 4.929-11 11-11zm5.247 8l-5.247 6.44-5.263-6.44-.737.678 6 7.322 6-7.335-.753-.665z"/>
    </svg>
SVG;

    $letters = range('a', 'z');
    $items   = isset($params['items']) ? (int) $params['items'] : count($letters);
    unset($params['items']);

    $maxItems = count($letters);
    $items    = max(0, min($items, $maxItems));

    $itemsMarkup = '';
    for ($index = 0; $index < $items; $index++) {
        $letter     = $letters[$index];
        $pre        = "artAccordion01_{$pad}_item{$letter}";
        $titleKey   = $pre . '_title';
        $contentKey = $pre . '_content';

        $titleObj   = $GLOBALS[$titleKey]   ?? null;
        $contentObj = $GLOBALS[$contentKey] ?? null;

        if (!is_object($titleObj) || !is_object($contentObj) || !isset($titleObj->text, $contentObj->text)) {
            continue;
        }

        $title   = $titleObj->text;
        $content = $contentObj->text;

        $itemsMarkup .= '<div class="accordion-item">'
            . '<' . $itemTag . ' class="artAccordion01-title" data-lang="' . $titleKey . '">'
                . $title
                . '<span class="artAccordion01-arrow">' . $svgArrowIcon . '</span>'
            . '</' . $itemTag . '>'
            . '<div class="artAccordion01-content">'
                . '<div class="entry">'
                    . '<p data-lang="' . $contentKey . '">' . $content . '</p>'
                . '</div>'
            . '</div>'
        . '</div>';
    }

    $headerKey  = "artAccordion01_{$pad}_headerPrimary";
    $headerObj  = $GLOBALS[$headerKey] ?? null;
    $headerText = (is_object($headerObj) && isset($headerObj->text)) ? $headerObj->text : '';

    $vars = [
        '{classVar}'           => "artAccordion01_{$pad}_classVar",
        '{header-primary}'      => '<' . $baseTag . ' data-lang="' . $headerKey . '">' . $headerText . '</' . $baseTag . '>',
        '{accordion-items}' => $itemsMarkup,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_artAccordion01.html', $vars);
}
?>