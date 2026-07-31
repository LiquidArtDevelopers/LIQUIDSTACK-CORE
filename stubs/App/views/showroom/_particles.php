<?php
/**
 * Partículas y fondos canvas/WebGL.
 *
 * BASE y los consumidores pueden añadir recursos propios mediante
 * App/views/showroom/_local.php sin modificar este catálogo canónico.
 */
?>
<?php
// aniBackground01 es una clase utilitaria reutilizable:
// - defaults en src/js/resources/_aniBackground01.js.
// - countDesktop/countTablet/countMobile recomendado: 8 - 500.
?>
<div class="aniBackground01"></div>

<?php
$particlesButtonPrimary = controller('moduleButtonType01', 0);
$particlesButtonSecondary = controller('moduleButtonType02', 0);
$particlesStep1Title = controller('moduleH2Type01', 6);
$particlesStep2Title = controller('moduleH2Type01', 7);
$particlesStep3Title = controller('moduleH2Type01', 8);

$particlesStep1Text = $GLOBALS['moduleH1Type02_00_p01_text']->text ?? '';
$particlesStep2Text = $GLOBALS['moduleH1Type02_00_p02_text']->text ?? '';
$particlesStep3Text = $GLOBALS['moduleTest_00_p_text']->text ?? '';

// sectionParticles01:
// - particles-count: 1400 - 32000; bg-count: 0 - 30000.
// - size: 0.6 - 3.5; depth: 6 - 44; speed: 0.2 - 2.2.
// - shape-ratio: 0.3 - 1; shape-scale: 0.25 - 1.2.
// - step-vh: 80 - 240; items recibe los steps con su HTML en content.
// Las claves PHP usan snake_case y el controlador genera los data-*.
//
// Matrix, conjunto: ratio 0.3-1, scale 0.2-1.3, offset X -0.45..0.45
// y hold 0-0.85.
// Cortina BG: cols 10-420, rows 12-320, column-density 0.2-2.5,
// column-fill 0.3-1.8, column-alpha 0.5-2.2, noise-density 0-2.5
// y row-spacing 1-6. matrix_bg_density (0.2-2.5) es el fallback.
// Los equivalentes matrix_cols/rows/density/column_*/noise_*/row_spacing
// solo actúan cuando no se proporcionan controles matrix_bg_*.
// Glifos/movimiento: font-scale 0.4-1.2 y speed 0.5-14.
// Imagen-palabra: count 60-máximo disponible, letter-gap 0-0.2,
// particle-scale 0.5-1.2, image-scale 0.18-0.8, boost 1-10,
// step 1-4 y offsets X/Y -0.4..0.4.
$particlesItems = [
    [
        'align' => 'left',
        'shape' => 'cube',
        'shape_ratio' => '0.94',
        'shape_scale' => '0.9',
        'shape_offset_x' => '0.26',
        'content' => <<<HTML
        <div class="sectionParticles01-copy">
            {$particlesStep1Title}
            <p data-lang="moduleH1Type02_00_p01_text">{$particlesStep1Text}</p>
            <div class="sectionParticles01-cta">{$particlesButtonPrimary}</div>
        </div>
        HTML,
    ],
    [
        'align' => 'right',
        'shape' => 'matrix',
        'shape_ratio' => '1',
        'shape_scale' => '1.3',
        'shape_offset_x' => '0',
        'shape_hold' => '1',
        'matrix_bg_cols' => '360',
        'matrix_bg_rows' => '160',
        'matrix_bg_density' => '2',
        'matrix_bg_column_density' => '2.2',
        'matrix_bg_column_fill' => '1.6',
        'matrix_bg_column_alpha' => '0.2',
        'matrix_bg_noise_density' => '0.35',
        'matrix_bg_row_spacing' => '1',
        'matrix_font_scale' => '0.40',
        'matrix_speed' => '5',
        'matrix_word_src' => '/assets/img/resources/sectionParticles01/matrix.png',
        'matrix_word_count' => '6000',
        'matrix_word_letter_gap' => '0',
        'matrix_word_particle_scale' => '1',
        'matrix_word_image_scale' => '0.34',
        'matrix_word_image_boost' => '8',
        'matrix_word_image_step' => '1',
        'matrix_word_image_offset_x' => '-0.17',
        'matrix_word_image_offset_y' => '0',
        'content' => <<<HTML
        <div class="sectionParticles01-copy">
            {$particlesStep2Title}
            <p data-lang="moduleH1Type02_00_p02_text">{$particlesStep2Text}</p>
            <div class="sectionParticles01-cta">{$particlesButtonSecondary}</div>
        </div>
        HTML,
    ],
    [
        'align' => 'left',
        'shape' => 'blackhole',
        'shape_ratio' => '0.92',
        'shape_scale' => '1.1',
        'shape_depth' => '1.6',
        'shape_depth_jitter' => '0.18',
        'shape_offset_x' => '0.18',
        'bh_disk_inner' => '0.34',
        'bh_disk_outer' => '0.74',
        'bh_disk_thickness' => '0.08',
        'bh_halo' => '0.26',
        'bh_rim' => '0.16',
        'bh_tilt' => '18',
        'content' => <<<HTML
        <div class="sectionParticles01-copy">
            {$particlesStep3Title}
            <p data-lang="moduleTest_00_p_text">{$particlesStep3Text}</p>
            <div class="sectionParticles01-cta">{$particlesButtonPrimary}</div>
        </div>
        HTML,
    ],
];

echo controller('sectionParticles01', 0, [
    'items' => $particlesItems,
]);
