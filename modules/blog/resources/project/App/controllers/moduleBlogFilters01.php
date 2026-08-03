<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * moduleBlogFilters01: formulario GET accesible, mejorado con fetch si existe
 * el destino indicado. No consulta DB ni conserva preferencias en storage.
 */
function controller_moduleBlogFilters01(
    int $i = 0,
    array $params = []
): string {
    $pad = sprintf('%02d', max(0, $i));
    $fallbackId = 'moduleBlogFilters01-' . $pad;
    $id = trim((string) ($params['id_prefix'] ?? $fallbackId));
    if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $id) !== 1) {
        $id = $fallbackId;
    }

    $action = trim((string) ($params['action'] ?? '/'));
    if (
        !str_starts_with($action, '/')
        || str_starts_with($action, '//')
        || str_contains($action, '\\')
        || preg_match('/[\x00-\x1F\x7F]/', $action) === 1
    ) {
        $action = '/';
    }
    $targetId = trim((string) ($params['target_id'] ?? 'blog-results'));
    if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $targetId) !== 1) {
        $targetId = 'blog-results';
    }

    $maximumSelectableCategories = 10;
    $selected = [];
    foreach ((array) ($params['selected_categories'] ?? []) as $slug) {
        if (
            is_string($slug)
            && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) === 1
        ) {
            $selected[$slug] = true;
            if (count($selected) >= $maximumSelectableCategories) {
                break;
            }
        }
    }
    $mode = ($params['category_mode'] ?? 'any') === 'all' ? 'all' : 'any';
    $query = trim((string) ($params['query'] ?? ''));
    if (function_exists('mb_substr')) {
        $query = mb_substr($query, 0, 120, 'UTF-8');
    } else {
        $query = substr($query, 0, 120);
    }

    $labels = array_replace([
        'search' => 'Buscar noticias',
        'placeholder' => 'Título o palabras del artículo',
        'categories' => 'Filtrar por categorías',
        'mode' => 'Coincidencia de categorías',
        'any' => 'Cualquiera',
        'all' => 'Todas',
        'submit' => 'Aplicar filtros',
        'clear' => 'Limpiar filtros',
        'status' => 'Resultados actualizados',
    ], is_array($params['labels'] ?? null) ? $params['labels'] : []);
    foreach ($labels as $key => $label) {
        $labels[$key] = trim((string) $label);
    }

    $publicFilters = array_slice(
        array_values((array) ($params['filters'] ?? [])),
        0,
        100
    );
    $normalizedFilters = [];
    foreach ($publicFilters as $filter) {
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
        if (count($visibleFilters) >= $maximumSelectableCategories) {
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

    return render('App/templates/_moduleBlogFilters01.html', [
        '{form-id}' => liquidstack_blog_resource_escape($id),
        '{action}' => liquidstack_blog_resource_escape($action),
        '{target-selector}' => liquidstack_blog_resource_escape('#' . $targetId),
        '{search-id}' => liquidstack_blog_resource_escape($id . '-search'),
        '{search-label}' => liquidstack_blog_resource_escape($labels['search']),
        '{search-placeholder}' => liquidstack_blog_resource_escape(
            $labels['placeholder']
        ),
        '{query}' => liquidstack_blog_resource_escape($query),
        '{categories-label}' => liquidstack_blog_resource_escape(
            $labels['categories']
        ),
        '{categories-hidden}' => $filtersHtml === '' ? ' hidden' : '',
        '{filters}' => $filtersHtml,
        '{mode-id}' => liquidstack_blog_resource_escape($id . '-mode'),
        '{mode-label}' => liquidstack_blog_resource_escape($labels['mode']),
        '{mode-disabled}' => $filtersHtml === '' ? ' disabled' : '',
        '{mode-any}' => liquidstack_blog_resource_escape($labels['any']),
        '{mode-all}' => liquidstack_blog_resource_escape($labels['all']),
        '{mode-any-selected}' => $mode === 'any' ? ' selected' : '',
        '{mode-all-selected}' => $mode === 'all' ? ' selected' : '',
        '{submit-label}' => liquidstack_blog_resource_escape($labels['submit']),
        '{clear-label}' => liquidstack_blog_resource_escape($labels['clear']),
        '{status-label}' => liquidstack_blog_resource_escape($labels['status']),
    ]);
}
