<?php
/**
 * Directrices de copy para sectionBlogGrid01:
 * - Encabezado principal: 4-8 palabras; incluir el identificador en showroom.
 * - Título de entrada: 5-12 palabras.
 * - Extracto: 24-45 palabras con una idea completa y sin HTML.
 * - Fecha: valor ISO válido; el elemento time conserva datetime accesible.
 *
 * Fixtures hidratables de showroom (cuatro entradas):
 * data-lang="sectionBlogGrid01_00_headerPrimary"
 * data-lang="sectionBlogGrid01_00_a_link"
 * data-lang="sectionBlogGrid01_00_a_excerpt"
 * data-lang="sectionBlogGrid01_00_a_publishedAt"
 * data-lang="sectionBlogGrid01_00_b_link"
 * data-lang="sectionBlogGrid01_00_b_excerpt"
 * data-lang="sectionBlogGrid01_00_b_publishedAt"
 * data-lang="sectionBlogGrid01_00_c_link"
 * data-lang="sectionBlogGrid01_00_c_excerpt"
 * data-lang="sectionBlogGrid01_00_c_publishedAt"
 * data-lang="sectionBlogGrid01_00_d_link"
 * data-lang="sectionBlogGrid01_00_d_excerpt"
 * data-lang="sectionBlogGrid01_00_d_publishedAt"
 */
