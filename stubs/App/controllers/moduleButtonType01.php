<?php
/**
 * Directrices de copy para moduleButtonType01:
 * - Texto visible (span): 2-4 palabras en imperativo o acción clara.
 * - Title del enlace: 3-6 palabras con beneficio directo.
 * - Alt/title del icono: 2-5 palabras alineadas con la acción.
 * Evita duplicar destinos en la misma vista para no dispersar clics.
 */
function controller_moduleButtonType01(int $i = 0, array $params = []): string
{
    $pad         = sprintf('%02d', $i);
    $ctaObj      = $GLOBALS["moduleButtonType01_{$pad}_cta"] ?? null;
    $ctaSpanObj  = $GLOBALS["moduleButtonType01_{$pad}_cta_span"] ?? null;
    $ctaImageObj = $GLOBALS["moduleButtonType01_{$pad}_cta_img"] ?? null;

    $ctaHref = '';
    if (is_object($ctaObj) && isset($ctaObj->href)) {
        $ctaHref = $ctaObj->href;
    } elseif (is_array($ctaObj) && isset($ctaObj['href'])) {
        $ctaHref = (string) $ctaObj['href'];
    }

    $vars = [
        '{classVar}'      => "moduleButtonType01_{$pad}_classVar",
        '{cta-dl}'        => "moduleButtonType01_{$pad}_cta",
        '{cta-href}'      => resolve_localized_href($ctaHref),
        '{cta-title}'     => is_object($ctaObj) && isset($ctaObj->title) ? $ctaObj->title : '',
        '{cta-span-dl}'   => "moduleButtonType01_{$pad}_cta_span",
        '{cta-span-text}' => is_object($ctaSpanObj) && isset($ctaSpanObj->text) ? $ctaSpanObj->text : '',
        '{cta-img-dl}'    => "moduleButtonType01_{$pad}_cta_img",
        '{cta-img-src}'   => is_object($ctaImageObj) && isset($ctaImageObj->src) ? $_ENV['RAIZ'].'/'.$ctaImageObj->src : '',
        '{cta-img-alt}'   => is_object($ctaImageObj) && isset($ctaImageObj->alt) ? $ctaImageObj->alt : '',
        '{cta-img-title}' => is_object($ctaImageObj) && isset($ctaImageObj->title) ? $ctaImageObj->title : '',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_moduleButtonType01.html', $vars);
}
?>
