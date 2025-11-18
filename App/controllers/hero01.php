<?php
/**
 * Directrices de copy para hero01:
 * - Contenido {hero01-content}: 30-50 palabras combinando titular, claim y CTA.
 * - Prioriza un único CTA con verbo imperativo breve.
 * Aprovecha el fondo animado del recurso para mensajes de campaña puntuales.
 */
function controller_hero01(int $i = 0, array $params = []): string
{
    $vars = [
        '{hero01-content}' => '',
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_hero01.html', $vars);
}
?>
