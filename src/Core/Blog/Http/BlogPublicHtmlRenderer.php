<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use Throwable;

final class BlogPublicHtmlRenderer
{
    public const STANDALONE_STYLESHEET =
        '/assets/modules/blog/blog-public.css';
    public const STANDALONE_SCRIPT =
        '/assets/modules/blog/blog-public.js';

    private readonly ?string $projectArticleView;

    public function __construct(?string $projectArticleView = null)
    {
        if ($projectArticleView === null) {
            $this->projectArticleView = null;
            return;
        }
        if (
            !is_file($projectArticleView)
            || is_link($projectArticleView)
            || !is_readable($projectArticleView)
        ) {
            throw new BlogException(BlogException::INVALID_STATE);
        }
        $resolved = realpath($projectArticleView);
        if (!is_string($resolved)) {
            throw new BlogException(BlogException::INVALID_STATE);
        }

        $this->projectArticleView = $resolved;
    }

    public function usesProjectArticleView(): bool
    {
        return $this->projectArticleView !== null;
    }

    /**
     * @param array<string, string> $alternateUrls
     * @param array<string, string> $languageNavigationUrls
     * @param list<array<string, mixed>> $relatedArticles
     */
    public function render(
        BlogPostVariant $variant,
        string $canonicalUrl,
        array $alternateUrls = [],
        ?string $xDefaultUrl = null,
        array $languageNavigationUrls = [],
        array $relatedArticles = []
    ): string {
        if (
            filter_var($canonicalUrl, FILTER_VALIDATE_URL) === false
            || !str_starts_with($canonicalUrl, 'https://')
        ) {
            throw new BlogException(BlogException::INVALID_STATE);
        }

        return $this->renderDocument(
            $variant,
            $canonicalUrl,
            null,
            null,
            $alternateUrls,
            $xDefaultUrl,
            $languageNavigationUrls,
            $relatedArticles
        );
    }

    /**
     * @param array<string, string> $alternateUrls
     * @param array<string, string> $languageNavigationUrls
     * @param list<array<string, mixed>> $relatedArticles
     */
    public function renderStructured(
        BlogPostVariant $variant,
        string $canonicalUrl,
        BlogDocument $document,
        BlogImageResolverInterface $imageResolver,
        array $alternateUrls = [],
        ?string $xDefaultUrl = null,
        array $languageNavigationUrls = [],
        array $relatedArticles = []
    ): string {
        if (
            filter_var($canonicalUrl, FILTER_VALIDATE_URL) === false
            || !str_starts_with($canonicalUrl, 'https://')
        ) {
            throw new BlogException(BlogException::INVALID_STATE);
        }

        return $this->renderDocument(
            $variant,
            $canonicalUrl,
            $document,
            $imageResolver,
            $alternateUrls,
            $xDefaultUrl,
            $languageNavigationUrls,
            $relatedArticles
        );
    }

    /**
     * @param array<string, string> $alternatePaths
     * @param array<string, string> $languageNavigationPaths
     * @param list<array<string, mixed>> $relatedArticles
     */
    public function renderFromOrigin(
        BlogPostVariant $variant,
        BlogPublicOrigin $origin,
        string $canonicalPath,
        array $alternatePaths = [],
        ?string $xDefaultPath = null,
        array $languageNavigationPaths = [],
        array $relatedArticles = []
    ): string {
        return $this->renderDocument(
            $variant,
            $origin->absoluteUrl($canonicalPath),
            null,
            null,
            $this->absoluteAlternates($origin, $alternatePaths),
            $xDefaultPath === null
                ? null
                : $origin->absoluteUrl($xDefaultPath),
            $this->absoluteAlternates(
                $origin,
                $languageNavigationPaths
            ),
            $relatedArticles
        );
    }

