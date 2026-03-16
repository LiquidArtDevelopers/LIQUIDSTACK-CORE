<?php
/**
 * Directrices de copy para hero04:
 * - Contenido {hero04-content}: 1 bloque principal con 20-50 palabras (titular + apoyo) y CTA opcional.
 * - Evita mensajes largos para no competir con el fondo WebGL.
 */
function controller_hero04(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $vars = [
        '{classVar}'       => "hero04_{$pad}_classVar",
        '{hero04-content}' => '',
        '{hero04-quality}' => '1',
        '{hero04-random}'  => 'false',
        '{hero04-colorful}' => 'true',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_hero04.html', $vars);
}
?>
