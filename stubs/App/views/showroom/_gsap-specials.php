<?php
/**
 * Recursos inmersivos con GSAP, ScrollTrigger, pin, canvas o WebGL.
 */
// artScatter01: scatter X/Y 0-800 px, rotate 0-90°, scale 0.3-6,
// duración 0.2-2.4 s, offset 0-1 y pin 120-320%. Los data-* viven
// en el template y el recurso reagrupa las palabras durante el scroll.
echo controller('artScatter01', 0);

// artMarquee01: items por fila 0-26, imágenes true/false.
// El template admite speed 6-40 s y direction -1/1.
echo controller('artMarquee01', 0, [
    'items' => 4,
    'items_row1' => 4,
    'items_row2' => 4,
    'with_images' => false,
]);

// artScale01: CTA inyectable; pin y escala permanecen fijados en su JS.
$scaleButton = controller('moduleButtonType01', 0);
echo controller('artScale01', 0, [
    '{button-primary}' => $scaleButton,
]);

// sectionParallax01: items 1-26 y list_items 0-26.
// El template admite parallax-shift 0-40 px y stack-margin-rem 0-6.
echo controller('sectionParallax01', 0, [
    'items' => 3,
    'list_items' => 3,
]);

// sectionDiskSlider01: items 1-26; step-vh 80-240; radius 0.4-0.64;
// strength 0.2-1.4; scroll-power 0.6-3; hold-delay 0-20 s;
// parallax-shift 0-40 px. Shader: noise-strength 0-2,
// noise-scale 0.5-8, noise-speed 0-3, noise-edge 0-1.5,
// mask-sin-strength 0-1, speed 0-4, frequency 0.5-6,
// softness 0.01-0.4, vignette-strength 0-1, power 0.5-3,
// edge-mix 0-1 y mouse-strength 0-2.
echo controller('sectionDiskSlider01', 0, [
    'items' => 5,
    '{disk-hold-delay}' => '1',
    '{disk-strength}' => '1.1',
    '{data-skew-max}' => '0.1',
]);

// sectionSkewGallery01: items 1-26; skew-max 4-40°,
// skew-factor 6-30, direction -1/1 y return 0.02-0.6.
echo controller('sectionSkewGallery01', 0, [
    'items' => 4,
    '{skew-max}' => '2',
    '{skew-factor}' => '15',
    '{skew-direction}' => '-1',
    '{skew-return}' => '0.03',
]);

// artWorksSkew01: skew-max 2-30°, factor 6-30, text-factor 0.2-1,
// ease 0.02-0.4, return 0.02-0.6, media-shift 0-160 px,
// text-shift 0-500 px y direction -1/1.
echo controller('artWorksSkew01', 0, [
    'items' => 4,
    '{skew-max}' => '2',
    '{skew-factor}' => '14',
    '{skew-text-factor}' => '0.45',
    '{skew-ease}' => '0.12',
    '{skew-return}' => '0.22',
    '{skew-media-shift}' => '40',
    '{skew-text-shift}' => '500',
    '{skew-direction}' => '-1',
]);

// sectionHScroll01: items 2-26 y hscroll-speed 0.6-3.
echo controller('sectionHScroll01', 0, [
    'items' => 4,
    '{hscroll-speed}' => '1.1',
]);

// artHeroScroll01: items 1-26, list_items 0-26,
// title-shift 6-60 px y word-shift 4-40 px.
echo controller('artHeroScroll01', 0, [
    'items' => 4,
    'list_items' => 3,
    '{title-shift}' => '28',
    '{word-shift}' => '18',
]);

// artPricingGlass01: items 1-26, list_items 0-26.
// strength 0-80, noise 0.001-0.05, blur 0-12, alpha 0-1,
// chroma 0-3 y text-scale 0.6-1.6.
echo controller('artPricingGlass01', 0, [
    'items' => 3,
    'list_items' => [
        'a' => 5,
        'b' => 5,
        'c' => 5,
    ],
    '{glass-strength}' => '40',
    '{glass-noise}' => '0.005',
    '{glass-blur}' => '4',
    '{glass-alpha}' => '0.5',
    '{glass-chroma}' => '1',
    '{glass-text-scale}' => '1.6',
]);

// artZipper: 1-26 títulos; animación zipper con pin.
echo controller('artZipper', 0, ['items' => 5]);
