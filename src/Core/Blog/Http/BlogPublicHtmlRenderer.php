<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostVariant;

final class BlogPublicHtmlRenderer
{
    public function render(
        BlogPostVariant $variant,
        string $canonicalUrl
    ): string {
        if (
            $variant->status() !== BlogPostVariant::PUBLISHED
            || !$variant->draft()->isPublishable()
            || filter_var($canonicalUrl, FILTER_VALIDATE_URL) === false
            || !str_starts_with($canonicalUrl, 'https://')
        ) {
            throw new BlogException(BlogException::INVALID_STATE);
        }

        $draft = $variant->draft();
        $paragraphs = preg_split(
            '/\n[\t ]*\n+/u',
            trim($draft->bodyText())
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

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
