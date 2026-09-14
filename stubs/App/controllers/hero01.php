<?php
/**
 * Directrices de copy para hero01:
 * - Contenido {hero01-content}: 30-50 palabras combinando titular, claim y CTA.
 * - Prioriza un único CTA con verbo imperativo breve.
 * - `with_image`: activa la capa de imagen y su edición inline (opt-in).
 * Aprovecha el fondo animado del recurso para mensajes de campaña puntuales.
 */
function controller_hero01(int $i = 0, array $params = []): string
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

    $contrastSurface = ($params['contrast_surface'] ?? false) === true;
    $withImage = ($params['with_image'] ?? false) === true;
    unset(
        $params['contrast_surface'],
        $params['with_image'],
        $params['{contrast-class}'],
        $params['{editor-attributes}'],
        $params['{hero01-media}']
    );

    $imageKey = "hero01_{$pad}_img";
    $imageObject = $GLOBALS[$imageKey] ?? null;
    $imageMarkup = '';
    $editorAttributes = '';

    if ($withImage) {
        $imageSrc = is_object($imageObject) && isset($imageObject->src)
            ? (string) $imageObject->src
            : 'assets/img/dummy/dummy01.avif';
        $imageAlt = is_object($imageObject) && isset($imageObject->alt)
            ? (string) $imageObject->alt
            : '';
        $imageTitle = is_object($imageObject) && isset($imageObject->title)
            ? (string) $imageObject->title
            : '';
        $imageWidth = max(1, (int) (
            is_object($imageObject) && isset($imageObject->width)
                ? $imageObject->width
                : 2560
        ));
        $imageHeight = max(1, (int) (
            is_object($imageObject) && isset($imageObject->height)
                ? $imageObject->height
                : 1600
        ));
        $imageUrl = $assetUrl($imageSrc);

        $imageMarkup = sprintf(
            '<picture class="hero01-picture">'
                . '<img class="hero01-media" data-lang="%s" '
                . 'data-blog-image-object-position-y="center" '
                . 'src="%s" srcset="%s %dw" sizes="100vw" '
                . 'alt="%s" title="%s" width="%d" height="%d" '
                . 'loading="eager" fetchpriority="high" decoding="async">'
                . '</picture>',
            $escapeAttr($imageKey),
            $escapeAttr($imageUrl),
            $escapeAttr($imageUrl),
            $imageWidth,
            $escapeAttr($imageAlt),
            $escapeAttr($imageTitle),
            $imageWidth,
            $imageHeight
        );

        $devMode = filter_var(
            $_ENV['DEV_MODE'] ?? getenv('DEV_MODE') ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        if ($devMode) {
            $editorAttributes = 'data-inline-background'
                . ' data-inline-background-target=".hero01-media"'
                . ' data-inline-background-image-key="'
                . $escapeAttr($imageKey)
                . '"';
        }
    }

    $vars = [
        '{editor-attributes}' => $editorAttributes,
        '{hero01-media}'      => $imageMarkup,
        '{hero01-content}'    => '',
        '{contrast-class}'    => $contrastSurface
            ? ' hero01--contrast-surface'
            : '',
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_hero01.html', $vars);
}
?>
