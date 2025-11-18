<?php
/**
 * Directrices de copy para artZipper:
 * - Encabezado principal: 4-7 palabras que definan el listado.
 * - Ítems desplegables (h4): 3-6 palabras cada uno con verbo y beneficio.
 */
function controller_artZipper(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $letters = range('a','z');
    $itemsCount = $params['items'] ?? 0;
    $itemsMarkup = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $var = "artZipper_{$pad}_{$letter}_h4";
        $text = $GLOBALS[$var]->text ?? '';
        $itemsMarkup .= '<li><h4 class="zipper_target" data-lang="'.$var.'">'.$text.'</h4></li>';
    }

    $vars = [
        '{classVar}'       => "artZipper_{$pad}_classVar",
        '{header-primary}' => $GLOBALS["artZipper_{$pad}_h3"]->text ?? '',
        '{zipper-items}'   => $itemsMarkup,
    ];
    unset($params['items']);
    $vars = array_replace($vars, $params);
    return render('App/templates/_artZipper.html', $vars);
}
?>
