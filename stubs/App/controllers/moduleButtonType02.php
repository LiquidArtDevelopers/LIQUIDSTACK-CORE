<?php
/**
 * Directrices de copy para moduleButtonType02:
 * - Texto visible (span): 3-5 palabras con acción o beneficio claro.
 * - Title del enlace: 3-6 palabras describiendo el destino.
 * - Icono: imagen editable; si es decorativa, deja alt y title vacíos.
 * Se recomienda un único enlace por recurso para favorecer la conversión.
 */
function controller_moduleButtonType02(int $i = 0, array $params = []): string
{
    $pad         = sprintf('%02d', $i);
    $ctaLinkObj  = $GLOBALS["moduleButtonType02_{$pad}_cta_link"] ?? null;
    $ctaSpanObj  = $GLOBALS["moduleButtonType02_{$pad}_cta_span"] ?? null;
    $ctaImageObj = $GLOBALS["moduleButtonType02_{$pad}_cta_img"] ?? null;

    $readField = static function ($value, string $field): string {
        if (is_object($value) && isset($value->{$field})) {
            return (string) $value->{$field};
        }

        if (is_array($value) && isset($value[$field])) {
            return (string) $value[$field];
        }

        return '';
    };

    $escapeAttribute = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $resolveAssetUrl = static function (string $src): string {
        if ($src === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $src) === 1) {
            return $src;
        }

        $root = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');

        return $root . '/' . ltrim($src, '/');
    };

    $ctaImageSrc = $readField($ctaImageObj, 'src');
    if ($ctaImageSrc === '') {
        $ctaImageSrc = 'assets/img/system/arrow-forward-outline.svg';
    }

    $vars = [
        '{classVar}'           => "moduleButtonType02_{$pad}_classVar",
        '{cta-link-dl}'        => "moduleButtonType02_{$pad}_cta_link",
        '{cta-link-href}'      => $escapeAttribute(resolve_localized_href($readField($ctaLinkObj, 'href'))),
        '{cta-link-title}'     => $escapeAttribute($readField($ctaLinkObj, 'title')),
        '{cta-link-span-dl}'   => "moduleButtonType02_{$pad}_cta_span",
        '{cta-link-span-text}' => $readField($ctaSpanObj, 'text'),
        '{cta-img-dl}'         => "moduleButtonType02_{$pad}_cta_img",
        '{cta-img-src}'        => $escapeAttribute($resolveAssetUrl($ctaImageSrc)),
        '{cta-img-alt}'        => $escapeAttribute($readField($ctaImageObj, 'alt')),
        '{cta-img-title}'      => $escapeAttribute($readField($ctaImageObj, 'title')),
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_moduleButtonType02.html', $vars);
}
?>
