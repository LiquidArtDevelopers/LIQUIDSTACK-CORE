<?php
/**
 * Directrices de copy para moduleH1Type01:
 * - H1 entre 45 y 65 caracteres (incluye espacios) con enfoque SEO.
 * - Primer párrafo: 40‑60 palabras priorizando beneficios y zonas.
 * - Segundo párrafo: 35‑55 palabras con datos operativos y contactos.
 * Añade negritas puntuales (<b>) y saltos (<br>) solo si alivian la lectura.
 */
function controller_moduleH1Type01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $readText = static function (
        string $placeholder,
        string $key
    ) use ($params): string {
        if (array_key_exists($placeholder, $params)) {
            return (string) $params[$placeholder];
        }

        $value = $GLOBALS[$key] ?? null;

        return is_object($value) && isset($value->text)
            ? (string) $value->text
            : '';
    };

    $headerKey = "moduleH1Type01_{$pad}_h1_text";
    $firstParagraphKey = "moduleH1Type01_{$pad}_p01_text";
    $secondParagraphKey = "moduleH1Type01_{$pad}_p02_text";

    $vars = [
        '{classVar}'  => "moduleH1Type01_{$pad}_classVar",
        '{h1-dl}'      => $headerKey,
        '{h1-text}'    => $readText('{h1-text}', $headerKey),
        '{p-01-dl}'    => $firstParagraphKey,
        '{p-01-text}'  => $readText('{p-01-text}', $firstParagraphKey),
        '{p-02-dl}'    => $secondParagraphKey,
        '{p-02-text}'  => $readText('{p-02-text}', $secondParagraphKey),
        '{a-button-primary}' => '',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_moduleH1Type01.html', $vars);
}
?>
