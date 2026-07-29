<?php
/**
 * Directrices de copy para art27:
 * - Encabezado principal: 5-10 palabras que introduzcan el conjunto.
 * - Introducción: 20-36 palabras para contextualizar las fichas.
 * - Encabezado por ficha: 2-6 palabras centradas en un servicio o beneficio.
 * - Descripción por ficha: 14-28 palabras con una explicación concreta.
 * - Alt/title del icono: 3-7 palabras cuando la imagen aporte significado.
 * - CTA opcional por ficha: 2-5 palabras.
 */
function controller_art27(int $i = 0, array $params = []): string
{
    $pad     = sprintf('%02d', $i);
    $letters = range('a', 'z');

    $itemsCount = max(0, (int) ($params['items'] ?? 9));
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

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];

    $iconFallbacks = [
        'assets/img/system/compass-outline.svg',
        'assets/img/system/shield-checkmark-outline.svg',
        'assets/img/system/people.svg',
        'assets/img/system/school-outline.svg',
        'assets/img/system/speedometer-outline.svg',
        'assets/img/system/settings-outline.svg',
        'assets/img/system/cube-outline.svg',
        'assets/img/system/code-slash-outline.svg',
        'assets/img/system/stats-chart-outline.svg',
    ];
    $textFallback = 'Matrix ipsum dolor sit amet. Una señal del sistema revela '
        . 'el siguiente movimiento dentro del código.';

    $itemsMarkup = '';

    for ($j = 0; $j < $itemsCount; $j++) {
        $letter = $letters[$j];
        $number = sprintf('%02d', $j + 1);

        $headerKey = "art27_{$pad}_headerSecondary_{$letter}";
        $imgKey    = "art27_{$pad}_{$letter}_img";
        $pKey      = "art27_{$pad}_{$letter}_p";

        $imgPath = $getProperty(
            $imgKey,
            'src',
            $iconFallbacks[$j % count($iconFallbacks)]
        );
        $imgSrc  = $assetUrl($imgPath);
        $imgAlt  = $getProperty($imgKey, 'alt');
        $imgTitle = $getProperty($imgKey, 'title');
        $headerText = $getProperty(
            $headerKey,
            'text',
            "Matrix ipsum {$number}"
        );
        $paragraphText = $getProperty($pKey, 'text', $textFallback);

        $buttonKey    = '{' . $letter . '-button-primary}';
        $buttonMarkup = $params[$buttonKey] ?? '';
        $buttonMarkup = is_string($buttonMarkup) ? $buttonMarkup : '';
        unset($params[$buttonKey]);

        $itemsMarkup .=
            '<div class="art27-card">' .
                '<img data-lang="' . $escapeAttr($imgKey)
                    . '" src="' . $escapeAttr($imgSrc)
                    . '" alt="' . $escapeAttr($imgAlt)
                    . '" title="' . $escapeAttr($imgTitle)
                    . '" width="50" height="50" loading="lazy" decoding="async">' .
                '<h' . $itemLevel . ' class="art27-card-title" data-lang="'
                    . $escapeAttr($headerKey) . '">' . $headerText
                    . '</h' . $itemLevel . '>' .
                '<p data-lang="' . $escapeAttr($pKey) . '">'
                    . $paragraphText . '</p>' .
                $buttonMarkup .
            '</div>';
    }

    $headerKey = "art27_{$pad}_headerPrimary";
    $introKey  = "art27_{$pad}_intro_p";

    $vars = [
        '{classVar}'       => "art27_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel
            . ' class="art27-title" data-lang="' . $escapeAttr($headerKey)
            . '">' . $getProperty(
                $headerKey,
                'text',
                'art27 · Article grid de iconos'
            ) . '</h' . $baseLevel . '>',
        '{intro-p-dl}'     => $introKey,
        '{intro-p-text}'   => $getProperty(
            $introKey,
            'text',
            'Matrix ipsum dolor sit amet. Las señales permiten reconocer '
                . 'cada nueva capa del sistema.'
        ),
        '{items}'          => $itemsMarkup,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art27.html', $vars);
}
?>
