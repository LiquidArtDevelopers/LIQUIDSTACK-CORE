<?php
/**
 * Formularios, acordeones y pestañas.
 */
$tabsHeader = controller('moduleH2Type01', 2);
echo controller('sectTabs01', 0, [
    '{section-h2}' => $tabsHeader,
    'items' => 3,
]);

// artForm01 mantiene su flujo completo, loader, validación y bloque lateral.
echo controller('artForm01', 0);
// Los tres módulos reutilizan de forma asíncrona el endpoint POST /form;
// solo cambia su composición visual, sin ficha de contacto ni mapa.
echo controller('moduleFormContact01', 0);
echo controller('moduleFormContact02', 0);
echo controller('moduleFormContact03', 0);
// items controla el número de entradas de cada acordeón.
echo controller('artAccordion01', 0, ['items' => 3]);
echo controller('artAccordion02', 0, ['items' => 6]);
