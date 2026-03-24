<!DOCTYPE html>
<html lang="<?= $lang ?>">

<head>
    <!-- Global & Variant HEAD -->
    <?php include_once __DIR__.'/../includes/_globalHead.php'?>
</head>

<body>

    

    <!-- Global BODY -->
    <?php include_once __DIR__.'/../includes/_globalBody.php'?>  

    <!-- NAV -->
    <?php include __DIR__.'/../includes/_nav.php' ?>


    <div id="smooth-wrapper">
        <div id="smooth-content">

            <?php
            $boton   = controller('moduleButtonType01', 0);
            $content = controller('moduleH1Type01', 0, [
                '{a-button-primary}' => $boton
            ]);
            echo controller('hero01', 0, ['{hero01-content}' => $content]);
            echo controller('hero00', 0, ['{hero00-content}' => $content]);
            echo controller('hero02', 0, ['{hero02-content}' => $content]);
            // Hero03 cursor parallax controls:
            // - {mouse-enabled}: true/false activa el movimiento por mouse.
            // - {mouse-bg}: 0 - 40 (px) movimiento de la imagen de fondo.
            // - {mouse-brand}: 0 - 24 (px) movimiento del logo + h2.
            echo controller('hero03', 0, [
                '{mouse-enabled}' => 'true',
                '{mouse-bg}' => '18',
                '{mouse-brand}' => '8',
            ]);
            // hero04 (WebGL fluid) - configuracion:
            // - {hero04-quality}: 0 - 3 (0 = max, 3 = low).
            // - {hero04-random}: true/false (splats aleatorios).
            // - {hero04-colorful}: true/false (paleta multicolor).
            // - {hero04-content}: placeholder libre (item interior del header).
            echo controller('hero04', 0, [
                '{hero04-content}' => $content,
                '{hero04-quality}' => '0',
                '{hero04-random}' => 'false',
                '{hero04-colorful}' => 'true',
            ]);
            // hero05 (Liquid distortion) - configuracion:
            // - {hero05-text}: texto de fondo (2-4 palabras recomendado).
            // - {hero05-content}: placeholder libre (item interior del header).
            // - {hero05-distortion}: 0.02 - 0.35 (fuerza de refraccion).
            // - {hero05-chroma}: 0 - 3 (aberracion cromatica).
            // - {hero05-damping}: 0.9 - 0.9995 (persistencia del oleaje).
            // - {hero05-radius}: 0.02 - 0.18 (radio de impacto del cursor).
            // - {hero05-force}: 0.2 - 3 (energia del impacto del cursor).
            // - {hero05-sim}: 96 - 512 (resolucion de simulacion).
            echo controller('hero05', 0, [
                '{hero05-text}' => 'Liquid Matrix',
                '{hero05-distortion}' => '0.15',
                '{hero05-chroma}' => '1.3',
                '{hero05-damping}' => '0.99',
                '{hero05-radius}' => '0.03',
                '{hero05-force}' => '1.2',
                '{hero05-sim}' => '512',
            ]);
            ?>

            <main>

                <?php
                // aniBackground01 (clase utilitaria):
                // - Se aplica por clase .aniBackground01 a cualquier caja del layout.
                // - Config JS en src/js/resources/_aniBackground01.js (ANI_BACKGROUND01_DEFAULTS).
                // - Rango recomendado countDesktop/countTablet/countMobile: 8 - 500.
                // Resumen: fondo WebGL con relojes en tiempo real reutilizable por clase.
                ?>
                <section class="aniBackground01"></section>

                <section>
                    <?php
                    // artScatter01 (min/max recomendados):
                    // - data-scatter-x / data-scatter-y: 0 - 800 (px) dispersion del texto.
                    // - data-scatter-rotate: 0 - 90 (deg, reservado).
                    // - data-scatter-scale-min / data-scatter-scale-max: 0.3 - 6 (escala de palabras).
                    // - data-scatter-duration-min / data-scatter-duration-max: 0.2 - 2.4 (s) duracion de entrada.
                    // - data-scatter-offset-max: 0 - 1 (offset inicial en timeline).
                    // - data-scatter-pin: 120 - 320 (%) distancia de pin.
                    // Resumen: dispersa cada palabra y la reagrupa durante el scroll con pin.
                    // Nota: los data-* se editan en el template si se quiere ajustar.
                    echo controller('artScatter01', 0);
                    ?>
                </section>

                <section>
                    <?php
                    // artMarquee01 (min/max):
                    // - items / items_row1 / items_row2: 0 - 26 (cantidad de items por fila).
                    // - with_images: true/false (muestra iconos por item).
                    // - data-marquee-speed: 6 - 40 (s) velocidad del loop (en template).
                    // - data-direction: -1 / 1 (sentido del loop, en template).
                    // Resumen: doble cinta de textos en bucle, con inversion de sentido al scroll.
                    echo controller('artMarquee01', 0, [
                        'items'      => 4,
                        'items_row1' => 4,
                        'items_row2' => 4,
                        'with_images'=> false
                    ]);
                    ?>
                </section>

                <section>
                    <?php
                    // artScale01:
                    // - {button-primary}: placeholder para CTA/boton.
                    // - Sin modificadores en sniper (pin + escala fijos en JS).
                    // Resumen: video escala de 0.15 a 1 con pin, y el copy sube suavemente.
                    $scaleButton = controller('moduleButtonType01', 0);
                    echo controller('artScale01', 0, [
                        '{button-primary}' => $scaleButton,
                    ]);
                    ?>
                </section>

                <?php
                // sectionParallax01 (min/max):
                // - items: 1 - 26 (cards).
                // - list_items: 0 - 26 (items por lista).
                // - {a-list-items}, {b-list-items}... (override HTML por card).
                // - data-parallax-shift: 0 - 40 (px) parallax por pointer (opcional en template).
                // - data-stack-margin-rem: 0 - 6 (rem) offset del pin (opcional en template).
                // - Títulos/CTA vienen del JSON (no hay placeholder directo de botón).
                // Resumen: stack de cards con parallax y pin progresivo.
                echo controller('sectionParallax01', 0, [
                    'items'      => 3,
                    'list_items' => 3,
                ]);
                ?>

                <?php
                
                $particlesButtonPrimary   = controller('moduleButtonType01', 0);
                $particlesButtonSecondary = controller('moduleButtonType02', 0);

                $particlesStep1Title = controller('moduleH2Type01', 0);
                $particlesStep2Title = controller('moduleH2Type01', 1);
                $particlesStep3Title = controller('moduleH2Type01', 2);

                $particlesStep1Text = $GLOBALS['moduleH1Type02_00_p01_text']->text ?? '';
                $particlesStep2Text = $GLOBALS['moduleH1Type02_00_p02_text']->text ?? '';
                $particlesStep3Text = $GLOBALS['moduleTest_00_p_text']->text ?? '';

                // sectionParticles01 (min/max):
                // - {particles-count}: 1400 - 32000 (cantidad base).
                // - {particles-bg-count}: 0 - 30000 (particulas de fondo).
                // - {particles-size}: 0.6 - 3.5 (tamano particula).
                // - {particles-depth}: 6 - 44 (profundidad).
                // - {particles-speed}: 0.2 - 2.2 (velocidad).
                // - {particles-shape-ratio}: 0.3 - 1 (ratio de particulas en shape).
                // - {particles-shape-scale}: 0.25 - 1.2 (escala base del shape).
                // - {particles-step-vh}: 80 - 240 (alto por step).
                // - {particles-radius}: reservado (no se usa en JS).
                // - items: array de steps (cada item -> <article data-particles-step>).
                // - Usa claves en snake_case (ej: shape_ratio -> data-particles-shape-ratio).
                // - "content" es el HTML interno del step (ahi puedes inyectar titulos/botones).
                // Resumen: morph de particulas por steps con pin y animacion en scroll.
                // MATRIX CONTROLS (min/max)
                /*
                SHAPE (afecta al conjunto matrix completo):
                - data-particles-shape-ratio: % de particulas dedicadas al shape (0.3-1). Menos = menos letras+cortina.
                - data-particles-shape-scale: escala base del shape (0.2-1.3). En matrix puede auto-aumentar para cubrir viewport.
                - data-particles-shape-offset-x: desplaza en X (-0.45 a 0.45).
                - data-particles-shape-hold: pausa en el centro (0-0.85; >0.85 se recorta).

                CORTINA (BG) - USAR ESTOS:
                - data-particles-matrix-bg-cols: columnas de la cortina (10-420).
                - data-particles-matrix-bg-rows: filas de la cortina (12-320).
                - data-particles-matrix-bg-column-density: % columnas activas (0.2-2.5; 1=100%).
                - data-particles-matrix-bg-column-fill: largo de las columnas (0.3-1.8).
                - data-particles-matrix-bg-column-alpha: brillo de columnas (0.5-2.2).
                - data-particles-matrix-bg-noise-density: ruido aleatorio (0-2.5).
                - data-particles-matrix-bg-row-spacing: salto vertical (1-6; 1=mas continuo).
                - data-particles-matrix-bg-density: base/fallback (0.2-2.5). Solo se usa si no defines bg-column-density o bg-noise-density.

                FALLBACKS (solo si NO hay bg-*):
                - data-particles-matrix-cols: columnas fallback (10-420).
                - data-particles-matrix-rows: filas fallback (12-320).
                - data-particles-matrix-density: densidad base fallback (0.25-2.5).
                - data-particles-matrix-column-density: columnas activas fallback (0.2-2.5).
                - data-particles-matrix-column-fill: largo fallback (0.3-1.8).
                - data-particles-matrix-column-alpha: brillo fallback (0.5-2.2).
                - data-particles-matrix-noise-density: ruido fallback (0-2.5).
                - data-particles-matrix-row-spacing: salto vertical fallback (1-6).

                GLIFOS/FONT:
                - data-particles-matrix-font-scale: tamano del glifo (0.4-1.2).

                MOVIMIENTO:
                - data-particles-matrix-speed: velocidad de caida (0.5-14).

                LETRAS:
                - data-particles-matrix-word-src: imagen de las letras.
                - data-particles-matrix-word-count: particulas SOLO letras (60-max disponible; se recorta si te pasas).
                - data-particles-matrix-word-letter-gap: separacion interna (0-0.2).
                - data-particles-matrix-word-particle-scale: tamano particulas letras (0.5-1.2).
                - data-particles-matrix-word-image-scale: escala imagen letras (0.18-0.8).
                - data-particles-matrix-word-image-boost: densidad de muestreo (1-10).
                - data-particles-matrix-word-image-step: paso muestreo (1-4; 1 mas denso).
                - data-particles-matrix-word-image-offset-x/y: desplazamiento (-0.4 a 0.4).
                */
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
                ?>

                <?php
                // sectionDiskSlider01 (min/max):
                // - items: 1 - 26 (slides).
                // - {header-primary}: placeholder para titulo (opcional).
                // - {step-vh}: 80 - 240 (alto por step).
                // - {disk-radius}: 0.4 - 0.64 (radio del disco).
                // - {disk-strength}: 0.2 - 1.4 (intensidad del shader).
                // - {disk-scroll-power}: 0.6 - 3 (sensibilidad al scroll).
                // - {disk-hold-delay}: 0 - 20 (s) pausa automatica.
                // - {disk-parallax-shift}: 0 - 40 (px) parallax del fondo.
                // - {disk-noise-strength}: 0 - 2.
                // - {disk-noise-scale}: 0.5 - 8.
                // - {disk-noise-speed}: 0 - 3.
                // - {disk-noise-edge}: 0 - 1.5.
                // - {disk-mask-sin-strength}: 0 - 1.
                // - {disk-mask-sin-speed}: 0 - 4.
                // - {disk-mask-sin-frequency}: 0.5 - 6.
                // - {disk-mask-softness}: 0.01 - 0.4.
                // - {disk-vignette-strength}: 0 - 1.
                // - {disk-vignette-power}: 0.5 - 3.
                // - {disk-edge-color}: color hex.
                // - {disk-edge-mix}: 0 - 1.
                // - {disk-mouse-strength}: 0 - 2.
                // Resumen: slider circular con canvas + progresion en scroll.
                echo controller('sectionDiskSlider01', 0, [
                    'items' => 5,
                    '{disk-hold-delay}' => '1',
                    '{disk-strength}' => '1.1',
                    '{data-skew-max}' => '0.1',
                ]);
                ?>

                <?php
                // sectionSkewGallery01 (min/max):
                // - items: 1 - 26 (cards).
                // - {header-primary}: placeholder para titulo (opcional).
                // - {skew-max}: 4 - 40 (deg)
                // - {skew-factor}: 6 - 30 (más alto = menos sesgado)
                // - {skew-direction}: -1 o 1
                // {skew-return} (0.02–0.6): más alto = vuelve antes.
                // Resumen: galeria con skew reactivo al scroll.
                echo controller('sectionSkewGallery01', 0, [
                    'items' => 4,
                    '{skew-max}' => '2',
                    '{skew-factor}' => '15',
                    '{skew-direction}' => '-1',
                    '{skew-return}' => '0.03',
                ]);
                ?>

                <?php
                // artWorksSkew01 (min/max):
                // - {skew-max}: 2 - 30 (deg)
                // - {skew-factor}: 6 - 30 (mas alto = menos sesgado)
                // - {skew-text-factor}: 0.2 - 1 (multiplicador de texto)
                // - {skew-ease}: 0.02 - 0.4
                // - {skew-return}: 0.02 - 0.6 (mas alto = vuelve antes)
                // - {skew-media-shift}: 0 - 160 (px)
                // - {skew-text-shift}: 0 - 500 (px)
                // - {skew-direction}: -1 o 1
                // - {header-primary}: placeholder para titulo (opcional).
                // Resumen: tarjetas con skew dinamico y desplazamiento de media/texto.
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
                ?>

                <?php
                // sectionHScroll01 (min/max):
                // - items: 2 - 26 (cards).
                // - {hscroll-speed}: 0.6 - 3 (multiplica distancia de scroll).
                // - {header-primary}: placeholder para titulo (opcional).
                // Resumen: carrusel horizontal con pin y desplazamiento por scroll.
                echo controller('sectionHScroll01', 0, [
                    'items' => 4,
                    '{hscroll-speed}' => '1.1',
                ]);
                ?>

                <?php
                // artHeroScroll01 (min/max):
                // - items: 1 - 26 (numero de cards).
                // - list_items / subitems: 0 - 26 (subitems por card).
                // - {title-shift}: 6 - 60 (px, reservado en template).
                // - {word-shift}: 4 - 40 (px, reservado en template).
                // - {header-primary}: placeholder para titulo (opcional).
                // Resumen: cards con titulos split/hover y palabra destacada en cabecera.
                echo controller('artHeroScroll01', 0, [
                    'items' => 4,
                    'list_items' => 3,
                    '{title-shift}' => '28',
                    '{word-shift}' => '18',
                ]);
                ?>

                <?php
                // moduleH2Type01 Refactorizado (fondo oscuro y animación)
                $h2 = controller('moduleH2Type01', 0);
                ?>

                <?php
                // moduleH1Type02 (título y texto centrados sin fondo)
                $moduleH1Type02Button = controller('moduleButtonType01', 0);
                echo controller('moduleH1Type02', 0, [
                    '{a-button-primary}' => $moduleH1Type02Button,
                ]);
                ?>

                <?php
                $boton01 = controller('moduleButtonType01', 0);
                $boton02 = controller('moduleButtonType01', 0);
                echo controller('sect01', 0, [
                    '{header-primary}'     => $h2,
                    '{a-button-secondary}' => $boton01,
                    '{d-button-secondary}' => $boton02,
                    'items'               => 4
                ]);
                ?>


                
                <?php
                $h2       = controller('moduleH2Type01', 1);
                $boton01  = controller('moduleButtonType02', 0);
                $boton02  = controller('moduleButtonType02', 0);
                $boton03  = controller('moduleButtonType02', 0);
                ?>

                <?php
                echo controller('sect02', 0, [
                    '{header-primary}'     => $h2,
                    '{a-button-secondary}' => $boton01,
                    '{b-button-secondary}' => $boton02,
                    '{c-button-secondary}' => $boton03,
                    'items'                => 3
                ]);
                ?>
                

                <?php
                // moduleH2Type01 Refactorizado (fondo oscuro y animación)
                $h2 = controller('moduleH2Type01', 2);
                echo controller('sectTabs01', 0, ['{section-h2}' => $h2, 'items' => 3]);
                ?>



                <section>
                    
                    <?php
                    // moduleH2Type01 Refactorizado (fondo oscuro y animación)
                    echo controller('moduleH2Type01', 3);
                    ?>


                    <?php
                    // moduleButtonType01 Refactorizado (flecha animada · oscuro)
                    $moduleButton02 = controller('moduleButtonType01', 0);
                    ?>
                    <?php
                    // moduleButtonType02 Refactorizado (simple)
                    $moduleButton02a = controller('moduleButtonType02', 0);
                    ?>
                    <?php
                    // moduleButtonType02 Refactorizado (simple)
                    $moduleButton02b = controller('moduleButtonType02', 0);
                    ?>                 

                    <?php
                    echo controller('art01', 0, [
                        '{a-button-secondary}' => $moduleButton02a,
                        '{b-button-secondary}' => $moduleButton02b,
                        '{button-primary}'    => $moduleButton02,
                        'items'               => 2,
                    ]);
                    ?>

                    <?php
                    $moduleButton02b = controller('moduleButtonType02', 0);
                    echo controller('art02', 0, [
                        '{a-button-primary}' => $moduleButton02b,
                        '{b-button-primary}' => $moduleButton02b,
                        'items'              => 2,
                    ]);
                    ?>

                                        
                    <?php
                    echo controller('art03', 0, ['items' => 3]);
                    ?>


                    <?php
                    // art04 Refactorizado (instancia 00)
                    echo controller('art04', 0, ['items' => 3]);
                    ?>

                    <?php
                    // art05-2 Refactorizado (instancia 00)
                    echo controller('art05', 0, ['items' => 3]);
                    ?>

                    <?php
                    // art06 Refactorizado (instancia 00)
                    echo controller('art06', 0, ['items' => 3]);
                    ?>

                    <?php
                    // art07 Refactorizado (instancia 00)
                    echo controller('art07', 0);
                    ?>

                    <?php
                    $art16Button = controller('moduleButtonType01', 0);
                    echo controller('art16', 0, [
                        '{button-primary}' => $art16Button,
                    ]);
                    ?>

                    <?php
                    $art17Header = controller('moduleH2Type01', 4);
                    $art17Cta    = controller('moduleButtonType02', 0);

                    echo controller('art17', 0, [
                        '{header-primary}'   => $art17Header,
                        '{a-button-primary}' => $art17Cta,
                        'items'              => 2,
                        'list_items'         => [
                            'a' => 7,
                            'b' => 6,
                        ],
                    ]);
                    ?>

                    <?php
                    // moduleButtonType01 Refactorizado (flecha animada · oscuro)
                    $moduleButton01 = controller('moduleButtonType01', 0);
                    ?>


                    <?php
                    // art08 Refactorizado (instancia 00)
                    echo controller('art08', 0, [
                        'items' => 2,
                        '{a-button-primary}' => $moduleButton01,
                        '{b-button-primary}' => $moduleButton01
                    ]);
                    ?>
                    <?php
                    // art09 Refactorizado (instancia 00)
                    echo controller('art09', 0, ['items' => 3]);
                    ?>


                    <?php
                    // art10 Refactorizado (instancia 00)
                    echo controller('art10', 0, ['items' => 3]);
                    ?>


                    <?php
                    echo controller('art11', 0, ['items' => 3]);
                    ?>


                    <?php
                    // art12 Refactorizado (instancia 00)
                    echo controller('art12', 0, ['items' => 3]);
                    ?>


                    <?php
                    // art13 Refactorizado (instancia 00)
                    echo controller('art13', 0, ['items' => 1]);
                    ?>


                    <?php
                    // moduleButtonType01 Refactorizado (flecha animada · oscuro)
                    $boton = controller('moduleButtonType01', 0);
                    ?>

                    <?php
                    // art14 Refactorizado (instancia 00)
                    echo controller('art14', 0, ['{button-primary}' => $boton]);
                    ?>


                    <?php
                    // art15 Refactorizado (instancia 00)
                    echo controller('art15', 0);
                    ?>

                    <?php
                    // art15 Refactorizado (instancia 00)
                    echo controller('art16', 0);
                    ?>

                    <?php
                    // art15 Refactorizado (instancia 00)
                    echo controller('art17', 0);
                    ?>
                    
                    <?php
                    echo controller('art18', 0, [
                        'items' => 3,
                        'list_items' => [
                            'a' => 3,
                            'b' => 3,
                            'c' => 4
                        ],
                    ]);
                    ?>

                    <?php
                    // art19 (min/max):
                    // - items: 2 - 26 (slides).
                    // - Header principal: key art19_00_headerPrimary (o placeholder {header-primary}).
                    // - Titulos/subtitulos por slide: art19_00_slide[a..z]_[h3|p].
                    // - Imagen por slide: art19_00_slide[a..z]_img (src/alt/title).
                    // - {wave-distortion}: 0.05 - 0.5 (intensidad de refraccion).
                    // - {wave-chroma}: 0 - 2 (aberracion cromatica del frente de onda).
                    // - {wave-damping}: 0.92 - 0.999 (persistencia del oleaje).
                    // - {wave-radius}: 0.02 - 0.2 (radio de impacto del clic).
                    // - {wave-force}: 0.2 - 3 (energia inicial del impacto).
                    // - {wave-duration}: 0.45 - 8 (duracion de la transicion de imagen).
                    // - {wave-sim}: 96 - 512 (resolucion de simulacion).
                    // Resumen: slider por clic que revela la siguiente imagen con ondas de agua desde el punto pulsado.
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
                    ?>

                    <?php
                    // artPricingGlass01 (min/max):
                    // - items: 1 - 26 (cards).
                    // - list_items: 0 - 26 (beneficios por card).
                    // - {bg-text}: texto gigante de fondo (key artPricingGlass01_00_bg_text).
                    // - Titulos cards: artPricingGlass01_00_headerSecondary_[a..z].
                    // - CTA cards: artPricingGlass01_00_[a..z]_cta (text/href/title).
                    // - {glass-strength}: 0 - 80 (scale del displacement).
                    // - {glass-noise}: 0.001 - 0.05 (ruido del turbulence).
                    // - {glass-blur}: 0 - 12 (blur del ruido).
                    // - {glass-alpha}: 0 - 1 (tinte del glass).
                    // - {glass-chroma}: 0 - 3 (aberracion cromatica sutil).
                    // - {glass-text-scale}: 0.6 - 1.6 (escala del texto de fondo).
                    // Resumen: pricing cards con liquid glass sutil sobre el texto de fondo.
                    echo controller('artPricingGlass01', 0, [
                        'items' => 3,
                        'list_items' => [
                            'a' => 5,
                            'b' => 5,
                            'c' => 5
                        ],
                        '{glass-strength}' => '40',
                        '{glass-noise}' => '0.005',
                        '{glass-blur}' => '4',
                        '{glass-alpha}' => '0.5',
                        '{glass-chroma}' => '1',
                        '{glass-text-scale}' => '1.6',
                    ]);
                    ?>

                    

                    <?php
                    // artSlider01 (min/max):
                    // - items: 1 - 26 (slides).
                    // - Autoplay: delay 6s, duracion 2s (fijo en JS).
                    // Resumen: carrusel infinito draggable con autoplay y botones prev/next.
                    echo controller('artSlider01', 0, ['items' => 10]);
                    ?>

                    <?php
                    // artSlider02 (min/max):
                    // - items: 1 - 26 (slides).
                    // - Autoplay: delay 6s, duracion 2s (fijo en JS).
                    // Resumen: slider draggable con titulos en viewport y botones prev/next.
                    echo controller('artSlider02', 0, ['items' => 3]);
                    ?>
                    

                    <?php
                    // artForm01:
                    // - Sin modificadores (solo copy + campos).
                    // Resumen: formulario completo con loader, validacion y bloque lateral.
                    echo controller('artForm01', 0);
                    ?>
                    <?php
                    // artAccordion01 Refactorizado (instancia 00)
                    echo controller('artAccordion01', 0, ['items' => 3]);
                    ?>


                    <?php
                    // artZipper (min/max):
                    // - items: 1 - 26 (titulos).
                    // Resumen: lista con animacion zipper y pin en scroll.
                    echo controller('artZipper', 0, ['items' => 5]);
                    ?>
                    <?php
                    /*--------------------------------------
                    _moduleTest
                    --------------------------------------*/
                    echo controller('moduleTest', 0);
                    ?>

                    <?php
                    // Módulo de imagen simple
                    echo controller('moduleImgType01', 0);
                    ?>
                    
                    
                </section>

            </main>

            <?php include __DIR__.'/../includes/_footer.php' ?>
        </div>
    </div>

    

</body>

</html>
