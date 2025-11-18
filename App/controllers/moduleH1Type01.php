<?php
/**
 * Directrices de copy para moduleH1Type01:
 * - H1 entre 45 y 65 caracteres (incluye espacios) con enfoque SEO.
 * - Primer párrafo: 40‑60 palabras priorizando beneficios y zonas.
 * - Segundo párrafo: 35‑55 palabras con datos operativos y contactos.
 * Añade negritas puntuales (<b>) y saltos (<br>) solo si alivian la lectura.
 */
function controller_moduleH1Type01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $vars = [
        '{classVar}'  => "moduleH1Type01_{$pad}_classVar",
        '{h1-dl}'      => "moduleH1Type01_{$pad}_h1_text",
        '{h1-text}'    => $GLOBALS["moduleH1Type01_{$pad}_h1_text"]->text,
        '{p-01-dl}'    => "moduleH1Type01_{$pad}_p01_text",
        '{p-01-text}'  => $GLOBALS["moduleH1Type01_{$pad}_p01_text"]->text,
        '{p-02-dl}'    => "moduleH1Type01_{$pad}_p02_text",
        '{p-02-text}'  => $GLOBALS["moduleH1Type01_{$pad}_p02_text"]->text,
        '{a-button-primary}' => '',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_moduleH1Type01.html', $vars);
}
?>
