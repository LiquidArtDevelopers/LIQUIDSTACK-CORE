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
