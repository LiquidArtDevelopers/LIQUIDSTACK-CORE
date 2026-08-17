<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\PublicDelivery\BlogPublicMediaRoute;
use InvalidArgumentException;

/** ID-free presentation value for one responsive public card thumbnail. */
final class BlogPublicCardThumbnail
{
    private const FALLBACK_TARGET_WIDTH = 900;
    private const MAX_ALT_BYTES = 1_000;
    private const MAX_CANDIDATES = 8;

    /** @var list<BlogPublicCardThumbnailCandidate> */
    private readonly array $candidates;
    private readonly BlogPublicCardThumbnailCandidate $fallback;

    /** @param list<BlogPublicCardThumbnailCandidate> $candidates */
    public function __construct(
        private readonly string $alt,
        array $candidates
    ) {
        if (
            $candidates === []
            || count($candidates) > self::MAX_CANDIDATES
            || !array_is_list($candidates)
            || strlen($alt) > self::MAX_ALT_BYTES
            || preg_match('//u', $alt) !== 1
            || preg_match('/\p{Cc}/u', $alt) === 1
        ) {
            throw new InvalidArgumentException(
                'Invalid public Blog card thumbnail.'
            );
        }

        $previousWidth = 0;
        $fallback = null;
        $assetPublicId = null;
        $baseWidth = null;
        $baseHeight = null;
        foreach ($candidates as $candidate) {
            if (
                !$candidate instanceof BlogPublicCardThumbnailCandidate
                || $candidate->width() <= $previousWidth
            ) {
                throw new InvalidArgumentException(
                    'Invalid public Blog card thumbnail.'
                );
            }
            $route = BlogPublicMediaRoute::match($candidate->url());
            if (
                $route === null
                || ($assetPublicId !== null
                    && $route['public_id'] !== $assetPublicId)
            ) {
                throw new InvalidArgumentException(
                    'Invalid public Blog card thumbnail.'
                );
            }
            $assetPublicId ??= $route['public_id'];
            $baseWidth ??= $candidate->width();
            $baseHeight ??= $candidate->height();
            $expected = $candidate->width() * $baseHeight;
            $actual = $baseWidth * $candidate->height();
            if (abs($expected - $actual) * 100 > max(1, $expected)) {
                throw new InvalidArgumentException(
                    'Invalid public Blog card thumbnail.'
                );
            }
            if ($candidate->width() <= self::FALLBACK_TARGET_WIDTH) {
                $fallback = $candidate;
            }
            $previousWidth = $candidate->width();
        }

        if ($fallback === null) {
            throw new InvalidArgumentException(
                'A public Blog thumbnail needs a small AVIF candidate.'
            );
        }
        $this->candidates = $candidates;
        $this->fallback = $fallback;
    }

    /**
     * @return array{
     *   src:string,
     *   srcset:string,
     *   alt:string,
     *   width:int,
     *   height:int
     * }
     */
    public function toResourceData(): array
    {
        return [
            'src' => $this->fallback->url(),
            'srcset' => implode(', ', array_map(
                static fn (BlogPublicCardThumbnailCandidate $candidate): string =>
                    $candidate->url() . ' ' . $candidate->width() . 'w',
                $this->candidates
            )),
            'alt' => $this->alt,
            'width' => $this->fallback->width(),
            'height' => $this->fallback->height(),
        ];
    }
}
