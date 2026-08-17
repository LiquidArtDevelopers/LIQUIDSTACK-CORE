<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogCustomTextHtmlSanitizer;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentWalker;

/** Extracts SEO semantics from the validated structured document. */
final class BlogSeoSemanticProjector
{
    public function __construct(
        private readonly BlogSeoTextNormalizer $normalizer =
            new BlogSeoTextNormalizer(),
        private readonly BlogDocumentTextProjector $textProjector =
            new BlogDocumentTextProjector(),
        private readonly BlogCustomTextHtmlSanitizer $customTextHtml =
            new BlogCustomTextHtmlSanitizer()
    ) {
    }

    public function project(BlogDocument $document): BlogSeoSemanticProjection
    {
        $headings = [];
        $images = [];
        foreach ((new BlogDocumentWalker())->modules($document) as $block) {
            if ($block['type'] === 'heading') {
                $headings[] = [
                    'level' => (int) $block['level'],
                    'text' => $this->inline($block['content']),
                ];
            }
            if ($block['type'] === 'paragraph') {
                array_push($headings, ...$this->textHeadings($block));
            }
            if ($block['type'] === 'image') {
                $images[] = [
                    'decorative' => (bool) $block['decorative'],
                    'alt' => (string) $block['alt'],
                ];
            }
        }
        $bodyText = $this->textProjector->project($document);
        $tokens = $this->normalizer->tokens($bodyText);

        return new BlogSeoSemanticProjection(
            $bodyText,
            $tokens,
            array_slice($tokens, 0, 100),
            $headings,
            $images
        );
    }

    /**
     * @param array<string, mixed> $block
     * @return list<array{level: int, text: string}>
     */
    private function textHeadings(array $block): array
    {
        if (array_key_exists('html', $block)) {
            $html = $block['html'] ?? null;

            return is_string($html) ? $this->customTextHtml->headings($html) : [];
        }
        $content = $block['content'] ?? null;
        if (!is_array($content)) {
            return [];
        }
        $headings = [];
        foreach ($content as $flowNode) {
            if (
                !is_array($flowNode)
                || ($flowNode['type'] ?? null) !== 'heading'
                || !is_array($flowNode['content'] ?? null)
            ) {
                continue;
            }
            $headings[] = [
                'level' => (int) ($flowNode['level'] ?? 0),
                'text' => $this->inline($flowNode['content']),
            ];
        }

        return $headings;
    }

    /** @param list<array<string, mixed>> $nodes */
    private function inline(array $nodes): string
    {
        $text = '';
        foreach ($nodes as $node) {
            $text .= ($node['type'] ?? null) === 'break'
                ? ' '
                : (string) ($node['text'] ?? '');
        }

        return trim($text);
    }
}
