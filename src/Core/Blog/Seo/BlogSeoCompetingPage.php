<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

/** Safe candidate projection used only to detect editorial overlap. */
final class BlogSeoCompetingPage
{
    public const BLOG = 'blog';
    public const STATIC_PAGE = 'static';

    public function __construct(
        private readonly string $source,
        private readonly string $locale,
        private readonly string $url,
        private readonly string $h1,
        private readonly ?string $seoTitle = null,
        private readonly ?string $postPublicId = null
    ) {
        if (
            !in_array($source, [self::BLOG, self::STATIC_PAGE], true)
            || preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/', $locale) !== 1
            || !str_starts_with($url, '/')
            || str_starts_with($url, '//')
            || str_contains($url, '?')
            || str_contains($url, '#')
            || str_contains($url, '\\')
            || str_contains($url, '//')
            || preg_match('/%(?:2f|5c)/i', $url) === 1
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1
            || in_array('.', explode('/', trim($url, '/')), true)
            || in_array('..', explode('/', trim($url, '/')), true)
            || strlen($url) > 2_048
            || strlen($h1) > 255
            || ($seoTitle !== null && strlen($seoTitle) > 255)
            || preg_match('//u', $url . $h1 . ($seoTitle ?? '')) !== 1
            || trim($h1) !== $h1
            || $h1 === ''
            || preg_match('/[\x00-\x1F\x7F]/', $h1 . ($seoTitle ?? '')) === 1
            || strip_tags($h1) !== $h1
            || ($seoTitle !== null && (
                trim($seoTitle) !== $seoTitle
                || $seoTitle === ''
                || strip_tags($seoTitle) !== $seoTitle
            ))
            || ($postPublicId !== null && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $postPublicId
            ) !== 1)
        ) {
            throw new \InvalidArgumentException(
                'Invalid Blog SEO competing page.'
            );
        }
    }

    public function source(): string
    {
        return $this->source;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function h1(): string
    {
        return $this->h1;
    }

    public function seoTitle(): ?string
    {
        return $this->seoTitle;
    }

    /** @return array<string, string|null> */
    public function toSafeArray(string $match): array
    {
        if (!in_array($match, ['complete', 'partial'], true)) {
            throw new \InvalidArgumentException(
                'Invalid Blog SEO competition match.'
            );
        }

        return [
            'source' => $this->source,
            'url' => $this->url,
            'h1' => $this->h1,
            'match' => $match,
        ];
    }
}
