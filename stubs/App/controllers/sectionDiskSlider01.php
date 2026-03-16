<?php
/**
 * Directrices de copy para sectionDiskSlider01:
 * - Encabezado principal: 4-8 palabras.
 * - Texto introductorio: 12-22 palabras.
 * - Pistas de interacción: 4-8 palabras.
 * - Caption por slide: 3-6 palabras descriptivas.
 * - Atributos alt/title: 6-12 palabras claras.
 */
function controller_sectionDiskSlider01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $headerLevels = resolve_header_levels($params, '{header-primary}', 2);
    $baseLevel    = $headerLevels['base'];

    $headerObj  = $GLOBALS["sectionDiskSlider01_{$pad}_headerPrimary"] ?? null;
    $introObj   = $GLOBALS["sectionDiskSlider01_{$pad}_intro"] ?? null;
    $hintObj    = $GLOBALS["sectionDiskSlider01_{$pad}_hint"] ?? null;

    $introText = is_object($introObj) && isset($introObj->text) ? $introObj->text : '';
    $hintText  = is_object($hintObj) && isset($hintObj->text) ? $hintObj->text : '';

    $headerHtml = '';
    if (is_object($headerObj) && isset($headerObj->text) && $headerObj->text !== '') {
        $headerHtml = '<h' . $baseLevel . ' data-lang="sectionDiskSlider01_' . $pad . '_headerPrimary">'
            . $headerObj->text
            . '</h' . $baseLevel . '>';
    }

    $vars = [
        '{classVar}'          => "sectionDiskSlider01_{$pad}_classVar",
        '{header-primary}'    => $headerHtml,
        '{intro-dl}'          => "sectionDiskSlider01_{$pad}_intro",
        '{intro-text}'        => $introText,
        '{hint-dl}'           => "sectionDiskSlider01_{$pad}_hint",
        '{hint-text}'         => $hintText,
        '{slides}'            => '',
        '{center-items}'      => '',
        '{step-vh}'           => '100',
        '{disk-radius}'       => '0.48',
        '{disk-strength}'     => '0.75',
        '{disk-scroll-power}' => '1.3',
        '{disk-hold-delay}'   => '4',
        '{disk-parallax-shift}' => '14',
        '{disk-noise-strength}' => '0.55',
        '{disk-noise-scale}'    => '2.6',
        '{disk-noise-speed}'    => '0.55',
        '{disk-noise-edge}'     => '0.45',
        '{disk-mask-sin-strength}'  => '0.35',
        '{disk-mask-sin-speed}'     => '1',
        '{disk-mask-sin-frequency}' => '2.4',
        '{disk-mask-softness}'      => '0.08',
        '{disk-vignette-strength}'  => '0.32',
        '{disk-vignette-power}'     => '1.35',
        '{disk-edge-color}'         => '#dce8ff',
        '{disk-edge-mix}'           => '0.28',
        '{disk-mouse-strength}'     => '0.5',
    ];

    $letters    = range('a', 'z');
    $itemsCount = isset($params['items']) ? (int) $params['items'] : 4;
    $itemsCount = max(2, min(count($letters), $itemsCount));

    $slidesHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $pre    = "sectionDiskSlider01_{$pad}_slide{$letter}";

        $imgObj     = $GLOBALS[$pre . '_img'] ?? null;
        $captionObj = $GLOBALS[$pre . '_caption'] ?? null;
        $kickerObj  = $GLOBALS[$pre . '_kicker'] ?? null;

        $src = '';
        $alt = '';
        $title = '';
        if (is_object($imgObj)) {
            $src   = isset($imgObj->src) ? $_ENV['RAIZ'] . '/' . ltrim((string) $imgObj->src, '/') : '';
            $alt   = isset($imgObj->alt) ? (string) $imgObj->alt : '';
            $title = isset($imgObj->title) ? (string) $imgObj->title : '';
        }

        $captionText = is_object($captionObj) && isset($captionObj->text) ? (string) $captionObj->text : '';
        $kickerText  = is_object($kickerObj) && isset($kickerObj->text) ? (string) $kickerObj->text : '';

        $slidesHtml .= '<figure class="sectionDiskSlider01-slide" data-disk-slide data-disk-slide-index="' . $j . '" data-disk-slide-src="' . $src . '" data-disk-kicker="' . htmlspecialchars($kickerText, ENT_QUOTES) . '" data-disk-title="' . htmlspecialchars($captionText, ENT_QUOTES) . '">'
            . '<img data-lang="' . $pre . '_img" src="' . $src . '" alt="' . $alt . '" title="' . $title . '" loading="lazy" decoding="async">'
            . '<figcaption class="sectionDiskSlider01-caption" data-lang="' . $pre . '_caption">' . $captionText . '</figcaption>'
            . '</figure>';
    }

    $vars['{slides}'] = $slidesHtml;

    unset($params['items']);
    $vars = array_replace($vars, $params);

    return render('App/templates/_sectionDiskSlider01.html', $vars);
}
?>
