<?php
/**
 * Directrices de copy para art21:
 * - Encabezado principal: 35-75 caracteres.
 * - Texto de apoyo: 20-45 palabras.
 * - CTA inyectada: 2-5 palabras de acción.
 * - Galería: 1-26 imágenes; cada alt debe describir su contenido.
 */
function controller_art21(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = (int) ($params['items'] ?? 3);
    $itemsCount = max(1, min($itemsCount, count($letters)));
    unset($params['items']);

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $headerTag    = 'h' . $headerLevels['base'];

    $escapeAttr = static fn ($value): string => htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $assetUrl = static function (string $src): string {
        if ($src === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $src) === 1) {
            return $src;
        }

        $root = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');

        return $root . '/' . ltrim($src, '/');
    };

    $imageDefaults = [
        ['assets/img/dummy/dummy01.avif', 2560, 1600],
        ['assets/img/dummy/dummy03.avif', 2560, 1696],
        ['assets/img/dummy/dummy04.avif', 2560, 1440],
    ];

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter       = $letters[$j];
        $imageKey     = "art21_{$pad}_{$letter}_img";
        $imageObj     = $GLOBALS[$imageKey] ?? null;
        $imageDefault = $imageDefaults[$j % count($imageDefaults)];
        $imageSrc     = is_object($imageObj) && isset($imageObj->src)
            ? (string) $imageObj->src
            : $imageDefault[0];
        $imageAlt = is_object($imageObj) && isset($imageObj->alt)
            ? (string) $imageObj->alt
            : 'Escena de Matrix';
        $imageTitle = is_object($imageObj) && isset($imageObj->title)
            ? (string) $imageObj->title
            : '';
        $imageWidth = max(1, (int) (
            is_object($imageObj) && isset($imageObj->width)
                ? $imageObj->width
                : $imageDefault[1]
        ));
        $imageHeight = max(1, (int) (
            is_object($imageObj) && isset($imageObj->height)
                ? $imageObj->height
                : $imageDefault[2]
        ));

        $itemsHtml .= '<div class="art21-item">'
            . '<img data-lang="' . $imageKey
            . '" src="' . $escapeAttr($assetUrl($imageSrc))
            . '" alt="' . $escapeAttr($imageAlt)
            . '" title="' . $escapeAttr($imageTitle)
            . '" width="' . $imageWidth
            . '" height="' . $imageHeight
            . '" loading="lazy" decoding="async">'
            . '</div>';
    }

    $headerKey = "art21_{$pad}_headerPrimary";
    $introKey  = "art21_{$pad}_intro_p";
    $headerObj = $GLOBALS[$headerKey] ?? null;
    $introObj  = $GLOBALS[$introKey] ?? null;
    $headerText = is_object($headerObj) && isset($headerObj->text)
        ? (string) $headerObj->text
        : 'art21 · Matrix ipsum';
    $introText = is_object($introObj) && isset($introObj->text)
        ? (string) $introObj->text
        : 'Matrix ipsum dolor sit amet, Morpheus quaerat optionem et realitatem.';

    $introHtml = trim($introText) !== ''
        ? '<p class="art21-intro" data-lang="' . $introKey . '">'
            . $introText . '</p>'
        : '';

    $vars = [
        '{classVar}'       => "art21_{$pad}_classVar",
        '{items-class}'    => 'art21--items-' . $itemsCount,
        '{header-primary}' => '<' . $headerTag . ' class="art21-title" data-lang="'
            . $headerKey . '">' . $headerText . '</' . $headerTag . '>',
        '{intro}'          => $introHtml,
        '{button-primary}' => '',
        '{items}'          => $itemsHtml,
    ];

    return render('App/templates/_art21.html', array_replace($vars, $params));
}
?>
