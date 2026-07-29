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

    $escapeCssString = static fn (string $value): string => str_replace(
        ["\\", '"', "\r", "\n", "\f"],
        ["\\\\", '\\"', '\D ', '\A ', '\C '],
        $value
    );

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

    $devMode = filter_var(
        $_ENV['DEV_MODE'] ?? getenv('DEV_MODE') ?? false,
        FILTER_VALIDATE_BOOLEAN
    );

    $editorAttributes = $devMode
        ? 'data-inline-background'
            . ' data-inline-background-target=".bg"'
            . ' data-inline-background-mobile-key="hero00_bg_mobile"'
            . ' data-inline-background-tablet-key="hero00_bg_tablet"'
            . ' data-inline-background-desktop-key="hero00_bg_desktop"'
            . ' data-inline-background-fallback-key="hero00_bg_fallback"'
        : '';

    $vars = [
        '{editor-attributes}' => $editorAttributes,
        '{hero00-content}'    => '',
        '{bg-mobile-dl}'      => 'hero00_bg_mobile',
        '{bg-mobile-src}'     => $escapeAttr($mobileUrl),
        '{bg-tablet-dl}'      => 'hero00_bg_tablet',
        '{bg-tablet-src}'     => $escapeAttr($tabletUrl),
        '{bg-desktop-dl}'     => 'hero00_bg_desktop',
        '{bg-desktop-src}'    => $escapeAttr($desktopUrl),
        '{bg-fallback-dl}'    => 'hero00_bg_fallback',
        '{bg-fallback-src}'   => $escapeAttr($fallbackUrl),
        '{bg-fallback-style}' => $escapeAttr(
            'background-image: url("'
            . $escapeCssString($fallbackUrl)
            . '");'
        ),
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_hero00.html', $vars);
}
?>
