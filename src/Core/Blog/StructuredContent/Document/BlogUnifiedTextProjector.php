<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

/**
 * Pure, in-memory normalization of deprecated V2 modules.
 *
 * Each historical wrapper remains a separate module so its UUID,
 * presentation, position and container ownership stay stable. Immutable
 * revisions are never touched; callers decide whether the projected snapshot
 * is persisted as a new revision.
 */
final class BlogUnifiedTextProjector
{
    private readonly BlogDocumentValidator $draftValidator;

    public function __construct(
        private readonly BlogDocumentTextProjector $textProjector =
            new BlogDocumentTextProjector(),
        ?BlogDocumentValidator $validator = null
    ) {
        $this->draftValidator = ($validator ?? new BlogDocumentValidator())
            ->forDrafts();
    }

    public function project(BlogDocument $document): BlogDocument
    {
        if ($document->version() !== BlogDocument::LAYOUT_VERSION) {
            throw new BlogDocumentException(
                BlogDocumentException::UNSUPPORTED_SCHEMA
            );
        }

        $data = $document->toArray();
        $blocks = array_map(
            fn (array $node): array => $this->node($node),
            $document->blocks()
        );
        if ($blocks === $document->blocks()) {
            return $document;
        }

        $before = $this->textProjector->project($document);
        $data['blocks'] = $blocks;
        $projected = BlogDocument::fromArray($data, $this->draftValidator);
        if ($this->textProjector->project($projected) !== $before) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }

        return $projected;
    }

    /** @param array<string, mixed> $node @return array<string, mixed> */
    private function node(array $node): array
    {
        $type = $node['type'] ?? null;
        if ($type === 'section') {
            $node['children'] = array_map(
                fn (array $child): array => $this->node($child),
                $node['children']
            );

            return $node;
        }
        if (in_array($type, ['article', 'div'], true)) {
            foreach ($node['layout']['columns'] as $index => $column) {
                $column['children'] = array_map(
                    fn (array $child): array => $this->node($child),
                    $column['children']
                );
                $node['layout']['columns'][$index] = $column;
            }

            return $node;
        }

        return $this->module($node);
    }

    /** @param array<string, mixed> $module @return array<string, mixed> */
    private function module(array $module): array
    {
        $type = $module['type'] ?? null;
        if ($type === 'link') {
            $module['type'] = 'cta';
            $module['variant'] = 'primary';
            if (
                is_array($module['presentation'] ?? null)
                && ($module['presentation']['text_align'] ?? null) === 'justify'
            ) {
                $module['presentation']['text_align'] = 'start';
            }

            return $module;
        }
        if ($type === 'paragraph') {
            if (
                array_key_exists('html', $module)
                || $this->isTextFlow($module['content'])
            ) {
                return $module;
            }
            $module['content'] = [[
                'type' => 'paragraph',
                'content' => $module['content'],
            ]];

            return $module;
        }
        if (!in_array($type, ['heading', 'list', 'quote', 'callout'], true)) {
            return $module;
        }

        return [
            'id' => $module['id'],
            'type' => 'paragraph',
            'content' => [$this->flowNode($module)],
            'presentation' => $this->paragraphPresentation(
                $module['presentation']
            ),
        ];
    }

    /** @param array<string, mixed> $module @return array<string, mixed> */
    private function flowNode(array $module): array
    {
        return match ($module['type']) {
            'heading' => array_filter([
                'type' => 'heading',
                'level' => $module['level'],
                'content' => $module['content'],
                'preset' => $module['preset'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'list' => array_filter([
                'type' => 'list',
                'ordered' => $module['ordered'],
                'items' => $module['items'],
                'marker' => $module['marker'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'quote' => [
                'type' => 'quote',
                'content' => $module['content'],
                'author' => $module['author'],
                'source' => $module['source'],
                'preset' => $module['preset'],
            ],
            'callout' => [
                'type' => 'callout',
                'content' => $module['content'],
                'tone' => $module['tone'],
            ],
            default => throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            ),
        };
    }

    /** @param mixed $content */
    private function isTextFlow(mixed $content): bool
    {
        if (!is_array($content) || !array_is_list($content)) {
            return false;
        }
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

    /**
     * A standalone list historically allowed a text color without the other
     * Texto typography keys. Complete only those missing neutral values while
     * preserving every existing presentation value verbatim.
     *
     * @param array<string, mixed> $presentation
     * @return array<string, mixed>
     */
    private function paragraphPresentation(array $presentation): array
    {
        if (
            array_key_exists('text_color', $presentation)
            && !array_key_exists('font_weight', $presentation)
        ) {
            if (
                !array_key_exists('size', $presentation)
                && !array_key_exists('font_size', $presentation)
            ) {
                $presentation['size'] = 'm';
            }
            $presentation['font_weight'] = 'default';
        }

        return $presentation;
    }
}