    /**
     * @param array<string, string> $alternatePaths
     * @param array<string, string> $languageNavigationPaths
     * @param list<array<string, mixed>> $relatedArticles
     */
    public function renderStructuredFromOrigin(
        BlogPostVariant $variant,
        BlogPublicOrigin $origin,
        string $canonicalPath,
        BlogDocument $document,
        BlogImageResolverInterface $imageResolver,
        array $alternatePaths = [],
        ?string $xDefaultPath = null,
        array $languageNavigationPaths = [],
        array $relatedArticles = []
    ): string {
        return $this->renderDocument(
            $variant,
            $origin->absoluteUrl($canonicalPath),
            $document,
            $imageResolver,
            $this->absoluteAlternates($origin, $alternatePaths),
            $xDefaultPath === null
                ? null
                : $origin->absoluteUrl($xDefaultPath),
            $this->absoluteAlternates(
                $origin,
                $languageNavigationPaths
            ),
            $relatedArticles
        );
    }

    /**
     * @param array<string, string> $alternateUrls
     * @param array<string, string> $languageNavigationUrls
     * @param list<array<string, mixed>> $relatedArticles
     */
    private function renderDocument(
        BlogPostVariant $variant,
        string $canonicalUrl,
        ?BlogDocument $document = null,
        ?BlogImageResolverInterface $imageResolver = null,
        array $alternateUrls = [],
        ?string $xDefaultUrl = null,
        array $languageNavigationUrls = [],
        array $relatedArticles = []
    ): string {
        if (
            $variant->status() !== BlogPostVariant::PUBLISHED
            || !$variant->draft()->isPublishable()
            || filter_var($canonicalUrl, FILTER_VALIDATE_URL) === false
        ) {
            throw new BlogException(BlogException::INVALID_STATE);
        }

        $draft = $variant->draft();
        $body = $document === null
            ? $this->legacyBody($draft->bodyText())
            : $this->structuredBody($document, $imageResolver);
        $alternates = $this->normalizeAlternates(
            $variant,
            $canonicalUrl,
            $alternateUrls
        );
        $xDefaultUrl ??= $canonicalUrl;
        if (
            !$this->isAbsolutePublicUrl($xDefaultUrl)
            || !in_array($xDefaultUrl, $alternates, true)
        ) {
            throw new BlogException(BlogException::INVALID_STATE);
        }
        $languageNavigationUrls = $this->normalizeLanguageNavigationUrls(
            $alternates,
            $languageNavigationUrls
        );
        $coverImageUrl = $document === null || $imageResolver === null
            ? null
            : $this->coverImageUrl(
                $document,
                $imageResolver,
                $canonicalUrl
            );
        $publishedAt = $variant->publishedAt();
        if ($publishedAt === null) {
            throw new BlogException(BlogException::INVALID_STATE);
        }
        $article = new BlogPublicArticleViewModel(
            $variant->locale(),
            $canonicalUrl,
            $alternates,
            $languageNavigationUrls,
            $xDefaultUrl,
            (string) $draft->seoTitle(),
            (string) $draft->metaDescription(),
            $draft->h1(),
            (string) $draft->excerpt(),
            $body,
            $coverImageUrl,
            $document?->template()
                ?? BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            $publishedAt,
            $variant->updatedAt(),
            $relatedArticles
        );

        return $this->projectArticleView === null
            ? $this->renderStandalone($article)
            : $this->renderProjectView($article);
    }

