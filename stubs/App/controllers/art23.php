<?php
/**
 * Directrices de copy para art23:
 * - Encabezado principal: 35-75 caracteres.
 * - Cada párrafo: 20-55 palabras.
 * - Alt de imagen: 5-14 palabras descriptivas.
 */
function controller_art23(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

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

    $headerKey = "art23_{$pad}_headerPrimary";
    $p1Key     = "art23_{$pad}_p1";
    $p2Key     = "art23_{$pad}_p2";
    $imageKey  = "art23_{$pad}_img";
    $headerObj = $GLOBALS[$headerKey] ?? null;
    $p1Obj     = $GLOBALS[$p1Key] ?? null;
    $p2Obj     = $GLOBALS[$p2Key] ?? null;
    $imageObj  = $GLOBALS[$imageKey] ?? null;

    $headerText = is_object($headerObj) && isset($headerObj->text)
        ? (string) $headerObj->text
        : 'art23 · Matrix ipsum';
    $p1Text = is_object($p1Obj) && isset($p1Obj->text)
        ? (string) $p1Obj->text
        : 'Matrix ipsum dolor sit amet, Neo eligendi veritatis codicem et simulacrum.';
    $p2Text = is_object($p2Obj) && isset($p2Obj->text)
        ? (string) $p2Obj->text
        : 'Morpheus quaerat optionem, pillula rubra aperiam systema et realitatem.';

    $imageSrc = is_object($imageObj) && isset($imageObj->src)
        ? (string) $imageObj->src
        : 'assets/img/dummy/dummy03.avif';
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
        is_object($imageObj) && isset($imageObj->height) ? $imageObj->height : 1696
    ));

    $vars = [
        '{classVar}'       => "art23_{$pad}_classVar",
        '{header-primary}' => '<' . $headerTag . ' class="art23-title" data-lang="'
            . $headerKey . '">' . $headerText . '</' . $headerTag . '>',
        '{p1-dl}'          => $p1Key,
        '{p1-text}'        => $p1Text,
        '{p2-dl}'          => $p2Key,
        '{p2-text}'        => $p2Text,
        '{img-dl}'         => $imageKey,
        '{img-src}'        => $escapeAttr($assetUrl($imageSrc)),
        '{img-alt}'        => $escapeAttr($imageAlt),
        '{img-title}'      => $escapeAttr($imageTitle),
        '{img-width}'      => (string) $imageWidth,
        '{img-height}'     => (string) $imageHeight,
    ];

    return render('App/templates/_art23.html', array_replace($vars, $params));
}
?>
