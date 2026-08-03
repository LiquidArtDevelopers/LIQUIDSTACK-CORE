<?php
/**
 * Vídeo, imagen, sliders y galerías interactivas.
 */
?>
<section>
<?php
// art19: items 2-26. Wave controls: distortion 0.05-0.5,
// chroma 0-2, damping 0.92-0.999, radius 0.02-0.2, force 0.2-3,
// duration 0.45-8 s y sim 96-512.
echo controller('art19', 0, [
    'items' => 4,
    '{wave-distortion}' => '0.18',
    '{wave-chroma}' => '2',
    '{wave-damping}' => '0.997',
    '{wave-radius}' => '0.09',
    '{wave-force}' => '1.35',
    '{wave-duration}' => '4.2',
    '{wave-sim}' => '256',
]);

// Sliders: items 1-26. Ambos mantienen autoplay de 6 s y transición de 2 s.
echo controller('artSlider01', 0, ['items' => 10]);
echo controller('artSlider02', 0, ['items' => 3]);

// artVideo01 + moduleVideo01:
// - Ctrl + doble clic sobre el vídeo edita youtube/local y sus campos.
// - YouTube usa fachada ligera; el iframe se monta tras consentimiento y play.
// - type puede fijar el proveedor desde PHP; media_position: start | end.
// - {content} acepta módulos y {button-primary} es un CTA opcional.
// - h3 natural, escalable con header_level o {header-primary} externo.
$artVideoYoutube = controller('moduleVideo01', 0);
$artVideoParagraph = controller('moduleParrafo01', 2);
$artVideoCta = controller('moduleButtonType04', 1);
echo controller('artVideo01', 0, [
    'media_position' => 'end',
    '{content}' => $artVideoParagraph,
    '{video}' => $artVideoYoutube,
    '{button-primary}' => $artVideoCta,
]);

$artVideoLocal = controller('moduleVideo01', 1);
$artVideoList = controller('moduleList01', 3, [
    'items' => 4,
]);
echo controller('artVideo01', 1, [
    'media_position' => 'start',
    '{content}' => $artVideoList,
    '{video}' => $artVideoLocal,
]);

// artVideo02: article al 100% móvil, 90% tablet y 80% desktop;
// el vídeo ocupa 100% en móvil y 60% desde tablet.
$artVideoColumn = controller('moduleVideo01', 2);
$artVideoColumnParagraph = controller('moduleParrafo01', 4);
$artVideoColumnCta = controller('moduleButtonType04', 2);
echo controller('artVideo02', 0, [
    '{content}' => $artVideoColumnParagraph,
    '{video}' => $artVideoColumn,
    '{button-primary}' => $artVideoColumnCta,
]);

echo controller('moduleImgType01', 0);
?>
</section>
