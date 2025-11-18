<?php
/**
 * Directrices de copy para art12:
 * - Encabezado principal: 9-12 palabras orientadas a estrategia modular y valor comercial.
 * - Encabezados secundarios por ficha: 3-5 palabras con foco en acción concreta.
 * - Descripción por ficha: 18-24 palabras combinando proceso, beneficio y resultado cuantificable.
 * - Atributos alt/title de iconos: 4-6 palabras describiendo funcionalidad o soporte.
 * - Atributos alt/title de imágenes de fondo: 5-7 palabras que muestren contexto visual del partner.
 * - Texto de botón opcional (params): 2-4 palabras dirigidas a conversión.
 */
function controller_art12(int $i = 0, array $params = []): string
{
    $pad         = sprintf('%02d', $i);
    $letters     = range('a', 'z');
    $itemsCount  = max(0, (int) ($params['items'] ?? 3));

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $itemTpl = <<<HTML
        <div class="art12-ficha">
            {X-header-secondary}
            <img data-lang="{X-img-dl}"
                 src="{X-img-src}" alt="{X-img-alt}" title="{X-img-title}"
                 width="30" height="30"
                 class="ficha-icon">
            <div class="fade-right">
                <img data-lang="{X-imgBack-dl}"
                     src="{X-imgBack-src}" alt="{X-imgBack-alt}" title="{X-imgBack-title}"
                     width="500" height="500" class="imgBack">
            </div>
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

        $headerVar     = "art12_{$pad}_headerSecondary_{$letter}";
        $iconKey       = "art12_{$pad}_{$letter}_img";
        $bgKey         = "art12_{$pad}_{$letter}_imgBack";
        $paragraphKey  = "art12_{$pad}_{$letter}_p";

        $buttonKey  = '{' . $letter . '-button-cta}';
        $buttonHtml = $params[$buttonKey] ?? '';
        unset($params[$buttonKey]);

        $headerObj    = $GLOBALS[$headerVar] ?? null;
        $iconObj      = $GLOBALS[$iconKey] ?? null;
        $bgObj        = $GLOBALS[$bgKey] ?? null;
        $paragraphObj = $GLOBALS[$paragraphKey] ?? null;

        $headerText = (is_object($headerObj) && isset($headerObj->text)) ? $headerObj->text : '';

        $iconSrcValue = (is_object($iconObj) && isset($iconObj->src)) ? $iconObj->src : '';
        $iconSrc      = $iconSrcValue !== '' ? $_ENV['RAIZ'] . '/' . ltrim($iconSrcValue, '/') : '';
        $iconAlt      = (is_object($iconObj) && isset($iconObj->alt)) ? $iconObj->alt : '';
        $iconTitle    = (is_object($iconObj) && isset($iconObj->title)) ? $iconObj->title : '';

        $bgSrcValue = (is_object($bgObj) && isset($bgObj->src)) ? $bgObj->src : '';
        $bgSrc      = $bgSrcValue !== '' ? $_ENV['RAIZ'] . '/' . ltrim($bgSrcValue, '/') : '';
        $bgAlt      = (is_object($bgObj) && isset($bgObj->alt)) ? $bgObj->alt : '';
        $bgTitle    = (is_object($bgObj) && isset($bgObj->title)) ? $bgObj->title : '';

        $paragraphText = (is_object($paragraphObj) && isset($paragraphObj->text)) ? $paragraphObj->text : '';

        $itemHtml = str_replace('{X', '{' . $letter, $itemTpl);
        $search   = [
            '{' . $letter . '-header-secondary}',
            '{' . $letter . '-img-dl}',
            '{' . $letter . '-img-src}',
            '{' . $letter . '-img-alt}',
            '{' . $letter . '-img-title}',
            '{' . $letter . '-imgBack-dl}',
            '{' . $letter . '-imgBack-src}',
            '{' . $letter . '-imgBack-alt}',
            '{' . $letter . '-imgBack-title}',
            '{' . $letter . '-p-dl}',
            '{' . $letter . '-p-text}',
            '{' . $letter . '-button-cta}',
        ];
        $replace = [
            '<h' . $itemLevel . ' data-lang="' . $headerVar . '">' . $headerText . '</h' . $itemLevel . '>',
            $iconKey,
            $iconSrc,
            $iconAlt,
            $iconTitle,
            $bgKey,
            $bgSrc,
            $bgAlt,
            $bgTitle,
            $paragraphKey,
            $paragraphText,
            $buttonHtml,
        ];

        $itemsHtml .= str_replace($search, $replace, $itemHtml);
    }

    unset($params['items']);

    $headerPrimaryObj = $GLOBALS["art12_{$pad}_headerPrimary"] ?? null;
    $headerPrimary    = (is_object($headerPrimaryObj) && isset($headerPrimaryObj->text)) ? $headerPrimaryObj->text : '';

    $topFill    = $GLOBALS["art12_{$pad}_top_fill"] ?? '';
    $bottomFill = $GLOBALS["art12_{$pad}_bottom_fill"] ?? '';

    $vars = [
        '{classVar}'       => "art12_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="art12_' . $pad . '_headerPrimary">' . $headerPrimary . '</h' . $baseLevel . '>',
        '{items}'          => $itemsHtml,
        '{top-fill}'       => $topFill,
        '{bottom-fill}'    => $bottomFill,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art12.html', $vars);
}
?>
