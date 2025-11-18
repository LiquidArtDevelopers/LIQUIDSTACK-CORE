<?php
/**
 * Directrices de copy para art14:
 * - Encabezado principal: 8-11 palabras con foco en beneficios legales.
 * - Párrafo descriptivo: 45-60 palabras alineando propuesta y contexto territorial.
 * - Imagen (alt/title): 6-9 palabras destacando acción o escenario relevante.
 */
function controller_art14(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $vars = [
        '{classVar}'       => "art14_{$pad}_classVar",
        '{header-primary}' => '<h3 data-lang="art14_'.$pad.'_headerPrimary">'.$GLOBALS["art14_{$pad}_headerPrimary"]->text.'</h3>',
        '{p-dl}'           => "art14_{$pad}_p",
        '{p-text}'         => $GLOBALS["art14_{$pad}_p"]->text,
        '{button-primary}' => '',
        '{img-dl}'         => "art14_{$pad}_img",
        '{img-src}'        => $_ENV['RAIZ'].'/'.$GLOBALS["art14_{$pad}_img"]->src,
        '{img-alt}'        => $GLOBALS["art14_{$pad}_img"]->alt,
        '{img-title}'      => $GLOBALS["art14_{$pad}_img"]->title,
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_art14.html', $vars);
}
?>
