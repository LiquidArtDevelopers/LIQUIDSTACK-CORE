<?php
/**
 * Directrices de copy para moduleButtonType02:
 * - Texto visible (span): 3-5 palabras con acción o beneficio claro.
 * - Title del enlace: 3-6 palabras describiendo el destino.
 * Se recomienda un único enlace por recurso para favorecer la conversión.
 */
function controller_moduleButtonType02(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $ctaLinkObj = $GLOBALS["moduleButtonType02_{$pad}_cta_link"] ?? null;
    $ctaSpanObj = $GLOBALS["moduleButtonType02_{$pad}_cta_span"] ?? null;

    $ctaHref = '';
    if (is_object($ctaLinkObj) && isset($ctaLinkObj->href)) {
        $ctaHref = $ctaLinkObj->href;
    } elseif (is_array($ctaLinkObj) && isset($ctaLinkObj['href'])) {
        $ctaHref = (string) $ctaLinkObj['href'];
    }

    $vars = [
        '{classVar}'           => "moduleButtonType02_{$pad}_classVar",
        '{cta-link-dl}'        => "moduleButtonType02_{$pad}_cta_link",
        '{cta-link-href}'      => resolve_localized_href($ctaHref),
        '{cta-link-title}'     => is_object($ctaLinkObj) && isset($ctaLinkObj->title) ? $ctaLinkObj->title : '',
        '{cta-link-span-dl}'   => "moduleButtonType02_{$pad}_cta_span",
        '{cta-link-span-text}' => is_object($ctaSpanObj) && isset($ctaSpanObj->text) ? $ctaSpanObj->text : '',
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_moduleButtonType02.html', $vars);
}
?>
