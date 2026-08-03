<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use Throwable;

/** Pure, CSP-neutral renderer for the body fragment of Blog document v1. */
final class BlogDocumentHtmlRenderer
{
    private const TEMPLATE_CLASSES = [
        BlogDocumentTemplateRegistry::ARTICLE_BASIC => 'blogDocument--basic',
        BlogDocumentTemplateRegistry::ARTICLE_COVER => 'blogDocument--cover',
    ];
    private const CALLOUT_CLASSES = [
        'neutral' => 'blogDocument__callout--neutral',
        'info' => 'blogDocument__callout--info',
        'warning' => 'blogDocument__callout--warning',
    ];
    private const IMAGE_CLASSES = [
        'content' => 'blogDocument__image--content',
        'wide' => 'blogDocument__image--wide',
        'cover' => 'blogDocument__image--cover',
    ];
    private const IMAGE_SIZES = [
        'content' => '(max-width: 48rem) 100vw, 48rem',
        'wide' => '(max-width: 72rem) 100vw, 72rem',
        'cover' => '100vw',
    ];
    private const CTA_CLASSES = [
        'primary' => 'blogDocument__cta--primary',
        'secondary' => 'blogDocument__cta--secondary',
    ];

    public function __construct(
        private readonly BlogImageResolverInterface $imageResolver
    ) {
    }

    public function render(BlogDocument $document): string
    {
        $templateClass = self::TEMPLATE_CLASSES[$document->template()] ?? null;
        if ($templateClass === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        $html = '<div class="blogDocument ' . $templateClass . '">';
        foreach ($document->blocks() as $block) {
            $html .= $this->renderBlock($block);
        }

        return $html . '</div>';
    }

    /** @param array<string, mixed> $block */
    private function renderBlock(array $block): string
    {
        return match ($block['type']) {
            'paragraph' => $this->renderParagraph($block),
            'heading' => $this->renderHeading($block),
            'list' => $this->renderList($block),
            'callout' => $this->renderCallout($block),
            'link' => $this->renderStandaloneLink($block),
            'image' => $this->renderImage($block),
            'video' => $this->renderYoutube($block),
            'cta' => $this->renderCta($block),
            default => throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            ),
        };
    }

    /** @param array<string, mixed> $block */
    private function renderParagraph(array $block): string
    {
        return '<p id="' . $this->blockId($block['id'])
            . '" class="blogDocument__paragraph">'
            . $this->renderInline($block['content']) . '</p>';
    }

    /** @param array<string, mixed> $block */
    private function renderHeading(array $block): string
    {
        $tag = match ($block['level']) {
            2 => 'h2',
            3 => 'h3',
            default => throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            ),
        };

        return '<' . $tag . ' id="' . $this->blockId($block['id'])
            . '" class="blogDocument__heading">'
            . $this->renderInline($block['content'])
            . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $block */
    private function renderList(array $block): string
    {
        $tag = match ($block['ordered']) {
            true => 'ol',
            false => 'ul',
            default => throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            ),
        };
        $html = '<' . $tag . ' id="' . $this->blockId($block['id'])
            . '" class="blogDocument__list">';
        foreach ($block['items'] as $item) {
            $html .= '<li id="blog-item-' . $this->escape($item['id'])
                . '" class="blogDocument__listItem">'
                . $this->renderInline($item['content']) . '</li>';
        }

