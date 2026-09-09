<?php
/**
 * Directrices de copy para art02v1:
 * - Encabezado principal: 50-70 caracteres en páginas finales; en showroom
 *   debe identificar art02v1 de forma localizable.
 * - Intro: 25-35 palabras; objetivo recomendado, unas 30.
 * - Párrafo p1: 25-35 palabras; objetivo recomendado, unas 30.
 * - Párrafo p2: 18-25 palabras; objetivo recomendado, unas 20.
 * - Encabezados de perfil: 2-8 palabras; admite <b> y <br> para nombre/cargo.
 * - Descripción de perfil: 22-40 palabras.
 * - Alt/title de retrato: 4-10 palabras con persona y contexto.
 * - Perfiles: 1-26; cuatro por defecto.
 */
function controller_art02v1(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = isset($params['items']) ? (int) $params['items'] : 4;

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
        $src = trim($src);
        if (
            $src === ''
            || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|\#)#i', $src) === 1
        ) {
            return $src;
        }

        $root = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');

        return ($root !== '' ? $root . '/' : '/') . ltrim($src, '/');
    };

    $readText = static function ($value): string {
        return is_object($value) ? (string) ($value->text ?? '') : '';
    };

    $itemsMarkup = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter    = $letters[$j];
        $headerKey = "art02v1_{$pad}_headerSecondary_{$letter}";
        $imageKey  = "art02v1_{$pad}_{$letter}_img";
        $textKey   = "art02v1_{$pad}_{$letter}_p";
        $buttonKey = '{' . $letter . '-button-primary}';
        $image     = $GLOBALS[$imageKey] ?? null;
        $imageSrc  = is_object($image) ? (string) ($image->src ?? '') : '';
        $imageAlt  = is_object($image) ? (string) ($image->alt ?? '') : '';
        $imageTitle = is_object($image)
            ? (string) ($image->title ?? '')
            : '';
        $buttonMarkup = (string) ($params[$buttonKey] ?? '');
        $resolvedImageSrc = $assetUrl($imageSrc);

        $imageMarkup = $resolvedImageSrc === ''
            ? ''
            : '<img class="art02v1-avatar" data-lang="' . $imageKey
                . '" src="' . $escapeAttr($resolvedImageSrc)
                . '" alt="' . $escapeAttr($imageAlt)
                . '" title="' . $escapeAttr($imageTitle)
                . '" width="900" height="900" loading="lazy" decoding="async">';
        $ctaMarkup = trim($buttonMarkup) === ''
            ? ''
            : '<div class="art02v1-cardCta">' . $buttonMarkup . '</div>';

        $itemsMarkup .= '<div class="art02v1-card">'
            . '<h' . $itemLevel . ' class="art02v1-cardTitle" data-lang="'
            . $headerKey . '">'
            . $readText($GLOBALS[$headerKey] ?? null)
            . '</h' . $itemLevel . '>'
            . $imageMarkup
            . '<p class="art02v1-cardText" data-lang="' . $textKey . '">'
            . $readText($GLOBALS[$textKey] ?? null) . '</p>'
            . $ctaMarkup
            . '</div>';
    }

    $headerKey      = "art02v1_{$pad}_headerPrimary";
    $introKey       = "art02v1_{$pad}_intro_p";
    $p1Key          = "art02v1_{$pad}_p1";
    $p2Key          = "art02v1_{$pad}_p2";
    $externalHeader = trim((string) ($params['{header-primary}'] ?? ''));
    $headerMarkup   = $externalHeader !== ''
        ? $externalHeader
        : '<h' . $baseLevel . ' data-lang="' . $headerKey . '">'
            . $readText($GLOBALS[$headerKey] ?? null)
            . '</h' . $baseLevel . '>';

    return render('App/templates/_art02v1.html', [
        '{classVar}'       => "art02v1_{$pad}_classVar",
        '{items-class}'    => 'art02v1--items-' . $itemsCount,
        '{header-primary}' => $headerMarkup,
        '{intro-p-dl}'     => $introKey,
        '{intro-p-text}'   => $readText($GLOBALS[$introKey] ?? null),
        '{p1-dl}'          => $p1Key,
        '{p1-text}'        => $readText($GLOBALS[$p1Key] ?? null),
        '{p2-dl}'          => $p2Key,
        '{p2-text}'        => $readText($GLOBALS[$p2Key] ?? null),
        '{items}'          => $itemsMarkup,
    ]);
}
?>
