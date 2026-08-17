<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

/** Deterministic compatibility projection for post_localizations.body_text. */
final class BlogDocumentTextProjector
{
    public function __construct(
        private readonly BlogDocumentWalker $walker = new BlogDocumentWalker(),
        private readonly BlogCustomTextHtmlSanitizer $customTextHtml =
            new BlogCustomTextHtmlSanitizer()
    ) {
    }

    public function project(BlogDocument $document): string
    {
        $parts = [];
        foreach ($this->walker->modules($document) as $block) {
            $text = match ($block['type']) {
                'paragraph' => $this->paragraphText($block),
                'heading', 'callout' => $this->inline($block['content']),
                'quote' => $this->quoteText($block),
                'embed' => $this->embedText($block),
                'list' => $this->listText($block),
                'link', 'cta' => $block['label'],
                'image' => $this->imageText($block),
                'video' => $block['title'],
                'separator' => '',
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
    private function paragraphText(array $block): string
    {
        if (array_key_exists('html', $block)) {
            $html = $block['html'] ?? null;
            if (!is_string($html)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }

            return $html === '' ? '' : $this->customTextHtml->plainText($html);
        }
        $content = $block['content'];
        if (!$this->isTextFlow($content)) {
            return $this->inline($content);
        }

        $parts = [];
        foreach ($content as $flowNode) {
            $type = $flowNode['type'] ?? null;
            if ($type === 'break') {
                continue;
            }
            if ($type !== 'list') {
                $text = trim(
                    $type === 'quote'
                        ? $this->quoteText($flowNode)
                        : $this->inline($flowNode['content'])
                );
                if ($text !== '') {
                    $parts[] = $text;
                }
                continue;
            }
            $items = [];
            foreach (($flowNode['items'] ?? []) as $item) {
                $text = trim($this->inline($item['content']));
                if ($text === '') {
                    continue;
                }
                $prefix = ($flowNode['ordered'] ?? false)
                    ? (string) (count($items) + 1) . '. '
                    : '- ';
                $items[] = $prefix . $text;
            }
            if ($items !== []) {
                $parts[] = implode("\n", $items);
            }
        }

        return implode("\n\n", $parts);
    }

    /** @param list<array<string, mixed>> $content */
    private function isTextFlow(array $content): bool
    {
        foreach ($content as $node) {
            $type = $node['type'] ?? null;
            if ($type === 'break') {
                continue;
            }

            return in_array(
                $type,
                ['paragraph', 'heading', 'list', 'quote', 'callout'],
                true
            );
        }

        return true;
    }

    /** @param array<string, mixed> $block */
    private function listText(array $block): string
    {
        $items = [];
        foreach ($block['items'] as $item) {
            $text = trim($this->inline($item['content']));
            if ($text === '') {
                continue;
            }
            $prefix = $block['ordered']
                ? (string) (count($items) + 1) . '. '
                : '- ';
            $items[] = $prefix . $text;
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

    /** @param array<string, mixed> $block */
    private function quoteText(array $block): string
    {
        $text = $this->inline($block['content']);
        $credit = [];
        if (($block['author'] ?? null) !== null) {
            $credit[] = $block['author'];
        }
        if (($block['source'] ?? null) !== null) {
            $credit[] = $block['source'];
        }

        return $credit === []
            ? $text
            : $text . "\n" . implode(', ', $credit);
    }

    /** @param array<string, mixed> $block */
    private function embedText(array $block): string
    {
        if ($block['caption'] !== null) {
            return $block['caption'];
        }
        $text = html_entity_decode(
            strip_tags($block['html']),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $text = preg_replace('/\s+/u', ' ', $text);
        if (!is_string($text) || trim($text) === '') {
            return 'Contenido multimedia incrustado';
        }

        return trim($text);
    }
}
