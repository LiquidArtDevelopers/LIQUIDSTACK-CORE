<?php
/**
 * Directrices de copy para art16:
 * - Fragmento de encabezado (línea 1): 3-4 palabras destacando la propuesta principal.
 * - Fragmento de encabezado (línea 2): 3-5 palabras con beneficio, público o enfoque geográfico.
 * - Párrafo descriptivo: 40-60 palabras combinando servicio, cobertura y valor diferencial.
 * - CTA opcional: 2-4 palabras con verbo imperativo o promesa directa.
 */
function controller_art16(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

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

    $readBackground = static function (
        string $key,
        string $fallback
    ): string {
        $value = $GLOBALS[$key] ?? $fallback;

        if (is_object($value) && isset($value->src)) {
            return (string) $value->src;
        }

        return is_scalar($value) ? (string) $value : $fallback;
    };

    $mobileKey = "art16_{$pad}_bg_mobile";
    $tabletKey = "art16_{$pad}_bg_tablet";
    $desktopKey = "art16_{$pad}_bg_desktop";

    $mobileUrl = $assetUrl($readBackground(
        $mobileKey,
        'assets/img/dummy/dummy_900.avif'
    ));
    $tabletUrl = $assetUrl($readBackground(
        $tabletKey,
        'assets/img/dummy/dummy_1800.avif'
    ));
    $desktopUrl = $assetUrl($readBackground(
        $desktopKey,
        'assets/img/dummy/dummy_2560.avif'
    ));

    $devMode = filter_var(
        $_ENV['DEV_MODE'] ?? getenv('DEV_MODE') ?? false,
        FILTER_VALIDATE_BOOLEAN
    );
    $editorAttributes = $devMode
        ? 'data-inline-background'
            . ' data-inline-background-target=".bg"'
            . ' data-inline-background-mobile-key="' . $mobileKey . '"'
            . ' data-inline-background-tablet-key="' . $tabletKey . '"'
            . ' data-inline-background-desktop-key="' . $desktopKey . '"'
        : '';

    $bg = sprintf(
        '<span class="bg" data-bg-mobile="%s" data-bg-tablet="%s" data-bg-desktop="%s" style="%s"></span>',
        $escapeAttr($mobileUrl),
        $escapeAttr($tabletUrl),
        $escapeAttr($desktopUrl),
        $escapeAttr(
            'background-image: url("'
            . $escapeCssString($desktopUrl)
            . '")'
        )
    );

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $headerTag = 'h' . $headerLevels['base'];
    $headingFirstKey = "art16_{$pad}_h3_1";
    $headingSecondKey = "art16_{$pad}_h3_2";
    $headingFirst = $GLOBALS[$headingFirstKey]->text ?? 'Matrix ipsum';
    $headingSecond = $GLOBALS[$headingSecondKey]->text ?? 'dolor sit amet';

    $vars = [
        '{classVar}'          => "art16_{$pad}_classVar",
        '{editor-attributes}' => $editorAttributes,
        '{span-bg-img}'       => $bg,
        '{header-primary}'    => '<' . $headerTag . ' class="art16-title">'
            . '<span data-lang="' . $headingFirstKey . '">'
            . $headingFirst
            . '</span>'
            . '<span data-lang="' . $headingSecondKey . '">'
            . $headingSecond
            . '</span>'
            . '</' . $headerTag . '>',
        '{p-dl}'              => "art16_{$pad}_body_p",
        '{p-text}'            => $GLOBALS["art16_{$pad}_body_p"]->text
            ?? 'Matrix ipsum dolor sit amet.',
        '{button-primary}'     => '',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art16.html', $vars);
}
?>
