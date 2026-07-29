<?php
/**
 * Directrices de copy para art24:
 * - Encabezado principal: 35-75 caracteres.
 * - Encabezado de ficha: 2-8 palabras.
 * - Texto de ficha: 15-40 palabras.
 * - Rejilla: 1-26 fichas.
 */
function controller_art24(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = (int) ($params['items'] ?? 4);
    $itemsCount = max(1, min($itemsCount, count($letters)));
    unset($params['items']);

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $headerTag    = 'h' . $headerLevels['base'];
    $itemTag      = 'h' . $headerLevels['child'];

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
        ['assets/img/dummy/dummy02.avif', 2560, 1722],
        ['assets/img/dummy/dummy03.avif', 2560, 1696],
        ['assets/img/dummy/dummy04.avif', 2560, 1440],
    ];

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter       = $letters[$j];
        $headerKey    = "art24_{$pad}_headerSecondary_{$letter}";
        $paragraphKey = "art24_{$pad}_{$letter}_p";
        $imageKey     = "art24_{$pad}_{$letter}_img";
        $headerObj    = $GLOBALS[$headerKey] ?? null;
        $paragraphObj = $GLOBALS[$paragraphKey] ?? null;
        $imageObj     = $GLOBALS[$imageKey] ?? null;
        $imageDefault = $imageDefaults[$j % count($imageDefaults)];

        $headerText = is_object($headerObj) && isset($headerObj->text)
            ? (string) $headerObj->text
            : 'Matrix ipsum ' . str_pad((string) ($j + 1), 2, '0', STR_PAD_LEFT);
        $paragraphText = is_object($paragraphObj) && isset($paragraphObj->text)
            ? (string) $paragraphObj->text
            : 'Matrix ipsum dolor sit amet, consectetur adipisicing elit.';
        $imageSrc = is_object($imageObj) && isset($imageObj->src)
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

        $itemsHtml .= '<div class="art24-item">'
            . '<' . $itemTag . ' class="art24-itemTitle" data-lang="'
            . $headerKey . '">' . $headerText . '</' . $itemTag . '>'
            . '<img data-lang="' . $imageKey
            . '" src="' . $escapeAttr($assetUrl($imageSrc))
            . '" alt="' . $escapeAttr($imageAlt)
            . '" title="' . $escapeAttr($imageTitle)
            . '" width="' . $imageWidth
            . '" height="' . $imageHeight
            . '" loading="lazy" decoding="async">'
            . '<p data-lang="' . $paragraphKey . '">' . $paragraphText . '</p>'
            . '<span class="art24-rule" aria-hidden="true"></span>'
            . '</div>';
    }

    $headerKey = "art24_{$pad}_headerPrimary";
    $headerObj = $GLOBALS[$headerKey] ?? null;
    $headerText = is_object($headerObj) && isset($headerObj->text)
        ? (string) $headerObj->text
        : 'art24 · Matrix ipsum';

    $vars = [
        '{classVar}'       => "art24_{$pad}_classVar",
        '{items-class}'    => 'art24--items-' . $itemsCount,
        '{header-primary}' => '<' . $headerTag . ' class="art24-title" data-lang="'
            . $headerKey . '">' . $headerText . '</' . $headerTag . '>',
        '{items}'          => $itemsHtml,
    ];

    return render('App/templates/_art24.html', array_replace($vars, $params));
}
?>
