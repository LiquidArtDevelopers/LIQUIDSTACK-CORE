<?php
/**
 * Directrices de copy para artHeroScroll01:
 * - Encabezado principal: 3-7 palabras.
 * - Texto introductorio: 10-20 palabras.
 * - Numero por ficha: 2-3 caracteres (01, 02, 03).
 * - Palabra destacada: 1-3 palabras.
 * - Titulo por ficha: 2-5 palabras.
 * - Texto por ficha: ~30-40 caracteres.
 * - Subitems: 2-6 palabras por item.
 */
function controller_artHeroScroll01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $letters = range('a', 'z');

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel = $headerLevels['base'];
    $itemLevel = $headerLevels['child'];

    $headerObj = $GLOBALS["artHeroScroll01_{$pad}_headerPrimary"] ?? null;
    $introObj = $GLOBALS["artHeroScroll01_{$pad}_intro"] ?? null;

    $introText = is_object($introObj) && isset($introObj->text) ? $introObj->text : '';

    $headerHtml = '';
    if (is_object($headerObj) && isset($headerObj->text) && $headerObj->text !== '') {
        $headerHtml = '<h' . $baseLevel . ' data-lang="artHeroScroll01_' . $pad . '_headerPrimary">'
            . $headerObj->text
            . '</h' . $baseLevel . '>';
    }

    $itemsCount = isset($params['items']) ? (int) $params['items'] : 4;
    $itemsCount = max(1, min($itemsCount, count($letters)));

    $listItemsParam = $params['list_items'] ?? ($params['subitems'] ?? 3);
    unset($params['items'], $params['list_items'], $params['subitems']);

    $itemsHtml = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $pre = "artHeroScroll01_{$pad}_item{$letter}";

        $indexObj = $GLOBALS[$pre . '_index'] ?? null;
        $wordObj = $GLOBALS[$pre . '_word'] ?? null;
        $titleObj = $GLOBALS[$pre . '_title'] ?? null;
        $textObj = $GLOBALS[$pre . '_text'] ?? null;

        $indexText = is_object($indexObj) && isset($indexObj->text) ? $indexObj->text : '';
        $wordText = is_object($wordObj) && isset($wordObj->text) ? $wordObj->text : '';
        $titleText = is_object($titleObj) && isset($titleObj->text) ? $titleObj->text : '';
        $textText = is_object($textObj) && isset($textObj->text) ? $textObj->text : '';

        $listCount = is_numeric($listItemsParam) ? (int) $listItemsParam : 0;
        if (is_array($listItemsParam)) {
            if (array_key_exists($letter, $listItemsParam)) {
                $listCount = (int) $listItemsParam[$letter];
            } elseif (array_key_exists($j, $listItemsParam)) {
                $listCount = (int) $listItemsParam[$j];
            }
        }
        if ($listCount <= 0) {
            $listCount = 3;
        }
        $listCount = max(0, min($listCount, count($letters)));

        $listHtml = '';
        for ($k = 0; $k < $listCount; $k++) {
            $subLetter = $letters[$k];
            $subKey = $pre . '_sub' . $subLetter;
            $subObj = $GLOBALS[$subKey] ?? null;
            $subText = is_object($subObj) && isset($subObj->text) ? $subObj->text : '';

            $listHtml .= '<li class="artHeroScroll01-listItem">'
                . '<span class="artHeroScroll01-dash">&mdash;</span>'
                . '<span data-lang="' . $subKey . '">' . $subText . '</span>'
                . '</li>';
        }

        $wordHtml = '';
        if ($wordText !== '') {
            $wordHtml = '<span class="artHeroScroll01-word" data-hero-word data-lang="' . $pre . '_word">'
                . $wordText
                . '</span>';
        }

        $itemsHtml .= '<article class="artHeroScroll01-item" data-hero-item>'
            . '<div class="artHeroScroll01-itemInner">'
            . '<span class="artHeroScroll01-index" data-lang="' . $pre . '_index">' . $indexText . '</span>'
            . $wordHtml
            . '<h' . $itemLevel . ' class="artHeroScroll01-title artHeroScroll01-split" data-hero-title data-lang="' . $pre . '_title">'
            . $titleText
            . '</h' . $itemLevel . '>'
            . '<p class="artHeroScroll01-text" data-lang="' . $pre . '_text">' . $textText . '</p>'
            . '<ul class="artHeroScroll01-listItems">' . $listHtml . '</ul>'
            . '</div>'
            . '</article>';
    }

    $vars = [
        '{classVar}' => "artHeroScroll01_{$pad}_classVar",
        '{header-primary}' => $headerHtml,
        '{intro-dl}' => "artHeroScroll01_{$pad}_intro",
        '{intro-text}' => $introText,
        '{items}' => $itemsHtml,
        '{title-shift}' => '28',
        '{word-shift}' => '18',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_artHeroScroll01.html', $vars);
}
?>
