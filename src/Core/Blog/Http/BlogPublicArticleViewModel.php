<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Blog\StructuredContent\Presentation\BlogHeaderSelection;
use App\Core\Blog\StructuredContent\Rendering\BlogArticleHeaderMedia;
use DateTimeImmutable;

/**
 * Minimal public projection for a project-owned article shell.
 *
 * Scalar strings remain raw so the project can encode them for the exact
 * output context. bodyHtml(), mainHtml(), headerMediaHtml() and headerHtml()
 * are the only pre-rendered, sanitized HTML fields. customCss() is a scoped,
 * sanitized stylesheet projection which the project must emit in a
 * nonce-bearing style element. bodyHtml() preserves the
 * original composition contract; new views should pair headerHtml() with
 * mainHtml().
 */
final class BlogPublicArticleViewModel
{
    private readonly BlogRobotsPreferences $robotsPreferences;

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
        private readonly array $relatedArticles = [],
        private readonly bool $analyticsEnabled = false,
        private readonly int $analyticsRetentionDays = 90,
        private readonly int $analyticsSessionTimeoutSeconds = 1800,
        #[\SensitiveParameter]
        private readonly ?string $analyticsPageGrant = null,
        private readonly ?BlogArticleHeaderMedia $headerMedia = null,
        ?BlogRobotsPreferences $robotsPreferences = null,
        ?BlogHeaderSelection $headerSelection = null,
        private readonly string $headerHtml = '',
        private readonly ?string $authorDisplayName = null,
        private readonly ?string $authorRoleLabel = null,
        private readonly ?string $localizedPublicationDate = null,
        private readonly string $customCss = ''
    ) {
        $this->robotsPreferences = $robotsPreferences
            ?? BlogRobotsPreferences::defaults();
        $this->headerSelection = $headerSelection
            ?? BlogHeaderSelection::forTemplate($template);
    }

    private readonly BlogHeaderSelection $headerSelection;

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

    public function headerMedia(): ?BlogArticleHeaderMedia
    {
        return $this->headerMedia;
    }

    public function headerSelection(): BlogHeaderSelection
    {
        return $this->headerSelection;
    }

    public function headerHtml(): string
    {
        return $this->headerHtml;
    }

    /**
     * Safe scoped CSS; a project-owned shell must still attach its CSP nonce.
     */
    public function customCss(): string
    {
        return $this->customCss;
    }

    public function authorDisplayName(): ?string { return $this->authorDisplayName; }
    public function authorRoleLabel(): ?string { return $this->authorRoleLabel; }
    public function localizedPublicationDate(): ?string
    {
        return $this->localizedPublicationDate;
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

    public function analyticsEnabled(): bool
    {
        return $this->analyticsEnabled;
    }

    public function analyticsRetentionDays(): int
    {
        return $this->analyticsRetentionDays;
    }

    public function analyticsSessionTimeoutSeconds(): int
    {
        return $this->analyticsSessionTimeoutSeconds;
    }

    public function analyticsPageGrant(): ?string
    {
        return $this->analyticsPageGrant;
    }

    public function robotsPreferences(): BlogRobotsPreferences
    {
        return $this->robotsPreferences;
    }

    public function robotsDirective(): string
    {
        return $this->robotsPreferences->directive();
    }

    /** @return array<string, string|bool> */
    public function __debugInfo(): array
    {
        return [
            'locale' => $this->locale,
            'canonical_url' => $this->canonicalUrl,
            'analytics_enabled' => $this->analyticsEnabled,
            'analytics_page_grant' => $this->analyticsPageGrant === null
                ? false
                : '[redacted]',
            'robots' => $this->robotsDirective(),
        ];
    }
}
