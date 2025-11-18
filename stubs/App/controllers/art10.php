<?php
/**
 * Directrices de copy para art10:
 * - Encabezado principal: 8-11 palabras enfatizando servicios modulares y dinamismo comercial.
 * - Encabezados secundarios de ficha: 3-5 palabras centradas en acción o beneficio.
 * - Descripción por ficha: 18-26 palabras con proceso, soporte y métrica esperada.
 * - Atributos alt/title de iconos: 4-6 palabras describiendo función visual o resultado.
 * - Texto de botón (params): 2-4 palabras orientadas a conversión.
 */
function controller_art10(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = max(0, (int) ($params['items'] ?? 3));

    $vars = [
        '{classVar}'       => "art10_{$pad}_classVar",
    ];

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $itemTpl = <<<HTML
        <div class="art10-ficha">
            {X-header-secondary}
            <img data-lang="{X-img-dl}"
                 src="{X-img-src}" alt="{X-img-alt}" title="{X-img-title}"
                 width="30" height="30">
            <p data-lang="{X-p-dl}">{X-p-text}</p>
            {X-button-cta}
        </div>
    HTML;

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j] ?? null;
        if ($letter === null) {
            break;
        }

        $headerVar    = "art10_{$pad}_headerSecondary_{$letter}";
        $iconVar      = "art10_{$pad}_{$letter}_img";
        $paragraphVar = "art10_{$pad}_{$letter}_p";

        $buttonKey  = '{' . $letter . '-button-cta}';
        $buttonHtml = $params[$buttonKey] ?? '';
        unset($params[$buttonKey]);

        $headerObj    = $GLOBALS[$headerVar] ?? null;
        $iconObj      = $GLOBALS[$iconVar] ?? null;
        $paragraphObj = $GLOBALS[$paragraphVar] ?? null;

        $headerText = (is_object($headerObj) && isset($headerObj->text)) ? $headerObj->text : '';

        $iconSrcValue = (is_object($iconObj) && isset($iconObj->src)) ? $iconObj->src : '';
        $iconSrc      = $iconSrcValue !== '' ? $_ENV['RAIZ'] . '/' . ltrim($iconSrcValue, '/') : '';
        $iconAlt      = (is_object($iconObj) && isset($iconObj->alt)) ? $iconObj->alt : '';
        $iconTitle    = (is_object($iconObj) && isset($iconObj->title)) ? $iconObj->title : '';

        $paragraphText = (is_object($paragraphObj) && isset($paragraphObj->text)) ? $paragraphObj->text : '';

        $itemHtml = str_replace('{X', '{' . $letter, $itemTpl);
        $search   = [
            '{' . $letter . '-header-secondary}',
            '{' . $letter . '-img-dl}',
            '{' . $letter . '-img-src}',
            '{' . $letter . '-img-alt}',
            '{' . $letter . '-img-title}',
            '{' . $letter . '-p-dl}',
            '{' . $letter . '-p-text}',
            '{' . $letter . '-button-cta}',
        ];
        $replace = [
            '<h' . $itemLevel . ' data-lang="' . $headerVar . '">' . $headerText . '</h' . $itemLevel . '>',
            $iconVar,
            $iconSrc,
            $iconAlt,
            $iconTitle,
            $paragraphVar,
            $paragraphText,
            $buttonHtml,
        ];

        $itemsHtml .= str_replace($search, $replace, $itemHtml);
    }

    unset($params['items']);

    $headerPrimaryObj = $GLOBALS["art10_{$pad}_headerPrimary"] ?? null;
    $headerPrimary    = (is_object($headerPrimaryObj) && isset($headerPrimaryObj->text)) ? $headerPrimaryObj->text : '';

    $topFill    = $GLOBALS["art10_{$pad}_top_fill"] ?? '';
    $bottomFill = $GLOBALS["art10_{$pad}_bottom_fill"] ?? '';

    $vars = [
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="art10_' . $pad . '_headerPrimary">' . $headerPrimary . '</h' . $baseLevel . '>',
        '{items}'          => $itemsHtml,
        '{top-fill}'       => $topFill,
        '{bottom-fill}'    => $bottomFill,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art10.html', $vars);
}
?>
