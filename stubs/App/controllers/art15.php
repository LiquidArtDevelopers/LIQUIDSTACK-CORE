<?php
/**
 * Directrices de copy para art15:
 * - Encabezado principal: 9-12 palabras resaltando la propuesta metodológica.
 * - Párrafo 1: 45-55 palabras detallando diagnóstico y automatización.
 * - Párrafo 2: 35-45 palabras centrado en acompañamiento y métricas de adopción.
 * - Imágenes (alt/title): 6-9 palabras describiendo escenas operativas o equipos.
 */
function controller_art15(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $vars = [
        '{classVar}'       => "art15_{$pad}_classVar",
        '{header-primary}' => '<h3 data-lang="art15_'.$pad.'_headerPrimary">'.$GLOBALS["art15_{$pad}_headerPrimary"]->text.'</h3>',
        '{p-01-dl}'        => "art15_{$pad}_p1",
        '{p-01-text}'      => $GLOBALS["art15_{$pad}_p1"]->text,
        '{p-02-dl}'        => "art15_{$pad}_p2",
        '{p-02-text}'      => $GLOBALS["art15_{$pad}_p2"]->text,
        '{img-01-dl}'      => "art15_{$pad}_img1",
        '{img-01-src}'     => $_ENV['RAIZ'].'/'.$GLOBALS["art15_{$pad}_img1"]->src,
        '{img-01-alt}'     => $GLOBALS["art15_{$pad}_img1"]->alt,
        '{img-01-title}'   => $GLOBALS["art15_{$pad}_img1"]->title,
        '{img-02-dl}'      => "art15_{$pad}_img2",
        '{img-02-src}'     => $_ENV['RAIZ'].'/'.$GLOBALS["art15_{$pad}_img2"]->src,
        '{img-02-alt}'     => $GLOBALS["art15_{$pad}_img2"]->alt,
        '{img-02-title}'   => $GLOBALS["art15_{$pad}_img2"]->title,
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_art15.html', $vars);
}
?>
