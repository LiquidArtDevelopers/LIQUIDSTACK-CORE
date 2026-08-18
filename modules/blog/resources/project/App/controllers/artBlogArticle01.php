<?php

declare(strict_types=1);

use App\Core\Blog\Http\BlogPublicArticleTaxonomyRenderer;
use App\Core\Blog\PublicFeed\BlogPublicCardCategory;
use App\Core\Blog\PublicFeed\BlogPublicCardTag;
use App\Core\Blog\PublicFeed\BlogPublicCardTaxonomyBatch;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;

/**
 * Composición pública de un artículo Blog.
 *
 * `body_html` debe proceder exclusivamente de
 * BlogPublicArticleViewModel::bodyHtml() en composiciones legacy o de
 * BlogPublicArticleViewModel::mainHtml() en vistas nuevas. El fragmento
 * opcional `header_media_html` debe proceder de
 * BlogPublicArticleViewModel::headerMediaHtml(). El controlador escapa el
 * resto de escalares y no consulta DB, configuración ni estado interno del
 * módulo.
 *
 * @param array<string, mixed> $params
 */
function controller_artBlogArticle01(
    int $i = 0,
    array $params = []
): string {
    $pad = sprintf('%02d', max(0, $i));
    $escape = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $article = is_array($params['article_data'] ?? null)
        ? $params['article_data']
        : [];
    unset($params['article_data']);

    $articleLocale = trim((string) ($article['locale'] ?? ''));
    $normalizeTerms = static function (
        mixed $value,
        bool $tags
    ) use ($articleLocale): array {
        if ($value === null) {
            return [];
        }
        $maximum = $tags
            ? BlogPublicCardTaxonomyBatch::MAX_TAGS_PER_CARD
            : BlogPublicCardTaxonomyBatch::MAX_CATEGORIES_PER_CARD;
        if (
            !is_array($value)
            || !array_is_list($value)
            || count($value) > $maximum
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog article taxonomy presentation data.'
            );
        }
        $normalized = [];
        $seenSlugs = [];
        $effectiveLocale = $articleLocale;
        try {
            foreach ($value as $term) {
                if (
                    !is_array($term)
                    || !is_string($term['locale'] ?? null)
                    || !is_string($term['slug'] ?? null)
                    || !is_string($term['name'] ?? null)
                ) {
                    throw new InvalidArgumentException(
                        'Invalid Blog article taxonomy presentation data.'
                    );
                }
                $projection = $tags
                    ? new BlogPublicCardTag(
                        $term['locale'],
                        $term['slug'],
                        $term['name']
                    )
                    : new BlogPublicCardCategory(
                        $term['locale'],
                        $term['slug'],
                        $term['name']
                    );
                $effectiveLocale = $effectiveLocale === ''
                    ? $projection->locale()
                    : $effectiveLocale;
                if (
                    $projection->locale() !== $effectiveLocale
                    || isset($seenSlugs[$projection->slug()])
                ) {
                    throw new InvalidArgumentException(
                        'Invalid Blog article taxonomy presentation data.'
                    );
                }
                $seenSlugs[$projection->slug()] = true;
                $normalized[] = $projection->toResourceData();
            }
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvalidArgumentException(
                'Invalid Blog article taxonomy presentation data.'
            );
        }
        usort(
            $normalized,
            static fn (array $left, array $right): int =>
                strcmp($left['slug'], $right['slug'])
        );

        return $normalized;
    };
    $categories = $normalizeTerms($article['categories'] ?? null, false);
    $tags = $normalizeTerms($article['tags'] ?? null, true);
    if (
        $categories !== []
        && $tags !== []
        && $categories[0]['locale'] !== $tags[0]['locale']
    ) {
        throw new InvalidArgumentException(
            'Invalid Blog article taxonomy presentation data.'
        );
    }

    $template = trim((string) (
        $article['template'] ?? BlogDocumentTemplateRegistry::ARTICLE_BASIC
    ));
    $modifier = match ($template) {
        BlogDocumentTemplateRegistry::ARTICLE_BASIC => 'basic',
        BlogDocumentTemplateRegistry::ARTICLE_COVER => 'cover',
        BlogDocumentTemplateRegistry::ARTICLE_HERO00,
        BlogDocumentTemplateRegistry::ARTICLE_HERO06 => 'cover',
        default => throw new InvalidArgumentException(
            'Unsupported Blog article template.'
        ),
    };

    $id = trim((string) (
        $params['id_prefix'] ?? "artBlogArticle01-{$pad}"
    ));
    unset($params['id_prefix']);
    if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $id) !== 1) {
        $id = "artBlogArticle01-{$pad}";
    }
    $headingId = $id . '-heading';

    $classVar = trim((string) ($params['class'] ?? ''));
    unset($params['class']);
    if (
        $classVar !== ''
        && preg_match(
            '/\A[A-Za-z][A-Za-z0-9_-]*(?:\s+[A-Za-z][A-Za-z0-9_-]*)*\z/',
            $classVar
        ) !== 1
    ) {
        $classVar = '';
    }

    $headerLevels = resolve_header_levels(
        $params,
        '{article-intro}',
        1
    );
    $headingLevel = $headerLevels['base'];
    $headingTag = 'h' . $headingLevel;

    $h1 = trim((string) ($article['h1'] ?? ''));
    $excerpt = trim((string) ($article['excerpt'] ?? ''));
    $bodyHtml = is_string($article['body_html'] ?? null)
        ? trim($article['body_html'])
        : '';
    $headerMediaHtml = is_string($article['header_media_html'] ?? null)
        ? trim($article['header_media_html'])
        : '';
    if (
        $headerMediaHtml !== ''
        && !BlogDocumentTemplateRegistry::hasCover($template)
    ) {
        throw new InvalidArgumentException(
            'Header media requires the Blog cover template.'
        );
    }
    $bodyHeadingShift = max(0, $headingLevel - 1);
    if ($bodyHtml !== '' && $bodyHeadingShift > 0) {
        $bodyHtml = (string) preg_replace_callback(
            '/<(\/?)h([1-6])(\b[^>]*)>/i',
            static function (array $matches) use (
                $bodyHeadingShift
            ): string {
                $level = min(6, (int) $matches[2] + $bodyHeadingShift);

                return '<' . $matches[1] . 'h' . $level
                    . $matches[3] . '>';
            },
            $bodyHtml
        );
    }
    $publishedLabel = trim((string) (
        $article['published_label'] ?? ''
    ));
    $publishedText = trim((string) (
        $article['published_text'] ?? ''
    ));
    $backLabel = trim((string) ($article['back_label'] ?? ''));
    $backHref = trim((string) ($article['back_href'] ?? ''));

    $taxonomyLocale = $articleLocale;
    if ($taxonomyLocale === '') {
        $taxonomyLocale = (string) (
            $categories[0]['locale'] ?? $tags[0]['locale'] ?? 'en'
        );
    }
    $defaultTaxonomyLabels = match (
        strtolower(explode('-', $taxonomyLocale, 2)[0])
    ) {
        'es' => ['categories' => 'Categorías', 'tags' => 'Etiquetas'],
        'eu' => ['categories' => 'Kategoriak', 'tags' => 'Etiketak'],
        default => ['categories' => 'Categories', 'tags' => 'Tags'],
    };
    $taxonomyLabels = [
        'categories' => trim((string) (
            $article['categories_label']
                ?? $defaultTaxonomyLabels['categories']
        )),
        'tags' => trim((string) (
            $article['tags_label'] ?? $defaultTaxonomyLabels['tags']
        )),
    ];
    if (
        $taxonomyLabels['categories'] === ''
        || $taxonomyLabels['tags'] === ''
    ) {
        throw new InvalidArgumentException(
            'Incomplete Blog article taxonomy labels.'
        );
    }

    $publishedAt = $article['published_at'] ?? null;
    try {
        if ($publishedAt instanceof DateTimeInterface) {
            $publishedDate = DateTimeImmutable::createFromInterface(
                $publishedAt
            );
        } elseif (is_string($publishedAt) && trim($publishedAt) !== '') {
            $publishedDate = new DateTimeImmutable(trim($publishedAt));
        } else {
            $publishedDate = null;
        }
    } catch (Throwable) {
        $publishedDate = null;
    }

    if ($h1 === '' || $bodyHtml === '' || $publishedDate === null) {
        throw new InvalidArgumentException(
            'Incomplete Blog article presentation data.'
        );
    }
    if ($publishedText === '') {
        $publishedText = $publishedDate->format('d/m/Y');
    }

    $validBackHref = !str_contains($backHref, '\\') && (
        (
            str_starts_with($backHref, '/')
            && !str_starts_with($backHref, '//')
        ) || (
            filter_var($backHref, FILTER_VALIDATE_URL) !== false
            && in_array(
                strtolower((string) parse_url($backHref, PHP_URL_SCHEME)),
                ['http', 'https'],
                true
            )
        )
    );
    if (!$validBackHref || $backLabel === '') {
        $backHref = '';
        $backLabel = '';
    }

    $dateHtml = '<p class="artBlogArticle01-date">';
    if ($publishedLabel !== '') {
        $dateHtml .= '<span>' . $escape($publishedLabel) . '</span> ';
    }
    $dateHtml .= '<time datetime="'
        . $escape($publishedDate->format(DATE_ATOM)) . '">'
        . $escape($publishedText) . '</time></p>';

    $injectedIntro = is_string($params['{article-intro}'] ?? null)
        ? trim($params['{article-intro}'])
        : '';
    unset($params['{article-intro}']);
    if ($injectedIntro !== '') {
        $matchedHeading = false;
        $injectedIntro = (string) preg_replace_callback(
            '/<h([1-6])\b([^>]*)>/i',
            static function (array $matches) use (
                &$matchedHeading,
                $headingId,
                $escape
            ): string {
                $matchedHeading = true;
                $attributes = $matches[2];
                if (preg_match('/\bid\s*=\s*(["\']).*?\1/i', $attributes) === 1) {
                    $attributes = (string) preg_replace(
                        '/\bid\s*=\s*(["\']).*?\1/i',
                        'id="' . $escape($headingId) . '"',
                        $attributes,
                        1
                    );

                    return '<h' . $matches[1] . $attributes . '>';
                }

                return '<h' . $matches[1] . ' id="'
                    . $escape($headingId) . '"' . $attributes . '>';
            },
            $injectedIntro,
            1
        );
        if (!$matchedHeading) {
            $injectedIntro = '';
        }
    }

    $introHtml = $injectedIntro !== ''
        ? $injectedIntro
        : '<div class="artBlogArticle01-heading">' . $dateHtml
            . '<' . $headingTag . ' id="' . $escape($headingId)
            . '" class="artBlogArticle01-title">' . $escape($h1)
            . '</' . $headingTag . '>'
            . ($excerpt === '' ? '' : '<p class="artBlogArticle01-excerpt">'
                . $escape($excerpt) . '</p>')
            . '</div>';

    $injectedBack = is_string($params['{article-back}'] ?? null)
        ? trim($params['{article-back}'])
        : '';
    unset($params['{article-back}']);
    $backHtml = $injectedBack !== ''
        ? $injectedBack
        : ($backHref === ''
            ? ''
            : '<a class="artBlogArticle01-backAction" rel="up" href="'
                . $escape($backHref) . '" title="' . $escape($backLabel)
                . '"><span>' . $escape($backLabel) . '</span></a>');

    try {
        $taxonomyHtml = (new BlogPublicArticleTaxonomyRenderer())
            ->renderProjection(
                $taxonomyLocale,
                $categories,
                $tags,
                $taxonomyLabels
            );
    } catch (Throwable) {
        throw new InvalidArgumentException(
            'Invalid Blog article taxonomy presentation data.'
        );
    }

    $vars = [
        '{article-id}' => $escape($id),
        '{heading-id}' => $escape($headingId),
        '{template}' => $escape($template),
        '{modifier}' => $modifier,
        '{classVar}' => $escape($classVar),
        '{article-intro}' => $introHtml,
        // Fragmentos HTML confiables: ya saneados por CORE.
        '{article-header-media}' => $headerMediaHtml,
        '{article-taxonomies}' => $taxonomyHtml,
        '{article-body}' => $bodyHtml,
        '{article-back}' => $backHtml,
    ];

    return render('App/templates/_artBlogArticle01.html', $vars);
}
