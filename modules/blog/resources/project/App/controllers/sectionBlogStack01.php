<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * Directrices de copy para sectionBlogStack01:
 * - Encabezado principal: 4-8 palabras; incluir el identificador en showroom.
 * - Título de entrada: 5-12 palabras.
 * - Extracto: 24-45 palabras con una idea completa y sin HTML.
 * - Estados de carga: 2-8 palabras, concretos y orientados a recuperación.
 */
function controller_sectionBlogStack01(
    int $i = 0,
    array $params = []
): string {
    $params['stable_item_ids'] = true;
    $context = liquidstack_blog_resource_context(
        'sectionBlogStack01',
        $i,
        $params,
        2,
        50
    );
    $escape = 'liquidstack_blog_resource_escape';
    $loadMode = ($params['load_mode'] ?? 'manual') === 'near-end'
        ? 'near-end'
        : 'manual';

    $nextUrl = liquidstack_blog_resource_safe_url(
        $params['next_url'] ?? ''
    );
    if (
        !str_starts_with($nextUrl, '/')
        || str_starts_with($nextUrl, '//')
    ) {
        $nextUrl = '';
    }

    $copy = static function (
        array $source,
        string $key,
        string $fallback
    ): string {
        $value = trim((string) ($source[$key] ?? ''));

        return $value === '' ? $fallback : $value;
    };
    $loadMoreLabel = $copy(
        $params,
        'load_more_label',
        'Cargar más entradas'
    );
    $loadingMessage = $copy(
        $params,
        'loading_message',
        'Cargando entradas...'
    );
    $endMessage = $copy(
        $params,
        'end_message',
        'No hay más entradas.'
    );
    $errorMessage = $copy(
        $params,
        'error_message',
        'No se pudieron cargar más entradas.'
    );
    $emptyMessage = $copy(
        $params,
        'empty_message',
        'No hay entradas disponibles.'
    );

    $items = '';
    $renderedCount = 0;
    $seenKeys = [];
    foreach ($context['items'] as $item) {
        $key = (string) ($item['key'] ?? '');
        if ($key === '' || isset($seenKeys[$key])) {
            continue;
        }
        $seenKeys[$key] = true;
        $items .= liquidstack_blog_resource_card($context, $item);
        $renderedCount++;
    }

    $nextControl = $nextUrl === ''
        ? ''
        : '<a class="sectionBlogStack01-next" href="'
            . $escape($nextUrl)
            . '" data-blog-collection-next>'
            . $escape($loadMoreLabel)
            . '</a>';
    $statusState = $renderedCount === 0
        ? 'empty'
        : ($nextUrl === '' ? 'end' : 'ready');
    $statusText = $renderedCount === 0
        ? $emptyMessage
        : ($nextUrl === '' ? $endMessage : '');

    return render('App/templates/_sectionBlogStack01.html', [
        '{section-id}' => $escape($context['id']),
        '{heading-id}' => $escape($context['heading_id']),
        '{classVar}' => $escape($context['class_var']),
        '{load-mode}' => $loadMode,
        '{items-count}' => (string) $renderedCount,
        '{header-primary}' => liquidstack_blog_resource_heading($context),
        '{items}' => $items,
        '{next-control}' => $nextControl,
        '{status-state}' => $statusState,
        '{status-text}' => $escape($statusText),
        '{status-visibility}' => $statusText === '' ? 'hidden' : '',
        '{loading-message}' => $escape($loadingMessage),
        '{end-message}' => $escape($endMessage),
        '{error-message}' => $escape($errorMessage),
    ]);
}
