<?php
/**
 * Directrices de copy para hero03:
 * - Contenido {hero03-content}: logo + H2 centrado.
 * - H2: 3-7 palabras.
 * - Texto frontal: 2-4 palabras, muy breve (H1).
 * - Imagen: alt/title 4-10 palabras descriptivas.
 */
function controller_hero03(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $imgKey = "hero03_{$pad}_img";
    $imgObj = $GLOBALS[$imgKey] ?? null;

    $logoKey = "hero03_{$pad}_logo";
    $logoObj = $GLOBALS[$logoKey] ?? null;

    $titleKey = "hero03_{$pad}_h1_text";
    $titleObj = $GLOBALS[$titleKey] ?? null;

    $frontKey = "hero03_{$pad}_front_text";
    $frontObj = $GLOBALS[$frontKey] ?? null;

    $bgKey = "hero03_{$pad}_bg";
    $bgObj = $GLOBALS[$bgKey] ?? null;

    $src = '';
    $alt = '';
    $title = '';
    if (is_object($imgObj)) {
        $src = isset($imgObj->src) ? $_ENV['RAIZ'] . '/' . ltrim((string) $imgObj->src, '/') : '';
        $alt = isset($imgObj->alt) ? (string) $imgObj->alt : '';
        $title = isset($imgObj->title) ? (string) $imgObj->title : '';
    }

    $bgSrc = $src;
    $bgAlt = $alt;
    $bgTitle = $title;
    if (is_object($bgObj)) {
        if (isset($bgObj->src) && $bgObj->src !== '') {
            $bgSrc = $_ENV['RAIZ'] . '/' . ltrim((string) $bgObj->src, '/');
        }
        if (isset($bgObj->alt) && $bgObj->alt !== '') {
            $bgAlt = (string) $bgObj->alt;
        }
        if (isset($bgObj->title) && $bgObj->title !== '') {
            $bgTitle = (string) $bgObj->title;
        }
    }

    $logoSrc = $_ENV['RAIZ'] . '/assets/img/logos/logo-black.svg';
    $logoAlt = 'Liquid Art Developers';
    $logoTitle = 'Liquid Art Developers';
    if (is_object($logoObj)) {
        if (isset($logoObj->src) && $logoObj->src !== '') {
            $logoSrc = $_ENV['RAIZ'] . '/' . ltrim((string) $logoObj->src, '/');
        }
        if (isset($logoObj->alt) && $logoObj->alt !== '') {
            $logoAlt = (string) $logoObj->alt;
        }
        if (isset($logoObj->title) && $logoObj->title !== '') {
            $logoTitle = (string) $logoObj->title;
        }
    }

    $titleText = is_object($titleObj) && isset($titleObj->text) ? (string) $titleObj->text : '';
    $frontText = is_object($frontObj) && isset($frontObj->text) ? (string) $frontObj->text : '';

    $itemsCount = isset($params['items']) ? (int) $params['items'] : 6;
    $itemsCount = max(3, min(12, $itemsCount));

    $columnsHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $columnsHtml .= '<div class="hero03-col" data-hero03-col style="--hero03-col:' . $j . ';">'
            . '<picture>'
            . '<img data-lang="' . $imgKey . '" src="' . $src . '" alt="' . $alt . '" title="' . $title . '" loading="eager" decoding="async">'
            . '</picture>'
            . '<span class="hero03-swipe" aria-hidden="true"></span>'
            . '</div>';
    }

    $frontHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $frontHtml .= '<span class="hero03-front-col" style="--hero03-col:' . $j . ';" aria-hidden="true">'
            . '<span class="hero03-front-text">' . $frontText . '</span>'
            . '</span>';
    }

    $brandHtml = '<div class="hero03-brand">'
        . '<img class="hero03-logo" data-lang="' . $logoKey . '" src="' . $logoSrc . '" alt="' . $logoAlt . '" title="' . $logoTitle . '" loading="eager" decoding="async">'
        . '<h2 class="hero03-title" data-lang="' . $titleKey . '">' . $titleText . '</h2>'
        . '</div>';

    $vars = [
        '{classVar}'       => "hero03_{$pad}_classVar",
        '{hero03-content}' => $brandHtml,
        '{columns}'        => $columnsHtml,
        '{col-count}'      => (string) $itemsCount,
        '{front-text-dl}'  => $frontKey,
        '{front-text}'     => $frontHtml,
        '{front-text-plain}' => $frontText,
        '{bg-dl}'          => $bgKey,
        '{bg-src}'         => $bgSrc,
        '{bg-alt}'         => $bgAlt,
        '{bg-title}'       => $bgTitle,
        '{col-gap}'        => '2px',
        '{logo-filter}'    => 'invert(1)',
        '{mouse-enabled}'  => 'true',
        '{mouse-bg}'       => '18',
        '{mouse-brand}'    => '8',
    ];

    unset($params['items']);
    $vars = array_replace($vars, $params);

    return render('App/templates/_hero03.html', $vars);
}
?>
