<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

/** Immutable image presentation returned by preview or public resolvers. */
final class BlogResolvedImage
{
    public const MAX_CANDIDATES = 8;
    private const UUID_V4_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /** @var list<BlogResolvedImageCandidate> */
    private readonly array $candidates;

    /**
     * @param list<BlogResolvedImageCandidate> $candidates
     */
    public function __construct(
        private readonly string $mediaAssetPublicId,
        array $candidates,
        private readonly int $width,
        private readonly int $height
    ) {
        if (
            preg_match(self::UUID_V4_PATTERN, $mediaAssetPublicId) !== 1
            || $width < 1
            || $width > BlogResolvedImageCandidate::MAX_WIDTH
            || $height < 1
            || $height > BlogResolvedImageCandidate::MAX_WIDTH
            || $candidates === []
            || count($candidates) > self::MAX_CANDIDATES
            || !array_is_list($candidates)
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_IMAGE_PRESENTATION
            );
        }

        $byWidth = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof BlogResolvedImageCandidate) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_IMAGE_PRESENTATION
                );
            }
            if (
                $candidate->width() > $width
                || isset($byWidth[$candidate->width()])
            ) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_IMAGE_PRESENTATION
                );
            }
            $byWidth[$candidate->width()] = $candidate;
        }

        ksort($byWidth, SORT_NUMERIC);
        $normalized = array_values($byWidth);
        $largest = $normalized[count($normalized) - 1];
        if ($largest->width() !== $width) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_IMAGE_PRESENTATION
            );
        }

        $this->candidates = $normalized;
    }

    public function mediaAssetPublicId(): string
    {
        return $this->mediaAssetPublicId;
    }

    /** @return list<BlogResolvedImageCandidate> */
    public function candidates(): array
    {
        return $this->candidates;
    }

    public function sourceUrl(): string
    {
        return $this->candidates[count($this->candidates) - 1]->url();
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }
}
