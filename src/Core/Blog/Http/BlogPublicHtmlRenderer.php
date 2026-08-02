<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;

final class BlogPublicHtmlRenderer
{
    public function render(
        BlogPostVariant $variant,
        string $canonicalUrl
    ): string {
        if (
            filter_var($canonicalUrl, FILTER_VALIDATE_URL) === false
            || !str_starts_with($canonicalUrl, 'https://')
        ) {
            throw new BlogException(BlogException::INVALID_STATE);
        }

        return $this->renderDocument($variant, $canonicalUrl);
    }

    public function renderStructured(
        BlogPostVariant $variant,
        string $canonicalUrl,
        BlogDocument $document,
        BlogImageResolverInterface $imageResolver
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
            $imageResolver
        );
    }

    public function renderFromOrigin(
        BlogPostVariant $variant,
        BlogPublicOrigin $origin,
        string $canonicalPath
    ): string {
        return $this->renderDocument(
            $variant,
            $origin->absoluteUrl($canonicalPath)
        );
    }

    public function renderStructuredFromOrigin(
        BlogPostVariant $variant,
        BlogPublicOrigin $origin,
        string $canonicalPath,
        BlogDocument $document,
        BlogImageResolverInterface $imageResolver
    ): string {
        return $this->renderDocument(
            $variant,
            $origin->absoluteUrl($canonicalPath),
            $document,
            $imageResolver
        );
    }

    private function renderDocument(
        BlogPostVariant $variant,
        string $canonicalUrl,
        ?BlogDocument $document = null,
        ?BlogImageResolverInterface $imageResolver = null
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

        return '<!doctype html><html lang="'
            . $this->escape($variant->locale())
            . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->escape((string) $draft->seoTitle())
            . '</title><meta name="description" content="'
            . $this->escape((string) $draft->metaDescription()) . '">'
            . '<link rel="canonical" href="'
            . $this->escape($canonicalUrl) . '"></head><body>'
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

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
