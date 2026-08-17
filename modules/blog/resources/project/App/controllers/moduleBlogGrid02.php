<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * Directrices de copy para moduleBlogGrid02:
 * - Título de entrada: 5-12 palabras.
 * - Extracto: 24-45 palabras con una idea completa y sin HTML.
 * - CTA: 2-4 palabras con una acción clara, por ejemplo «Leer más».
 * - Estados de carga: 2-8 palabras, concretos y orientados a recuperación.
 * - Si existe una `thumbnail` segura, se prefiere a la portada original.
 * - `pagination_mode=external` delega los controles sin anunciar un final falso.
 */
function controller_moduleBlogGrid02(
    int $i = 0,
    array $params = []
): string {
    $resourceParams = $params;
    unset(
        $resourceParams['{header-primary}'],
        $resourceParams['header_text'],
        $resourceParams['header_lang']
    );
    $resourceParams['stable_item_ids'] = true;
    $context = liquidstack_blog_resource_context(
        'moduleBlogGrid02',
        $i,
        $resourceParams,
        2,
        50
    );
    $escape = 'liquidstack_blog_resource_escape';
    $layout = ($params['layout'] ?? 'regular') === 'bento'
        ? 'bento'
        : 'regular';
    $loadMode = ($params['load_mode'] ?? 'manual') === 'near-end'
        ? 'near-end'
        : 'manual';
    $paginationMode = ($params['pagination_mode'] ?? 'internal') === 'external'
        ? 'external'
        : 'internal';

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
    $ctaLabel = $copy($params, 'cta_label', 'Leer más');

    $items = '';
    $renderedCount = 0;
    $seenKeys = [];
    foreach ($context['items'] as $item) {
        $key = (string) ($item['key'] ?? '');
        if ($key === '' || isset($seenKeys[$key])) {
            continue;
        }
        $seenKeys[$key] = true;
        $items .= liquidstack_blog_resource_card(
            $context,
            $item,
            '',
            $ctaLabel
        );
        $renderedCount++;
    }

    $nextControl = $nextUrl === ''
        ? ''
        : '<a class="moduleBlogGrid02-next" href="'
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
    $collectionUi = '';
    if ($paginationMode === 'internal' || $renderedCount === 0) {
        $collectionUi = '<div class="moduleBlogGrid02-pagination">'
            . $nextControl
            . '<p class="moduleBlogGrid02-status" role="status"'
            . ' aria-live="polite" data-blog-collection-status'
            . ' data-state="' . $escape($statusState) . '"'
            . ' data-loading-message="' . $escape($loadingMessage) . '"'
            . ' data-end-message="' . $escape($endMessage) . '"'
            . ' data-error-message="' . $escape($errorMessage) . '"'
            . ($statusText === '' ? ' hidden' : '') . '>'
            . $escape($statusText) . '</p></div>';
    }

    return render('App/templates/_moduleBlogGrid02.html', [
        '{module-id}' => $escape($context['id']),
        '{classVar}' => $escape($context['class_var']),
        '{layout}' => $layout,
        '{load-mode}' => $loadMode,
        '{items-count}' => (string) $renderedCount,
        '{items}' => $items,
        '{collection-ui}' => $collectionUi,
    ]);
}
