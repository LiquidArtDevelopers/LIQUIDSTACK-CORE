<?php

declare(strict_types=1);

/**
 * Shared, presentation-only normalization for the public Blog resources.
 * Database access stays behind CORE projections; resources only receive arrays.
 *
 * @return array{
 *     resource: string,
 *     id: string,
 *     heading_id: string,
 *     primary_tag: string,
 *     child_tag: string,
 *     heading_markup: string,
 *     heading_text: string,
 *     heading_lang: string,
 *     class_var: string,
 *     items: list<array{
 *         id: string,
 *         url: string,
 *         title: string,
 *         excerpt: string,
 *         datetime: string,
 *         date_text: string
 *     }>
 * }
 */
function liquidstack_blog_resource_context(
    string $resource,
    int $index,
    array $params,
    int $defaultHeadingLevel = 2,
    int $maximumItems = 50
): array {
    if (
        preg_match('/\A(?:section|module)Blog[A-Za-z0-9]+\z/', $resource)
            !== 1
    ) {
        throw new InvalidArgumentException('Invalid Blog resource id.');
    }

    $pad = sprintf('%02d', max(0, $index));
    $fallbackId = $resource . '-' . $pad;
    $id = trim((string) ($params['id_prefix'] ?? $fallbackId));
    if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $id) !== 1) {
        $id = $fallbackId;
    }

    $injectedHeading = is_string($params['{header-primary}'] ?? null)
        ? trim($params['{header-primary}'])
        : '';
    $levels = resolve_header_levels(
        $params,
        '{header-primary}',
        $defaultHeadingLevel
    );
    $headingId = $id . '-heading';
    if ($injectedHeading !== '') {
        $matchedHeading = false;
        $injectedHeading = (string) preg_replace_callback(
            '/<h([1-6])\b([^>]*)>/i',
            static function (array $matches) use (
                &$headingId,
                &$matchedHeading
            ): string {
                $matchedHeading = true;
                $attributes = $matches[2];
                if (
                    preg_match(
                        '/\bid\s*=\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/i',
                        $attributes,
                        $idMatch
                    ) === 1
                ) {
                    $headingId = $idMatch[2];

                    return '<h' . $matches[1] . $attributes . '>';
                }

                $idAttribute = ' id="'
                    . liquidstack_blog_resource_escape($headingId)
                    . '"';
                if (
                    preg_match('/\bid\s*=\s*(["\']).*?\1/i', $attributes)
                        === 1
                ) {
                    $attributes = (string) preg_replace(
                        '/\bid\s*=\s*(["\']).*?\1/i',
                        trim($idAttribute),
                        $attributes,
                        1
                    );
                    $idAttribute = '';
                }

                return '<h' . $matches[1] . $idAttribute . $attributes . '>';
            },
            $injectedHeading,
            1
        );
        if (!$matchedHeading) {
            $injectedHeading = '';
        }
    }
    $headingText = trim((string) (
        $params['header_text'] ?? $resource
    ));
    if ($headingText === '') {
        $headingText = $resource;
    }
    $headingLang = trim((string) ($params['header_lang'] ?? ''));
    if (
        $headingLang !== ''
        && preg_match('/\A[A-Za-z0-9_.-]+\z/', $headingLang) !== 1
    ) {
        $headingLang = '';
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

    $rawItems = is_array($params['items_data'] ?? null)
        ? array_values($params['items_data'])
        : [];
    $limit = array_key_exists('items', $params)
        ? max(0, min($maximumItems, (int) $params['items']))
        : min($maximumItems, count($rawItems));
    $language = strtolower((string) ($GLOBALS['lang'] ?? 'es'));
    $items = [];

    foreach (array_slice($rawItems, 0, $limit) as $rawItem) {
        $value = static function (string $field) use ($rawItem) {
            if (is_array($rawItem)) {
                return $rawItem[$field] ?? null;
            }
            if (is_object($rawItem) && isset($rawItem->{$field})) {
                return $rawItem->{$field};
            }

            return null;
        };

        $url = trim((string) $value('url'));
        $title = trim((string) ($value('h1') ?? $value('title')));
        $excerpt = trim((string) $value('excerpt'));
        $publishedAt = $value('published_at');
        $dateText = trim((string) $value('published_text'));
        $urlIsValid = !str_contains($url, '\\') && (
            str_starts_with($url, '/')
            && !str_starts_with($url, '//')
        ) || (
            filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(
                strtolower((string) parse_url($url, PHP_URL_SCHEME)),
                ['http', 'https'],
                true
            )
        );

        try {
            if ($publishedAt instanceof DateTimeInterface) {
                $date = DateTimeImmutable::createFromInterface($publishedAt);
            } elseif (
                is_string($publishedAt)
                && trim($publishedAt) !== ''
            ) {
                $date = new DateTimeImmutable(trim($publishedAt));
            } else {
                $date = null;
            }
        } catch (Throwable) {
            $date = null;
        }

        if (!$urlIsValid || $title === '' || $date === null) {
            continue;
        }
        if ($dateText === '') {
            $dateText = $language === 'en'
                ? $date->format('M j, Y')
                : $date->format('d/m/Y');
        }

        $position = count($items) + 1;
        $items[] = [
            'id' => $id . '-item-' . $position . '-heading',
            'url' => $url,
            'title' => $title,
            'excerpt' => $excerpt,
            'datetime' => $date->format('Y-m-d'),
            'date_text' => $dateText,
        ];
    }

    return [
        'resource' => $resource,
        'id' => $id,
        'heading_id' => $headingId,
        'primary_tag' => 'h' . $levels['base'],
        'child_tag' => 'h' . $levels['child'],
        'heading_markup' => $injectedHeading,
        'heading_text' => $headingText,
        'heading_lang' => $headingLang,
        'class_var' => $classVar,
        'items' => $items,
    ];
}

function liquidstack_blog_resource_escape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/** @param array<string, string> $item */
function liquidstack_blog_resource_card(
    array $context,
    array $item,
    string $modifier = ''
): string {
    $resource = $context['resource'];
    $tag = $context['child_tag'];
    $modifier = preg_match(
        '/\A[A-Za-z][A-Za-z0-9_-]*\z/',
        $modifier
    ) === 1 ? ' ' . $modifier : '';
    $escape = 'liquidstack_blog_resource_escape';

    return '<article class="' . $escape($resource . '-item' . $modifier)
        . '" aria-labelledby="' . $escape($item['id']) . '">'
        . '<p class="' . $escape($resource . '-date') . '"><time datetime="'
        . $escape($item['datetime']) . '">' . $escape($item['date_text'])
        . '</time></p><' . $tag . ' id="' . $escape($item['id']) . '">'
        . '<a href="' . $escape($item['url']) . '">'
        . $escape($item['title']) . '</a></' . $tag . '>'
        . '<p class="' . $escape($resource . '-excerpt') . '">'
        . $escape($item['excerpt']) . '</p></article>';
}

function liquidstack_blog_resource_heading(array $context): string
{
    if (($context['heading_markup'] ?? '') !== '') {
        return (string) $context['heading_markup'];
    }

    $escape = 'liquidstack_blog_resource_escape';
    $languageAttribute = $context['heading_lang'] === ''
        ? ''
        : ' data-lang="' . $escape($context['heading_lang']) . '"';

    return '<' . $context['primary_tag'] . ' id="'
        . $escape($context['heading_id']) . '"' . $languageAttribute . '>'
        . $escape($context['heading_text'])
        . '</' . $context['primary_tag'] . '>';
}
