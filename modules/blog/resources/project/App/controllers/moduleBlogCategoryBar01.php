<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * moduleBlogCategoryBar01 copy ranges:
 * - labels: 2-56 characters each.
 * - category names: 1-120 characters supplied by the public feed.
 *
 * Accessible category GET bar. It preserves q/order and never queries the DB.
 */
function controller_moduleBlogCategoryBar01(
    int $i = 0,
    array $params = []
): string {
    $pad = sprintf('%02d', max(0, $i));
    $fallbackId = 'moduleBlogCategoryBar01-' . $pad;
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

    $order = (string) ($params['order'] ?? 'newest');
    if (!in_array($order, ['newest', 'oldest', 'updated'], true)) {
        $order = 'newest';
    }
    $mode = ($params['category_mode'] ?? 'any') === 'all' ? 'all' : 'any';

    $selected = [];
    foreach ((array) ($params['selected_categories'] ?? []) as $slug) {
        if (
            !is_string($slug)
            || strlen($slug) > 190
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
        ) {
            continue;
        }
        $selected[$slug] = true;
        if (count($selected) >= 10) {
            break;
        }
    }

    $catalog = array_slice(
        array_values((array) ($params['filters'] ?? [])),
        0,
        100
    );
    $normalizedFilters = [];
    foreach ($catalog as $filter) {
        $value = static function (string $field) use ($filter) {
            if (is_array($filter)) {
                return $filter[$field] ?? null;
            }
            if (is_object($filter) && isset($filter->{$field})) {
                return $filter->{$field};
            }

            return null;
        };
        $slug = trim((string) $value('slug'));
        $name = trim((string) $value('name'));
        if (
            $name === ''
            || strlen($slug) > 190
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
        ) {
            continue;
        }
        $normalizedFilters[$slug] ??= [
            'slug' => $slug,
            'name' => $name,
            'count' => max(0, (int) $value('count')),
        ];
    }

    $visibleFilters = [];
    foreach (array_keys($selected) as $slug) {
        if (isset($normalizedFilters[$slug])) {
            $visibleFilters[$slug] = $normalizedFilters[$slug];
        }
    }
    foreach ($normalizedFilters as $slug => $filter) {
        if (count($visibleFilters) >= 10) {
            break;
        }
        $visibleFilters[$slug] ??= $filter;
    }

    $filtersHtml = '';
    foreach ($visibleFilters as $filter) {
        $slug = $filter['slug'];
        $controlId = $id . '-category-' . $slug;
        $filtersHtml .= '<label for="'
            . liquidstack_blog_resource_escape($controlId) . '"><input id="'
            . liquidstack_blog_resource_escape($controlId)
            . '" type="checkbox" name="category[]" value="'
            . liquidstack_blog_resource_escape($slug) . '"'
            . (isset($selected[$slug]) ? ' checked' : '') . '><span>'
            . liquidstack_blog_resource_escape($filter['name'])
            . '</span><small>(' . $filter['count'] . ')</small></label>';
    }

    $defaultLabels = [
        'categories' => 'Filtrar por categorías',
        'mode' => 'Coincidencia',
        'any' => 'Cualquiera',
        'all' => 'Todas',
        'submit' => 'Aplicar categorías',
        'reset' => 'Quitar categorías',
        'empty' => 'No hay categorías disponibles.',
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
    if ($query !== '') {
        $preservedFields .= '<input type="hidden" name="q" value="'
            . liquidstack_blog_resource_escape($query) . '">';
    }
    $preservedFields .= '<input type="hidden" name="order" value="'
        . liquidstack_blog_resource_escape($order) . '">';

    $resetQuery = [];
    if ($query !== '') {
        $resetQuery['q'] = $query;
    }
    if ($order !== 'newest') {
        $resetQuery['order'] = $order;
    }
    $resetUrl = $action;
    if ($resetQuery !== []) {
        $resetUrl .= '?' . http_build_query(
            $resetQuery,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    return render('App/templates/_moduleBlogCategoryBar01.html', [
        '{form-id}' => liquidstack_blog_resource_escape($id),
        '{action}' => liquidstack_blog_resource_escape($action),
        '{target-id}' => liquidstack_blog_resource_escape($targetId),
        '{target-selector}' => liquidstack_blog_resource_escape(
            '#' . $targetId
        ),
        '{preserved-fields}' => $preservedFields,
        '{categories-hidden}' => $filtersHtml === '' ? ' hidden' : '',
        '{empty-hidden}' => $filtersHtml === '' ? '' : ' hidden',
        '{categories-label}' => liquidstack_blog_resource_escape(
            $labels['categories']
        ),
        '{filters}' => $filtersHtml,
        '{mode-id}' => liquidstack_blog_resource_escape($id . '-mode'),
        '{mode-label}' => liquidstack_blog_resource_escape($labels['mode']),
        '{mode-disabled}' => $filtersHtml === '' ? ' disabled' : '',
        '{mode-any}' => liquidstack_blog_resource_escape($labels['any']),
        '{mode-all}' => liquidstack_blog_resource_escape($labels['all']),
        '{mode-any-selected}' => $mode === 'any' ? ' selected' : '',
        '{mode-all-selected}' => $mode === 'all' ? ' selected' : '',
        '{submit-label}' => liquidstack_blog_resource_escape($labels['submit']),
        '{reset-url}' => liquidstack_blog_resource_escape($resetUrl),
        '{reset-label}' => liquidstack_blog_resource_escape($labels['reset']),
        '{reset-hidden}' => $selected === [] ? ' hidden' : '',
        '{empty-label}' => liquidstack_blog_resource_escape($labels['empty']),
        '{status-label}' => liquidstack_blog_resource_escape($labels['status']),
        '{error-message}' => liquidstack_blog_resource_escape($labels['error']),
    ]);
}
