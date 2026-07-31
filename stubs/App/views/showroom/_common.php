<?php
/**
 * Artículos comunes: composiciones directas de imagen, título y texto.
 */
$commonButtonPrimary = controller('moduleButtonType01', 0);
$commonButtonSecondary = controller('moduleButtonType02', 0);

echo controller('art01', 0, [
    '{a-button-secondary}' => $commonButtonSecondary,
    '{b-button-secondary}' => $commonButtonSecondary,
    '{button-primary}' => $commonButtonPrimary,
    'items' => 2,
]);

echo controller('art05', 0, ['items' => 3]);
echo controller('art07', 0);

echo controller('art14', 0, [
    '{button-primary}' => $commonButtonPrimary,
]);

echo controller('art15', 0);

// art16 con CTA inyectado y variante con contenido por defecto.
echo controller('art16', 0, [
    '{button-primary}' => $commonButtonPrimary,
]);
echo controller('art16', 0);

echo controller('art20', 0, [
    '{button-primary}' => $commonButtonSecondary,
]);

echo controller('art23', 0);

echo controller('art25', 0, [
    '{button-primary}' => $commonButtonPrimary,
]);

echo controller('art26', 0, [
    '{button-primary}' => $commonButtonPrimary,
]);

echo controller('art29', 0);
