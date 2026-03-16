<?php
/**
 * Directrices de copy para sectionHScroll01:
 * - Encabezado principal: 2-5 palabras.
 * - Texto introductorio: 12-22 palabras.
 * - Indice por item: 2 caracteres (01, 02, 03).
 * - Titulo por item: 3-6 palabras.
 * - Texto por item: 14-28 palabras.
 * - Atributos alt/title: 6-12 palabras claras.
 */
function controller_sectionHScroll01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $headerLevels = resolve_header_levels($params, '{header-primary}', 2);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $headerObj = $GLOBALS["sectionHScroll01_{$pad}_headerPrimary"] ?? null;
    $introObj  = $GLOBALS["sectionHScroll01_{$pad}_intro"] ?? null;

    $introText = is_object($introObj) && isset($introObj->text) ? $introObj->text : '';

    $headerHtml = '';
    if (is_object($headerObj) && isset($headerObj->text) && $headerObj->text !== '') {
        $headerHtml = '<h' . $baseLevel . ' data-lang="sectionHScroll01_' . $pad . '_headerPrimary">'
            . $headerObj->text
            . '</h' . $baseLevel . '>';
    }

    $vars = [
        '{classVar}' => "sectionHScroll01_{$pad}_classVar",
        '{header-primary}' => $headerHtml,
        '{intro-dl}' => "sectionHScroll01_{$pad}_intro",
        '{intro-text}' => $introText,
        '{items}' => '',
        '{hscroll-speed}' => '1.1',
    ];

    $letters = range('a', 'z');
    $itemsCount = isset($params['items']) ? (int) $params['items'] : 4;
    $itemsCount = max(2, min(count($letters), $itemsCount));

    $itemsHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $pre = "sectionHScroll01_{$pad}_item{$letter}";

        $imgObj = $GLOBALS[$pre . '_img'] ?? null;
        $indexObj = $GLOBALS[$pre . '_index'] ?? null;
        $titleObj = $GLOBALS[$pre . '_title'] ?? null;
        $textObj = $GLOBALS[$pre . '_text'] ?? null;

        $src = '';
        $alt = '';
        $title = '';
        if (is_object($imgObj)) {
            $src = isset($imgObj->src) ? $_ENV['RAIZ'] . '/' . ltrim((string) $imgObj->src, '/') : '';
            $alt = isset($imgObj->alt) ? (string) $imgObj->alt : '';
            $title = isset($imgObj->title) ? (string) $imgObj->title : '';
        }

        $indexText = is_object($indexObj) && isset($indexObj->text) ? (string) $indexObj->text : '';
        $titleText = is_object($titleObj) && isset($titleObj->text) ? (string) $titleObj->text : '';
        $bodyText  = is_object($textObj) && isset($textObj->text) ? (string) $textObj->text : '';

        $imgHtml = '';
        if ($src !== '') {
            $imgHtml = '<div class="sectionHScroll01-cardMedia">'
                . '<img data-lang="' . $pre . '_img" src="' . $src . '" alt="' . $alt . '" title="' . $title . '" loading="lazy" decoding="async">'
                . '</div>';
        }

        $itemsHtml .= '<article class="sectionHScroll01-card" data-hscroll-item>'
            . '<div class="sectionHScroll01-cardBody">'
            . '<span class="sectionHScroll01-cardIndex" data-lang="' . $pre . '_index">' . $indexText . '</span>'
            . '<h' . $itemLevel . ' data-lang="' . $pre . '_title">' . $titleText . '</h' . $itemLevel . '>'
            . '<p data-lang="' . $pre . '_text">' . $bodyText . '</p>'
            . $imgHtml
            . '</div>'
            . '</article>';
    }

    $vars['{items}'] = $itemsHtml;

    unset($params['items']);
    $vars = array_replace($vars, $params);

    return render('App/templates/_sectionHScroll01.html', $vars);
}
?>
