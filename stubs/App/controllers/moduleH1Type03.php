<?php
/**
 * Directrices de copy para moduleH1Type03:
 * - Eyebrow: 2-8 palabras.
 * - Encabezado principal: 35-85 caracteres.
 * - Texto de apoyo: 20-45 palabras.
 * - CTA inyectada: 2-5 palabras de acción.
 */
function controller_moduleH1Type03(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $readText = static function (string $key, string $fallback): string {
        $value = $GLOBALS[$key] ?? null;

        return is_object($value) && isset($value->text)
            ? (string) $value->text
            : $fallback;
    };

    $eyebrowKey = "moduleH1Type03_{$pad}_eyebrow_text";
    $headerKey  = "moduleH1Type03_{$pad}_h1_text";
    $introKey   = "moduleH1Type03_{$pad}_p01_text";

    $eyebrowText = $readText(
        $eyebrowKey,
        'Matrix ipsum · Header principal'
    );
    $headerText = $readText(
        $headerKey,
        'moduleH1Type03 · Encabezado inmersivo centrado'
    );
    $introText = $readText(
        $introKey,
        'Matrix ipsum dolor sit amet. Neo eligendi veritatis codicem et simulacrum.'
    );

    $headerLevels = resolve_header_levels(
        $params,
        '{header-primary}',
        1
    );
    $headerTag = 'h' . $headerLevels['base'];

    $eyebrowHtml = trim($eyebrowText) !== ''
        ? '<p class="moduleH1Type03-eyebrow" data-lang="' . $eyebrowKey . '">'
            . $eyebrowText . '</p>'
        : '';
    $introHtml = trim($introText) !== ''
        ? '<p class="moduleH1Type03-text" data-lang="' . $introKey . '">'
            . $introText . '</p>'
        : '';

    $vars = [
        '{classVar}'        => "moduleH1Type03_{$pad}_classVar",
        '{eyebrow}'         => $eyebrowHtml,
        '{header-primary}'  => '<' . $headerTag
            . ' class="moduleH1Type03-title" data-lang="' . $headerKey . '">'
            . $headerText
            . '</' . $headerTag . '>',
        '{intro}'           => $introHtml,
        '{a-button-primary}' => '',
    ];

    return render(
        'App/templates/_moduleH1Type03.html',
        array_replace($vars, $params)
    );
}
?>
