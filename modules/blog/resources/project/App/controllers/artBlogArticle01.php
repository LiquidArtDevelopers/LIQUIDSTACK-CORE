<?php

declare(strict_types=1);

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

    $template = trim((string) (
        $article['template'] ?? BlogDocumentTemplateRegistry::ARTICLE_BASIC
    ));
    $modifier = match ($template) {
        BlogDocumentTemplateRegistry::ARTICLE_BASIC => 'basic',
        BlogDocumentTemplateRegistry::ARTICLE_COVER => 'cover',
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
        && $template !== BlogDocumentTemplateRegistry::ARTICLE_COVER
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

    $vars = [
        '{article-id}' => $escape($id),
        '{heading-id}' => $escape($headingId),
        '{template}' => $escape($template),
        '{modifier}' => $modifier,
        '{classVar}' => $escape($classVar),
        '{article-intro}' => $introHtml,
        // Fragmentos HTML confiables: ya saneados por CORE.
        '{article-header-media}' => $headerMediaHtml,
        '{article-body}' => $bodyHtml,
        '{article-back}' => $backHtml,
    ];

    return render('App/templates/_artBlogArticle01.html', $vars);
}
