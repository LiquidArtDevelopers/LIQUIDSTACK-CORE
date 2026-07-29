<?php
/**
 * Directrices de copy para art20:
 * - Encabezado principal: 35-75 caracteres.
 * - Texto de apoyo: 20-45 palabras.
 * - CTA inyectada: 2-5 palabras de acción.
 * - Variante permitida: default | reverse.
 */
function controller_art20(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $variant = strtolower((string) ($params['variant'] ?? 'default'));
    $variant = in_array($variant, ['default', 'reverse'], true)
        ? $variant
        : 'default';
    unset($params['variant']);

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $headerTag    = 'h' . $headerLevels['base'];

    $escapeAttr = static fn ($value): string => htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $assetUrl = static function (string $src): string {
        if ($src === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $src) === 1) {
            return $src;
        }

        $root = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');

        return $root . '/' . ltrim($src, '/');
    };

    $headerKey = "art20_{$pad}_headerPrimary";
    $introKey  = "art20_{$pad}_intro_p";
    $imageKey  = "art20_{$pad}_img";
    $headerObj = $GLOBALS[$headerKey] ?? null;
    $introObj  = $GLOBALS[$introKey] ?? null;
    $imageObj  = $GLOBALS[$imageKey] ?? null;

    $headerText = is_object($headerObj) && isset($headerObj->text)
        ? (string) $headerObj->text
        : 'art20 · Matrix ipsum';
    $introText = is_object($introObj) && isset($introObj->text)
        ? (string) $introObj->text
        : 'Matrix ipsum dolor sit amet, Neo eligendi veritatis codicem et simulacrum.';

    $imageSrc = is_object($imageObj) && isset($imageObj->src)
        ? (string) $imageObj->src
        : 'assets/img/dummy/dummy02.avif';
    $imageAlt = is_object($imageObj) && isset($imageObj->alt)
        ? (string) $imageObj->alt
        : 'Escena de Matrix';
    $imageTitle = is_object($imageObj) && isset($imageObj->title)
        ? (string) $imageObj->title
        : '';
    $imageWidth = max(1, (int) (
        is_object($imageObj) && isset($imageObj->width) ? $imageObj->width : 2560
    ));
    $imageHeight = max(1, (int) (
        is_object($imageObj) && isset($imageObj->height) ? $imageObj->height : 1722
    ));

    $introHtml = trim($introText) !== ''
        ? '<p class="art20-intro" data-lang="' . $introKey . '">'
            . $introText . '</p>'
        : '';

    $vars = [
        '{classVar}'       => "art20_{$pad}_classVar",
        '{variant-class}'  => 'art20--' . $variant,
        '{img-dl}'         => $imageKey,
        '{img-src}'        => $escapeAttr($assetUrl($imageSrc)),
        '{img-alt}'        => $escapeAttr($imageAlt),
        '{img-title}'      => $escapeAttr($imageTitle),
        '{img-width}'      => (string) $imageWidth,
        '{img-height}'     => (string) $imageHeight,
        '{header-primary}' => '<' . $headerTag . ' class="art20-title" data-lang="'
            . $headerKey . '">' . $headerText . '</' . $headerTag . '>',
        '{intro}'          => $introHtml,
        '{button-primary}' => '',
    ];

    return render('App/templates/_art20.html', array_replace($vars, $params));
}
?>
