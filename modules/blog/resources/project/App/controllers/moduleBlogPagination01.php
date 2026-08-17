<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * moduleBlogPagination01: paginacion SSR para una coleccion Blog.
 * Etiquetas de navegacion: 1-6 palabras. Paginas visibles: 1-50.
 *
 * El sniper calcula las URLs y conserva filtros/orden. El recurso solo acepta
 * rutas root-relative y no conoce PDO, el request ni la consulta publica. Si
 * no queda ningun destino navegable valido, se autocontiene y devuelve vacio.
 * La raiz es siempre neutra: la section y su H2 pertenecen al compositor que
 * contextualiza los resultados. Los enlaces conservan su semantica SSR.
 */
function controller_moduleBlogPagination01(
    int $i = 0,
    array $params = []
): string {
    $pad = sprintf('%02d', max(0, $i));
    $fallbackId = 'moduleBlogPagination01-' . $pad;
    $id = trim((string) ($params['id_prefix'] ?? $fallbackId));
    if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $id) !== 1) {
        $id = $fallbackId;
    }

    $classVar = trim((string) ($params['class'] ?? ''));
    if (
        $classVar !== ''
        && preg_match(
            '/\A[A-Za-z][A-Za-z0-9_-]*(?:\s+[A-Za-z][A-Za-z0-9_-]*)*\z/',
            $classVar
        ) !== 1
    ) {
        $classVar = '';
    }

    $safePath = static function (mixed $value): string {
        if (!is_string($value)) {
            return '';
        }
        $rawPath = $value;
        $path = trim($rawPath);
        if (
            $path === ''
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7F]/', $rawPath) === 1
        ) {
            return '';
        }

        return $path;
    };
    $label = static function (
        array $source,
        string $key,
        string $fallback
    ): string {
        $value = trim((string) ($source[$key] ?? ''));

        return $value === '' ? $fallback : $value;
    };
    $escape = 'liquidstack_blog_resource_escape';
    $labels = is_array($params['labels'] ?? null) ? $params['labels'] : [];
    $previousLabel = $label($labels, 'previous', 'Pagina anterior');
    $nextLabel = $label($labels, 'next', 'Pagina siguiente');
    $pageLabel = $label($labels, 'page', 'Pagina');

    $pages = [];
    foreach (
        array_slice(array_values((array) ($params['pages_data'] ?? [])), 0, 50)
        as $rawPage
    ) {
        if (!is_array($rawPage) && !is_object($rawPage)) {
            continue;
        }
        $read = static function (string $field) use ($rawPage): mixed {
            if (is_array($rawPage)) {
                return $rawPage[$field] ?? null;
            }

            return $rawPage->{$field} ?? null;
        };
        $pageNumber = filter_var($read('page'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100_000],
        ]);
        if ($pageNumber === false) {
            continue;
        }
        $current = filter_var(
            $read('current'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) === true;
        $url = $current ? '' : $safePath($read('url'));
        if (!$current && $url === '') {
            continue;
        }
        $pages[(int) $pageNumber] = [
            'page' => (int) $pageNumber,
            'url' => $url,
            'current' => $current,
        ];
    }
    ksort($pages, SORT_NUMERIC);

    $pageItems = '';
    $hasNavigablePage = false;
    foreach ($pages as $page) {
        $pageNumber = (string) $page['page'];
        $accessibleLabel = $escape($pageLabel . ' ' . $pageNumber);
        $hasNavigablePage = $hasNavigablePage || !$page['current'];
        $content = $page['current']
            ? '<span aria-current="page" aria-label="' . $accessibleLabel
                . '">' . $escape($pageNumber) . '</span>'
            : '<a href="' . $escape($page['url']) . '" aria-label="'
                . $accessibleLabel . '">' . $escape($pageNumber) . '</a>';
        $pageItems .= '<li>' . $content . '</li>';
    }

    $previousUrl = $safePath($params['previous_url'] ?? null);
    $nextUrl = $safePath($params['next_url'] ?? null);
    $previous = $previousUrl === ''
        ? ''
        : '<a class="moduleBlogPagination01-previous" rel="prev" href="'
            . $escape($previousUrl) . '">' . $escape($previousLabel) . '</a>';
    $next = $nextUrl === ''
        ? ''
        : '<a class="moduleBlogPagination01-next" rel="next" href="'
            . $escape($nextUrl) . '">' . $escape($nextLabel) . '</a>';

    if (!$hasNavigablePage && $previous === '' && $next === '') {
        return '';
    }

    return render('App/templates/_moduleBlogPagination01.html', [
        '{pagination-id}' => $escape($id),
        '{classVar}' => $escape($classVar),
        '{previous}' => $previous,
        '{pages}' => $pageItems,
        '{next}' => $next,
    ]);
}
