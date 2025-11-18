<?php
/**
 * Directrices de copy para moduleTest:
 * - H2: 32-48 caracteres con hito medible y plazo claro.
 * - Párrafo: 35-55 palabras explicando metodología, soporte y métricas.
 * Reserva {test-slot} para testimonios o bullets opcionales.
 */
function controller_moduleTest(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $vars = [
        '{classVar}'     => "moduleTest_{$pad}_classVar",
        '{test-h2-dl}'   => "moduleTest_{$pad}_h2_text",
        '{test-h2-text}' => $GLOBALS["moduleTest_{$pad}_h2_text"]->text,
        '{test-p-dl}'    => "moduleTest_{$pad}_p_text",
        '{test-p-text}'  => $GLOBALS["moduleTest_{$pad}_p_text"]->text,
        '{test-slot}'    => ''
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_moduleTest.html', $vars);
}
?>
