<?php
/**
 * Directrices de copy para art05v1:
 * - Encabezado principal: 6-10 palabras destacando el valor del bloque.
 * - Intro: 26-40 palabras que contextualicen testimonios o casos.
 * - Encabezados de ficha: 4-7 palabras identificando cada caso.
 * - Párrafos de ficha: 24-38 palabras explicando reto y solución.
 * - Firmas: 2-4 palabras con nombre o cargo.
 * - Atributos alt/title: 5-9 palabras describiendo la imagen.
 * - Rejilla: 1-26 fichas; tres fichas por defecto.
 */
function controller_art05v1(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = isset($params['items']) ? (int) $params['items'] : 3;

    if ($itemsCount <= 0) {
        return '';
    }

    $itemsCount = min($itemsCount, count($letters));

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $escapeAttr = static fn ($value): string => htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $assetUrl = static function (string $src): string {
        if (
            $src === ''
            || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $src) === 1
        ) {
            return $src;
        }

        $root = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');

        return $root . '/' . ltrim($src, '/');
    };

    $readText = static function ($value): string {
        return is_object($value) ? (string) ($value->text ?? '') : '';
    };

    $itemsMarkup = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter    = $letters[$j];
        $headerKey = "art05v1_{$pad}_headerSecondary_{$letter}";
        $imageKey  = "art05v1_{$pad}_{$letter}_img";
        $textKey   = "art05v1_{$pad}_{$letter}_p";
        $signKey   = "art05v1_{$pad}_{$letter}_firma";
        $image     = $GLOBALS[$imageKey] ?? null;
        $imageSrc  = is_object($image) ? (string) ($image->src ?? '') : '';
        $imageAlt  = is_object($image) ? (string) ($image->alt ?? '') : '';
        $imageTitle = is_object($image)
            ? (string) ($image->title ?? '')
            : '';

        $itemsMarkup .= '<div class="art05v1-card">'
            . '<h' . $itemLevel . ' class="art05v1-cardTitle" data-lang="'
            . $headerKey . '">'
            . $readText($GLOBALS[$headerKey] ?? null)
            . '</h' . $itemLevel . '>'
            . '<img class="art05v1-cardMedia" data-lang="' . $imageKey
            . '" src="' . $escapeAttr($assetUrl($imageSrc))
            . '" alt="' . $escapeAttr($imageAlt)
            . '" title="' . $escapeAttr($imageTitle)
            . '" loading="lazy" decoding="async">'
            . '<p class="art05v1-cardText" data-lang="' . $textKey . '">'
            . $readText($GLOBALS[$textKey] ?? null) . '</p>'
            . '<p class="art05v1-cardSignature" data-lang="' . $signKey . '">'
            . $readText($GLOBALS[$signKey] ?? null) . '</p>'
            . '</div>';
    }

    $headerKey      = "art05v1_{$pad}_headerPrimary";
    $introKey       = "art05v1_{$pad}_intro_p";
    $externalHeader = trim((string) ($params['{header-primary}'] ?? ''));
    $headerMarkup   = $externalHeader !== ''
        ? $externalHeader
        : '<h' . $baseLevel . ' class="art05v1-title" data-lang="'
            . $headerKey . '">'
            . $readText($GLOBALS[$headerKey] ?? null)
            . '</h' . $baseLevel . '>';

    return render('App/templates/_art05v1.html', [
        '{classVar}'       => "art05v1_{$pad}_classVar",
        '{items-class}'    => 'art05v1--items-' . $itemsCount,
        '{header-primary}' => $headerMarkup,
        '{p-dl}'           => $introKey,
        '{p-text}'         => $readText($GLOBALS[$introKey] ?? null),
        '{items}'          => $itemsMarkup,
    ]);
}
?>
