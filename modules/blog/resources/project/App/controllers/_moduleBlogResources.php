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
 *         date_text: string,
 *         key: string,
 *         categories: list<array{slug:string,name:string,url:string}>,
 *         media: null|array{
 *             src:string,
 *             srcset:string,
 *             sizes:string,
 *             alt:string,
 *             width:int,
 *             height:int
 *         }
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
    $stableItemIds = ($params['stable_item_ids'] ?? false) === true;
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
        $cardKey = substr(
            hash('sha256', $language . "\0" . $url),
            0,
            24
        );
        $categories = liquidstack_blog_resource_categories(
            $value('categories')
        );
        $media = liquidstack_blog_resource_preferred_media(
            $resource,
            $value('thumbnail'),
            $value('media')
        );
        $items[] = [
            'id' => $id . '-item-'
                . ($stableItemIds ? $cardKey : (string) $position)
                . '-heading',
            'url' => $url,
            'title' => $title,
            'excerpt' => $excerpt,
            'datetime' => $date->format('Y-m-d'),
            'date_text' => $dateText,
            'key' => $cardKey,
            'categories' => $categories,
            'media' => $media,
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

function liquidstack_blog_resource_safe_url(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $url = trim($value);
    if ($url === '' || str_contains($url, '\\')) {
        return '';
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return $url;
    }
    if (
        filter_var($url, FILTER_VALIDATE_URL) !== false
        && in_array(
            strtolower((string) parse_url($url, PHP_URL_SCHEME)),
            ['http', 'https'],
            true
        )
    ) {
        return $url;
    }

    return '';
}

/** @return list<array{slug:string,name:string,url:string}> */
function liquidstack_blog_resource_categories(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $categories = [];
    foreach (array_slice(array_values($value), 0, 10) as $rawCategory) {
        $read = static function (string $field) use ($rawCategory): mixed {
            if (is_array($rawCategory)) {
                return $rawCategory[$field] ?? null;
            }
            if (is_object($rawCategory) && isset($rawCategory->{$field})) {
                return $rawCategory->{$field};
            }

            return null;
        };
        $slug = is_string($read('slug')) ? trim($read('slug')) : '';
        $name = is_string($read('name')) ? trim($read('name')) : '';
        if (
            $name === ''
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
        ) {
            continue;
        }
        $categories[$slug] = [
            'slug' => $slug,
            'name' => $name,
            'url' => liquidstack_blog_resource_safe_url($read('url')),
        ];
    }

    return array_values($categories);
}

function liquidstack_blog_resource_safe_media_url(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $url = $value;
    if (
        $url === ''
        || trim($url) !== $url
        || strlen($url) > 2_048
        || preg_match('//u', $url) !== 1
        || preg_match('/[\p{Cc}\p{Cf}]/u', $url) === 1
        || preg_match('/\s/u', $url) === 1
        || preg_match('/%(?![0-9A-Fa-f]{2})/', $url) === 1
        || str_contains($url, '\\')
        || str_contains($url, '#')
        || str_contains($url, ',')
        || preg_match('/[<>"\'`]/', $url) === 1
    ) {
        return '';
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return liquidstack_blog_resource_media_path_is_safe($url)
            ? $url
            : '';
    }
    if (!str_starts_with($url, 'https://')) {
        return '';
    }
    $parts = parse_url($url);
    if (
        filter_var($url, FILTER_VALIDATE_URL) === false
        || !is_array($parts)
        || ($parts['scheme'] ?? null) !== 'https'
        || !is_string($parts['host'] ?? null)
        || ($parts['host'] ?? '') === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || !liquidstack_blog_resource_media_path_is_safe($url)
    ) {
        return '';
    }

    return $url;
}

function liquidstack_blog_resource_media_path_is_safe(string $url): bool
{
    $parts = parse_url($url);
    $path = is_array($parts) ? ($parts['path'] ?? null) : null;
    if (
        !is_string($path)
        || !str_starts_with($path, '/')
        || str_contains($path, '//')
        || preg_match('/%(?:2f|5c)/i', $path) === 1
    ) {
        return false;
    }

    $decoded = $path;
    $stable = false;
    for ($pass = 0; $pass < 8; ++$pass) {
        if (
            preg_match('/%(?:2f|5c)/i', $decoded) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $decoded) === 1
        ) {
            return false;
        }
        $next = rawurldecode($decoded);
        if ($next === $decoded) {
            $stable = true;
            break;
        }
        $decoded = $next;
    }
    if (
        !$stable
        || preg_match('//u', $decoded) !== 1
        || preg_match('/[\p{Cc}\p{Cf}]/u', $decoded) === 1
        || str_contains($decoded, '\\')
        || str_contains($decoded, '//')
    ) {
        return false;
    }

    foreach (explode('/', $decoded) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return false;
        }
    }

    return true;
}

