<?php
/**
 * Directrices de copy para art03:
 * - Encabezado principal: 8-12 palabras orientadas a servicio y sector clave.
 * - Intro: 20-32 palabras que combinen beneficios, coberturas y cumplimiento.
 * - Encabezados secundarios: 4-6 palabras accionables por tarjeta.
 * - Títulos de enlace (atributo title): 4-6 palabras con verbo inicial.
 * - Atributos alt/title de imagen: 5-9 palabras describiendo escena y objetivo.
 */
function controller_art03(int $i = 0, array $params = []): string
{
    global $lang;
    $pad = sprintf('%02d', $i);
    $letters = range('a', 'z');
    $itemsCount = $params['items'] ?? 0;
    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];
    $itemTpl = <<<HTML
    <a data-lang="{X-link-dl}" href="{X-link-href}" title="{X-link-title}">
        {X-header-secondary}
        <picture>
            <source type="image/avif" srcset="{X-avif-srcset}" sizes="{X-avif-sizes}">
            <img data-lang="{X-img-dl}" src="{X-img-src}" width="1000" height="1000" alt="{X-img-alt}" title="{X-img-title}" srcset="{X-img-srcset}" sizes="{X-img-sizes}">
        </picture>
    </a>
    HTML;

    $itemsHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $linkKey   = "art03_{$pad}_{$letter}_link";
        $linkObj   = $GLOBALS[$linkKey] ?? null;
        $linkHrefValue = '#';
        if (is_object($linkObj) && isset($linkObj->href)) {
            $linkHrefValue = $linkObj->href;
        } elseif (is_array($linkObj) && isset($linkObj['href'])) {
            $linkHrefValue = (string) $linkObj['href'];
        }
        $linkHref  = resolve_localized_href($linkHrefValue, ['lang' => $lang]);
        $linkTitle = is_object($linkObj) && isset($linkObj->title) ? $linkObj->title : '';

        $headerVar = "art03_{$pad}_headerSecondary_{$letter}";
        $itemHtml  = str_replace('{X', '{'.$letter, $itemTpl);
        $search = [
            '{'.$letter.'-link-dl}',
            '{'.$letter.'-link-href}',
            '{'.$letter.'-link-title}',
            '{'.$letter.'-header-secondary}',
            '{'.$letter.'-avif-srcset}',
            '{'.$letter.'-avif-sizes}',
            '{'.$letter.'-img-dl}',
            '{'.$letter.'-img-src}',
            '{'.$letter.'-img-alt}',
            '{'.$letter.'-img-title}',
            '{'.$letter.'-img-srcset}',
            '{'.$letter.'-img-sizes}',
        ];
        $replace = [
            $linkKey,
            $linkHref,
            $linkTitle,
            '<h' . $itemLevel . ' data-lang="' . $headerVar . '">' . ($GLOBALS[$headerVar]->text ?? '') . '</h' . $itemLevel . '>',
            $_ENV['RAIZ'].'/'.($GLOBALS["art03_{$pad}_{$letter}_avif_src01"] ?? '').', '.$_ENV['RAIZ'].'/'.($GLOBALS["art03_{$pad}_{$letter}_avif_src02"] ?? ''),
            '(max-width: 899px) 1000px, 1300px',
            "art03_{$pad}_{$letter}_img",
            $_ENV['RAIZ'].'/'.($GLOBALS["art03_{$pad}_{$letter}_img"]->src ?? ''),
            $GLOBALS["art03_{$pad}_{$letter}_img"]->alt ?? '',
            $GLOBALS["art03_{$pad}_{$letter}_img"]->title ?? '',
            $_ENV['RAIZ'].'/'.($GLOBALS["art03_{$pad}_{$letter}_img_src01"] ?? '').', '.$_ENV['RAIZ'].'/'.($GLOBALS["art03_{$pad}_{$letter}_img_src02"] ?? ''),
            '(max-width: 899px) 1000px, 1300px',
        ];
        $itemsHtml .= str_replace($search, $replace, $itemHtml);
    }

    $vars = [
        '{classVar}'       => "art03_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="art03_' . $pad . '_headerPrimary">' . ($GLOBALS["art03_{$pad}_headerPrimary"]->text ?? '') . '</h' . $baseLevel . '>',
        '{p-dl}'           => "art03_{$pad}_intro_p",
        '{p-text}'         => $GLOBALS["art03_{$pad}_intro_p"]->text ?? '',
        '{items}'          => $itemsHtml,
    ];
    unset($params['items']);
    $vars = array_replace($vars, $params);
    return render('App/templates/_art03.html', $vars);
}
?>
