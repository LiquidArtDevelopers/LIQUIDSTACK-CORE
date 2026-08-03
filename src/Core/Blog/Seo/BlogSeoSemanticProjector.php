<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;

/** Extracts SEO semantics from the validated structured document. */
final class BlogSeoSemanticProjector
{
    public function __construct(
        private readonly BlogSeoTextNormalizer $normalizer =
            new BlogSeoTextNormalizer(),
        private readonly BlogDocumentTextProjector $textProjector =
            new BlogDocumentTextProjector()
    ) {
    }

    public function project(BlogDocument $document): BlogSeoSemanticProjection
    {
        $headings = [];
        $images = [];
        foreach ($document->blocks() as $block) {
            if ($block['type'] === 'heading') {
                $headings[] = [
                    'level' => (int) $block['level'],
                    'text' => $this->inline($block['content']),
                ];
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
