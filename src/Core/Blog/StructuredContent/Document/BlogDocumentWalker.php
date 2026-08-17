<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

/**
 * Deterministic depth-first projection of validated Blog document nodes.
 *
 * Columns are visited from left to right and each column preserves its own
 * content order. Container and column identifiers never leak into body text
 * or media-reference projections.
 */
final class BlogDocumentWalker
{
    /** @return list<array<string, mixed>> */
    public function modules(BlogDocument $document): array
    {
        if ($document->version() === BlogDocument::VERSION) {
            return $document->blocks();
        }

        $modules = [];
        foreach ($document->blocks() as $node) {
            $this->collectModules($node, $modules);
        }

        return $modules;
    }

    public function nodeCount(BlogDocument $document): int
    {
        if ($document->version() === BlogDocument::VERSION) {
            return count($document->blocks());
        }

        $count = 0;
        foreach ($document->blocks() as $node) {
            $this->countNode($node, $count);
        }

        return $count;
    }

    /** @return ?array<string, mixed> */
    public function cover(BlogDocument $document): ?array
    {
        $first = $document->blocks()[0] ?? null;

        return is_array($first)
            && ($first['type'] ?? null) === 'image'
            && ($first['display'] ?? null) === 'cover'
                ? $first
                : null;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $modules
     */
    private function collectModules(array $node, array &$modules): void
    {
        $type = $node['type'] ?? null;
        if (!in_array($type, ['section', 'article', 'div'], true)) {
            $modules[] = $node;

            return;
        }

        if ($type === 'section') {
            foreach ($node['children'] as $child) {
                $this->collectModules($child, $modules);
            }

            return;
        }

        foreach ($node['layout']['columns'] as $column) {
            foreach ($column['children'] as $child) {
                $this->collectModules($child, $modules);
            }
        }
    }

    /** @param array<string, mixed> $node */
    private function countNode(array $node, int &$count): void
    {
        ++$count;
        $type = $node['type'] ?? null;
        if ($type === 'section') {
            foreach ($node['children'] as $child) {
                $this->countNode($child, $count);
            }

            return;
        }
        if (!in_array($type, ['article', 'div'], true)) {
            return;
        }
        foreach ($node['layout']['columns'] as $column) {
            foreach ($column['children'] as $child) {
                $this->countNode($child, $count);
            }
        }
    }
}
