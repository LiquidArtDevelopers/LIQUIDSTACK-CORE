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
echo controller('moduleH2Type01', 10);
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

// Auth02 muestra la misma familia backend-agnostic con composición oscura,
// color corporativo secundario y feedback progresivo para la nueva clave.
$auth02Login = controller('moduleFormAuthLogin02', 0);
echo controller('artAuth02', 0, [
    '{form-slot}' => $auth02Login,
]);
$auth02Recover = controller('moduleFormAuthRecover02', 0);
echo controller('artAuth02', 1, [
    '{form-slot}' => $auth02Recover,
]);
$auth02Password = controller('moduleFormAuthPassword02', 0);
echo controller('artAuth02', 2, [
    '{form-slot}' => $auth02Password,
]);
unset($auth02Login, $auth02Recover, $auth02Password);

// items controla el número de entradas de cada acordeón.
echo controller('artAccordion01', 0, ['items' => 3]);
echo controller('artAccordion02', 0, ['items' => 6]);
?>
</section>