    private function renderStandalone(
        BlogPublicArticleViewModel $article
    ): string {
        return '<!doctype html><html lang="'
            . $this->escape($article->locale())
            . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->escape($article->seoTitle())
            . '</title><meta name="description" content="'
            . $this->escape($article->metaDescription()) . '">'
            . '<meta name="robots" content="index,follow">'
            . '<link rel="canonical" href="'
            . $this->escape($article->canonicalUrl()) . '">'
            . $this->alternateHead(
                $article->alternateUrls(),
                $article->xDefaultUrl()
            )
            . '<meta property="og:type" content="article">'
            . '<meta property="og:title" content="'
            . $this->escape($article->seoTitle()) . '">'
            . '<meta property="og:description" content="'
            . $this->escape($article->metaDescription()) . '">'
            . '<meta property="og:url" content="'
            . $this->escape($article->canonicalUrl()) . '">'
            . ($article->coverImageUrl() === null
                ? ''
                : '<meta property="og:image" content="'
                    . $this->escape($article->coverImageUrl()) . '">')
            . '<meta name="twitter:card" content="'
            . ($article->coverImageUrl() === null
                ? 'summary'
                : 'summary_large_image')
            . '"><meta name="twitter:title" content="'
            . $this->escape($article->seoTitle()) . '">'
            . '<meta name="twitter:description" content="'
            . $this->escape($article->metaDescription()) . '">'
            . ($article->coverImageUrl() === null
                ? ''
                : '<meta name="twitter:image" content="'
                    . $this->escape($article->coverImageUrl()) . '">')
            . '<link rel="stylesheet" href="'
            . self::STANDALONE_STYLESHEET . '">'
            . '<script src="' . self::STANDALONE_SCRIPT
            . '" defer></script>'
            . '</head><body>'
            . '<main><article><header><h1>'
            . $this->escape($article->h1()) . '</h1><p>'
            . $this->escape($article->excerpt())
            . '</p></header><div>' . $article->bodyHtml()
            . '</div></article></main>'
            . '</body></html>';
    }

    private function renderProjectView(
        BlogPublicArticleViewModel $blogArticle
    ): string {
        $view = $this->projectArticleView;
        if (
            $view === null
            || !is_file($view)
            || is_link($view)
            || !is_readable($view)
        ) {
            throw new BlogException(BlogException::INVALID_STATE);
        }

        $bufferLevel = ob_get_level();
        ob_start();
        try {
            (static function (
                string $_liquidstackArticleView,
                BlogPublicArticleViewModel $blogArticle
            ): void {
                require $_liquidstackArticleView;
            })($view, $blogArticle);

            if (ob_get_level() !== $bufferLevel + 1) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            $html = ob_get_clean();
        } catch (Throwable) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw new BlogException(BlogException::INVALID_STATE);
        }

        if (!is_string($html) || trim($html) === '') {
            throw new BlogException(BlogException::INVALID_STATE);
        }