function liquidstack_blog_resource_srcset(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $srcset = trim($value);
    if ($srcset === '' || strlen($srcset) > 4_096) {
        return '';
    }
    $candidates = array_map('trim', explode(',', $srcset));
    if (
        $candidates === []
        || count($candidates) > 8
        || in_array('', $candidates, true)
    ) {
        return '';
    }

    $normalized = [];
    $previousWidth = 0;
    foreach ($candidates as $candidate) {
        if (
            preg_match(
                '/\A(\S+)\s+([1-9][0-9]{0,4})w\z/',
                $candidate,
                $matches
            ) !== 1
        ) {
            return '';
        }
        $url = liquidstack_blog_resource_safe_media_url($matches[1]);
        $width = (int) $matches[2];
        if ($url === '' || $width > 2_560 || $width <= $previousWidth) {
            return '';
        }
        $normalized[] = $url . ' ' . $width . 'w';
        $previousWidth = $width;
    }

    return implode(', ', $normalized);
}

function liquidstack_blog_resource_sizes(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $sizes = trim($value);
    if ($sizes === '' || strlen($sizes) > 512) {
        return '';
    }
    $parts = array_map('trim', explode(',', $sizes));
    if ($parts === [] || count($parts) > 6 || in_array('', $parts, true)) {
        return '';
    }
    $length = '(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,3})?'
        . '(?:px|rem|em|vw)';
    $condition = '\((?:min|max)-width:\s*' . $length . '\)';
    foreach ($parts as $part) {
        if (
            preg_match(
                '/\A(?:' . $condition . '\s+)?' . $length . '\z/i',
                $part
            ) !== 1
        ) {
            return '';
        }
    }

    return implode(', ', $parts);
}

function liquidstack_blog_resource_default_media_sizes(
    string $resource
): string {
    return match ($resource) {
        'sectionBlogSlider01', 'sectionBlogSlider02' =>
            '(min-width: 64rem) 27rem, '
                . '(min-width: 48rem) 42vw, 82vw',
        'sectionBlogList01' =>
            '(min-width: 48rem) 18rem, 92vw',
        'sectionBlogFeatured01' =>
            '(min-width: 48rem) 55vw, 92vw',
        'sectionBlogStack01' =>
            '(min-width: 64rem) 60rem, 92vw',
        'moduleBlogGrid02' =>
            '(min-width: 64rem) 42vw, 92vw',
        default =>
            '(min-width: 64rem) 24rem, '
                . '(min-width: 48rem) 42vw, 92vw',
    };
}

/**
 * @return null|array{
 *     src:string,
 *     srcset:string,
 *     sizes:string,
 *     alt:string,
 *     width:int,
 *     height:int
 * }
 */
function liquidstack_blog_resource_media(
    mixed $value,
    string $defaultSizes = ''
): ?array
{
    if (!is_array($value) && !is_object($value)) {
        return null;
    }
    $read = static function (string $field) use ($value): mixed {
        if (is_array($value)) {
            return $value[$field] ?? null;
        }

        return isset($value->{$field}) ? $value->{$field} : null;
    };
    $src = liquidstack_blog_resource_safe_media_url($read('src'));
    $srcset = liquidstack_blog_resource_srcset($read('srcset'));
    $sizes = liquidstack_blog_resource_sizes($read('sizes'));
    if ($sizes === '') {
        $sizes = liquidstack_blog_resource_sizes($defaultSizes);
    }
    $rawAlt = $read('alt');
    $altIsValid = is_string($rawAlt)
        && strlen($rawAlt) <= 1_000
        && preg_match('//u', $rawAlt) === 1
        && preg_match('/[\p{Cc}\p{Cf}]/u', $rawAlt) !== 1;
    $width = filter_var($read('width'), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 10_000],
    ]);
    $height = filter_var($read('height'), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 10_000],
    ]);
    if (
        $src === ''
        || !$altIsValid
        || $width === false
        || $height === false
    ) {
        return null;
    }

    return [
        'src' => $src,
        'srcset' => $srcset,
        'sizes' => $sizes,
        'alt' => $rawAlt,
        'width' => (int) $width,
        'height' => (int) $height,
    ];
}

