<?php
/**
 * Directrices de copy para moduleH2Type01:
 * - Cada h2 debe contar con 28-44 caracteres y resumir beneficio o sección.
 * Mantén verbos activos y consistencia terminológica entre recursos hermanos.
 */
function controller_moduleH2Type01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $vars = [
        '{classVar}' => "moduleH2Type01_{$pad}_classVar",
        '{h2-dl}'   => "moduleH2Type01_{$pad}_h2_text",
        '{h2-text}' => $GLOBALS["moduleH2Type01_{$pad}_h2_text"]->text,
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_moduleH2Type01.html', $vars);
}
?>
