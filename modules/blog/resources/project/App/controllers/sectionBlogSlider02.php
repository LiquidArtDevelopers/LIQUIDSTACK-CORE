<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * sectionBlogSlider02: carrusel Blog progresivo con mejora GSAP opcional.
 * Encabezado principal: 4-8 palabras.
 * Títulos de entrada: 5-12 palabras.
 * Extractos: 24-45 palabras, sin HTML.
 * CTA, controles y estados: 1-8 palabras accionables.
 *
 * El sniper entrega exclusivamente datos públicos mediante `items_data` y
 * puede habilitar carga sucesiva con `next_url` y `load_mode` (`manual` o
 * `near-end`). El enlace siguiente permanece navegable sin JavaScript.
 * Si la proyección incluye una `thumbnail` segura se prefiere a `media` para
 * no descargar la portada grande. El helper revalida `srcset` y `sizes` sin
 * inventar variantes ni consultar infraestructura desde el recurso.
 */
function controller_sectionBlogSlider02(
    int $i = 0,
    array $params = []
): string {
    $resourceParams = $params;
    $resourceParams['stable_item_ids'] = true;
    $context = liquidstack_blog_resource_context(
        'sectionBlogSlider02',
        $i,
        $resourceParams,
        2,
        50
    );
    $escape = 'liquidstack_blog_resource_escape';
    $uniqueItems = [];
    $seenKeys = [];
    foreach ($context['items'] as $item) {
        $key = (string) ($item['key'] ?? '');
        if ($key === '' || isset($seenKeys[$key])) {
            continue;
        }
        $seenKeys[$key] = true;
        $uniqueItems[] = $item;
    }
    $context['items'] = $uniqueItems;
    $booleanOption = static function ($value, bool $default): bool {
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
        $value,
        float $default,
        float $minimum,
        float $maximum
    ): float {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return $default;
        }
        $number = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($number === false) {
            return $default;
        }

        return max($minimum, min($maximum, (float) $number));
    };
    $formatFloat = static function (float $value): string {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    };
    $label = static function (
        array $source,
        string $key,
        string $fallback
    ): string {
        $value = trim((string) ($source[$key] ?? ''));

        return $value === '' ? $fallback : $value;
    };

    $autoplay = $booleanOption($params['autoplay'] ?? null, true);
    $autoplayDelay = $boundedFloat(
        $params['autoplay_delay'] ?? null,
        6.0,
        2.0,
        60.0
    );
    $transitionDuration = $boundedFloat(
        $params['transition_duration'] ?? null,
        2.0,
        0.1,
        5.0
    );
    $loadMode = trim((string) ($params['load_mode'] ?? 'manual'));
    if (!in_array($loadMode, ['manual', 'near-end'], true)) {
        $loadMode = 'manual';
    }

    $nextUrl = trim((string) ($params['next_url'] ?? ''));
    if (
        $nextUrl !== ''
        && (
            !str_starts_with($nextUrl, '/')
            || str_starts_with($nextUrl, '//')
            || str_contains($nextUrl, '\\')
            || preg_match('/[\x00-\x20\x7F]/', $nextUrl) === 1
        )
    ) {
        $nextUrl = '';
    }

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
    $loadMoreLabel = $label(
        $params,
        'load_more_label',
        'Cargar más entradas'
    );
    $loadingLabel = $label(
        $params,
        'loading_message',
        $label($params, 'loading_label', 'Cargando entradas…')
    );
    $errorLabel = $label(
        $params,
        'error_message',
        $label(
            $params,
            'error_label',
            'No se pudieron cargar las entradas.'
        )
    );
    $retryLabel = $label(
        $params,
        'retry_message',
        $label($params, 'retry_label', 'Reintentar')
    );
    $endLabel = $label(
        $params,
        'end_message',
        $label($params, 'end_label', 'No hay más entradas.')
    );
    $emptyLabel = $label(
        $params,
        'empty_message',
        'No hay entradas disponibles.'
    );
    $ctaLabel = $label($params, 'cta_label', 'Leer artículo');
    $items = '';
    foreach ($context['items'] as $item) {
        $items .= liquidstack_blog_resource_card(
            $context,
            $item,
            '',
            $ctaLabel
        );
    }
    $itemsCount = count($context['items']);

    $nextLink = $nextUrl === ''
        ? ''
        : '<a class="sectionBlogSlider02-loadMore"'
            . ' data-blog-collection-next href="' . $escape($nextUrl) . '">'
            . $escape($loadMoreLabel) . '</a>';

    return render('App/templates/_sectionBlogSlider02.html', [
        '{section-id}' => $escape($context['id']),
        '{heading-id}' => $escape($context['heading_id']),
        '{viewport-id}' => $escape($context['id'] . '-viewport'),
        '{classVar}' => $escape($context['class_var']),
        '{items-count}' => (string) $itemsCount,
        '{collection-state}' => $itemsCount === 0 ? 'empty' : 'ready',
        '{viewport-tabindex}' => $itemsCount > 1 ? ' tabindex="0"' : '',
        '{header-primary}' => liquidstack_blog_resource_heading($context),
        '{previous-label}' => $escape($previousLabel),
        '{next-label}' => $escape($nextLabel),
        '{pause-label}' => $escape($pauseLabel),
        '{resume-label}' => $escape($resumeLabel),
        '{autoplay}' => $autoplay ? 'true' : 'false',
        '{autoplay-delay}' => $formatFloat($autoplayDelay),
        '{transition-duration}' => $formatFloat($transitionDuration),
        '{load-mode}' => $escape($loadMode),
        '{collection-next}' => $nextLink,
        '{loading-message}' => $escape($loadingLabel),
        '{error-message}' => $escape($errorLabel),
        '{retry-message}' => $escape($retryLabel),
        '{end-message}' => $escape($endLabel),
        '{empty-message}' => $escape($emptyLabel),
        '{status-hidden}' => $itemsCount === 0 ? '' : ' hidden',
        '{status-message}' => $itemsCount === 0 ? $escape($emptyLabel) : '',
        '{items}' => $items,
    ]);
}