        return $html . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $block */
    private function renderCallout(array $block): string
    {
        $toneClass = self::CALLOUT_CLASSES[$block['tone']] ?? null;
        if ($toneClass === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        return '<aside id="' . $this->blockId($block['id'])
            . '" class="blogDocument__callout ' . $toneClass
            . '" role="note"><p class="blogDocument__calloutContent">'
            . $this->renderInline($block['content']) . '</p></aside>';
    }

    /** @param array<string, mixed> $block */
    private function renderStandaloneLink(array $block): string
    {
        return '<p id="' . $this->blockId($block['id'])
            . '" class="blogDocument__standaloneLink">'
            . $this->renderAnchor(
                $block['label'],
                $block['href'],
                $block['title'],
                $block['target'],
                'blogDocument__standaloneLinkAnchor'
            )
            . '</p>';
    }

    /** @param array<string, mixed> $block */
    private function renderImage(array $block): string
    {
        $displayClass = self::IMAGE_CLASSES[$block['display']] ?? null;
        $sizes = self::IMAGE_SIZES[$block['display']] ?? null;
        if ($displayClass === null || $sizes === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        $image = $this->resolveImage($block['media_asset_public_id']);
        $srcset = implode(', ', array_map(
            fn (BlogResolvedImageCandidate $candidate): string =>
                $this->escape($candidate->url()) . ' ' . $candidate->width() . 'w',
            $image->candidates()
        ));
        $title = $block['title'] === null
            ? ''
            : ' title="' . $this->escape($block['title']) . '"';
        $loading = $block['display'] === 'cover'
            ? ' loading="eager" fetchpriority="high" decoding="async"'
            : ' loading="lazy" decoding="async"';

        $html = '<figure id="' . $this->blockId($block['id'])
            . '" class="blogDocument__image ' . $displayClass . '">'
            . '<picture class="blogDocument__picture">'
            . '<source type="image/avif" srcset="' . $srcset
            . '" sizes="' . $sizes . '">'
            . '<img class="blogDocument__imageElement" src="'
            . $this->escape($image->sourceUrl()) . '" width="'
            . $image->width() . '" height="' . $image->height()
            . '" alt="' . $this->escape($block['alt']) . '"'
            . $title . $loading . '>'
            . '</picture>';
        if ($block['caption'] !== null) {
            $html .= '<figcaption class="blogDocument__imageCaption">'
                . $this->escape($block['caption']) . '</figcaption>';
        }

        return $html . '</figure>';
    }

    /** @param array<string, mixed> $block */
    private function renderYoutube(array $block): string
    {
        $captionId = 'blog-video-caption-' . $this->escape($block['id']);
        $watchUrl = 'https://www.youtube.com/watch?v=' . $block['video_id'];
        if ($block['start_seconds'] > 0) {
            $watchUrl .= '&t=' . $block['start_seconds'] . 's';
        }

        return '<figure id="' . $this->blockId($block['id'])
            . '" class="blogDocument__video">'
            . '<div class="blogDocument__liteYoutube" data-blog-lite-youtube'
            . ' data-video-id="' . $this->escape($block['video_id']) . '"'
            . ' data-start-seconds="' . $block['start_seconds'] . '">'
            . '<a class="blogDocument__videoTrigger" href="'
            . $this->escape($watchUrl)
            . '" target="_blank" rel="noopener noreferrer"'
            . ' aria-labelledby="' . $captionId . '" data-blog-youtube-play>'
            . '<span class="blogDocument__videoPlay" aria-hidden="true">&#9654;</span>'
            . '</a></div>'
            . '<figcaption id="' . $captionId
            . '" class="blogDocument__videoCaption">'
            . $this->escape($block['title']) . '</figcaption></figure>';
    }

    /** @param array<string, mixed> $block */
    private function renderCta(array $block): string
    {
        $variantClass = self::CTA_CLASSES[$block['variant']] ?? null;
        if ($variantClass === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        return '<p id="' . $this->blockId($block['id'])
            . '" class="blogDocument__cta ' . $variantClass . '">'
            . $this->renderAnchor(
                $block['label'],
                $block['href'],
                $block['title'],
                $block['target'],
                'blogDocument__ctaLink'
            )
            . '</p>';
    }

    /** @param list<array<string, mixed>> $content */
    private function renderInline(array $content): string
    {
        $html = '';
        foreach ($content as $node) {
            if ($node['type'] === 'break') {
                $html .= '<br>';
                continue;
            }
            if (!in_array($node['type'], ['text', 'link'], true)) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }

            $text = $this->escape($node['text']);
            foreach (array_reverse($node['marks']) as $mark) {
                $tag = match ($mark) {
                    'strong' => 'strong',
                    'em' => 'em',
                    default => throw new BlogRenderingException(
                        BlogRenderingException::INVALID_RENDER_STATE
                    ),
                };
                $text = '<' . $tag . '>' . $text . '</' . $tag . '>';
            }
            if ($node['type'] === 'link') {
                $text = $this->renderAnchor(
                    $text,
                    $node['href'],
                    $node['title'],
                    $node['target'],
                    'blogDocument__inlineLink',
                    false
                );
            }
            $html .= $text;
        }

        return $html;
    }

    private function renderAnchor(
        string $label,
        string $href,
        ?string $title,
        string $target,
        string $className,
        bool $escapeLabel = true
    ): string {
        if (!in_array($target, ['same', 'new'], true)) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $attributes = ' class="' . $className . '" href="'
            . $this->escape($href) . '"';
        if ($title !== null) {
            $attributes .= ' title="' . $this->escape($title) . '"';
        }
        if ($target === 'new') {
            $attributes .= ' target="_blank" rel="noopener noreferrer"';
        }

        return '<a' . $attributes . '>'
            . ($escapeLabel ? $this->escape($label) : $label)
            . '</a>';
    }

    private function resolveImage(string $mediaAssetPublicId): BlogResolvedImage
    {
        try {
            $image = $this->imageResolver->resolve($mediaAssetPublicId);
        } catch (Throwable) {
            throw new BlogRenderingException(
                BlogRenderingException::MEDIA_UNAVAILABLE
            );
        }
        if (
            !$image instanceof BlogResolvedImage
            || $image->mediaAssetPublicId() !== $mediaAssetPublicId
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::MEDIA_UNAVAILABLE
            );
        }

        return $image;
    }

    private function blockId(string $id): string
    {
        return 'blog-block-' . $this->escape($id);
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
