<?php
/**
 * Directrices de copy para art02:
 * - Encabezado principal: 50‑70 caracteres alineado con consultas SEO.
 * - Intro: 18‑30 palabras describiendo propuesta y zonas.
 * - Bloques p1/p2: 35‑60 palabras cada uno con procesos y métricas.
 * - Cards: 25‑40 palabras; permite <b> y <br> para enfatizar acciones.
 */
function controller_art02(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = $params['items'] ?? 2;

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $itemTpl = <<<HTML
        <div class="art02-card">
            {X-header-secondary}
            <img data-lang="{X-img-dl}"
                 src="{X-img-src}" width="1000" height="250"
                 alt="{X-img-alt}" title="{X-img-title}">
            <p data-lang="{X-p-dl}">{X-p-text}</p>
            {X-button-primary}
        </div>
    HTML;

    $itemsHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter    = $letters[$j] ?? null;
        if ($letter === null) {
            break;
        }
        $headerVar = "art02_{$pad}_headerSecondary_{$letter}";
        $imgKey    = "art02_{$pad}_{$letter}_img";
        $pVar      = "art02_{$pad}_{$letter}_p";

        $buttonKey   = '{' . $letter . '-button-primary}';
        $buttonHtml  = $params[$buttonKey] ?? '';
        unset($params[$buttonKey]);

        $imgObj    = $GLOBALS[$imgKey] ?? null;
        $pObj      = $GLOBALS[$pVar] ?? null;
        $headerObj = $GLOBALS[$headerVar] ?? null;

        $imgSrcValue = (is_object($imgObj) && isset($imgObj->src)) ? $imgObj->src : '';
        $imgSrc      = $imgSrcValue !== '' ? $_ENV['RAIZ'] . '/' . ltrim($imgSrcValue, '/') : '';
        $imgAlt      = is_object($imgObj) && isset($imgObj->alt) ? $imgObj->alt : '';
        $imgTitle    = is_object($imgObj) && isset($imgObj->title) ? $imgObj->title : '';

        $pText      = is_object($pObj) && isset($pObj->text) ? $pObj->text : '';
        $headerText = is_object($headerObj) && isset($headerObj->text) ? $headerObj->text : '';

        $itemHtml = str_replace('{X', '{' . $letter, $itemTpl);
        $search   = [
            '{' . $letter . '-header-secondary}',
            '{' . $letter . '-img-dl}',
            '{' . $letter . '-img-src}',
            '{' . $letter . '-img-alt}',
            '{' . $letter . '-img-title}',
            '{' . $letter . '-p-dl}',
            '{' . $letter . '-p-text}',
            '{' . $letter . '-button-primary}',
        ];
        $replace = [
            '<h' . $itemLevel . ' data-lang="' . $headerVar . '">' . $headerText . '</h' . $itemLevel . '>',
            $imgKey,
            $imgSrc,
            $imgAlt,
            $imgTitle,
            $pVar,
            $pText,
            $buttonHtml,
        ];
        $itemsHtml .= str_replace($search, $replace, $itemHtml);
    }

    unset($params['items']);

    $headerPrimaryObj = $GLOBALS["art02_{$pad}_headerPrimary"] ?? null;
    $introObj         = $GLOBALS["art02_{$pad}_intro_p"] ?? null;
    $p1Obj            = $GLOBALS["art02_{$pad}_p1"] ?? null;
    $p2Obj            = $GLOBALS["art02_{$pad}_p2"] ?? null;

    $vars = [
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="art02_' . $pad . '_headerPrimary">' . (is_object($headerPrimaryObj) && isset($headerPrimaryObj->text) ? $headerPrimaryObj->text : '') . '</h' . $baseLevel . '>',
        '{intro-p-dl}'     => "art02_{$pad}_intro_p",
        '{intro-p-text}'   => (is_object($introObj) && isset($introObj->text)) ? $introObj->text : '',
        '{p1-dl}'          => "art02_{$pad}_p1",
        '{p1-text}'        => (is_object($p1Obj) && isset($p1Obj->text)) ? $p1Obj->text : '',
        '{p2-dl}'          => "art02_{$pad}_p2",
        '{p2-text}'        => (is_object($p2Obj) && isset($p2Obj->text)) ? $p2Obj->text : '',
        '{items}'          => $itemsHtml,
        '{classVar}'       => "art02_{$pad}_classVar",
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art02.html', $vars);
}
?>
