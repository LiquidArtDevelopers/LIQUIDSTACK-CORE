<?php
/**
 * Directrices de copy para art16:
 * - Fragmento h3 (línea 1): 3-4 palabras destacando la propuesta principal.
 * - Fragmento h3 (línea 2): 3-5 palabras con beneficio, público o enfoque geográfico.
 * - Párrafo descriptivo: 40-60 palabras combinando servicio, cobertura y valor diferencial.
 * - CTA opcional: 2-4 palabras con verbo imperativo o promesa directa.
 */
function controller_art16(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $bg = sprintf(
        '<span class="bg" data-bg-mobile="%s/%s" data-bg-tablet="%s/%s" data-bg-desktop="%s/%s" style="background-image:url(%s/%s)"></span>',
        $_ENV['RAIZ'], $GLOBALS["art16_{$pad}_bg_mobile"],
        $_ENV['RAIZ'], $GLOBALS["art16_{$pad}_bg_tablet"],
        $_ENV['RAIZ'], $GLOBALS["art16_{$pad}_bg_desktop"],
        $_ENV['RAIZ'], $GLOBALS["art16_{$pad}_bg_desktop"]
    );

    $vars = [
        '{classVar}'     => "art16_{$pad}_classVar",
        '{span-bg-img}'   => $bg,
        '{h3-1-dl}'       => "art16_{$pad}_h3_1",
        '{h3-1-text}'     => $GLOBALS["art16_{$pad}_h3_1"]->text,
        '{h3-2-dl}'       => "art16_{$pad}_h3_2",
        '{h3-2-text}'     => $GLOBALS["art16_{$pad}_h3_2"]->text,
        '{p-dl}'          => "art16_{$pad}_body_p",
        '{p-text}'        => $GLOBALS["art16_{$pad}_body_p"]->text,
        '{button-primary}' => '',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art16.html', $vars);
}
?>
