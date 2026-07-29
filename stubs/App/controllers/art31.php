<?php
/**
 * Directrices de copy para art31:
 * - Encabezado principal: 45-75 caracteres que introduzcan la secuencia editorial.
 * - Encabezado de fila: 4-9 palabras con un beneficio o enfoque concreto.
 * - Párrafos: 24-48 palabras cada uno; cada fila requiere exactamente dos.
 * - CTA inyectable por fila: 2-5 palabras; title de 4-8 palabras.
 * - Alt/title de imagen: 5-10 palabras que describan escena y propósito.
 */
function controller_art31(int $i = 0, array $params = []): string
{
    global $lang;

    $pad     = sprintf('%02d', $i);
    $letters = range('a', 'z');

    $itemsCount = isset($params['items']) ? (int) $params['items'] : 4;
    $itemsCount = max(0, min($itemsCount, count($letters)));
    unset($params['items']);

    $currentLang = (string) ($lang ?? $GLOBALS['lang'] ?? $_ENV['LANG_DEFAULT'] ?? 'es');
    $currentLang = preg_match('/^[A-Za-z0-9_-]+$/', $currentLang) === 1
        ? $currentLang
        : 'es';

    $templateFile = __DIR__ . '/../config/languages/templates/' . $currentLang . '.json';
    $templateJson = is_readable($templateFile) ? file_get_contents($templateFile) : '{}';
    $templateLang = json_decode($templateJson);
    $templateLang = is_object($templateLang) ? $templateLang : new stdClass();

    $readObject = static function (string $key, array $fallback) use ($templateLang): object {
        $templateKey = preg_replace('/^art31_\d{2}_/', 'art31_00_', $key);
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

    $headerKey = "art31_{$pad}_headerPrimary";
    $headerObj = $readObject($headerKey, [
        'text' => 'Un recorrido visual para comprender cada paso',
    ]);

    $rowFallbacks = [
        [
            'title' => 'Comprender el punto de partida',
            'p_a'   => 'Matrix ipsum dolor sit amet, consectetur adipisicing elit. El primer bloque reúne contexto, prioridades y expectativas para convertir una necesidad amplia en un punto de partida comprensible.',
            'p_b'   => 'Esta visión inicial permite ordenar las decisiones y reconocer desde el principio qué elementos requieren una atención especial.',
            'image' => 'assets/img/dummy/dummy01.avif',
        ],
        [
            'title' => 'Definir una dirección compartida',
            'p_a'   => 'Morpheus plantea alternativas y Neo recorre cada posibilidad con una referencia clara. La información se organiza para comparar escenarios sin perder de vista el objetivo principal.',
            'p_b'   => 'El resultado es una hoja de ruta proporcionada, flexible y preparada para incorporar nueva información cuando sea necesario.',
            'image' => 'assets/img/dummy/dummy02.avif',
        ],
        [
            'title' => 'Convertir la visión en acciones',
            'p_a'   => 'Trinity conecta estrategia y ejecución mediante pasos concretos, responsables definidos y puntos de revisión que mantienen el trabajo alineado con las decisiones adoptadas.',
            'p_b'   => 'Cada avance queda explicado de forma directa para que el conjunto siga siendo fácil de evaluar y ajustar.',
            'image' => 'assets/img/dummy/dummy03.avif',
        ],
        [
            'title' => 'Revisar resultados y continuidad',
            'p_a'   => 'El último bloque contrasta el recorrido realizado con los objetivos iniciales, identifica aprendizajes y reúne las recomendaciones que deben acompañar la siguiente etapa.',
            'p_b'   => 'Así queda una base útil para mantener los resultados, abrir nuevas líneas o actualizar las decisiones cuando cambie el contexto.',
            'image' => 'assets/img/dummy/dummy04.avif',
        ],
    ];

    $itemsHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter   = $letters[$j];
        $fallback = $rowFallbacks[$j % count($rowFallbacks)];

        $rowHeaderKey = "art31_{$pad}_headerSecondary_{$letter}";
        $paragraphAKey = "art31_{$pad}_{$letter}_p01";
        $paragraphBKey = "art31_{$pad}_{$letter}_p02";
        $ctaKey        = "art31_{$pad}_{$letter}_cta";
        $imageKey      = "art31_{$pad}_{$letter}_img";

        $rowHeaderObj = $readObject($rowHeaderKey, ['text' => $fallback['title']]);
        $paragraphAObj = $readObject($paragraphAKey, ['text' => $fallback['p_a']]);
        $paragraphBObj = $readObject($paragraphBKey, ['text' => $fallback['p_b']]);
        $imageObj = $readObject($imageKey, [
            'src'   => $fallback['image'],
            'alt'   => 'Imagen de referencia para ' . strtolower($fallback['title']),
            'title' => $fallback['title'],
        ]);

        $buttonKey = '{' . $letter . '-button-primary}';
        if (array_key_exists($buttonKey, $params)) {
            $buttonMarkup = is_string($params[$buttonKey])
                ? $params[$buttonKey]
                : '';
            unset($params[$buttonKey]);
        } else {
            $ctaObj = $readObject($ctaKey, [
                'text'  => 'Conocer más',
                'href'  => '#',
                'title' => 'Conocer más sobre ' . strtolower($fallback['title']),
            ]);
            $ctaText = trim((string) ($ctaObj->text ?? ''));
            $ctaHref = trim((string) ($ctaObj->href ?? ''));
            $buttonMarkup = ($ctaText !== '' && $ctaHref !== '')
                ? '<a class="boton" data-lang="' . $escapeAttr($ctaKey)
                    . '" href="' . $escapeAttr(resolve_localized_href($ctaHref, ['lang' => $currentLang]))
                    . '" title="' . $escapeAttr($ctaObj->title ?? '')
                    . '">' . $ctaText . '</a>'
                : '';
        }

        $ctaHtml = trim($buttonMarkup) !== ''
            ? '<div class="art31-cta">' . $buttonMarkup . '</div>'
            : '';

        $imageSrc = $assetUrl((string) ($imageObj->src ?? ''));
        $imageHtml = $imageSrc === ''
            ? ''
            : '<img data-lang="' . $escapeAttr($imageKey)
                . '" src="' . $escapeAttr($imageSrc)
                . '" alt="' . $escapeAttr($imageObj->alt ?? '')
                . '" title="' . $escapeAttr($imageObj->title ?? '')
                . '" width="900" height="675" loading="lazy" decoding="async">';

        $itemsHtml .= '<div class="art31-row">'
            . '<div class="art31-rowCopy">'
                . '<h' . $itemLevel . ' data-lang="' . $escapeAttr($rowHeaderKey) . '">'
                    . ($rowHeaderObj->text ?? '')
                . '</h' . $itemLevel . '>'
                . '<p data-lang="' . $escapeAttr($paragraphAKey) . '">'
                    . ($paragraphAObj->text ?? '')
                . '</p>'
                . '<p data-lang="' . $escapeAttr($paragraphBKey) . '">'
                    . ($paragraphBObj->text ?? '')
                . '</p>'
                . $ctaHtml
            . '</div>'
            . '<div class="art31-rowMedia">'
                . $imageHtml
            . '</div>'
            . '</div>';
    }

    $vars = [
        '{classVar}'       => "art31_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="' . $escapeAttr($headerKey)
            . '">' . ($headerObj->text ?? '') . '</h' . $baseLevel . '>',
        '{items}'          => $itemsHtml,
    ];

    return render(
        'App/templates/_art31.html',
        array_replace($vars, $params)
    );
}
?>
