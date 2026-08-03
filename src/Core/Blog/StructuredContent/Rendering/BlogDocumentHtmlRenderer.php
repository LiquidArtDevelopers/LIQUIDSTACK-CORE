<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use Throwable;

/** Pure, CSP-neutral renderer for Blog document v1 HTML projections. */
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
        return $this->renderBody($document, false);
    }

    /**
     * Semantic body for new public compositions. Unlike render(), retained
     * for compatibility, this projection omits the featured header medium.
     */
    public function renderMain(BlogDocument $document): string
    {
        return $this->renderBody($document, true);
    }

    private function renderBody(
        BlogDocument $document,
        bool $omitHeaderMedia
    ): string
    {
        $templateClass = self::TEMPLATE_CLASSES[$document->template()] ?? null;
        if ($templateClass === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        $blocks = $document->blocks();
        if (
            $omitHeaderMedia
            && $document->template()
                === BlogDocumentTemplateRegistry::ARTICLE_COVER
        ) {
            array_shift($blocks);
        }

        $html = '<div class="blogDocument ' . $templateClass . '">';
        $sectionOpen = false;
        $articleOpen = false;
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'heading') {
                $level = (int) ($block['level'] ?? 0);

                if ($level === 2) {
                    if ($articleOpen) {
                        $html .= '</article>';
                        $articleOpen = false;
                    }
                    if ($sectionOpen) {
                        $html .= '</section>';
                    }

                    $html .= '<section class="blogDocument__section"'
                        . ' aria-labelledby="' . $this->blockId($block['id'])
                        . '">' . $this->renderHeading($block);
                    $sectionOpen = true;
                    continue;
                }

                if ($level === 3) {
                    if (!$sectionOpen) {
                        throw new BlogRenderingException(
                            BlogRenderingException::INVALID_RENDER_STATE
                        );
                    }
                    if ($articleOpen) {
                        $html .= '</article>';
                    }

                    $html .= '<article class="blogDocument__article"'
                        . ' aria-labelledby="' . $this->blockId($block['id'])
                        . '">' . $this->renderHeading($block);
                    $articleOpen = true;
                    continue;
                }

                if (!$sectionOpen || !$articleOpen) {
                    throw new BlogRenderingException(
                        BlogRenderingException::INVALID_RENDER_STATE
                    );
                }
            }

            $html .= $this->renderBlock($block);
        }

        if ($articleOpen) {
            $html .= '</article>';
        }
        if ($sectionOpen) {
            $html .= '</section>';
        }

        return $html . '</div>';
    }

    /**
     * Renders the optional featured medium for the page header. It is kept
     * separate from render() so the public body always starts with its own
     * section hierarchy and the cover is never duplicated.
     */
    public function renderHeaderMedia(BlogDocument $document): string
    {
        if ($document->template() !== BlogDocumentTemplateRegistry::ARTICLE_COVER) {
            return '';
        }
        $cover = $document->blocks()[0] ?? null;
        if (
            !is_array($cover)
            || ($cover['type'] ?? null) !== 'image'
            || ($cover['display'] ?? null) !== 'cover'
        ) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }

        return $this->renderImage($cover);
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
        $level = $block['level'] ?? null;
        if (!is_int($level) || $level < 2 || $level > 6) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_RENDER_STATE
            );
        }
        $tag = 'h' . $level;

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
