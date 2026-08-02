<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

/** Deterministic compatibility projection for post_localizations.body_text. */
final class BlogDocumentTextProjector
{
    public function project(BlogDocument $document): string
    {
        $parts = [];
        foreach ($document->blocks() as $block) {
            $text = match ($block['type']) {
                'paragraph', 'heading', 'callout' =>
                    $this->inline($block['content']),
                'list' => $this->listText($block),
                'link', 'cta' => $block['label'],
                'image' => $this->imageText($block),
                'video' => $block['title'],
                default => throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                ),
            };
            $text = trim($text);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $bodyText = implode("\n\n", $parts);
        if (strlen($bodyText) > BlogDocument::MAX_BODY_TEXT_BYTES) {
            throw new BlogDocumentException(
                BlogDocumentException::PROJECTION_TOO_LARGE
            );
        }

        return $bodyText;
    }

    /** @param list<array<string, mixed>> $content */
    private function inline(array $content): string
    {
        $text = '';
        foreach ($content as $node) {
            $text .= $node['type'] === 'break'
                ? "\n"
                : (string) $node['text'];
        }

        return $text;
    }

    /** @param array<string, mixed> $block */
    private function listText(array $block): string
    {
        $items = [];
        foreach ($block['items'] as $index => $item) {
            $prefix = $block['ordered']
                ? (string) ($index + 1) . '. '
                : '- ';
            $items[] = $prefix . trim($this->inline($item['content']));
        }

        return implode("\n", $items);
    }

    /** @param array<string, mixed> $block */
    private function imageText(array $block): string
    {
        if ($block['caption'] !== null) {
            return $block['caption'];
        }

        return $block['decorative'] ? '' : $block['alt'];
    }
}
