<?php
/**
 * Directrices de copy para art07:
 * - H3_1: 3-4 palabras que resuman la propuesta clave.
 * - H3_2: 3-5 palabras que aporten contexto territorial o sectorial.
 * - Intro: 24-34 palabras describiendo servicios, métricas y beneficios cuantificables.
 * - H4: 6-8 palabras con promesa de resultados o plazos.
 * - Cita: 20-26 palabras que refuercen autoridad técnica y fiabilidad.
 */
function controller_art07(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $bgHero = sprintf(
        '<span class="bg" data-bg-mobile="%s/%s" data-bg-tablet="%s/%s" data-bg-desktop="%s/%s" style="background-image:url(%s/%s)"></span>',
        $_ENV['RAIZ'], $GLOBALS["art07_{$pad}_bgHero_mobile"],
        $_ENV['RAIZ'], $GLOBALS["art07_{$pad}_bgHero_tablet"],
        $_ENV['RAIZ'], $GLOBALS["art07_{$pad}_bgHero_desktop"],
        $_ENV['RAIZ'], $GLOBALS["art07_{$pad}_bgHero_desktop"]
    );
    $bgMatrix = sprintf(
        '<span class="bg" data-bg-mobile="%s/%s" data-bg-tablet="%s/%s" data-bg-desktop="%s/%s" style="background-image:url(%s/%s)"></span>',
        $_ENV['RAIZ'], $GLOBALS["art07_{$pad}_bgMatrix_mobile"],
        $_ENV['RAIZ'], $GLOBALS["art07_{$pad}_bgMatrix_tablet"],
        $_ENV['RAIZ'], $GLOBALS["art07_{$pad}_bgMatrix_desktop"],
        $_ENV['RAIZ'], $GLOBALS["art07_{$pad}_bgMatrix_desktop"]
    );
    $vars = [
        '{classVar}'         => "art07_{$pad}_classVar",
        '{span-bg-hero-img}' => $bgHero,
        '{h3-1-dl}'          => "art07_{$pad}_h3_1",
        '{h3-1-text}'        => $GLOBALS["art07_{$pad}_h3_1"]->text,
        '{h3-2-dl}'          => "art07_{$pad}_h3_2",
        '{h3-2-text}'        => $GLOBALS["art07_{$pad}_h3_2"]->text,
        '{p-dl}'             => "art07_{$pad}_intro_p",
        '{p-text}'           => $GLOBALS["art07_{$pad}_intro_p"]->text,
        '{h4-dl}'            => "art07_{$pad}_h4",
        '{h4-text}'          => $GLOBALS["art07_{$pad}_h4"]->text,
        '{span-bg-img}'      => $bgMatrix,
        '{cite-dl}'          => "art07_{$pad}_cite",
        '{cite-text}'        => $GLOBALS["art07_{$pad}_cite"]->text,
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_art07.html', $vars);
}
?>
