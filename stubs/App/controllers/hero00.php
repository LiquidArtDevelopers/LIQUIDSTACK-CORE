<?php
/**
 * Directrices de copy para hero00:
 * - Bloque {hero00-content}: 25-45 palabras entre titular, apoyo y CTA.
 * - Títulos o alt de fondos opcionales: 3-6 palabras descriptivas.
 * El recurso solo orquesta fondos responsive; evita duplicar el H1 principal aquí.
 */
function controller_hero00(int $i = 0, array $params = []): string
{
    $vars = [
        '{hero00-content}'  => '',
        '{bg-mobile-dl}'    => 'hero00_bg_mobile',
        '{bg-mobile-src}'   => $_ENV['RAIZ'].'/'.$GLOBALS['hero00_bg_mobile']->src,
        '{bg-tablet-dl}'    => 'hero00_bg_tablet',
        '{bg-tablet-src}'   => $_ENV['RAIZ'].'/'.$GLOBALS['hero00_bg_tablet']->src,
        '{bg-desktop-dl}'   => 'hero00_bg_desktop',
        '{bg-desktop-src}'  => $_ENV['RAIZ'].'/'.$GLOBALS['hero00_bg_desktop']->src,
        '{bg-fallback-dl}'  => 'hero00_bg_fallback',
        '{bg-fallback-src}' => $_ENV['RAIZ'].'/'.$GLOBALS['hero00_bg_fallback']->src,
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_hero00.html', $vars);
}
?>
