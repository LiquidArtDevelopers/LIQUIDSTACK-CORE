<?php
/**
 * Directrices de copy para artAccordion02:
 * - Encabezado principal: 45-75 caracteres que presente el tema de consultas.
 * - Intro: 18-36 palabras por párrafo; se recomiendan uno o dos.
 * - Títulos de ítem: 4-10 palabras, preferentemente como pregunta directa.
 * - Respuesta de ítem: 24-55 palabras con una explicación autosuficiente.
 * - Alt/title de imagen: 5-10 palabras que describan escena y contexto.
 */
function controller_artAccordion02(int $i = 0, array $params = []): string
{
    $pad     = sprintf('%02d', $i);
    $letters = range('a', 'z');

    $itemsCount = isset($params['items']) ? (int) $params['items'] : 6;
    $itemsCount = max(0, min($itemsCount, count($letters)));
    unset($params['items']);

    $currentLang = (string) ($GLOBALS['lang'] ?? $_ENV['LANG_DEFAULT'] ?? 'es');
    $currentLang = preg_match('/^[A-Za-z0-9_-]+$/', $currentLang) === 1
        ? $currentLang
        : 'es';

    $templateFile = __DIR__ . '/../config/languages/templates/' . $currentLang . '.json';
    $templateJson = is_readable($templateFile) ? file_get_contents($templateFile) : '{}';
    $templateLang = json_decode($templateJson);
    $templateLang = is_object($templateLang) ? $templateLang : new stdClass();

    $readObject = static function (string $key, array $fallback) use ($templateLang): object {
        $templateKey = preg_replace(
            '/^artAccordion02_\d{2}_/',
            'artAccordion02_00_',
            $key
        );
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

    $headerKey = "artAccordion02_{$pad}_headerPrimary";
    $headerObj = $readObject($headerKey, [
        'text' => 'Respuestas claras para avanzar con mayor confianza',
    ]);

    $introFallbacks = [
        'a' => 'Matrix ipsum dolor sit amet. Reunimos las preguntas más habituales para ofrecer una orientación directa antes de tomar la siguiente decisión.',
        'b' => 'Abre cada apartado para consultar sus detalles; puedes mantener visibles todas las respuestas que necesites.',
    ];
    $introHtml = '';

    foreach ($introFallbacks as $letter => $fallbackText) {
        $introKey = "artAccordion02_{$pad}_intro_p_{$letter}";
        $introObj = $readObject($introKey, ['text' => $fallbackText]);
        $introText = (string) ($introObj->text ?? '');

        if (trim($introText) === '') {
            continue;
        }

        $introHtml .= '<p data-lang="' . $escapeAttr($introKey) . '">'
            . $introText
            . '</p>';
    }

    $imageKey = "artAccordion02_{$pad}_img";
    $imageObj = $readObject($imageKey, [
        'src'   => 'assets/img/dummy/dummy02.avif',
        'alt'   => 'Escena de referencia para preguntas y respuestas',
        'title' => 'Consultas frecuentes y respuestas',
    ]);
    $imageSrc = $assetUrl((string) ($imageObj->src ?? ''));
    $imageHtml = $imageSrc === ''
        ? ''
        : '<img data-lang="' . $escapeAttr($imageKey)
            . '" src="' . $escapeAttr($imageSrc)
            . '" alt="' . $escapeAttr($imageObj->alt ?? '')
            . '" title="' . $escapeAttr($imageObj->title ?? '')
            . '" width="900" height="675" loading="lazy" decoding="async">';

    $itemFallbacks = [
        [
            'title' => '¿Cómo comienza el proceso de acompañamiento?',
            'text'  => 'El primer paso consiste en comprender el contexto, ordenar las prioridades y acordar un recorrido realista. A partir de ahí se definen las acciones, los responsables y los puntos de seguimiento.',
        ],
        [
            'title' => '¿Qué información conviene preparar antes?',
            'text'  => 'Resulta útil reunir los datos principales, las decisiones previas y cualquier condicionante relevante. No es necesario que todo esté cerrado: la revisión inicial también sirve para detectar información pendiente.',
        ],
        [
            'title' => '¿Se adapta la propuesta a cada caso?',
            'text'  => 'Sí. El alcance, el ritmo y los entregables se ajustan a las necesidades reales de cada situación, manteniendo una estructura clara para comparar alternativas y validar cada avance.',
        ],
        [
            'title' => '¿Cómo se comunican los avances?',
            'text'  => 'Cada fase incorpora puntos de control y conclusiones comprensibles. Así puedes conocer qué se ha completado, qué decisiones siguen abiertas y cuál es el próximo paso recomendado.',
        ],
        [
            'title' => '¿Es posible ampliar el alcance después?',
            'text'  => 'La estructura es modular y permite incorporar nuevas líneas de trabajo cuando cambian las necesidades. Cualquier ampliación se define de forma explícita antes de comenzar.',
        ],
        [
            'title' => '¿Qué ocurre al finalizar el recorrido?',
            'text'  => 'El cierre reúne los resultados, las decisiones adoptadas y las recomendaciones de continuidad. De este modo queda una referencia útil para mantener o revisar el trabajo realizado.',
        ],
    ];

    $itemsHtml = '';
    for ($j = 0; $j < $itemsCount; $j++) {
        $letter   = $letters[$j];
        $fallback = $itemFallbacks[$j % count($itemFallbacks)];

        $titleKey   = "artAccordion02_{$pad}_headerSecondary_{$letter}";
        $contentKey = "artAccordion02_{$pad}_{$letter}_p";
        $titleObj   = $readObject($titleKey, ['text' => $fallback['title']]);
        $contentObj = $readObject($contentKey, ['text' => $fallback['text']]);

        $buttonId = "artAccordion02-{$pad}-{$letter}-trigger";
        $panelId  = "artAccordion02-{$pad}-{$letter}-panel";
        $panelLandmarkAttributes = $itemsCount <= 6
            ? ' role="region" aria-labelledby="' . $escapeAttr($buttonId) . '"'
            : '';

        $itemsHtml .= '<div class="artAccordion02-item">'
            . '<h' . $itemLevel . ' class="artAccordion02-itemTitle">'
                . '<button class="artAccordion02-trigger" type="button"'
                    . ' id="' . $escapeAttr($buttonId) . '"'
                    . ' aria-expanded="true"'
                    . ' aria-controls="' . $escapeAttr($panelId) . '">'
                    . '<span data-lang="' . $escapeAttr($titleKey) . '">'
                        . ($titleObj->text ?? '')
                    . '</span>'
                    . '<span class="artAccordion02-indicator" aria-hidden="true"></span>'
                . '</button>'
            . '</h' . $itemLevel . '>'
            . '<div class="artAccordion02-panel"'
                . ' id="' . $escapeAttr($panelId) . '"'
                . $panelLandmarkAttributes
                . '>'
                . '<div class="artAccordion02-panelInner">'
                    . '<p data-lang="' . $escapeAttr($contentKey) . '">'
                        . ($contentObj->text ?? '')
                    . '</p>'
                . '</div>'
            . '</div>'
            . '</div>';
    }

    $vars = [
        '{classVar}'       => "artAccordion02_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="' . $escapeAttr($headerKey)
            . '">' . ($headerObj->text ?? '') . '</h' . $baseLevel . '>',
        '{intro}'          => $introHtml,
        '{image}'          => $imageHtml,
        '{items}'          => $itemsHtml,
    ];

    return render(
        'App/templates/_artAccordion02.html',
        array_replace($vars, $params)
    );
}
?>
