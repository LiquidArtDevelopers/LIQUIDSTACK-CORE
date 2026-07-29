<?php
/**
 * Directrices de copy para art30:
 * - Encabezado principal: 45-75 caracteres que introduzcan la colección.
 * - Intro: 18-36 palabras por párrafo; se recomiendan uno o dos.
 * - Títulos de tarjeta: 2-6 palabras con una idea clara y diferenciada.
 * - Descripción de tarjeta: 12-28 palabras orientadas a valor o contexto.
 * - Títulos de beneficio: 2-6 palabras, paralelos y centrados en ventajas.
 * - Alt/title de medios: 4-9 palabras; title de enlace: 4-8 palabras.
 *
 * Configuración:
 * - items: número de fichas de la galería (0-4; 3 por defecto).
 * - benefits: número de iconos del banner (0-6; 5 por defecto).
 *   Con 0, el banner no se muestra.
 */
function controller_art30(int $i = 0, array $params = []): string
{
    global $lang;

    $pad         = sprintf('%02d', $i);
    $letters     = range('a', 'z');
    $maxItems    = 4;
    $maxBenefits = 6;

    $itemsCount = isset($params['items']) ? (int) $params['items'] : 3;
    $itemsCount = max(0, min($itemsCount, $maxItems));

    $benefitsCount = isset($params['benefits']) ? (int) $params['benefits'] : 5;
    $benefitsCount = max(0, min($benefitsCount, $maxBenefits));

    unset($params['items'], $params['benefits']);

    $currentLang = (string) ($lang ?? $GLOBALS['lang'] ?? $_ENV['LANG_DEFAULT'] ?? 'es');
    $currentLang = preg_match('/^[A-Za-z0-9_-]+$/', $currentLang) === 1
        ? $currentLang
        : 'es';

    $templateFile = __DIR__ . '/../config/languages/templates/' . $currentLang . '.json';
    $templateJson = is_readable($templateFile) ? file_get_contents($templateFile) : '{}';
    $templateLang = json_decode($templateJson);
    $templateLang = is_object($templateLang) ? $templateLang : new stdClass();

    $readObject = static function (string $key, array $fallback) use ($templateLang): object {
        $templateKey = preg_replace('/^art30_\d{2}_/', 'art30_00_', $key);
        $value = $GLOBALS[$key]
            ?? $templateLang->{$key}
            ?? ($templateKey !== null ? ($templateLang->{$templateKey} ?? null) : null);

        if (is_object($value)) {
            return (object) array_replace($fallback, get_object_vars($value));
        }

        if (is_array($value)) {
            return (object) array_replace($fallback, $value);
        }

        return (object) $fallback;
    };

    $escapeAttr = static fn ($value): string => htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $rootUrl = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');
    $devMode = filter_var(
        $_ENV['DEV_MODE'] ?? getenv('DEV_MODE') ?? false,
        FILTER_VALIDATE_BOOLEAN
    );
    $inlineGroupAttribute = $devMode ? ' data-inline-group' : '';

    $assetUrl = static function (string $src) use ($rootUrl): string {
        $src = trim($src);
        if ($src === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#)~i', $src) === 1) {
            return $src;
        }

        return ($rootUrl !== '' ? $rootUrl . '/' : '/') . ltrim($src, '/');
    };

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $headerKey = "art30_{$pad}_headerPrimary";
    $headerObj = $readObject($headerKey, [
        'text' => 'Explora una colección de ideas con valor propio',
    ]);

    $introFallbacks = [
        'a' => 'Matrix ipsum dolor sit amet. Cada propuesta abre una perspectiva distinta y permite recorrer el conjunto de forma visual e intuitiva.',
        'b' => 'Descubre los detalles de cada opción y las ventajas que acompañan a toda la experiencia.',
    ];
    $introHtml = '';

    foreach ($introFallbacks as $letter => $fallbackText) {
        $introKey = "art30_{$pad}_intro_p_{$letter}";
        $introObj = $readObject($introKey, ['text' => $fallbackText]);
        $introText = (string) ($introObj->text ?? '');

        if (trim($introText) === '') {
            continue;
        }

        $introHtml .= '<p data-lang="' . $escapeAttr($introKey) . '">'
            . $introText
            . '</p>';
    }

    $cardFallbacks = [
        [
            'title' => 'Nueva perspectiva',
            'text'  => 'Una propuesta visual que convierte una idea compleja en una experiencia directa.',
            'image' => 'assets/img/dummy/dummy01.avif',
        ],
        [
            'title' => 'Decisión consciente',
            'text'  => 'Contenido pensado para comparar alternativas y avanzar con mayor claridad.',
            'image' => 'assets/img/dummy/dummy03.avif',
        ],
        [
            'title' => 'Visión conectada',
            'text'  => 'Una lectura global que relaciona contexto, detalle y propósito en un mismo espacio.',
            'image' => 'assets/img/dummy/dummy04.avif',
        ],
        [
            'title' => 'Camino compartido',
            'text'  => 'Una opción flexible que facilita el siguiente paso y acompaña todo el recorrido.',
            'image' => 'assets/img/dummy/dummy02.avif',
        ],
    ];

    $itemsHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter   = $letters[$j];
        $fallback = $cardFallbacks[$j % count($cardFallbacks)];

        $headerSecondaryKey = "art30_{$pad}_headerSecondary_{$letter}";
        $paragraphKey       = "art30_{$pad}_{$letter}_p";
        $imageKey           = "art30_{$pad}_{$letter}_img";
        $linkKey            = "art30_{$pad}_{$letter}_link";

        $headerSecondaryObj = $readObject($headerSecondaryKey, ['text' => $fallback['title']]);
        $paragraphObj       = $readObject($paragraphKey, ['text' => $fallback['text']]);
        $imageObj           = $readObject($imageKey, [
            'src'   => $fallback['image'],
            'alt'   => 'Imagen de referencia para ' . strtolower($fallback['title']),
            'title' => $fallback['title'],
        ]);
        $linkObj = $readObject($linkKey, [
            'href'  => '#',
            'title' => 'Descubrir ' . strtolower($fallback['title']),
        ]);

        $imageSrc = $assetUrl((string) ($imageObj->src ?? ''));
        $imageHtml = $imageSrc === ''
            ? ''
            : '<img data-lang="' . $escapeAttr($imageKey)
                . '" src="' . $escapeAttr($imageSrc)
                . '" alt="' . $escapeAttr($imageObj->alt ?? '')
                . '" title="' . $escapeAttr($imageObj->title ?? '')
                . '" width="700" height="700" loading="lazy" decoding="async">';

        $cardBody = $imageHtml
            . '<div class="art30-cardOverlay">'
                . '<h' . $itemLevel . ' data-lang="' . $escapeAttr($headerSecondaryKey) . '">'
                    . ($headerSecondaryObj->text ?? '')
                . '</h' . $itemLevel . '>'
                . '<p data-lang="' . $escapeAttr($paragraphKey) . '">'
                    . ($paragraphObj->text ?? '')
                . '</p>'
            . '</div>';

        $linkHref = trim((string) ($linkObj->href ?? ''));
        if ($linkHref !== '') {
            $itemsHtml .= '<div class="art30-card art30-card--interactive"'
                . $inlineGroupAttribute . '>'
                . '<a class="art30-cardLink" data-lang="' . $escapeAttr($linkKey)
                . '" href="' . $escapeAttr(resolve_localized_href(
                    $linkHref,
                    ['lang' => $currentLang]
                ))
                . '" title="' . $escapeAttr($linkObj->title ?? '')
                . '">' . $cardBody . '</a>'
                . '</div>';
        } else {
            $itemsHtml .= '<div class="art30-card"' . $inlineGroupAttribute . '>'
                . $cardBody
                . '</div>';
        }
    }

    $benefitFallbacks = [
        ['title' => 'Orientación clara',       'image' => 'assets/img/system/compass-outline.svg'],
        ['title' => 'Proceso seguro',          'image' => 'assets/img/system/shield-checkmark-outline.svg'],
        ['title' => 'Atención cercana',        'image' => 'assets/img/system/people.svg'],
        ['title' => 'Respuesta ágil',          'image' => 'assets/img/system/time.svg'],
        ['title' => 'Resultados medibles',     'image' => 'assets/img/system/stats-chart-outline.svg'],
        ['title' => 'Experiencia contrastada', 'image' => 'assets/img/system/ribbon-outline.svg'],
    ];

    $benefitsHtml = '';
    for ($j = 0; $j < $benefitsCount; $j++) {
        $letter   = $letters[$j];
        $fallback = $benefitFallbacks[$j % count($benefitFallbacks)];

        $benefitHeaderKey = "art30_{$pad}_benefit_{$letter}_headerSecondary";
        $benefitImageKey  = "art30_{$pad}_benefit_{$letter}_img";

        $benefitHeaderObj = $readObject($benefitHeaderKey, ['text' => $fallback['title']]);
        $benefitImageObj  = $readObject($benefitImageKey, [
            'src'   => $fallback['image'],
            'alt'   => '',
            'title' => '',
        ]);

        $benefitImageSrc = $assetUrl((string) ($benefitImageObj->src ?? ''));
        $benefitImageHtml = $benefitImageSrc === ''
            ? ''
            : '<img data-lang="' . $escapeAttr($benefitImageKey)
                . '" src="' . $escapeAttr($benefitImageSrc)
                . '" alt="' . $escapeAttr($benefitImageObj->alt ?? '')
                . '" title="' . $escapeAttr($benefitImageObj->title ?? '')
                . '" width="48" height="48" loading="lazy" decoding="async">';

        $benefitsHtml .= '<div class="art30-benefit"' . $inlineGroupAttribute . '>'
            . $benefitImageHtml
            . '<h' . $itemLevel . ' data-lang="' . $escapeAttr($benefitHeaderKey) . '">'
                . ($benefitHeaderObj->text ?? '')
            . '</h' . $itemLevel . '>'
            . '</div>';
    }

    $vars = [
        '{classVar}'                   => "art30_{$pad}_classVar",
        '{header-primary}'             => '<h' . $baseLevel . ' data-lang="' . $escapeAttr($headerKey)
            . '">' . ($headerObj->text ?? '') . '</h' . $baseLevel . '>',
        '{intro}'                      => $introHtml,
        '{items}'                      => $itemsHtml,
        '{benefits}'                   => $benefitsHtml,
        '{benefits-editor-attributes}' => $inlineGroupAttribute,
    ];

    return render(
        'App/templates/_art30.html',
        array_replace($vars, $params)
    );
}
?>
