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

final class BlogPublicHtmlRenderer
{
    /** @param array<string, string> $alternateUrls */
    public function render(
        BlogPostVariant $variant,
        string $canonicalUrl,
        array $alternateUrls = [],
        ?string $xDefaultUrl = null
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
            $xDefaultUrl
        );
    }

    /** @param array<string, string> $alternateUrls */
    public function renderStructured(
        BlogPostVariant $variant,
        string $canonicalUrl,
        BlogDocument $document,
        BlogImageResolverInterface $imageResolver,
        array $alternateUrls = [],
        ?string $xDefaultUrl = null
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
            $xDefaultUrl
        );
    }

    /** @param array<string, string> $alternatePaths */
    public function renderFromOrigin(
        BlogPostVariant $variant,
        BlogPublicOrigin $origin,
        string $canonicalPath,
        array $alternatePaths = [],
        ?string $xDefaultPath = null
    ): string {
        return $this->renderDocument(
            $variant,
            $origin->absoluteUrl($canonicalPath),
            null,
            null,
            $this->absoluteAlternates($origin, $alternatePaths),
            $xDefaultPath === null
                ? null
                : $origin->absoluteUrl($xDefaultPath)
        );
    }

    /** @param array<string, string> $alternatePaths */
    public function renderStructuredFromOrigin(
        BlogPostVariant $variant,
        BlogPublicOrigin $origin,
        string $canonicalPath,
        BlogDocument $document,
        BlogImageResolverInterface $imageResolver,
        array $alternatePaths = [],
        ?string $xDefaultPath = null
    ): string {
        return $this->renderDocument(
            $variant,
            $origin->absoluteUrl($canonicalPath),
            $document,
            $imageResolver,
            $this->absoluteAlternates($origin, $alternatePaths),
            $xDefaultPath === null
                ? null
                : $origin->absoluteUrl($xDefaultPath)
        );
    }

    /** @param array<string, string> $alternateUrls */
    private function renderDocument(
        BlogPostVariant $variant,
        string $canonicalUrl,
        ?BlogDocument $document = null,
        ?BlogImageResolverInterface $imageResolver = null,
        array $alternateUrls = [],
        ?string $xDefaultUrl = null
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
        $coverImageUrl = $document === null || $imageResolver === null
            ? null
            : $this->coverImageUrl(
                $document,
                $imageResolver,
                $canonicalUrl
            );

        return '<!doctype html><html lang="'
            . $this->escape($variant->locale())
            . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->escape((string) $draft->seoTitle())
            . '</title><meta name="description" content="'
            . $this->escape((string) $draft->metaDescription()) . '">'
            . '<meta name="robots" content="index,follow">'
            . '<link rel="canonical" href="'
            . $this->escape($canonicalUrl) . '">'
            . $this->alternateHead($alternates, $xDefaultUrl)
            . '<meta property="og:type" content="article">'
            . '<meta property="og:title" content="'
            . $this->escape((string) $draft->seoTitle()) . '">'
            . '<meta property="og:description" content="'
            . $this->escape((string) $draft->metaDescription()) . '">'
            . '<meta property="og:url" content="'
            . $this->escape($canonicalUrl) . '">'
            . ($coverImageUrl === null
                ? ''
                : '<meta property="og:image" content="'
                    . $this->escape($coverImageUrl) . '">')
            . '<meta name="twitter:card" content="'
            . ($coverImageUrl === null ? 'summary' : 'summary_large_image')
            . '"><meta name="twitter:title" content="'
            . $this->escape((string) $draft->seoTitle()) . '">'
            . '<meta name="twitter:description" content="'
            . $this->escape((string) $draft->metaDescription()) . '">'
            . ($coverImageUrl === null
                ? ''
                : '<meta name="twitter:image" content="'
                    . $this->escape($coverImageUrl) . '">')
            . '</head><body>'
            . '<main><article><header><h1>'
            . $this->escape($draft->h1()) . '</h1><p>'
            . $this->escape((string) $draft->excerpt())
            . '</p></header><div>' . $body . '</div></article></main>'
            . '</body></html>';
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
