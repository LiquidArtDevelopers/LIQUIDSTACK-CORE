<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use DateTimeImmutable;

/**
 * Minimal public projection for a project-owned article shell.
 *
 * Scalar strings remain raw so the project can encode them for the exact
 * output context. bodyHtml(), mainHtml() and headerMediaHtml() are the only
 * pre-rendered, sanitized HTML fields. bodyHtml() preserves the original
 * composition contract; new views should pair headerMediaHtml() + mainHtml().
 */
final class BlogPublicArticleViewModel
{
    /**
     * @param array<string, string> $alternateUrls
     * @param array<string, string> $languageNavigationUrls
     * @param list<array{
     *     locale: string,
     *     slug: string,
     *     url: string,
     *     h1: string,
     *     excerpt: string,
     *     published_at: string,
     *     updated_at: string
     * }> $relatedArticles
     */
    public function __construct(
        private readonly string $locale,
        private readonly string $canonicalUrl,
        private readonly array $alternateUrls,
        private readonly array $languageNavigationUrls,
        private readonly string $xDefaultUrl,
        private readonly string $seoTitle,
        private readonly string $metaDescription,
        private readonly string $h1,
        private readonly string $excerpt,
        private readonly string $bodyHtml,
        private readonly string $mainHtml,
        private readonly string $headerMediaHtml,
        private readonly ?string $coverImageUrl,
        private readonly string $template,
        private readonly DateTimeImmutable $publishedAt,
        private readonly DateTimeImmutable $updatedAt,
        private readonly array $relatedArticles = []
    ) {
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function canonicalUrl(): string
    {
        return $this->canonicalUrl;
    }

    /** @return array<string, string> */
    public function alternateUrls(): array
    {
        return $this->alternateUrls;
    }

    /** @return array<string, string> */
    public function languageNavigationUrls(): array
    {
        return $this->languageNavigationUrls;
    }

    public function xDefaultUrl(): string
    {
        return $this->xDefaultUrl;
    }

    public function seoTitle(): string
    {
        return $this->seoTitle;
    }

    public function metaDescription(): string
    {
        return $this->metaDescription;
    }

    public function h1(): string
    {
        return $this->h1;
    }

    public function excerpt(): string
    {
        return $this->excerpt;
    }

    public function bodyHtml(): string
    {
        return $this->bodyHtml;
    }

    public function mainHtml(): string
    {
        return $this->mainHtml;
    }

    public function headerMediaHtml(): string
    {
        return $this->headerMediaHtml;
    }

    public function coverImageUrl(): ?string
    {
        return $this->coverImageUrl;
    }

    public function template(): string
    {
        return $this->template;
    }

    public function publishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return list<array{
     *     locale: string,
     *     slug: string,
     *     url: string,
     *     h1: string,
     *     excerpt: string,
     *     published_at: string,
     *     updated_at: string
     * }>
     */
    public function relatedArticles(): array
    {
        return $this->relatedArticles;
    }
}
