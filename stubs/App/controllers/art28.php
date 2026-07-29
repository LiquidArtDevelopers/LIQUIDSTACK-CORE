<?php
/**
 * Directrices de copy para art28:
 * - Encabezado principal: 5-10 palabras que agrupen las filas de contenido.
 * - Encabezado por fila: 4-8 palabras sobre un servicio, caso o beneficio.
 * - Primer párrafo: 22-38 palabras para presentar la necesidad o contexto.
 * - Segundo párrafo: 22-38 palabras para explicar el enfoque o resultado.
 * - Alt/title de imagen: 5-10 palabras con una descripción útil y específica.
 * - CTA inyectable por fila: 2-5 palabras.
 */
function controller_art28(int $i = 0, array $params = []): string
{
    $pad     = sprintf('%02d', $i);
    $letters = range('a', 'z');

    $itemsCount = max(0, (int) ($params['items'] ?? 3));
    $itemsCount = min($itemsCount, count($letters));
    unset($params['items']);

    $getProperty = static function (
        string $key,
        string $property,
        string $fallback = ''
    ): string {
        $value = $GLOBALS[$key] ?? null;

        if (is_object($value) && isset($value->$property)) {
            return (string) $value->$property;
        }

        if (is_array($value) && isset($value[$property])) {
            return (string) $value[$property];
        }

        return $fallback;
    };

    $escapeAttr = static fn ($value): string => htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $getScalar = static function (string $key): string {
        $value = $GLOBALS[$key] ?? '';

        return is_string($value) || is_numeric($value)
            ? trim((string) $value)
            : '';
    };

    $root = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');

    $assetUrl = static function (string $path) use ($root): string {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (preg_match('#^(?:https?:)?//#i', $path) || str_starts_with($path, 'data:')) {
            return $path;
        }

        return $root === ''
            ? ltrim($path, '/')
            : $root . '/' . ltrim($path, '/');
    };

    $srcsetCandidate = static function (string $candidate) use ($assetUrl): string {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return '';
        }

        if (preg_match('/^(.+?)\s+(\d+(?:\.\d+)?[wx])$/', $candidate, $matches)) {
            return $assetUrl($matches[1]) . ' ' . $matches[2];
        }

        return $assetUrl($candidate);
    };

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $imageFallbacks = [
        'assets/img/dummy/dummy01.avif',
        'assets/img/dummy/dummy02.avif',
        'assets/img/dummy/dummy03.avif',
        'assets/img/dummy/dummy04.avif',
    ];
    $p01Fallback = 'Matrix ipsum dolor sit amet. Neo recorre una nueva capa '
        . 'del simulacro y descubre que sus reglas también pueden doblarse.';
    $p02Fallback = 'Trinity mantiene abierta la conexión mientras Morpheus '
        . 'guía la elección y Agent Smith intenta cerrar todas las salidas.';

    $itemsMarkup = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $number = sprintf('%02d', $j + 1);

        $headerKey   = "art28_{$pad}_headerSecondary_{$letter}";
        $imgKey         = "art28_{$pad}_{$letter}_img";
        $imgSrcset01Key = "art28_{$pad}_{$letter}_img_srcset01";
        $imgSrcset02Key = "art28_{$pad}_{$letter}_img_srcset02";
        $p01Key         = "art28_{$pad}_{$letter}_p01";
        $p02Key         = "art28_{$pad}_{$letter}_p02";

        $imgPath = $getProperty(
            $imgKey,
            'src',
            $imageFallbacks[$j % count($imageFallbacks)]
        );
        $imgSrc  = $assetUrl($imgPath);
        $imgAlt  = $getProperty(
            $imgKey,
            'alt',
            "Escena Matrix de la fila {$number}"
        );
        $imgTitle = $getProperty(
            $imgKey,
            'title',
            "Matrix ipsum {$number} para art28"
        );
        $headerText = $getProperty(
            $headerKey,
            'text',
            "Matrix ipsum {$number}"
        );
        $p01Text = $getProperty($p01Key, 'text', $p01Fallback);
        $p02Text = $getProperty($p02Key, 'text', $p02Fallback);

        $srcsetValues = [
            $getScalar($imgSrcset01Key),
            $getScalar($imgSrcset02Key),
        ];

        if ($srcsetValues === ['', '']) {
            $srcsetValues = [
                'assets/img/dummy/dummy_1200.avif 1200w',
                'assets/img/dummy/dummy_1800.avif 1800w',
            ];
        }

        $srcset = implode(', ', array_filter(array_map($srcsetCandidate, $srcsetValues)));

        $sizesKey = '{' . $letter . '-img-sizes}';
        $sizes    = $params[$sizesKey] ?? '(max-width: 799px) calc(100vw - 3rem), (max-width: 1399px) 55vw, 45vw';
        $sizes    = is_string($sizes) ? $sizes : '';
        unset($params[$sizesKey]);

        $buttonKey    = '{' . $letter . '-button-primary}';
        $buttonMarkup = $params[$buttonKey] ?? '';
        $buttonMarkup = is_string($buttonMarkup) ? $buttonMarkup : '';
        unset($params[$buttonKey]);

        $ctaMarkup = $buttonMarkup !== ''
            ? '<div class="art28-cta">' . $buttonMarkup . '</div>'
            : '';

        $itemsMarkup .=
            '<div class="art28-row">' .
                '<img data-lang="' . $escapeAttr($imgKey)
                    . '" src="' . $escapeAttr($imgSrc)
                    . '" alt="' . $escapeAttr($imgAlt)
                    . '" title="' . $escapeAttr($imgTitle)
                    . '" width="1200" height="675" srcset="'
                    . $escapeAttr($srcset) . '" sizes="' . $escapeAttr($sizes)
                    . '" loading="lazy" decoding="async">' .
                '<div class="art28-content">' .
                    '<h' . $itemLevel . ' class="art28-row-title" data-lang="'
                        . $escapeAttr($headerKey) . '">' . $headerText
                        . '</h' . $itemLevel . '>' .
                    '<p data-lang="' . $escapeAttr($p01Key) . '">'
                        . $p01Text . '</p>' .
                    '<p data-lang="' . $escapeAttr($p02Key) . '">'
                        . $p02Text . '</p>' .
                    $ctaMarkup .
                '</div>' .
            '</div>';
    }

    $headerKey = "art28_{$pad}_headerPrimary";

    $vars = [
        '{classVar}'       => "art28_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel
            . ' class="art28-title" data-lang="' . $escapeAttr($headerKey)
            . '">' . $getProperty(
                $headerKey,
                'text',
                'art28 · Article media alterna'
            ) . '</h' . $baseLevel . '>',
        '{items}'          => $itemsMarkup,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art28.html', $vars);
}
?>
