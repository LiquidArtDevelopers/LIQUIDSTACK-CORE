<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use App\Core\Blog\StructuredContent\Presentation\BlogImagePresentationPolicy;
use App\Core\Blog\StructuredContent\Presentation\BlogImageRadiusPreset;
use App\Core\Blog\StructuredContent\Presentation\BlogPublicColor;
use InvalidArgumentException;

/**
 * Typed, immutable projection of the featured image used by a Blog header.
 */
final class BlogArticleHeaderMedia
{
    private const UUID_V4_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const RADIUS_CLASSES = [
        BlogImageRadiusPreset::NONE => 'blogDocument__image--radius-none',
        BlogImageRadiusPreset::SMALL => 'blogDocument__image--radius-small',
        BlogImageRadiusPreset::MEDIUM => 'blogDocument__image--radius-medium',
        BlogImageRadiusPreset::LARGE => 'blogDocument__image--radius-large',
    ];
    private const OBJECT_FIT_CLASSES = [
        'cover' => 'blogDocument__image--fit-cover',
        'contain' => 'blogDocument__image--fit-contain',
    ];
    private const OBJECT_POSITION_CLASSES = [
        'top' => 'blogDocument__image--position-y-top',
        'center' => 'blogDocument__image--position-y-center',
        'bottom' => 'blogDocument__image--position-y-bottom',
    ];
    private const OVERLAY_MODE_CLASSES = [
        'normal' => 'blogDocument__image--overlay-normal',
        'multiply' => 'blogDocument__image--overlay-multiply',
        'screen' => 'blogDocument__image--overlay-screen',
        'overlay' => 'blogDocument__image--overlay-overlay',
    ];

