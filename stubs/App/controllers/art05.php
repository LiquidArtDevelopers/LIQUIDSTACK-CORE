<?php
/** 
 * Directrices de copy para art05:
 * - Encabezado principal: 6-10 palabras destacando el valor del bloque.
 * - Intro: 26-40 palabras que contextualicen testimonios y servicio.
 * - Encabezados secundarios: 4-7 palabras identificando cada caso.
 * - Párrafos de ficha: 24-38 palabras explicando reto y solución.
 * - Firmas: 2-4 palabras con nombre o cargo.
 * - Atributos alt/title: 5-9 palabras describiendo a la persona retratada.
 */
function controller_art05(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $letters = range('a','z');
    $itemsCount = $params['items'] ?? 0;
    $itemsMarkup = '';

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $headerVar = "art05_{$pad}_headerSecondary_{$letter}";
        $imgVar    = "art05_{$pad}_{$letter}_img";
        $pVar      = "art05_{$pad}_{$letter}_p";
        $firmaVar  = "art05_{$pad}_{$letter}_firma";

        $header = '<h' . $itemLevel . ' data-lang="' . $headerVar . '">' . ($GLOBALS[$headerVar]->text ?? '') . '</h' . $itemLevel . '>';
        $imgSrc = $_ENV['RAIZ'].'/'.($GLOBALS[$imgVar]->src ?? '');
        $imgAlt = $GLOBALS[$imgVar]->alt ?? '';
        $imgTitle = $GLOBALS[$imgVar]->title ?? '';
        $img  = '<img data-lang="'.$imgVar.'" src="'.$imgSrc.'" alt="'.$imgAlt.'" title="'.$imgTitle.'">';
        $p    = '<p  data-lang="'.$pVar.'">'.($GLOBALS[$pVar]->text ?? '').'</p>';
        $firma    = '<p class="firma" data-lang="'.$firmaVar.'">'.($GLOBALS[$firmaVar]->text ?? '').'</p>';

        $itemsMarkup .= '<div class="ficha">'.$header.$img.$p.$firma.'</div>';
    }

    $vars = [
        '{classVar}'       => "art05_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="art05_' . $pad . '_headerPrimary">' . ($GLOBALS["art05_{$pad}_headerPrimary"]->text ?? '') . '</h' . $baseLevel . '>',
        '{p-dl}'           => "art05_{$pad}_intro_p",
        '{p-text}'         => $GLOBALS["art05_{$pad}_intro_p"]->text ?? '',
        '{items}'          => $itemsMarkup,
    ];
    $vars = array_replace($vars, $params);
    unset($params['items']);
    return render('App/templates/_art05.html', $vars);
}
?>