/**
 * Selects a projected thumbnail only when its mandatory attributes are safe.
 * Optional responsive attributes fail closed independently, so a malformed
 * `srcset` can never discard an otherwise valid, smaller fallback image.
 *
 * @return null|array{
 *     src:string,
 *     srcset:string,
 *     sizes:string,
 *     alt:string,
 *     width:int,
 *     height:int
 * }
 */
function liquidstack_blog_resource_preferred_media(
    string $resource,
    mixed $thumbnail,
    mixed $media
): ?array {
    $defaultSizes = liquidstack_blog_resource_default_media_sizes($resource);

    return liquidstack_blog_resource_media($thumbnail, $defaultSizes)
        ?? liquidstack_blog_resource_media($media, $defaultSizes);
}

/** @param null|array<string, mixed> $media */
function liquidstack_blog_resource_media_markup(
    string $resource,
    ?array $media
): string {
    if ($media === null) {
        return '';
    }
    $escape = 'liquidstack_blog_resource_escape';
    $srcset = ($media['srcset'] ?? '') === ''
        ? ''
        : ' srcset="' . $escape((string) $media['srcset']) . '"';
    $sizes = ($media['sizes'] ?? '') === ''
        ? ''
        : ' sizes="' . $escape((string) $media['sizes']) . '"';
    $draggable = in_array(
        $resource,
        ['sectionBlogSlider01', 'sectionBlogSlider02'],
        true
    ) ? ' draggable="false"' : '';

    return '<figure class="' . $escape($resource . '-media') . '">'
        . '<img src="' . $escape((string) $media['src']) . '"'
        . $srcset . $sizes . ' alt="'
        . $escape((string) $media['alt']) . '" width="'
        . (int) $media['width'] . '" height="'
        . (int) $media['height']
        . '" loading="lazy" decoding="async"'
        . $draggable . '></figure>';
}

/** @param array<string, mixed> $item */
function liquidstack_blog_resource_card(
    array $context,
    array $item,
    string $modifier = '',
    string $ctaLabel = ''
): string {
    $resource = $context['resource'];
    $tag = $context['child_tag'];
    $modifier = preg_match(
        '/\A[A-Za-z][A-Za-z0-9_-]*\z/',
        $modifier
    ) === 1 ? ' ' . $modifier : '';
    $escape = 'liquidstack_blog_resource_escape';

    $media = liquidstack_blog_resource_media_markup(
        $resource,
        is_array($item['media'] ?? null) ? $item['media'] : null
    );
    $categories = '';
    if (is_array($item['categories'] ?? null) && $item['categories'] !== []) {
        $categoryItems = '';
        foreach ($item['categories'] as $category) {
            $label = $escape((string) ($category['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $url = (string) ($category['url'] ?? '');
            $categoryItems .= '<li>' . ($url === ''
                ? '<span>' . $label . '</span>'
                : '<a href="' . $escape($url) . '">' . $label . '</a>')
                . '</li>';
        }
        if ($categoryItems !== '') {
            $categories = '<ul class="'
                . $escape($resource . '-categories')
                . '">' . $categoryItems . '</ul>';
        }
    }

    $ctaLabel = trim($ctaLabel);
    $cta = $ctaLabel === ''
        ? ''
        : '<a class="' . $escape($resource . '-cta') . '" href="'
            . $escape($item['url']) . '" aria-label="'
            . $escape($ctaLabel . ': ' . $item['title']) . '"><span>'
            . $escape($ctaLabel)
            . '</span><span aria-hidden="true">&rarr;</span></a>';

    return '<article class="' . $escape($resource . '-item' . $modifier)
        . '" data-blog-card-key="' . $escape((string) ($item['key'] ?? ''))
        . '" aria-labelledby="' . $escape($item['id']) . '">' . $media
        . '<p class="' . $escape($resource . '-date') . '"><time datetime="'
        . $escape($item['datetime']) . '">' . $escape($item['date_text'])
        . '</time></p>' . $categories . '<' . $tag . ' id="'
        . $escape($item['id']) . '">'
        . '<a href="' . $escape($item['url']) . '">'
        . $escape($item['title']) . '</a></' . $tag . '>'
        . '<p class="' . $escape($resource . '-excerpt') . '">'
        . $escape($item['excerpt']) . '</p>' . $cta . '</article>';
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
