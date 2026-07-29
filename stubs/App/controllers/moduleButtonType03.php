<?php
/**
 * Directrices de copy para moduleButtonType03:
 * - Texto visible: 2-4 palabras; el efecto funciona mejor con una llamada breve.
 * - Title del enlace: 3-6 palabras que describan con precisión el destino.
 * - El icono es decorativo y se construye con CSS.
 */
function controller_moduleButtonType03(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $ctaLinkObj = $GLOBALS["moduleButtonType03_{$pad}_cta_link"] ?? null;
    $ctaSpanObj = $GLOBALS["moduleButtonType03_{$pad}_cta_span"] ?? null;

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

    $vars = [
        '{classVar}'           => "moduleButtonType03_{$pad}_classVar",
        '{cta-link-dl}'        => "moduleButtonType03_{$pad}_cta_link",
        '{cta-link-href}'      => $escapeAttribute(resolve_localized_href($readField($ctaLinkObj, 'href'))),
        '{cta-link-title}'     => $escapeAttribute($readField($ctaLinkObj, 'title')),
        '{cta-link-span-dl}'   => "moduleButtonType03_{$pad}_cta_span",
        '{cta-link-span-text}' => $readField($ctaSpanObj, 'text'),
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_moduleButtonType03.html', $vars);
}
?>
