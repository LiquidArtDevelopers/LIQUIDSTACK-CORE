<?php
/**
 * Directrices de copy para moduleLoader01:
 * - No gestiona textos; define solo IDs entre 5 y 12 caracteres en kebab-case.
 * Usa nomenclaturas coherentes con los loaders disponibles en la vista.
 */
function controller_moduleLoader01(int $i = 1, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $vars = [
        '{classVar}'   => "moduleLoader01_{$pad}_classVar",
        '{loader-id}' => "loader{$pad}",
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_moduleLoader01.html', $vars);
}
?>
