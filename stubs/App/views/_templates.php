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
            ?>

            <main>


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
                    echo controller('art03', 0, ['items' => 4]);
                    ?>


                    <?php
                    // art04 Refactorizado (instancia 00)
                    echo controller('art04', 0, ['items' => 3]);
                    ?>

                    <?php
                    // art05-2 Refactorizado (instancia 00)
                    echo controller('art05', 0, ['items' => 4]);
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
                    // artSlider01 Refactorizado (instancia 00)
                    echo controller('artSlider01', 0, ['items' => 10]);
                    ?>

                    <?php
                    // artSlider02 Refactorizado (instancia 00)
                    echo controller('artSlider02', 0, ['items' => 4]);
                    ?>
                    

                    <?php
                    // artForm01 Refactorizado (instancia 00)
                    echo controller('artForm01', 0);
                    ?>
                    <?php
                    // artAccordion01 Refactorizado (instancia 00)
                    echo controller('artAccordion01', 0, ['items' => 3]);
                    ?>


                    <?php
                    // artZipper Refactorizado — dinámico (instancia 00)
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