function controller_sectionBlogGrid01(
    int $i = 0,
    array $params = []
): string {
    $pad = sprintf('%02d', max(0, $i));
    $escapeAttribute = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $escapeText = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $readField = static function ($entry, string $field): string {
        if (is_object($entry) && isset($entry->{$field})) {
            return (string) $entry->{$field};
        }
        if (is_array($entry) && isset($entry[$field])) {
            return (string) $entry[$field];
        }
        if ($field === 'text' && is_scalar($entry)) {
            return (string) $entry;
        }

        return '';
    };
    $readEntry = static fn (string $key) => $GLOBALS[$key] ?? null;

    $idPrefix = trim((string) ($params['id_prefix'] ?? "sectionBlogGrid01-{$pad}"));
    unset($params['id_prefix']);
    if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $idPrefix) !== 1) {
        $idPrefix = "sectionBlogGrid01-{$pad}";
    }

    $hasInjectedItems = array_key_exists('items_data', $params);
    $injectedItems = $hasInjectedItems && is_array($params['items_data'])
        ? $params['items_data']
        : [];
    unset($params['items_data']);

    $hasExplicitLimit = array_key_exists('items', $params);
    $requestedItems = $hasExplicitLimit ? (int) $params['items'] : null;
    unset($params['items']);

    $injectedHeading = is_string($params['{header-primary}'] ?? null)
        ? trim($params['{header-primary}'])
        : '';
    $headerLevels = resolve_header_levels($params, '{header-primary}', 2);
    $primaryTag = 'h' . $headerLevels['base'];
    $itemTag = 'h' . $headerLevels['child'];
    $headingId = $idPrefix . '-heading';
    if ($injectedHeading !== '') {
        $matchedHeading = false;
        $injectedHeading = (string) preg_replace_callback(
            '/<h([1-6])\b([^>]*)>/i',
            static function (array $matches) use (
                &$headingId,
                &$matchedHeading,
                $escapeAttribute
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
                    . $escapeAttribute($headingId)
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
    unset($params['{header-primary}']);
    $headerKey = "sectionBlogGrid01_{$pad}_headerPrimary";
    $hasHeaderText = array_key_exists('header_text', $params);
    $headerText = $hasHeaderText
        ? trim((string) $params['header_text'])
        : $readField($readEntry($headerKey), 'text');
    unset($params['header_text']);
    if ($headerText === '') {
        $headerText = $readField($readEntry($headerKey), 'text');
    }
    $headerLang = trim((string) ($params['header_lang'] ?? ''));
    unset($params['header_lang']);
    if (
        $headerLang !== ''
        && preg_match('/\A[A-Za-z0-9_.-]+\z/', $headerLang) !== 1
    ) {
        $headerLang = '';
    }
    $headerLanguageAttribute = $headerLang !== ''
        ? ' data-lang="' . $escapeAttribute($headerLang) . '"'
        : ($hasHeaderText
            ? ''
            : ' data-lang="' . $escapeAttribute($headerKey) . '"');

    $language = strtolower((string) ($GLOBALS['lang'] ?? 'es'));
    $formatDate = static function (
        $publishedAt,
        string $displayText = ''
    ) use ($language): ?array {
        try {
            if ($publishedAt instanceof DateTimeInterface) {
                $date = DateTimeImmutable::createFromInterface($publishedAt);
            } elseif (is_string($publishedAt) && trim($publishedAt) !== '') {
                $date = new DateTimeImmutable(trim($publishedAt));
            } else {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        if ($displayText === '') {
            $displayText = $language === 'en'
                ? $date->format('M j, Y')
                : $date->format('d/m/Y');
        }

        return [
            'datetime' => $date->format('Y-m-d'),
            'text' => $displayText,
        ];
    };
    $validUrl = static function (string $url): bool {
        if (str_contains($url, '\\')) {
            return false;
        }
        if (
            str_starts_with($url, '/')
            && !str_starts_with($url, '//')
        ) {
            return true;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(
            strtolower((string) parse_url($url, PHP_URL_SCHEME)),
            ['http', 'https'],
            true
        );
    };
    $valueFromItem = static function ($item, string $field) {
        if (is_array($item)) {
            return $item[$field] ?? null;
        }
        if (is_object($item) && isset($item->{$field})) {
            return $item->{$field};
        }

        return null;
    };

    $itemsData = [];
    if ($hasInjectedItems) {
        $limit = $hasExplicitLimit
            ? max(0, min(50, (int) $requestedItems))
            : min(50, count($injectedItems));

        foreach (array_slice($injectedItems, 0, $limit) as $item) {
            $url = trim((string) $valueFromItem($item, 'url'));
            $title = trim((string) $valueFromItem($item, 'h1'));
            $excerpt = trim((string) $valueFromItem($item, 'excerpt'));
            $date = $formatDate($valueFromItem($item, 'published_at'));

            if ($url === '' || !$validUrl($url) || $title === '' || $date === null) {
                continue;
            }

            $itemsData[] = [
                'url' => $url,
                'title' => $title,
                'link_title' => $title,
                'excerpt' => $excerpt,
                'date' => $date,
                'language_keys' => null,
            ];
        }
    } else {
        $letters = ['a', 'b', 'c', 'd'];
        $limit = $hasExplicitLimit
            ? max(0, min(count($letters), (int) $requestedItems))
            : count($letters);

        foreach (array_slice($letters, 0, $limit) as $letter) {
            $prefix = "sectionBlogGrid01_{$pad}_{$letter}";
            $linkKey = $prefix . '_link';
            $excerptKey = $prefix . '_excerpt';
            $publishedAtKey = $prefix . '_publishedAt';
            $linkEntry = $readEntry($linkKey);
            $publishedAtEntry = $readEntry($publishedAtKey);
            $url = trim($readField($linkEntry, 'href'));
            $title = trim($readField($linkEntry, 'text'));
            $date = $formatDate(
                $readField($publishedAtEntry, 'datetime'),
                trim($readField($publishedAtEntry, 'text'))
            );

            if ($url === '' || !$validUrl($url) || $title === '' || $date === null) {
                continue;
            }

            $itemsData[] = [
                'url' => $url,
                'title' => $title,
                'link_title' => trim($readField($linkEntry, 'title')),
                'excerpt' => trim($readField($readEntry($excerptKey), 'text')),
                'date' => $date,
                'language_keys' => [
                    'link' => $linkKey,
                    'excerpt' => $excerptKey,
                    'published_at' => $publishedAtKey,
                ],
            ];
        }
    }

    $itemsHtml = '';
    foreach ($itemsData as $index => $item) {
        $itemHeadingId = $idPrefix . '-item-' . ($index + 1) . '-heading';
        $keys = $item['language_keys'];
        $linkLanguageAttribute = is_array($keys)
            ? ' data-lang="' . $escapeAttribute($keys['link']) . '"'
            : '';
        $excerptLanguageAttribute = is_array($keys)
            ? ' data-lang="' . $escapeAttribute($keys['excerpt']) . '"'
            : '';
        $dateLanguageAttribute = is_array($keys)
            ? ' data-lang="' . $escapeAttribute($keys['published_at']) . '"'
            : '';

        $itemsHtml .= '<article class="sectionBlogGrid01-item" aria-labelledby="'
            . $escapeAttribute($itemHeadingId) . '">'
            . '<' . $itemTag . ' id="' . $escapeAttribute($itemHeadingId) . '">'
            . '<a' . $linkLanguageAttribute
            . ' href="' . $escapeAttribute($item['url']) . '"'
            . ' title="' . $escapeAttribute($item['link_title']) . '">'
            . $escapeText($item['title']) . '</a>'
            . '</' . $itemTag . '>'
            . '<p class="sectionBlogGrid01-date"><time'
            . $dateLanguageAttribute
            . ' datetime="' . $escapeAttribute($item['date']['datetime']) . '">'
            . $escapeText($item['date']['text']) . '</time></p>'
            . '<p class="sectionBlogGrid01-excerpt"'
            . $excerptLanguageAttribute . '>'
            . $escapeText($item['excerpt']) . '</p>'
            . '</article>';
    }

    $vars = [
        '{section-id}' => $escapeAttribute($idPrefix),
        '{heading-id}' => $escapeAttribute($headingId),
        '{classVar}' => "sectionBlogGrid01_{$pad}_classVar",
        '{items-count}' => (string) count($itemsData),
        '{header-primary}' => $injectedHeading !== ''
            ? $injectedHeading
            : '<' . $primaryTag
                . ' id="' . $escapeAttribute($headingId) . '"'
                . $headerLanguageAttribute . '>'
                . $escapeText($headerText)
                . '</' . $primaryTag . '>',
        '{items}' => $itemsHtml,
    ];

    return render(
        'App/templates/_sectionBlogGrid01.html',
        array_replace($vars, $params)
    );
}