        return $html;
    }

    private function legacyBody(string $bodyText): string
    {
        $paragraphs = preg_split(
            '/\n[\t ]*\n+/u',
            trim($bodyText)
        );
        if (!is_array($paragraphs) || $paragraphs === []) {
            throw new BlogException(BlogException::INVALID_STATE);
        }
        $body = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $body .= '<p>' . $this->escape($paragraph) . '</p>';
        }
        if ($body === '') {
            throw new BlogException(BlogException::INVALID_STATE);
        }

        return $body;
    }

    private function structuredBody(
        BlogDocument $document,
        ?BlogImageResolverInterface $imageResolver
    ): string {
        if ($imageResolver === null) {
            throw new BlogException(BlogException::INVALID_STATE);
        }

        return (new BlogDocumentHtmlRenderer($imageResolver))->render(
            $document
        );
    }

    /**
     * @param array<string, string> $alternateUrls
     * @return array<string, string>
     */
    private function normalizeAlternates(
        BlogPostVariant $variant,
        string $canonicalUrl,
        array $alternateUrls
    ): array {
        $normalized = [];
        foreach ($alternateUrls as $locale => $url) {
            if (!is_string($locale) || !is_string($url)) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            $locale = BlogInput::locale($locale);
            if (
                isset($normalized[$locale])
                || !$this->isAbsolutePublicUrl($url)
            ) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            $normalized[$locale] = $url;
        }
        $currentLocale = $variant->locale();
        if (isset($normalized[$currentLocale])) {
            if (!hash_equals($canonicalUrl, $normalized[$currentLocale])) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
        } else {
            $normalized[$currentLocale] = $canonicalUrl;
        }

        return $normalized;
    }

    /**
     * @param array<string, string> $alternates
     * @param array<string, string> $navigationUrls
     * @return array<string, string>
     */
    private function normalizeLanguageNavigationUrls(
        array $alternates,
        array $navigationUrls
    ): array {
        if ($navigationUrls === []) {
            return $alternates;
        }

        $normalized = [];
        foreach ($navigationUrls as $locale => $url) {
            if (!is_string($locale) || !is_string($url)) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            $locale = BlogInput::locale($locale);
            if (
                isset($normalized[$locale])
                || !$this->isAbsolutePublicUrl($url)
                || (
                    isset($alternates[$locale])
                    && !hash_equals($alternates[$locale], $url)
                )
            ) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            $normalized[$locale] = $url;
        }
        foreach ($alternates as $locale => $url) {
            if (!isset($normalized[$locale])) {
                $normalized[$locale] = $url;
            }
        }

        return $normalized;
    }

    /** @param array<string, string> $alternates */
    private function alternateHead(
        array $alternates,
        string $xDefaultUrl
    ): string {
        $html = '';
        foreach ($alternates as $locale => $url) {
            $html .= '<link rel="alternate" hreflang="'
                . $this->escape($locale) . '" href="'
                . $this->escape($url) . '">';
        }

        return $html . '<link rel="alternate" hreflang="x-default" href="'
            . $this->escape($xDefaultUrl) . '">';
    }

    /**
     * @param array<string, string> $alternatePaths
     * @return array<string, string>
     */
    private function absoluteAlternates(
        BlogPublicOrigin $origin,
        array $alternatePaths
    ): array {
        $urls = [];
        foreach ($alternatePaths as $locale => $path) {
            if (!is_string($locale) || !is_string($path)) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            $urls[$locale] = $origin->absoluteUrl($path);
        }

        return $urls;
    }

    private function coverImageUrl(
        BlogDocument $document,
        BlogImageResolverInterface $imageResolver,
        string $canonicalUrl
    ): ?string {
        if ($document->template() !== BlogDocumentTemplateRegistry::ARTICLE_COVER) {
            return null;
        }
        $cover = $document->blocks()[0] ?? null;
        $mediaAssetPublicId = is_array($cover)
            ? ($cover['media_asset_public_id'] ?? null)
            : null;
        if (!is_string($mediaAssetPublicId)) {
            throw new BlogException(BlogException::INVALID_STATE);
        }
        $image = $imageResolver->resolve($mediaAssetPublicId);
        if ($image === null) {
            throw new BlogException(BlogException::INVALID_STATE);
        }
        $sourceUrl = $image->sourceUrl();
        if (!str_starts_with($sourceUrl, '/')) {
            return $this->isAbsolutePublicUrl($sourceUrl)
                ? $sourceUrl
                : throw new BlogException(BlogException::INVALID_STATE);
        }
        $parts = parse_url($canonicalUrl);
        if (
            !is_array($parts)
            || !is_string($parts['scheme'] ?? null)
            || !is_string($parts['host'] ?? null)
        ) {
            throw new BlogException(BlogException::INVALID_STATE);
        }
        $host = (string) $parts['host'];
        if (str_contains($host, ':')) {
            $host = '[' . trim($host, '[]') . ']';
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $absolute = $parts['scheme'] . '://' . $host . $port . $sourceUrl;
        if (!$this->isAbsolutePublicUrl($absolute)) {
            throw new BlogException(BlogException::INVALID_STATE);
        }

        return $absolute;
    }

    private function isAbsolutePublicUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }
        $scheme = $parts['scheme'] ?? null;
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http'
            && in_array(
                strtolower((string) $parts['host']),
                ['localhost', '127.0.0.1', '::1'],
                true
            );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
