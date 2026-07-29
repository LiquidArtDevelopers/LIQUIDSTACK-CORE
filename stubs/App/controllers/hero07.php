<?php
/**
 * Directrices de copy para hero07:
 * - Bloque {hero07-content}: módulo H1 o contenido equivalente inyectado.
 * - Alt/title de imagen: 5-10 palabras descriptivas.
 * El recurso controla únicamente escenario, overlay e imagen editable.
 */
function controller_hero07(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

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

    $imageKey = "hero07_{$pad}_img";
    $imageObj = $GLOBALS[$imageKey] ?? null;

    $devMode = filter_var(
        $_ENV['DEV_MODE'] ?? getenv('DEV_MODE') ?? false,
        FILTER_VALIDATE_BOOLEAN
    );
    $editorAttributes = $devMode
        ? 'data-inline-background'
            . ' data-inline-background-target=".hero07-media"'
            . ' data-inline-background-image-key="' . $escapeAttr($imageKey) . '"'
        : '';

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

    $vars = [
        '{classVar}'          => "hero07_{$pad}_classVar",
        '{editor-attributes}' => $editorAttributes,
        '{hero07-content}'    => '',
        '{img-dl}'            => $imageKey,
        '{img-src}'           => $escapeAttr($assetUrl($imageSrc)),
        '{img-alt}'           => $escapeAttr($imageAlt),
        '{img-title}'         => $escapeAttr($imageTitle),
        '{img-width}'         => (string) $imageWidth,
        '{img-height}'        => (string) $imageHeight,
    ];

    return render('App/templates/_hero07.html', array_replace($vars, $params));
}
?>
