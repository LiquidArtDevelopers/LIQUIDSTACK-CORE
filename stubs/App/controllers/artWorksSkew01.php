<?php
/**
 * Directrices de copy para artWorksSkew01:
 * - Encabezado principal: 3-7 palabras.
 * - Texto introductorio: 12-22 palabras.
 * - Numero por item: 2-3 caracteres (01, 02, 03).
 * - Titulo por item: 2-6 palabras.
 * - Subtitulo por item: 2-6 palabras.
 * - Atributos alt/title: 6-12 palabras claras.
 */
function controller_artWorksSkew01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $headerLevels = resolve_header_levels($params, '{header-primary}', 2);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $headerObj = $GLOBALS["artWorksSkew01_{$pad}_headerPrimary"] ?? null;
    $introObj  = $GLOBALS["artWorksSkew01_{$pad}_intro"] ?? null;

    $introText = is_object($introObj) && isset($introObj->text) ? $introObj->text : '';

    $headerHtml = '';
    if (is_object($headerObj) && isset($headerObj->text) && $headerObj->text !== '') {
        $headerHtml = '<h' . $baseLevel . ' data-lang="artWorksSkew01_' . $pad . '_headerPrimary">'
            . $headerObj->text
            . '</h' . $baseLevel . '>';
    }

    $vars = [
        '{classVar}' => "artWorksSkew01_{$pad}_classVar",
        '{header-primary}' => $headerHtml,
        '{intro-dl}' => "artWorksSkew01_{$pad}_intro",
        '{intro-text}' => $introText,
        '{items}' => '',
        '{skew-max}' => '14',
        '{skew-factor}' => '12',
        '{skew-text-factor}' => '0.5',
        '{skew-ease}' => '0.12',
        '{skew-return}' => '0.22',
        '{skew-media-shift}' => '40',
        '{skew-text-shift}' => '220',
        '{skew-direction}' => '-1',
    ];

    $letters = range('a', 'z');
    $itemsCount = isset($params['items']) ? (int) $params['items'] : 4;
    $itemsCount = max(2, min(count($letters), $itemsCount));

    $itemsHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $pre = "artWorksSkew01_{$pad}_item{$letter}";

        $imgObj = $GLOBALS[$pre . '_img'] ?? null;
        $linkObj = $GLOBALS[$pre . '_link'] ?? null;
        $indexObj = $GLOBALS[$pre . '_index'] ?? null;
        $titleObj = $GLOBALS[$pre . '_title'] ?? null;
        $subtitleObj = $GLOBALS[$pre . '_subtitle'] ?? null;

        $src = '';
        $alt = '';
        $title = '';
        if (is_object($imgObj)) {
            $src = isset($imgObj->src) ? $_ENV['RAIZ'] . '/' . ltrim((string) $imgObj->src, '/') : '';
            $alt = isset($imgObj->alt) ? (string) $imgObj->alt : '';
            $title = isset($imgObj->title) ? (string) $imgObj->title : '';
        }

        $linkHrefValue = '';
        $linkTitle = '';
        $linkTarget = '';
        if (is_object($linkObj)) {
            if (isset($linkObj->href)) {
                $linkHrefValue = (string) $linkObj->href;
            }
            if (isset($linkObj->title)) {
                $linkTitle = (string) $linkObj->title;
            }
            if (isset($linkObj->target)) {
                $linkTarget = (string) $linkObj->target;
            }
        } elseif (is_array($linkObj)) {
            if (isset($linkObj['href'])) {
                $linkHrefValue = (string) $linkObj['href'];
            }
            if (isset($linkObj['title'])) {
                $linkTitle = (string) $linkObj['title'];
            }
            if (isset($linkObj['target'])) {
                $linkTarget = (string) $linkObj['target'];
            }
        }
        $indexText = is_object($indexObj) && isset($indexObj->text) ? (string) $indexObj->text : '';
        $titleText = is_object($titleObj) && isset($titleObj->text) ? (string) $titleObj->text : '';
        $subtitleText = is_object($subtitleObj) && isset($subtitleObj->text) ? (string) $subtitleObj->text : '';

        $linkHref = $linkHrefValue !== '' ? resolve_localized_href($linkHrefValue) : '#';
        $linkTitleAttr = $linkTitle !== '' ? $linkTitle : $titleText;
        $targetAttr = $linkTarget !== '' ? ' target="' . htmlspecialchars($linkTarget, ENT_QUOTES) . '"' : '';
        $relAttr = $linkTarget === '_blank' ? ' rel="noopener"' : '';

        $alignClass = ($j % 2 === 0) ? 'is-left' : 'is-right';

        $itemsHtml .= '<article class="artWorksSkew01-item ' . $alignClass . '" data-works-item>'
            . '<a class="artWorksSkew01-media" data-works-media data-lang="' . $pre . '_link" href="' . htmlspecialchars($linkHref, ENT_QUOTES) . '" title="' . htmlspecialchars($linkTitleAttr, ENT_QUOTES) . '"' . $targetAttr . $relAttr . '>'
            . '<img data-lang="' . $pre . '_img" src="' . $src . '" alt="' . $alt . '" title="' . $title . '" loading="lazy" decoding="async">'
            . '</a>'
            . '<div class="artWorksSkew01-meta" data-works-text>'
            . '<span class="artWorksSkew01-index" data-lang="' . $pre . '_index">' . $indexText . '</span>'
            . '<h' . $itemLevel . ' data-lang="' . $pre . '_title">' . $titleText . '</h' . $itemLevel . '>'
            . '<p data-lang="' . $pre . '_subtitle">' . $subtitleText . '</p>'
            . '</div>'
            . '</article>';
    }

    $vars['{items}'] = $itemsHtml;

    unset($params['items']);
    $vars = array_replace($vars, $params);

    return render('App/templates/_artWorksSkew01.html', $vars);
}
?>
