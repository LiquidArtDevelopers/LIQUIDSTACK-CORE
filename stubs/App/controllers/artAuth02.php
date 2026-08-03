<?php
/**
 * Directrices de copy para artAuth02:
 * - Encabezado principal: 4-8 palabras; incluir `artAuth02` en showroom.
 * - Introducción: 24-42 palabras para explicar el acceso y el siguiente paso.
 * - Encabezado secundario: 3-6 palabras.
 * - Texto de apoyo: 18-32 palabras, sin repetir instrucciones del formulario.
 * - Variante permitida: default | reverse. Si se omite, los índices impares
 *   usan reverse sin depender de la posición entre otros articles hermanos.
 * El article parte de h3 y escala sus encabezados interiores cuando recibe
 * un `{header-primary}` externo de otro rango. Los slots aceptan únicamente
 * HTML de confianza compuesto por el consumidor.
 */
function controller_artAuth02(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', max(0, $i));
    $variant = strtolower((string) (
        $params['variant'] ?? ($i % 2 === 1 ? 'reverse' : 'default')
    ));
    $variant = in_array($variant, ['default', 'reverse'], true)
        ? $variant
        : 'default';
    unset($params['variant']);
    $escapeAttribute = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $escapeText = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $readText = static function (string $key): string {
        $entry = $GLOBALS[$key] ?? null;

        if (is_object($entry) && isset($entry->text)) {
            return (string) $entry->text;
        }

        if (is_array($entry) && isset($entry['text'])) {
            return (string) $entry['text'];
        }

        return is_scalar($entry) ? (string) $entry : '';
    };

    $idPrefix = trim((string) ($params['id_prefix'] ?? "artAuth02-{$pad}"));
    unset($params['id_prefix']);
    if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $idPrefix) !== 1) {
        $idPrefix = "artAuth02-{$pad}";
    }

    $languageAttributes = !array_key_exists('language_attributes', $params)
        || (bool) $params['language_attributes'];
    unset($params['language_attributes']);

    $key = static fn (string $suffix): string => "artAuth02_{$pad}_{$suffix}";
    $langAttr = static function (string $languageKey) use (
        $escapeAttribute,
        $languageAttributes
    ): string {
        return $languageAttributes
            ? ' data-lang="' . $escapeAttribute($languageKey) . '"'
            : '';
    };

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $primaryTag = 'h' . $headerLevels['base'];
    $secondaryTag = 'h' . $headerLevels['child'];
    $headingId = $idPrefix . '-heading';
    $supportHeadingId = $idPrefix . '-support-heading';

    $vars = [
        '{article-id}'        => $escapeAttribute($idPrefix),
        '{heading-id}'        => $escapeAttribute($headingId),
        '{classVar}'          => "artAuth02_{$pad}_classVar"
            . ($variant === 'reverse' ? ' artAuth02--reverse' : ''),
        '{header-primary}'    => '<' . $primaryTag
            . ' id="' . $escapeAttribute($headingId) . '"'
            . $langAttr($key('headerPrimary')) . '>'
            . $escapeText($readText($key('headerPrimary')))
            . '</' . $primaryTag . '>',
        '{intro-lang-attr}'   => $langAttr($key('intro')),
        '{intro-text}'        => $escapeText($readText($key('intro'))),
        '{header-secondary}'  => '<' . $secondaryTag
            . ' id="' . $escapeAttribute($supportHeadingId) . '"'
            . $langAttr($key('headerSecondary')) . '>'
            . $escapeText($readText($key('headerSecondary')))
            . '</' . $secondaryTag . '>',
        '{support-lang-attr}' => $langAttr($key('support')),
        '{support-text}'      => $escapeText($readText($key('support'))),
        '{aside-slot}'        => '',
        '{form-slot}'         => '',
    ];

    return render(
        'App/templates/_artAuth02.html',
        array_replace($vars, $params)
    );
}
