<?php
/**
 * Directrices de copy para art22:
 * - Encabezado principal: 35-75 caracteres.
 * - Texto de apoyo: 20-45 palabras.
 * - Encabezado de tarjeta: 2-8 palabras.
 * - Galería enlazada: 1-26 tarjetas con destino y medio propios.
 */
function controller_art22(int $i = 0, array $params = []): string
{
    $pad        = sprintf('%02d', $i);
    $letters    = range('a', 'z');
    $itemsCount = (int) ($params['items'] ?? 3);
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
        ['assets/img/dummy/dummy04.avif', 2560, 1440],
    ];

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter       = $letters[$j];
        $headerKey    = "art22_{$pad}_headerSecondary_{$letter}";
        $linkKey      = "art22_{$pad}_{$letter}_link";
        $imageKey     = "art22_{$pad}_{$letter}_img";
        $headerObj    = $GLOBALS[$headerKey] ?? null;
        $linkObj      = $GLOBALS[$linkKey] ?? null;
        $imageObj     = $GLOBALS[$imageKey] ?? null;
        $imageDefault = $imageDefaults[$j % count($imageDefaults)];

        $headerText = is_object($headerObj) && isset($headerObj->text)
            ? (string) $headerObj->text
            : 'Matrix ipsum ' . str_pad((string) ($j + 1), 2, '0', STR_PAD_LEFT);
        $linkHref = is_object($linkObj) && isset($linkObj->href)
            ? (string) $linkObj->href
            : '#';
        $linkTitle = is_object($linkObj) && isset($linkObj->title)
            ? (string) $linkObj->title
            : $headerText;
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

        $itemsHtml .= '<div class="art22-item">'
            . '<a data-lang="' . $linkKey
            . '" href="' . $escapeAttr(resolve_localized_href($linkHref))
            . '" title="' . $escapeAttr($linkTitle) . '">'
            . '<img data-lang="' . $imageKey
            . '" src="' . $escapeAttr($assetUrl($imageSrc))
            . '" alt="' . $escapeAttr($imageAlt)
            . '" title="' . $escapeAttr($imageTitle)
            . '" width="' . $imageWidth
            . '" height="' . $imageHeight
            . '" loading="lazy" decoding="async">'
            . '<' . $itemTag . ' class="art22-itemTitle" data-lang="'
            . $headerKey . '">' . $headerText . '</' . $itemTag . '>'
            . '</a>'
            . '</div>';
    }

    $headerKey = "art22_{$pad}_headerPrimary";
    $introKey  = "art22_{$pad}_intro_p";
    $headerObj = $GLOBALS[$headerKey] ?? null;
    $introObj  = $GLOBALS[$introKey] ?? null;
    $headerText = is_object($headerObj) && isset($headerObj->text)
        ? (string) $headerObj->text
        : 'art22 · Matrix ipsum';
    $introText = is_object($introObj) && isset($introObj->text)
        ? (string) $introObj->text
        : 'Matrix ipsum dolor sit amet, Neo eligendi veritatis codicem et simulacrum.';

    $introHtml = trim($introText) !== ''
        ? '<p class="art22-intro" data-lang="' . $introKey . '">'
            . $introText . '</p>'
        : '';

    $vars = [
        '{classVar}'       => "art22_{$pad}_classVar",
        '{items-class}'    => 'art22--items-' . $itemsCount,
        '{header-primary}' => '<' . $headerTag . ' class="art22-title" data-lang="'
            . $headerKey . '">' . $headerText . '</' . $headerTag . '>',
        '{intro}'          => $introHtml,
        '{button-primary}' => '',
        '{items}'          => $itemsHtml,
    ];

    return render('App/templates/_art22.html', array_replace($vars, $params));
}
?>
