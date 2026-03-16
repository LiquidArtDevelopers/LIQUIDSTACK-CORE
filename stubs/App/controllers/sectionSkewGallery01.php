<?php
/**
 * Directrices de copy para sectionSkewGallery01:
 * - Encabezado principal: 5-9 palabras con enfoque editorial.
 * - Texto introductorio: 14-26 palabras descriptivas.
 * - TÃ­tulo por item: 4-8 palabras.
 * - Texto por item: 16-28 palabras.
 * - Atributos alt/title: 6-12 palabras claras.
 */
function controller_sectionSkewGallery01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $headerLevels = resolve_header_levels($params, '{header-primary}', 2);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $headerObj = $GLOBALS["sectionSkewGallery01_{$pad}_headerPrimary"] ?? null;
    $introObj  = $GLOBALS["sectionSkewGallery01_{$pad}_intro"] ?? null;

    $introText = is_object($introObj) && isset($introObj->text) ? $introObj->text : '';

    $headerHtml = '';
    if (is_object($headerObj) && isset($headerObj->text) && $headerObj->text !== '') {
        $headerHtml = '<h' . $baseLevel . ' data-lang="sectionSkewGallery01_' . $pad . '_headerPrimary">'
            . $headerObj->text
            . '</h' . $baseLevel . '>';
    }

    $vars = [
        '{classVar}'   => "artSkewGallery01_{$pad}_classVar",
        '{header-primary}' => $headerHtml,
        '{intro-dl}'   => "sectionSkewGallery01_{$pad}_intro",
        '{intro-text}' => $introText,
        '{items}'      => '',
        '{skew-max}'   => '20',
        '{skew-ease}'  => '0.12',
        '{skew-return}' => '0.22',
        '{skew-zoom}'  => '1.1',
        '{skew-factor}' => '10',
        '{skew-direction}' => '-1',
    ];

    $letters    = range('a', 'z');
    $itemsCount = isset($params['items']) ? (int) $params['items'] : 5;
    $itemsCount = max(2, min(count($letters), $itemsCount));

    $itemsHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $pre    = "sectionSkewGallery01_{$pad}_item{$letter}";

        $imgObj   = $GLOBALS[$pre . '_img'] ?? null;
        $titleObj = $GLOBALS[$pre . '_title'] ?? null;
        $textObj  = $GLOBALS[$pre . '_text'] ?? null;

        $src = '';
        $srcFull = '';
        $alt = '';
        $title = '';
        if (is_object($imgObj)) {
            $src   = isset($imgObj->src) ? $_ENV['RAIZ'] . '/' . ltrim((string) $imgObj->src, '/') : '';
            if (isset($imgObj->src_full)) {
                $srcFull = $_ENV['RAIZ'] . '/' . ltrim((string) $imgObj->src_full, '/');
            } elseif (isset($imgObj->srcFull)) {
                $srcFull = $_ENV['RAIZ'] . '/' . ltrim((string) $imgObj->srcFull, '/');
            } elseif (isset($imgObj->src_hd)) {
                $srcFull = $_ENV['RAIZ'] . '/' . ltrim((string) $imgObj->src_hd, '/');
            }
            $alt   = isset($imgObj->alt) ? (string) $imgObj->alt : '';
            $title = isset($imgObj->title) ? (string) $imgObj->title : '';
        }
        if ($srcFull === '') {
            $srcFull = $src;
        }

        $titleText = is_object($titleObj) && isset($titleObj->text) ? (string) $titleObj->text : '';
        $descText  = is_object($textObj) && isset($textObj->text) ? (string) $textObj->text : '';

        $itemsHtml .= '<article class="artSkewGallery01-item" data-skew-item>'
            . '<button class="artSkewGallery01-media" type="button" data-skew-open data-skew-src="'
            . htmlspecialchars($src, ENT_QUOTES)
            . '" data-skew-src-full="'
            . htmlspecialchars($srcFull, ENT_QUOTES)
            . '" data-skew-alt="'
            . htmlspecialchars($alt, ENT_QUOTES)
            . '" data-skew-title="'
            . htmlspecialchars($titleText, ENT_QUOTES)
            . '">'
            . '<img data-lang="' . $pre . '_img" src="' . $src . '" alt="' . $alt . '" title="' . $title . '" loading="lazy" decoding="async">'
            . '</button>'
            . '<div class="artSkewGallery01-content">'
            . '<h' . $itemLevel . ' data-lang="' . $pre . '_title">' . $titleText . '</h' . $itemLevel . '>'
            . '<p data-lang="' . $pre . '_text">' . $descText . '</p>'
            . '</div>'
            . '</article>';
    }

    $vars['{items}'] = $itemsHtml;

    unset($params['items']);
    $vars = array_replace($vars, $params);

    return render('App/templates/_sectionSkewGallery01.html', $vars);
}
?>
