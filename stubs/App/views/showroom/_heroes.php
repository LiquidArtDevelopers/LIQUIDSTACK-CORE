<?php
/**
 * Héroes: hero00..hero07 y módulos H1 usados para componerlos.
 */
$heroButton = controller('moduleButtonType01', 0);
$hero01Content = controller('moduleH1Type01', 0, [
    '{a-button-primary}' => $heroButton,
]);
$hero00Content = controller('moduleH1Type01', 1, [
    '{a-button-primary}' => $heroButton,
]);
$hero02Content = controller('moduleH1Type01', 2, [
    '{a-button-primary}' => $heroButton,
]);
$hero04Content = controller('moduleH1Type01', 3, [
    '{a-button-primary}' => $heroButton,
]);

echo controller('hero01', 0, ['{hero01-content}' => $hero01Content]);
echo controller('hero00', 0, ['{hero00-content}' => $hero00Content]);
echo controller('hero02', 0, ['{hero02-content}' => $hero02Content]);

// hero03, parallax de puntero:
// - {mouse-enabled}: true/false.
// - {mouse-bg}: 0 - 40 px.
// - {mouse-brand}: 0 - 24 px.
echo controller('hero03', 0, [
    '{mouse-enabled}' => 'true',
    '{mouse-bg}' => '18',
    '{mouse-brand}' => '8',
]);

// hero04, escenario WebGL fluido:
// - {hero04-quality}: 0 - 3 (0 = máxima calidad).
// - {hero04-random} y {hero04-colorful}: true/false.
// - {hero04-content}: composición H1 intercambiable.
echo controller('hero04', 0, [
    '{hero04-content}' => $hero04Content,
    '{hero04-quality}' => '0',
    '{hero04-random}' => 'false',
    '{hero04-colorful}' => 'true',
]);

// hero05, distorsión líquida:
// - distortion: 0.02 - 0.35; chroma: 0 - 3.
// - damping: 0.9 - 0.9995; radius: 0.02 - 0.18.
// - force: 0.2 - 3; sim: 96 - 512.
echo controller('hero05', 0, [
    '{hero05-text}' => 'hero05 · Liquid Matrix',
    '{hero05-distortion}' => '0.15',
    '{hero05-chroma}' => '1.3',
    '{hero05-damping}' => '0.99',
    '{hero05-radius}' => '0.03',
    '{hero05-force}' => '1.2',
    '{hero05-sim}' => '512',
]);

$hero06Content = controller('moduleH1Type03', 0, [
    '{a-button-primary}' => $heroButton,
]);
$hero07Content = controller('moduleH1Type04', 0, [
    '{a-button-primary}' => $heroButton,
]);

// hero06/07 controlan el escenario y la imagen editable; sus módulos H1
// controlan el contenido y pueden intercambiarse.
echo controller('hero06', 0, [
    '{hero06-content}' => $hero06Content,
]);
echo controller('hero07', 0, [
    '{hero07-content}' => $hero07Content,
]);

// moduleH1Type02 es el módulo H1 autónomo, centrado y sin escenario.
echo controller('moduleH1Type02', 0, [
    '{a-button-primary}' => $heroButton,
]);
