<?php

declare(strict_types=1);

function liquidstack_commerce_escape(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function liquidstack_commerce_value(mixed $source, string $field): mixed
{
    if (is_array($source)) {
        return $source[$field] ?? null;
    }
    if (!is_object($source)) {
        return null;
    }
    if (method_exists($source, $field)) {
        return $source->{$field}();
    }

    return isset($source->{$field}) ? $source->{$field} : null;
}

function liquidstack_commerce_text(
    mixed $value,
    int $maximumBytes = 4_000
): string {
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    if (
        $value === ''
        || strlen($value) > $maximumBytes
        || preg_match('//u', $value) !== 1
        || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1
    ) {
        return '';
    }

    return $value;
}

function liquidstack_commerce_path(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    if (
        $value === ''
        || strlen($value) > 2_048
        || !str_starts_with($value, '/')
        || str_starts_with($value, '//')
        || str_contains($value, '\\')
        || preg_match('/[\x00-\x20<>"\']/', $value) === 1
    ) {
        return '';
    }

    return $value;
}

function liquidstack_commerce_token(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);

    return preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $value) === 1
        ? $value
        : '';
}

/** @return array<string, string> */
function liquidstack_commerce_labels(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $labels = [];
    foreach ($value as $key => $label) {
        if (!is_string($key)) {
            continue;
        }
        $normalized = liquidstack_commerce_text($label, 800);
        if ($normalized !== '') {
            $labels[$key] = $normalized;
        }
    }

    return $labels;
}

function liquidstack_commerce_pagination(
    string $catalogPath,
    mixed $query,
    bool $hasNext,
    array $labels,
    string $className
): string {
    $pageValue = liquidstack_commerce_value($query, 'page');
    $page = is_int($pageValue) ? $pageValue : (int) $pageValue;
    $page = max(1, $page);
    if ($page === 1 && !$hasNext) {
        return '';
    }
    if (
        liquidstack_commerce_path($catalogPath) === ''
        || preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]*\z/D', $className) !== 1
    ) {
        return '';
    }

    $previousLabel = liquidstack_commerce_text(
        $labels['pagination_previous'] ?? '',
        200
    );
    $nextLabel = liquidstack_commerce_text(
        $labels['pagination_next'] ?? '',
        200
    );
    $paginationLabel = liquidstack_commerce_text(
        $labels['pagination_label'] ?? '',
        200
    );
    if (
        $paginationLabel === ''
        || ($page > 1 && $previousLabel === '')
        || ($hasNext && $nextLabel === '')
    ) {
        return '';
    }

    $baseQuery = [];
    $search = liquidstack_commerce_text(
        liquidstack_commerce_value($query, 'search'),
        100
    );
    $category = liquidstack_commerce_token(
        liquidstack_commerce_value($query, 'category')
    );
    $tag = liquidstack_commerce_token(
        liquidstack_commerce_value($query, 'tag')
    );
    if ($search !== '') {
        $baseQuery['q'] = $search;
    }
    if ($category !== '') {
        $baseQuery['category'] = $category;
    }
    if ($tag !== '') {
        $baseQuery['tag'] = $tag;
    }
    $pageUrl = static function (int $targetPage) use (
        $catalogPath,
        $baseQuery
    ): string {
        $params = $baseQuery;
        if ($targetPage > 1) {
            $params['page'] = $targetPage;
        }
        $queryString = $params === []
            ? ''
            : '?' . http_build_query(
                $params,
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        return $catalogPath . $queryString;
    };

    $previous = $page <= 1 ? ''
        : '<a rel="prev" href="'
            . liquidstack_commerce_escape($pageUrl($page - 1))
            . '" data-commerce-pagination-link>'
            . liquidstack_commerce_escape($previousLabel) . '</a>';
    $next = !$hasNext ? ''
        : '<a rel="next" href="'
            . liquidstack_commerce_escape($pageUrl($page + 1))
            . '" data-commerce-pagination-link>'
            . liquidstack_commerce_escape($nextLabel) . '</a>';
    if ($previous === '' && $next === '') {
        return '';
    }

    return '<nav class="' . liquidstack_commerce_escape($className)
        . '" aria-label="' . liquidstack_commerce_escape($paginationLabel)
        . '">' . $previous . $next . '</nav>';
}

/** @return list<array{label:string,value:string}> */
function liquidstack_commerce_features(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $features = [];
    foreach (array_slice(array_values($value), 0, 12) as $feature) {
        $label = liquidstack_commerce_text(
            liquidstack_commerce_value($feature, 'label'),
            200
        );
        $featureValue = liquidstack_commerce_text(
            liquidstack_commerce_value($feature, 'value'),
            500
        );
        if ($label !== '' && $featureValue !== '') {
            $features[] = ['label' => $label, 'value' => $featureValue];
        }
    }

    return $features;
}
