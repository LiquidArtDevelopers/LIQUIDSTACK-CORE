<?php
/**
 * Cards, matrices, listados y bloques repetibles.
 */
$cardsButtonPrimary = controller('moduleButtonType01', 0);
$cardsButtonSecondary = controller('moduleButtonType02', 0);
?>
<section>
<?php
echo controller('art02', 0, [
    'items' => 8,
]);

echo controller('art32', 0, [
    'items' => 8,
]);

$art02littleParagraphA = controller('moduleParrafo01', 0);
$art02littleParagraphB = controller('moduleParrafo01', 1);
// art02little:
// - items: 1-3 tarjetas; variant: image | icon.
// - {header-primary}: encabezado externo que recalcula los secundarios.
// - {a-content}, {b-content}, {c-content}: cualquier sniper renderizado.
// - Sin header externo: h3 principal y h4 por tarjeta.
echo controller('art02little', 0, [
    'items' => 2,
    'variant' => 'image',
    '{a-content}' => $art02littleParagraphA,
    '{b-content}' => $art02littleParagraphB,
]);

$art02littleHeader = controller('moduleH2Type01', 5);
$art02littleListA = controller('moduleList01', 0, ['items' => 5]);
$art02littleListB = controller('moduleList01', 1, ['items' => 6]);
$art02littleListC = controller('moduleList01', 2, ['items' => 3]);
// El H2 externo convierte los encabezados de tarjeta en H3.
echo controller('art02little', 1, [
    'items' => 3,
    'variant' => 'icon',
    '{header-primary}' => $art02littleHeader,
    '{a-content}' => $art02littleListA,
    '{b-content}' => $art02littleListB,
    '{c-content}' => $art02littleListC,
]);

echo controller('art03', 0, ['items' => 3]);
echo controller('art04', 0, ['items' => 3]);
echo controller('art06', 0, ['items' => 3]);

echo controller('art08', 0, [
    'items' => 2,
    '{a-button-primary}' => $cardsButtonPrimary,
    '{b-button-primary}' => $cardsButtonPrimary,
]);

echo controller('art09', 0, ['items' => 3]);
echo controller('art10', 0, ['items' => 3]);
echo controller('art11', 0, ['items' => 3]);
echo controller('art12', 0, ['items' => 3]);
echo controller('art13', 0, ['items' => 1]);

$art17Header = controller('moduleH2Type01', 4);
echo controller('art17', 0, [
    '{header-primary}' => $art17Header,
    '{a-button-primary}' => $cardsButtonSecondary,
    'items' => 2,
    'list_items' => [
        'a' => 7,
        'b' => 6,
    ],
]);

// Segunda instancia contractual con contenido por defecto.
echo controller('art17', 0);

echo controller('art18', 0, [
    'items' => 3,
    'list_items' => [
        'a' => 3,
        'b' => 3,
        'c' => 4,
    ],
]);

echo controller('art21', 0, [
    'items' => 3,
    '{button-primary}' => $cardsButtonSecondary,
]);

echo controller('art22', 0, [
    'items' => 3,
    '{button-primary}' => $cardsButtonSecondary,
]);

echo controller('art24', 0, ['items' => 4]);
echo controller('art27', 0, ['items' => 9]);

echo controller('art28', 0, [
    'items' => 3,
    '{a-button-primary}' => $cardsButtonSecondary,
    '{b-button-primary}' => $cardsButtonSecondary,
    '{c-button-primary}' => $cardsButtonSecondary,
]);

// art30: items controla las fichas (0-4) y benefits los iconos del banner
// (0-6). Con benefits => 0 el banner se oculta.
echo controller('art30', 0, [
    'items' => 4,
    'benefits' => 6,
]);

echo controller('art31', 0, [
    'items' => 4,
    '{a-button-primary}' => $cardsButtonSecondary,
    '{b-button-primary}' => $cardsButtonSecondary,
    '{c-button-primary}' => $cardsButtonSecondary,
    '{d-button-primary}' => $cardsButtonSecondary,
]);

$art33Paragraph = controller('moduleParrafo01', 5);
$art33List = controller('moduleList01', 4, [
    'items' => 4,
]);
// art33: article con h3 natural, fichas div con h4 e items 1-26.
// Cada {x-content} admite módulos; cada {x-button-primary}, un CTA opcional.
// moduleList01 conserva su editor agrupado e iconos también al inyectarse.
echo controller('art33', 0, [
    'items' => 2,
    '{a-content}' => $art33Paragraph,
    '{b-content}' => $art33List,
    '{a-button-primary}' => $cardsButtonSecondary,
]);

$art34List = controller('moduleList01', 5, [
    'items' => 5,
]);
$art34Paragraph = controller('moduleParrafo01', 6);
// art34 conserva el contrato semántico y añade un CTA general mediante
// {button-primary}.
echo controller('art34', 0, [
    'items' => 2,
    '{a-content}' => $art34List,
    '{b-content}' => $art34Paragraph,
    '{button-primary}' => $cardsButtonPrimary,
]);
?>
</section>
