<?php
/**
 * Directrices de copy para hero00:
 * - Bloque {hero00-content}: 25-45 palabras entre titular, apoyo y CTA.
 * - Títulos o alt de fondos opcionales: 3-6 palabras descriptivas.
 * El recurso solo orquesta fondos responsive; evita duplicar el H1 principal aquí.
 */
function controller_hero00(int $i = 0, array $params = []): string
{
    $escapeAttr = static fn ($value): string => htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $assetUrl = static function (string $src): string {
        $src = trim($src);

        if (
            $src === ''
            || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#)~i', $src) === 1
        ) {
            return $src;
        }

        $root = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');

        return ($root !== '' ? $root . '/' : '/')
            . ltrim($src, '/');
    };

    $mobileUrl = $assetUrl((string) (
        $GLOBALS['hero00_bg_mobile']->src
        ?? 'assets/img/dummy/dummy_900.avif'
    ));
    $tabletUrl = $assetUrl((string) (
        $GLOBALS['hero00_bg_tablet']->src
        ?? 'assets/img/dummy/dummy_1800.avif'
    ));
    $desktopUrl = $assetUrl((string) (
        $GLOBALS['hero00_bg_desktop']->src
        ?? 'assets/img/dummy/dummy_2560.avif'
    ));
    $fallbackUrl = $assetUrl((string) (
        $GLOBALS['hero00_bg_fallback']->src
        ?? 'assets/img/dummy/dummy_900.avif'
    ));
    $fallbackImage = $GLOBALS['hero00_bg_fallback'] ?? null;
    $imageAlt = is_object($fallbackImage) && isset($fallbackImage->alt)
        ? (string) $fallbackImage->alt
        : '';
    $imageTitle = is_object($fallbackImage) && isset($fallbackImage->title)
        ? (string) $fallbackImage->title
        : '';
    $imageWidth = max(1, (int) (
        is_object($fallbackImage) && isset($fallbackImage->width)
            ? $fallbackImage->width
            : 2560
    ));
    $imageHeight = max(1, (int) (
        is_object($fallbackImage) && isset($fallbackImage->height)
            ? $fallbackImage->height
            : 1600
    ));

    $devMode = filter_var(
        $_ENV['DEV_MODE'] ?? getenv('DEV_MODE') ?? false,
        FILTER_VALIDATE_BOOLEAN
    );

    $editorAttributes = $devMode
        ? 'data-inline-background'
            . ' data-inline-background-target=".hero00-media"'
            . ' data-inline-background-picture-source=".hero00-picture source"'
            . ' data-inline-background-mobile-key="hero00_bg_mobile"'
            . ' data-inline-background-tablet-key="hero00_bg_tablet"'
            . ' data-inline-background-desktop-key="hero00_bg_desktop"'
            . ' data-inline-background-fallback-key="hero00_bg_fallback"'
            . ' data-inline-background-mobile-descriptor="480w"'
            . ' data-inline-background-tablet-descriptor="900w"'
            . ' data-inline-background-desktop-descriptor="1800w"'
        : '';

    $vars = [
        '{editor-attributes}'     => $editorAttributes,
        '{hero00-content}'        => '',
        '{img-dl}'                => 'hero00_bg_fallback',
        '{img-src}'               => $escapeAttr($fallbackUrl),
        '{img-mobile-src}'        => $escapeAttr($mobileUrl),
        '{img-tablet-src}'        => $escapeAttr($tabletUrl),
        '{img-desktop-src}'       => $escapeAttr($desktopUrl),
        '{img-fallback-src}'      => $escapeAttr($fallbackUrl),
        '{img-srcset}'            => $escapeAttr(
            $mobileUrl . ' 480w, '
            . $tabletUrl . ' 900w, '
            . $desktopUrl . ' 1800w'
        ),
        '{img-sizes}'             => '100vw',
        '{img-alt}'               => $escapeAttr($imageAlt),
        '{img-title}'             => $escapeAttr($imageTitle),
        '{img-width}'             => (string) $imageWidth,
        '{img-height}'            => (string) $imageHeight,
        '{img-object-position-y}' => 'center',
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_hero00.html', $vars);
}
?>
