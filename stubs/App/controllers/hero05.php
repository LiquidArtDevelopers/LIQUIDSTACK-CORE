<?php
/**
 * Directrices de copy para hero05:
 * - Texto principal {hero05-text}: 2-4 palabras.
 * - Contenido opcional {hero05-content}: 0-30 palabras.
 * - Mantener copy corto para priorizar el efecto liquid de fondo.
 */
function controller_hero05(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $vars = [
        '{classVar}'           => "hero05_{$pad}_classVar",
        '{hero05-text}'        => 'Liquid Matrix',
        '{hero05-content}'     => '',
        '{hero05-distortion}'  => '0.12',
        '{hero05-chroma}'      => '0.9',
        '{hero05-damping}'     => '0.985',
        '{hero05-radius}'      => '0.08',
        '{hero05-force}'       => '1.15',
        '{hero05-sim}'         => '256',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_hero05.html', $vars);
}
?>
