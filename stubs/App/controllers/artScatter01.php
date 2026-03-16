<?php
/**
 * Directrices de copy para artScatter01:
 * - Label/eyebrow: 2-4 palabras breves que introduzcan el bloque.
 * - Texto principal: 60-120 palabras con nombres o conceptos encadenados.
 */
function controller_artScatter01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $labelKey = "artScatter01_{$pad}_label";
    $textKey  = "artScatter01_{$pad}_text";

    $labelObj = $GLOBALS[$labelKey] ?? null;
    $textObj  = $GLOBALS[$textKey] ?? null;

    $vars = [
        '{classVar}'   => "artScatter01_{$pad}_classVar",
        '{label-dl}'   => $labelKey,
        '{label-text}' => (is_object($labelObj) && isset($labelObj->text)) ? $labelObj->text : '',
        '{text-dl}'    => $textKey,
        '{text-text}'  => (is_object($textObj) && isset($textObj->text)) ? $textObj->text : '',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_artScatter01.html', $vars);
}
?>
