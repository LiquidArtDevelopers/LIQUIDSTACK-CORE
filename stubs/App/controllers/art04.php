<?php
/**
 * Directrices de copy para art04:
 * - Encabezado principal: 6-9 palabras destacando categoría o caso.
 * - Intro: 20-30 palabras que integren propuesta visual y beneficios.
 * - Títulos de enlace (atributo title): 3-5 palabras con llamada a la acción.
 * - Atributos alt/title de imagen: 5-8 palabras con contexto descriptivo.
 */
function controller_art04(int $i = 0, array $params = []): string
{
    global $lang;
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = $params['items'] ?? 3;

    $itemTpl = <<<HTML
        <a class="art04-item" data-lang="{X-link-dl}" href="{X-link-href}" title="{X-link-title}">
            <picture>
                <source type="image/avif" srcset="{X-avif-srcset}" sizes="{X-avif-sizes}">
                <img data-lang="{X-img-dl}"
                     src="{X-img-src}" width="1000" height="1000"
                     alt="{X-img-alt}" title="{X-img-title}"
                     srcset="{X-img-srcset}" sizes="{X-img-sizes}">
            </picture>
        </a>
    HTML;

    $itemsHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j] ?? null;
        if ($letter === null) {
            break;
        }

        $prefix  = "art04_{$pad}_{$letter}";
        $linkKey = $prefix . '_link';
        $imgKey  = $prefix . '_img';

        $linkObj = $GLOBALS[$linkKey] ?? null;
        $imgObj  = $GLOBALS[$imgKey] ?? null;

        $linkHrefValue = (is_object($linkObj) && isset($linkObj->href)) ? $linkObj->href : '#';
        $linkHref      = resolve_localized_href($linkHrefValue, ['lang' => $lang]);
        $linkTitle     = is_object($linkObj) && isset($linkObj->title) ? $linkObj->title : '';

        $imgSrcValue = (is_object($imgObj) && isset($imgObj->src)) ? $imgObj->src : '';
        $imgSrc      = $imgSrcValue !== '' ? $_ENV['RAIZ'] . '/' . ltrim($imgSrcValue, '/') : '';
        $imgAlt      = is_object($imgObj) && isset($imgObj->alt) ? $imgObj->alt : '';
        $imgTitle    = is_object($imgObj) && isset($imgObj->title) ? $imgObj->title : '';

        $avifSrcsetParts = [];
        $avif1           = $GLOBALS[$prefix . '_avif_src01'] ?? '';
        $avif2           = $GLOBALS[$prefix . '_avif_src02'] ?? '';
        if ($avif1 !== '') {
            $avifSrcsetParts[] = $_ENV['RAIZ'] . '/' . ltrim($avif1, '/');
        }
        if ($avif2 !== '') {
            $avifSrcsetParts[] = $_ENV['RAIZ'] . '/' . ltrim($avif2, '/');
        }
        $avifSrcset = implode(', ', $avifSrcsetParts);

        $imgSrcsetParts = [];
        $imgSet1        = $GLOBALS[$prefix . '_img_src01'] ?? '';
        $imgSet2        = $GLOBALS[$prefix . '_img_src02'] ?? '';
        if ($imgSet1 !== '') {
            $imgSrcsetParts[] = $_ENV['RAIZ'] . '/' . ltrim($imgSet1, '/');
        }
        if ($imgSet2 !== '') {
            $imgSrcsetParts[] = $_ENV['RAIZ'] . '/' . ltrim($imgSet2, '/');
        }
        $imgSrcset = implode(', ', $imgSrcsetParts);

        $itemHtml = str_replace('{X', '{' . $letter, $itemTpl);
        $search   = [
            '{' . $letter . '-link-dl}',
            '{' . $letter . '-link-href}',
            '{' . $letter . '-link-title}',
            '{' . $letter . '-avif-srcset}',
            '{' . $letter . '-avif-sizes}',
            '{' . $letter . '-img-dl}',
            '{' . $letter . '-img-src}',
            '{' . $letter . '-img-alt}',
            '{' . $letter . '-img-title}',
            '{' . $letter . '-img-srcset}',
            '{' . $letter . '-img-sizes}',
        ];
        $replace = [
            $linkKey,
            $linkHref,
            $linkTitle,
            $avifSrcset,
            '(max-width: 899px) 1000px, 1300px',
            $imgKey,
            $imgSrc,
            $imgAlt,
            $imgTitle,
            $imgSrcset,
            '(max-width: 899px) 1000px, 1300px',
        ];
        $itemsHtml .= str_replace($search, $replace, $itemHtml);
    }

    unset($params['items']);

    $headerPrimaryObj = $GLOBALS["art04_{$pad}_headerPrimary"] ?? null;
    $introObj         = $GLOBALS["art04_{$pad}_intro_p"] ?? null;

    $vars = [
        '{classVar}'       => "art04_{$pad}_classVar",
        '{header-primary}' => '<h3 data-lang="art04_' . $pad . '_headerPrimary">' . (is_object($headerPrimaryObj) && isset($headerPrimaryObj->text) ? $headerPrimaryObj->text : '') . '</h3>',
        '{p-dl}'           => "art04_{$pad}_intro_p",
        '{p-text}'         => (is_object($introObj) && isset($introObj->text)) ? $introObj->text : '',
        '{items}'          => $itemsHtml,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art04.html', $vars);
}
?>
