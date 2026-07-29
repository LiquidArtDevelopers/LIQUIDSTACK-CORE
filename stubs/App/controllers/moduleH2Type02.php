<?php
/**
 * Directrices de copy para moduleH2Type02:
 * - Encabezado: 28-70 caracteres que identifiquen con claridad la sección.
 * - Evitar saltos de línea manuales salvo que formen parte del diseño aprobado.
 */
function controller_moduleH2Type02(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $headerLevels = resolve_header_levels($params, '{header-primary}', 2);
    $headerTag    = 'h' . $headerLevels['base'];
    $headerKey    = "moduleH2Type02_{$pad}_headerPrimary";
    $headerObj    = $GLOBALS[$headerKey] ?? null;
    $headerText   = is_object($headerObj) && isset($headerObj->text)
        ? (string) $headerObj->text
        : 'moduleH2Type02 · Matrix ipsum';

    unset($params['{header-primary}']);

    $vars = [
        '{classVar}'   => "moduleH2Type02_{$pad}_classVar",
        '{header-tag}' => $headerTag,
        '{header-dl}'  => $headerKey,
        '{header-text}' => $headerText,
    ];

    return render(
        'App/templates/_moduleH2Type02.html',
        array_replace($vars, $params)
    );
}
?>
