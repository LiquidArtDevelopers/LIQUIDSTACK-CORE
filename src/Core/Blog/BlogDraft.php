<?php

declare(strict_types=1);

namespace App\Core\Blog;

/** Validated plain-text editorial payload for one locale. */
final class BlogDraft
{
    public const MAX_SLUG_BYTES = 190;
    public const MAX_H1_BYTES = 255;
    public const MAX_SEO_TITLE_BYTES = 255;
    public const MAX_META_DESCRIPTION_BYTES = 320;
    public const MAX_EXCERPT_BYTES = 4096;
    /**
     * Keeps the complete application/x-www-form-urlencoded request below
     * Request::MAX_BODY_BYTES even when every payload byte needs percent
     * encoding. The editor contract must be reachable through its real HTTP
     * boundary, not only through direct service calls.
     */
    public const MAX_BODY_BYTES = 300_000;

    private readonly string $h1;
    private readonly string $bodyText;
    private readonly ?string $slug;
    private readonly ?string $seoTitle;
    private readonly ?string $metaDescription;
    private readonly ?string $excerpt;

    public function __construct(
        #[\SensitiveParameter] string $h1,
        #[\SensitiveParameter] string $bodyText,
        #[\SensitiveParameter] ?string $slug = null,
        #[\SensitiveParameter] ?string $seoTitle = null,
        #[\SensitiveParameter] ?string $metaDescription = null,
        #[\SensitiveParameter] ?string $excerpt = null
    ) {
        $this->h1 = BlogInput::requiredSingleLine(
            $h1,
            self::MAX_H1_BYTES
        );
        $this->bodyText = BlogInput::multiline(
            $bodyText,
            self::MAX_BODY_BYTES
        );
        $this->slug = BlogInput::slug($slug);
        $this->seoTitle = BlogInput::nullableSingleLine(
            $seoTitle,
            self::MAX_SEO_TITLE_BYTES
        );
        $this->metaDescription = BlogInput::nullableSingleLine(
            $metaDescription,
            self::MAX_META_DESCRIPTION_BYTES
        );
        $this->excerpt = BlogInput::nullableMultiline(
            $excerpt,
            self::MAX_EXCERPT_BYTES
        );
    }

    public function h1(): string
    {
        return $this->h1;
    }

    public function bodyText(): string
    {
        return $this->bodyText;
    }

    public function slug(): ?string
    {
        return $this->slug;
    }

    public function seoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function metaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function excerpt(): ?string
    {
        return $this->excerpt;
    }

    public function isPublishable(): bool
    {
        return $this->slug !== null
            && trim($this->h1) !== ''
            && $this->seoTitle !== null
            && trim($this->seoTitle) !== ''
            && $this->metaDescription !== null
            && trim($this->metaDescription) !== ''
            && $this->excerpt !== null
            && trim($this->excerpt) !== ''
            && trim($this->bodyText) !== '';
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'slug' => $this->slug === null ? null : '[redacted]',
            'h1' => '[redacted]',
            'seo_title' => $this->seoTitle === null ? null : '[redacted]',
            'meta_description' => $this->metaDescription === null
                ? null
                : '[redacted]',
            'excerpt' => $this->excerpt === null ? null : '[redacted]',
            'body_text' => '[redacted]',
            'publishable' => $this->isPublishable(),
        ];
    }
}
