<?php
/**
 * Directrices de copy para moduleButtonType04:
 * - Texto visible: 2-5 palabras con una acción clara.
 * - Title del enlace: 3-6 palabras que anticipen el destino.
 * - Pensado como CTA general con estados hover, focus y active convencionales.
 */
function controller_moduleButtonType04(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $ctaLinkObj = $GLOBALS["moduleButtonType04_{$pad}_cta_link"] ?? null;
    $ctaSpanObj = $GLOBALS["moduleButtonType04_{$pad}_cta_span"] ?? null;

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

    $resolveHref = static function (string $href): string {
        $value = trim($href);

        if (str_starts_with($value, '/')) {
            return $value;
        }

        return resolve_localized_href($value);
    };

    $vars = [
        '{classVar}'           => "moduleButtonType04_{$pad}_classVar",
        '{cta-link-dl}'        => "moduleButtonType04_{$pad}_cta_link",
        '{cta-link-href}'      => $escapeAttribute($resolveHref($readField($ctaLinkObj, 'href'))),
        '{cta-link-title}'     => $escapeAttribute($readField($ctaLinkObj, 'title')),
        '{cta-link-span-dl}'   => "moduleButtonType04_{$pad}_cta_span",
        '{cta-link-span-text}' => $readField($ctaSpanObj, 'text'),
        '{cta-link-attributes}' => '',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_moduleButtonType04.html', $vars);
}
?>
