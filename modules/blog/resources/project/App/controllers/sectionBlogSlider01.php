<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * sectionBlogSlider01: carrusel editorial GSAP con fallback de scroll nativo.
 * Encabezado principal: 4-8 palabras.
 * Títulos: 5-12 palabras. Extractos: 24-45 palabras.
 * Etiquetas de control: 1-8 palabras accionables.
 *
 * Cada entrada puede aportar `thumbnail` o `media`; si ambas son seguras se
 * prioriza la miniatura para no descargar la imagen editorial de mayor tamaño.
 */
function controller_sectionBlogSlider01(
    int $i = 0,
    array $params = []
): string {
    $resourceParams = $params;
    $resourceParams['stable_item_ids'] = true;
    $context = liquidstack_blog_resource_context(
        'sectionBlogSlider01',
        $i,
        $resourceParams,
        2,
        20
    );
    $seenKeys = [];
    $items = '';
    foreach ($context['items'] as $item) {
        $key = (string) ($item['key'] ?? '');
        if ($key === '' || isset($seenKeys[$key])) {
            continue;
        }
        $seenKeys[$key] = true;
        $items .= liquidstack_blog_resource_card($context, $item);
    }

    $label = static function (
        array $source,
        string $key,
        string $fallback
    ): string {
        $value = trim((string) ($source[$key] ?? ''));

        return $value === '' ? $fallback : $value;
    };
    $booleanOption = static function (mixed $value, bool $default): bool {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    };
    $boundedFloat = static function (
        mixed $value,
        float $default,
        float $minimum,
        float $maximum
    ): float {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return $default;
        }
        $number = filter_var($value, FILTER_VALIDATE_FLOAT);

        return $number === false
            ? $default
            : max($minimum, min($maximum, (float) $number));
    };
    $formatFloat = static fn (float $value): string => rtrim(
        rtrim(number_format($value, 2, '.', ''), '0'),
        '.'
    );

    $previousLabel = $label(
        $params,
        'previous_label',
        'Ver entradas anteriores'
    );
    $nextLabel = $label(
        $params,
        'next_label',
        'Ver entradas siguientes'
    );
    $pauseLabel = $label(
        $params,
        'pause_label',
        'Pausar reproducción automática'
    );
    $resumeLabel = $label(
        $params,
        'resume_label',
        'Reanudar reproducción automática'
    );
    $autoplay = $booleanOption($params['autoplay'] ?? null, true);
    $autoplayDelay = $boundedFloat(
        $params['autoplay_delay'] ?? null,
        6.0,
        2.0,
        60.0
    );
    $transitionDuration = $boundedFloat(
        $params['transition_duration'] ?? null,
        1.0,
        0.1,
        5.0
    );
    $itemsCount = count($seenKeys);
    $escape = 'liquidstack_blog_resource_escape';

    return render('App/templates/_sectionBlogSlider01.html', [
        '{section-id}' => $escape($context['id']),
        '{heading-id}' => $escape($context['heading_id']),
        '{viewport-id}' => $escape($context['id'] . '-viewport'),
        '{classVar}' => $escape($context['class_var']),
        '{items-count}' => (string) $itemsCount,
        '{viewport-tabindex}' => $itemsCount > 1 ? ' tabindex="0"' : '',
        '{header-primary}' => liquidstack_blog_resource_heading($context),
        '{previous-label}' => $escape($previousLabel),
        '{next-label}' => $escape($nextLabel),
        '{pause-label}' => $escape($pauseLabel),
        '{resume-label}' => $escape($resumeLabel),
        '{autoplay}' => $autoplay ? 'true' : 'false',
        '{autoplay-delay}' => $formatFloat($autoplayDelay),
        '{transition-duration}' => $formatFloat($transitionDuration),
        '{items}' => $items,
    ]);
}
