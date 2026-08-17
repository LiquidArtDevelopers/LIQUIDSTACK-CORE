<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * moduleBlogSearch01 copy ranges:
 * - labels: 2-48 characters each.
 * - placeholder: 2-80 characters.
 *
 * Compact GET search/order form. It only projects presentation data and keeps
 * the active category state so it can share one catalog with another filter.
 */
function controller_moduleBlogSearch01(
    int $i = 0,
    array $params = []
): string {
    $pad = sprintf('%02d', max(0, $i));
    $fallbackId = 'moduleBlogSearch01-' . $pad;
    $id = trim((string) ($params['id_prefix'] ?? $fallbackId));
    if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $id) !== 1) {
        $id = $fallbackId;
    }

    $action = trim((string) ($params['action'] ?? '/'));
    if (
        !str_starts_with($action, '/')
        || str_starts_with($action, '//')
        || str_contains($action, '\\')
        || str_contains($action, '?')
        || str_contains($action, '#')
        || preg_match('/[\x00-\x1F\x7F]/', $action) === 1
    ) {
        $action = '/';
    }

    $targetId = trim((string) ($params['target_id'] ?? 'blog-results'));
    if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $targetId) !== 1) {
        $targetId = 'blog-results';
    }

    $query = trim((string) ($params['query'] ?? ''));
    $normalizedQuery = preg_replace('/\s+/u', ' ', $query);
    $query = is_string($normalizedQuery) ? $normalizedQuery : '';
    $query = function_exists('mb_substr')
        ? mb_substr($query, 0, 120, 'UTF-8')
        : substr($query, 0, 120);

    $orders = ['newest', 'oldest', 'updated'];
    $order = (string) ($params['order'] ?? 'newest');
    if (!in_array($order, $orders, true)) {
        $order = 'newest';
    }

    $selectedCategories = [];
    foreach ((array) ($params['selected_categories'] ?? []) as $slug) {
        if (
            !is_string($slug)
            || strlen($slug) > 190
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
        ) {
            continue;
        }
        $selectedCategories[$slug] = $slug;
        if (count($selectedCategories) >= 10) {
            break;
        }
    }
    $selectedCategories = array_values($selectedCategories);
    $categoryMode = ($params['category_mode'] ?? 'any') === 'all'
        ? 'all'
        : 'any';

    $defaultLabels = [
        'search' => 'Buscar noticias',
        'placeholder' => 'Título o palabras del artículo',
        'minimum' => 'Escribe al menos 2 caracteres.',
        'order' => 'Ordenar por',
        'newest' => 'Más recientes',
        'oldest' => 'Más antiguos',
        'updated' => 'Actualizados recientemente',
        'submit' => 'Buscar',
        'clear' => 'Limpiar búsqueda',
        'status' => 'Resultados actualizados',
        'error' => 'No se pudo actualizar. Inténtalo de nuevo.',
    ];
    $labels = array_replace(
        $defaultLabels,
        is_array($params['labels'] ?? null) ? $params['labels'] : []
    );
    foreach ($labels as $key => $label) {
        $normalized = trim((string) $label);
        $labels[$key] = $normalized !== ''
            ? $normalized
            : $defaultLabels[$key];
    }

    $preservedFields = '';
    foreach ($selectedCategories as $slug) {
        $preservedFields .= '<input type="hidden" name="category[]" value="'
            . liquidstack_blog_resource_escape($slug) . '">';
    }
    if ($selectedCategories !== []) {
        $preservedFields .= '<input type="hidden" name="category_mode" value="'
            . liquidstack_blog_resource_escape($categoryMode) . '">';
    }

    $clearQuery = [];
    if ($selectedCategories !== []) {
        $clearQuery['category'] = $selectedCategories;
        $clearQuery['category_mode'] = $categoryMode;
    }
    if ($order !== 'newest') {
        $clearQuery['order'] = $order;
    }
    $clearUrl = $action;
    if ($clearQuery !== []) {
        $clearUrl .= '?' . http_build_query(
            $clearQuery,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    return render('App/templates/_moduleBlogSearch01.html', [
        '{form-id}' => liquidstack_blog_resource_escape($id),
        '{action}' => liquidstack_blog_resource_escape($action),
        '{target-id}' => liquidstack_blog_resource_escape($targetId),
        '{target-selector}' => liquidstack_blog_resource_escape(
            '#' . $targetId
        ),
        '{search-id}' => liquidstack_blog_resource_escape($id . '-search'),
        '{search-label}' => liquidstack_blog_resource_escape($labels['search']),
        '{search-placeholder}' => liquidstack_blog_resource_escape(
            $labels['placeholder']
        ),
        '{minimum-message}' => liquidstack_blog_resource_escape(
            $labels['minimum']
        ),
        '{query}' => liquidstack_blog_resource_escape($query),
        '{order-id}' => liquidstack_blog_resource_escape($id . '-order'),
        '{order-label}' => liquidstack_blog_resource_escape($labels['order']),
        '{order-newest}' => liquidstack_blog_resource_escape($labels['newest']),
        '{order-oldest}' => liquidstack_blog_resource_escape($labels['oldest']),
        '{order-updated}' => liquidstack_blog_resource_escape($labels['updated']),
        '{order-newest-selected}' => $order === 'newest' ? ' selected' : '',
        '{order-oldest-selected}' => $order === 'oldest' ? ' selected' : '',
        '{order-updated-selected}' => $order === 'updated' ? ' selected' : '',
        '{preserved-fields}' => $preservedFields,
        '{submit-label}' => liquidstack_blog_resource_escape($labels['submit']),
        '{clear-url}' => liquidstack_blog_resource_escape($clearUrl),
        '{clear-label}' => liquidstack_blog_resource_escape($labels['clear']),
        '{clear-hidden}' => $query === '' ? ' hidden' : '',
        '{status-label}' => liquidstack_blog_resource_escape($labels['status']),
        '{error-message}' => liquidstack_blog_resource_escape($labels['error']),
    ]);
}
