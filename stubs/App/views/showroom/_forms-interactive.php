<?php
/**
 * Formularios, acordeones y pestañas.
 */
$tabsHeader = controller('moduleH2Type01', 2);
echo controller('sectTabs01', 0, [
    '{section-h2}' => $tabsHeader,
    'items' => 3,
]);
?>
<section>
<?php
// artForm01 mantiene su flujo completo, loader, validación y bloque lateral.
echo controller('artForm01', 0);
// Los tres módulos reutilizan de forma asíncrona el endpoint POST /form;
// solo cambia su composición visual, sin ficha de contacto ni mapa.
echo controller('moduleFormContact01', 0);
echo controller('moduleFormContact02', 0);
echo controller('moduleFormContact03', 0);

// Familia visual de autenticación. Los módulos no fijan endpoint ni backend;
// artAuth01 compone una única muestra con el formulario de acceso.
$authLogin = controller('moduleFormAuthLogin01', 0);
echo controller('artAuth01', 0, [
    '{form-slot}' => $authLogin,
]);
echo controller('moduleFormAuthRecover01', 0);
echo controller('moduleFormAuthPassword01', 0);
// items controla el número de entradas de cada acordeón.
echo controller('artAccordion01', 0, ['items' => 3]);
echo controller('artAccordion02', 0, ['items' => 6]);
?>
</section>
