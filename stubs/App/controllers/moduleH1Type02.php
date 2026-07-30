<?php
/**
 * Directrices de copy para moduleH1Type02:
 * - H1: 45-65 caracteres combinando servicio y ámbito geográfico.
 * - Párrafo 1: 35-50 palabras con la propuesta de valor principal.
 * - Párrafo 2: 30-45 palabras con pruebas operativas o métricas.
 * Se permiten <b> y enlaces puntuales si facilitan la lectura.
 * Por defecto renderiza h1; `header_level` permite reutilizar la presentación
 * en otro nivel semántico sin alterar su composición visual.
 */
function controller_moduleH1Type02(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $headerLevels = resolve_header_levels($params, '{header-primary}', 1);

    $vars = [
        '{classVar}'       => "moduleH1Type02_{$pad}_classVar",
        '{header-tag}'      => 'h' . $headerLevels['base'],
        '{h1-dl}'           => "moduleH1Type02_{$pad}_h1_text",
        '{h1-text}'         => $GLOBALS["moduleH1Type02_{$pad}_h1_text"]->text,
        '{p-01-dl}'         => "moduleH1Type02_{$pad}_p01_text",
        '{p-01-text}'       => $GLOBALS["moduleH1Type02_{$pad}_p01_text"]->text,
        '{p-02-dl}'         => "moduleH1Type02_{$pad}_p02_text",
        '{p-02-text}'       => $GLOBALS["moduleH1Type02_{$pad}_p02_text"]->text,
        '{a-button-primary}' => '',
    ];

    return render(
        'App/templates/_moduleH1Type02.html',
        array_replace($vars, $params)
    );
}
?>