    public function __construct(
        private readonly BlogResolvedImage $image,
        private readonly string $blockId,
        private readonly string $alt,
        private readonly ?string $title,
        private readonly ?string $caption,
        private readonly ?string $radius,
        private readonly string $sizes = '100vw',
        private readonly ?int $radiusPercent = null,
        private readonly ?int $heightDvh = null,
        private readonly string $objectFit =
            BlogImagePresentationPolicy::DEFAULT_OBJECT_FIT,
        private readonly string $objectPositionY =
            BlogImagePresentationPolicy::DEFAULT_OBJECT_POSITION_Y,
        private readonly ?string $overlayMode = null,
        private readonly ?string $overlayColor = null,
        private readonly ?int $overlayOpacity = null
    ) {
        $overlayValues = [
            $overlayMode,
            $overlayColor,
            $overlayOpacity,
        ];
        $overlayCount = count(array_filter(
            $overlayValues,
            static fn (mixed $value): bool => $value !== null
        ));
        $canonicalOverlayColor = null;
        if ($overlayColor !== null) {
            try {
                $canonicalOverlayColor = BlogPublicColor::fromInput(
                    $overlayColor
                )->value();
            } catch (InvalidArgumentException) {
                $canonicalOverlayColor = null;
            }
        }
        if (
            preg_match(self::UUID_V4_PATTERN, $blockId) !== 1
            || preg_match('//u', $alt) !== 1
            || preg_match('/\p{Cc}/u', $alt) === 1
            || (
                $title !== null
                && (
                    preg_match('//u', $title) !== 1
                    || preg_match('/\p{Cc}/u', $title) === 1
                )
            )
            || (
                $caption !== null
                && (
                    preg_match('//u', $caption) !== 1
                    || preg_match('/\p{Cc}/u', $caption) === 1
                )
            )
            || (($radius === null) === ($radiusPercent === null))
            || (
                $radius !== null
                && !isset(self::RADIUS_CLASSES[$radius])
            )
            || (
                $radiusPercent !== null
                && !BlogImagePresentationPolicy::supportsRadiusPercent(
                    $radiusPercent
                )
            )
            || (
                $heightDvh !== null
                && !BlogImagePresentationPolicy::supportsHeightDvh(
                    $heightDvh
                )
            )
            || !BlogImagePresentationPolicy::supportsObjectFit($objectFit)
            || !BlogImagePresentationPolicy::supportsObjectPositionY(
                $objectPositionY
            )
            || ($overlayCount !== 0 && $overlayCount !== 3)
            || ($overlayCount === 3 && (
                !BlogImagePresentationPolicy::supportsOverlayMode(
                    $overlayMode
                )
                || $canonicalOverlayColor !== $overlayColor
                || !BlogImagePresentationPolicy::supportsOverlayOpacity(
                    $overlayOpacity
                )
            ))
            || $sizes !== '100vw'
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_IMAGE_PRESENTATION
            );
        }
    }

    public function image(): BlogResolvedImage
    {
        return $this->image;
    }

    public function alt(): string
    {
        return $this->alt;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function sizes(): string
    {
        return $this->sizes;
    }

    public function objectFit(): string
    {
        return $this->objectFit;
    }

    /** Shared focal-axis value for the future Hero presentation controls. */
    public function objectPositionY(): string
    {
        return $this->objectPositionY;
    }

    public function html(): string
    {
        $srcset = implode(', ', array_map(
            fn (BlogResolvedImageCandidate $candidate): string =>
                $this->escape($candidate->url()) . ' '
                    . $candidate->width() . 'w',
            $this->image->candidates()
        ));
        $title = $this->title === null
            ? ''
            : ' title="' . $this->escape($this->title) . '"';
        $radiusClass = $this->radius === null
            ? 'blogDocument__image--radius-percent'
            : self::RADIUS_CLASSES[$this->radius];
        $classes = 'blogDocument__image blogDocument__image--cover '
            . $radiusClass . ' ' . self::OBJECT_FIT_CLASSES[$this->objectFit]
            . ' ' . self::OBJECT_POSITION_CLASSES[$this->objectPositionY];
        $attributes = ' data-blog-image-object-fit="'
            . $this->escape($this->objectFit) . '"'
            . ' data-blog-image-object-position-y="'
            . $this->escape($this->objectPositionY) . '"';
        if ($this->radius !== null) {
            $attributes .= ' data-blog-image-radius="'
                . $this->escape($this->radius) . '"';
        } else {
            $attributes .= ' data-blog-image-radius-percent="'
                . $this->radiusPercent . '"';
        }
        if ($this->heightDvh !== null) {
            $classes .= ' blogDocument__image--height-custom';
            $attributes .= ' data-blog-image-height-dvh="'
                . $this->heightDvh . '"';
        }
        if (
            $this->overlayMode !== null
            && $this->overlayColor !== null
            && $this->overlayOpacity !== null
        ) {
            $classes .= ' blogDocument__image--overlay '
                . self::OVERLAY_MODE_CLASSES[$this->overlayMode];
            $attributes .= ' data-blog-image-overlay-mode="'
                . $this->escape($this->overlayMode) . '"'
                . ' data-blog-image-overlay-color="'
                . $this->escape($this->overlayColor) . '"'
                . ' data-blog-image-overlay-opacity="'
                . $this->overlayOpacity . '"';
        }
        $html = '<figure id="blog-block-' . $this->escape($this->blockId)
            . '" class="' . $classes . '"' . $attributes
            . '><picture class="blogDocument__picture">'
            . '<source type="image/avif" srcset="' . $srcset
            . '" sizes="' . $this->escape($this->sizes) . '">'
            . '<img class="blogDocument__imageElement" src="'
            . $this->escape($this->image->sourceUrl()) . '" width="'
            . $this->image->width() . '" height="' . $this->image->height()
            . '" alt="' . $this->escape($this->alt) . '"' . $title
            . ' loading="eager" fetchpriority="high" decoding="async">'
            . '</picture>';
        if ($this->caption !== null) {
            $html .= '<figcaption class="blogDocument__imageCaption">'
                . $this->escape($this->caption) . '</figcaption>';
        }

        return $html . '</figure>';
    }

    public function candidateUrlForWidth(int $targetWidth): string
    {
        if ($targetWidth < 1) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_IMAGE_PRESENTATION
            );
        }
        foreach ($this->image->candidates() as $candidate) {
            if ($candidate->width() >= $targetWidth) {
                return $candidate->url();
            }
        }

        return $this->image->sourceUrl();
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
    }
}
