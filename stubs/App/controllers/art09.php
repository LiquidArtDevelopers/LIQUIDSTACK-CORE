<?php
/**
 * Directrices de copy para art09:
 * - Encabezado principal: 8-12 palabras destacando casos y resultados logrados.
 * - Párrafo destacado (p1): 24-32 palabras resumiendo metodología y beneficios cuantificables.
 * - Texto de apoyo (span): 3-5 palabras que introduzcan el enlace.
 * - Enlace (title/text): title de 5-7 palabras con verbo inicial; anchor de 2-4 palabras accionables.
 * - Encabezados secundarios de ficha: 4-6 palabras con rol y logro principal.
 * - Descripción de ficha: 22-32 palabras combinando reto, acción y métrica.
 * - Atributos alt/title de imágenes: 5-9 palabras describiendo escena y resultado.
 */
function controller_art09(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];
    $linkObj = $GLOBALS["art09_{$pad}_link"] ?? null;
    $linkHrefValue = '';
    if (is_object($linkObj) && isset($linkObj->href)) {
        $linkHrefValue = $linkObj->href;
    } elseif (is_array($linkObj) && isset($linkObj['href'])) {
        $linkHrefValue = (string) $linkObj['href'];
    }
    $linkTitle = is_object($linkObj) && isset($linkObj->title) ? $linkObj->title : '';
    $linkText  = is_object($linkObj) && isset($linkObj->text) ? $linkObj->text : '';

    $vars = [
        '{classVar}'        => "art09_{$pad}_classVar",
        '{header-primary}'   => '<h' . $baseLevel . ' data-lang="art09_' . $pad . '_headerPrimary">' . $GLOBALS["art09_{$pad}_headerPrimary"]->text . '</h' . $baseLevel . '>',
        '{p1-dl}'            => "art09_{$pad}_p1",
        '{p1-text}'          => $GLOBALS["art09_{$pad}_p1"]->text,
        '{span-dl}'          => "art09_{$pad}_span",
        '{span-text}'        => $GLOBALS["art09_{$pad}_span"]->text,
        '{link-dl}'          => "art09_{$pad}_link",
        '{link-href}'        => resolve_localized_href($linkHrefValue),
        '{link-title}'       => $linkTitle,
        '{link-text}'        => $linkText,
        '{art09-fichas}'     => ''
    ];

    $tpl = <<<HTML
<div>
{X-header-secondary}
<img data-lang="{X-img1-dl}" src="{X-img1-src}" alt="{X-img1-alt}" title="{X-img1-title}" width="150" height="150">
<img data-lang="{X-img2-dl}" src="{X-img2-src}" alt="{X-img2-alt}" title="{X-img2-title}" width="150" height="150">
<p data-lang="{X-desc-dl}">{X-desc-text}</p>
{X-button}
</div>
HTML;

    $letters    = range('a','z');
    $itemsCount = $params['items'] ?? 0;
    $cardsHtml  = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $pre    = "art09_{$pad}_{$letter}";
        $buttonKey = '{'.$letter.'-button}';
        $buttonVal = $params[$buttonKey] ?? '';
        unset($params[$buttonKey]);

        $cardHtml = str_replace('{X', '{'.$letter, $tpl);
        $search = [
            '{'.$letter.'-header-secondary}',
            '{'.$letter.'-img1-dl}','{'.$letter.'-img1-src}','{'.$letter.'-img1-alt}','{'.$letter.'-img1-title}',
            '{'.$letter.'-img2-dl}','{'.$letter.'-img2-src}','{'.$letter.'-img2-alt}','{'.$letter.'-img2-title}',
            '{'.$letter.'-desc-dl}','{'.$letter.'-desc-text}','{'.$letter.'-button}'
        ];
        $replace = [
            '<h' . $itemLevel . ' data-lang="art09_' . $pad . '_headerSecondary_' . $letter . '">' . $GLOBALS["art09_{$pad}_headerSecondary_{$letter}"]->text . '</h' . $itemLevel . '>',
            $pre.'_img1',
            $_ENV['RAIZ'].'/'.$GLOBALS[$pre.'_img1']->src,
            $GLOBALS[$pre.'_img1']->alt,
            $GLOBALS[$pre.'_img1']->title,
            $pre.'_img2',
            $_ENV['RAIZ'].'/'.$GLOBALS[$pre.'_img2']->src,
            $GLOBALS[$pre.'_img2']->alt,
            $GLOBALS[$pre.'_img2']->title,
            $pre.'_desc',
            $GLOBALS[$pre.'_desc']->text,
            $buttonVal
        ];
        $cardsHtml .= str_replace($search, $replace, $cardHtml);
    }
    $vars['{art09-fichas}'] = $cardsHtml;
    unset($params['items']);
    $vars = array_replace($vars, $params);
    return render('App/templates/_art09.html', $vars);
}
?>
