<?php
/**
 * Átomos reutilizables y composiciones de sección.
 */

?>
<section id="showroom-button-modules">
    <?php echo controller('moduleH2Type01', 9); ?>
    <div class="showroom-buttonModules">
        <?php
        echo controller('moduleButtonType01', 0);
        echo controller('moduleButtonType02', 0);
        echo controller('moduleButtonType03', 0);
        echo controller('moduleButtonType04', 0);
        ?>
    </div>
</section>
<section>
    <?php
    echo controller('moduleH2Type02', 0);
    echo controller('moduleTable01', 0, [
        'items' => 3,
        'list_items' => 3,
    ]);
    ?>
</section>
<?php

$section01Header = controller('moduleH2Type01', 0);
$section01ButtonA = controller('moduleButtonType01', 0);
$section01ButtonD = controller('moduleButtonType01', 0);
echo controller('sect01', 0, [
    '{header-primary}' => $section01Header,
    '{a-button-secondary}' => $section01ButtonA,
    '{d-button-secondary}' => $section01ButtonD,
    'items' => 4,
]);

$section02Header = controller('moduleH2Type01', 1);
$section02ButtonA = controller('moduleButtonType02', 0);
$section02ButtonB = controller('moduleButtonType02', 0);
$section02ButtonC = controller('moduleButtonType02', 0);
echo controller('sect02', 0, [
    '{header-primary}' => $section02Header,
    '{a-button-secondary}' => $section02ButtonA,
    '{b-button-secondary}' => $section02ButtonB,
    '{c-button-secondary}' => $section02ButtonC,
    'items' => 3,
]);
?>
<section>
    <?php
    echo controller('moduleH2Type01', 3);
    echo controller('moduleTest', 0);
    ?>
</section>
