<?php
/**
 * Directrices de copy para sectTabs01:
 * - Intro: 28-40 palabras contextualizando los recursos a descargar.
 * - Nav tabs: 2-4 palabras con acción o categoría clara.
 * - Titulares h3: 7-12 palabras orientadas a beneficio.
 * - Subtítulos h4: 5-9 palabras. Descripción h5: 12-18 palabras.
 * - Enlaces: 2-5 palabras con verbo o sustantivo accionable. IDs en kebab-case.
 */
function controller_sectTabs01(int $i = 0, array $params = []): string
{
    global $lang;
    $pad = sprintf('%02d', $i);
    $vars = [
        '{classVar}'        => "sectTabs01_{$pad}_classVar",
        '{section-h2}'      => '',
        '{intro-dl}'        => '',
        '{intro-text}'      => '',
        '{tabs-nav}'        => '',
        '{tabs-containers}' => ''
    ];

    $base    = $_ENV['RAIZ'] . "/$lang/descargar?file=";
    $introDl = "sectTabs01_{$pad}_intro";
    $vars['{intro-dl}']   = $introDl;
    $vars['{intro-text}'] = $GLOBALS[$introDl]->text ?? '';

    $letters    = range('a', 'z');
    $itemsCount = $params['items'] ?? 0;
    $navHtml    = '<div class="tabs-nav">';
    $contHtml   = '<div class="tab-containers">';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $pre    = "sectTabs01_{$pad}_{$letter}";

        $id      = $GLOBALS[$pre . '_id']->text ?? '';
        $navDl   = $pre . '_nav';
        $navText = $GLOBALS[$navDl]->text ?? '';
        $h3Dl    = $pre . '_h3';
        $h3Text  = $GLOBALS[$h3Dl]->text ?? '';
        $active  = ($j === 0) ? ' is-active' : '';

        $navHtml  .= '<div class="nav-item' . $active . '" data-nav="' . $id . '"><span data-lang="' . $navDl . '">' . $navText . '</span></div>';
        $contHtml .= '<article class="tab-container__item' . $active . '" data-tab="' . $id . '"><div class="tab-content">'
                   . '<h3 data-lang="' . $h3Dl . '">' . $h3Text . '</h3>';

        for ($b = 1; $b <= 3; $b++) {
            $blockPre = $pre . '_b' . $b;
            $h4Dl     = $blockPre . '_h4';
            $h4Text   = $GLOBALS[$h4Dl]->text ?? '';
            $h5Dl     = $blockPre . '_h5';
            $h5Text   = $GLOBALS[$h5Dl]->text ?? '';
            $contHtml .= '<h4 data-lang="' . $h4Dl . '">' . $h4Text . '</h4>'
                       . '<ul><h5 data-lang="' . $h5Dl . '">' . $h5Text . '</h5>';
            for ($l = 1; $l <= 2; $l++) {
                $linkKey = $blockPre . '_l' . $l;
                $href    = $base . rawurlencode($GLOBALS[$linkKey]->href ?? '');
                $text    = $GLOBALS[$linkKey]->text ?? '';
                $contHtml .= '<li><a data-lang="' . $linkKey . '" href="' . $href . '" class="btn-download" title="">' . $text . '</a></li>';
            }
            $contHtml .= '</ul>';
        }
        $contHtml .= '</div></article>';
    }

    $navHtml  .= '</div><div class="nav__line"><div class="line"></div></div>';
    $contHtml .= '</div>';

    $vars['{tabs-nav}']        = $navHtml;
    $vars['{tabs-containers}'] = $contHtml;

    unset($params['items']);
    $vars = array_replace($vars, $params);
    return render('App/templates/_sectTabs01.html', $vars);
}
?>
