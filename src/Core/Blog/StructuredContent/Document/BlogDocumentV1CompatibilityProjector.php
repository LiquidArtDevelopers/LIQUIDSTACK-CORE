<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

/**
 * Derives the flat v1 fallback stored beside a canonical layout document.
 *
 * Container boundaries and presentation tokens are intentionally omitted.
 * Heading levels are lowered only when needed to satisfy the historical v1
 * hierarchy; the canonical v2 document and its SSR keep the chosen levels.
 */
final class BlogDocumentV1CompatibilityProjector
{
    public function __construct(
        private readonly BlogCustomTextHtmlSanitizer $customTextHtml =
            new BlogCustomTextHtmlSanitizer()
    ) {
    }

    public function project(BlogDocument $document): BlogDocument
    {
        if ($document->version() === BlogDocument::VERSION) {
            return $document;
        }
        if ($document->version() !== BlogDocument::LAYOUT_VERSION) {
            throw new BlogDocumentException(
                BlogDocumentException::UNSUPPORTED_SCHEMA
            );
        }

        $blocks = [];
        $nodes = $document->blocks();
        if (BlogDocumentTemplateRegistry::hasCover($document->template())) {
            $cover = array_shift($nodes);
            if (!is_array($cover)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_TEMPLATE_CONTRACT
                );
            }
            $coverActive = [];
            $this->appendModule($cover, $blocks, $coverActive);
        }
        foreach ($nodes as $section) {
            $active = [];
            foreach ($section['children'] as $child) {
                if (($child['type'] ?? null) === 'article') {
                    $articleActive = isset($active[2]) ? [2 => true] : [];
                    $this->projectGrid($child, $blocks, $articleActive);
                    continue;
                }
                if (($child['type'] ?? null) === 'div') {
                    $this->projectGrid($child, $blocks, $active);
                    continue;
                }
                $this->appendModule($child, $blocks, $active);
            }
        }

        return BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => $document->template(),
            'blocks' => $blocks,
        ]);
    }

    /**
     * @param array<string, mixed> $container
     * @param list<array<string, mixed>> $blocks
     * @param array<int, true> $active
     */
    private function projectGrid(
        array $container,
        array &$blocks,
        array &$active
    ): void {
        foreach ($container['layout']['columns'] as $column) {
            foreach ($column['children'] as $child) {
                if (($child['type'] ?? null) === 'div') {
                    $this->projectGrid($child, $blocks, $active);
                    continue;
                }
                $this->appendModule($child, $blocks, $active);
            }
        }
    }

    /**
     * @param array<string, mixed> $module
     * @param list<array<string, mixed>> $blocks
     * @param array<int, true> $active
     */
    private function appendModule(
        array $module,
        array &$blocks,
        array &$active
    ): void {
        foreach ($this->expandedModules($module) as $expanded) {
            $projected = $this->module($expanded, $active);
            if ($projected !== null) {
                $blocks[] = $projected;
            }
        }
    }

    /**
     * @param array<string, mixed> $module
     * @return list<array<string, mixed>>
     */
    private function expandedModules(array $module): array
    {
        if (($module['type'] ?? null) !== 'paragraph') {
            return [$module];
        }
        if (array_key_exists('html', $module)) {
            return $this->advancedParagraphFallback($module);
        }
        $content = $module['content'] ?? null;
        if (!is_array($content) || !$this->isTextFlow($content)) {
            return [$module];
        }
        $content = $this->foldRootBreaksForV1($content);
        if ($this->isLegacyTextFlow($content)) {
            $module['content'] = $this->textFlowInlineFallback($content);

            return [$module];
        }

        return $this->textFlowModules($module['id'], $content);
    }

    /**
     * @param array<string, mixed> $module
     * @param array<int, true> $active
     * @return array<string, mixed>|null
     */
    private function module(array $module, array &$active): ?array
    {
        $module = $this->withoutV2InlineMarks($module);
        unset($module['presentation']);
        $type = $module['type'] ?? null;
        if ($type === 'quote') {
            return $this->quoteFallback($module);
        }
        if ($type === 'embed') {
            return $this->embedFallback($module);
        }
        if ($type === 'separator') {
            return null;
        }
        if ($type === 'list') {
            unset($module['marker']);

            $module['items'] = array_values(array_filter(
                $module['items'],
                fn (array $item): bool => $this->hasMeaningfulInline(
                    $item['content']
                )
            ));

            return $module['items'] === [] ? null : $module;
        }
        if ($type === 'cta') {
            if (in_array($module['variant'] ?? null, ['type03', 'type04'], true)) {
                $module['variant'] = $module['variant'] === 'type04'
                    ? 'secondary' : 'primary';
            }

            return $module;
        }
        if (
            in_array($type, ['paragraph', 'heading', 'callout'], true)
            && !$this->hasMeaningfulInline($module['content'])
        ) {
            return null;
        }
        if ($type !== 'heading') {
            return $module;
        }
        unset($module['preset']);

        $level = (int) $module['level'];
        if ($level === 2) {
            $active = [2 => true];
            return $module;
        }
        while ($level > 2 && !isset($active[$level - 1])) {
            --$level;
        }
        foreach (array_keys($active) as $activeLevel) {
            if ($activeLevel >= $level) {
                unset($active[$activeLevel]);
            }
        }
        $active[$level] = true;
        $module['level'] = $level;

        return $module;
    }

    /** @param array<string, mixed> $module @return array<string, mixed> */
    private function withoutV2InlineMarks(array $module): array
    {
        $type = $module['type'] ?? null;
        if (in_array(
            $type,
            ['paragraph', 'heading', 'callout', 'quote'],
            true
        )) {
            $module['content'] = $this->v1InlineContent($module['content']);

            return $module;
        }
        if ($type !== 'list') {
            return $module;
        }
        foreach ($module['items'] as $index => $item) {
            $module['items'][$index]['content'] = $this->v1InlineContent(
                $item['content']
            );
        }

        return $module;
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

    /**
     * V1 has no flow-root break. Preserve its position and multiplicity by
     * folding it into the closest inline-capable neighbour without inventing
     * an empty paragraph.
     *
     * @param list<array<string, mixed>> $content
     * @return array<int, array<string, mixed>>
     */
    private function foldRootBreaksForV1(array $content): array
    {
        foreach ($content as $index => $flowNode) {
            if (($flowNode['type'] ?? null) !== 'break') {
                continue;
            }
            $previous = null;
            for ($candidate = $index - 1; $candidate >= 0; --$candidate) {
                if (
                    isset($content[$candidate])
                    && !in_array(
                        $content[$candidate]['type'] ?? null,
                        ['break', 'heading'],
                        true
                    )
                ) {
                    $previous = $candidate;
                    break;
                }
            }
            $next = null;
            $lastIndex = array_key_last($content);
            for (
                $candidate = $index + 1;
                is_int($lastIndex) && $candidate <= $lastIndex;
                ++$candidate
            ) {
                if (
                    isset($content[$candidate])
                    && !in_array(
                        $content[$candidate]['type'] ?? null,
                        ['break', 'heading'],
                        true
                    )
                ) {
                    $next = $candidate;
                    break;
                }
            }
            if ($next !== null) {
                $this->injectV1FlowBreak($content[$next], true);
            } elseif ($previous !== null) {
                $this->injectV1FlowBreak($content[$previous], false);
            }
            unset($content[$index]);
        }

        return $content;
    }

    /** @param array<string, mixed> $flowNode */
    private function injectV1FlowBreak(array &$flowNode, bool $prepend): void
    {
        if (($flowNode['type'] ?? null) === 'list') {
            $items = &$flowNode['items'];
            if (!is_array($items) || $items === []) {
                return;
            }
            $itemIndex = $prepend ? array_key_first($items) : array_key_last($items);
            if ($itemIndex === null || !is_array($items[$itemIndex]['content'] ?? null)) {
                return;
            }
            $inline = &$items[$itemIndex]['content'];
        } else {
            if (!is_array($flowNode['content'] ?? null)) {
                return;
            }
            $inline = &$flowNode['content'];
        }
        if ($prepend) {
            array_unshift($inline, ['type' => 'break']);
        } else {
            $inline[] = ['type' => 'break'];
        }
    }

    /** @param list<array<string, mixed>> $content */
    private function isLegacyTextFlow(array $content): bool
    {
        foreach ($content as $flowNode) {
            if (!in_array($flowNode['type'] ?? null, ['paragraph', 'list'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Preserves the exact historical p/list-only V1 compatibility shape.
     *
     * @param list<array<string, mixed>> $content
     * @return list<array<string, mixed>>
     */
    private function textFlowInlineFallback(array $content): array
    {
        $inline = [];
        foreach ($content as $flowNode) {
            $part = [];
            if (($flowNode['type'] ?? null) === 'paragraph') {
                $part = $this->trimInlineContent($flowNode['content']);
            } elseif (($flowNode['type'] ?? null) === 'list') {
                $position = 0;
                foreach ($flowNode['items'] as $item) {
                    $itemContent = $this->trimInlineContent(
                        $item['content']
                    );
                    if ($itemContent === []) {
                        continue;
                    }
                    if ($part !== []) {
                        $part[] = ['type' => 'break'];
                    }
                    ++$position;
                    $part[] = [
                        'type' => 'text',
                        'text' => ($flowNode['ordered']
                            ? (string) $position . '. '
                            : '- '),
                        'marks' => [],
                    ];
                    array_push($part, ...$itemContent);
                }
            }
            if ($part === []) {
                continue;
            }
            if ($inline !== []) {
                $inline[] = ['type' => 'break'];
                $inline[] = ['type' => 'break'];
            }
            array_push($inline, ...$part);
        }

        return $inline;
    }

    /**
     * Mirrors trim(inline(content)) without flattening links or marks.
     *
     * @param list<array<string, mixed>> $content
     * @return list<array<string, mixed>>
     */
    private function trimInlineContent(array $content): array
    {
        while ($content !== []) {
            $node = $content[0];
            if (($node['type'] ?? null) === 'break') {
                array_shift($content);
                continue;
            }
            $node['text'] = ltrim((string) ($node['text'] ?? ''));
            if ($node['text'] === '') {
                array_shift($content);
                continue;
            }
            $content[0] = $node;
            break;
        }

        while ($content !== []) {
            $last = count($content) - 1;
            $node = $content[$last];
            if (($node['type'] ?? null) === 'break') {
                array_pop($content);
                continue;
            }
            $node['text'] = rtrim((string) ($node['text'] ?? ''));
            if ($node['text'] === '') {
                array_pop($content);
                continue;
            }
            $content[$last] = $node;
            break;
        }

        return array_values($content);
    }

    /**
     * @param list<array<string, mixed>> $content
     * @return list<array<string, mixed>>
     */
    private function textFlowModules(string $moduleId, array $content): array
    {
        $modules = [];
        foreach ($content as $index => $flowNode) {
            $id = $index === 0
                ? $moduleId
                : $this->derivedId($moduleId, 'flow-' . $index);
            $type = $flowNode['type'] ?? null;
            if ($type === 'list') {
                $items = [];
                foreach ($flowNode['items'] as $itemIndex => $item) {
                    $items[] = [
                        'id' => $item['id'] ?? $this->derivedId(
                            $moduleId,
                            'flow-' . $index . '-item-' . $itemIndex
                        ),
                        'content' => $item['content'],
                    ];
                }
                $list = [
                    'id' => $id,
                    'type' => 'list',
                    'ordered' => $flowNode['ordered'],
                    'items' => $items,
                ];
                if (array_key_exists('marker', $flowNode)) {
                    $list['marker'] = $flowNode['marker'];
                }
                $modules[] = $list;
                continue;
            }
            if ($type === 'heading') {
                $heading = [
                    'id' => $id,
                    'type' => 'heading',
                    'level' => $flowNode['level'],
                    'content' => $flowNode['content'],
                ];
                if (array_key_exists('preset', $flowNode)) {
                    $heading['preset'] = $flowNode['preset'];
                }
                $modules[] = $heading;
                continue;
            }
            if ($type === 'quote') {
                $fallback = $this->quoteFallback([
                    'id' => $id,
                    'type' => 'quote',
                    'content' => $flowNode['content'],
                    'author' => $flowNode['author'] ?? null,
                    'source' => $flowNode['source'] ?? null,
                    'preset' => $flowNode['preset'] ?? 'default',
                ]);
                if ($fallback !== null) {
                    $modules[] = $fallback;
                }
                continue;
            }
            if ($type === 'callout') {
                $modules[] = [
                    'id' => $id,
                    'type' => 'callout',
                    'tone' => $flowNode['tone'] ?? 'neutral',
                    'content' => $flowNode['content'],
                ];
                continue;
            }
            if ($type === 'paragraph') {
                $modules[] = [
                    'id' => $id,
                    'type' => 'paragraph',
                    'content' => $flowNode['content'],
                ];
            }
        }

        return $modules;
    }

    /**
     * @param list<array<string, mixed>> $content
     * @return list<array<string, mixed>>
     */
    private function v1InlineContent(array $content): array
    {
        $normalized = [];
        foreach ($content as $node) {
            if (
                in_array($node['type'] ?? null, ['text', 'link'], true)
                && ($node['text'] ?? null) === ''
            ) {
                continue;
            }
            if (array_key_exists('marks', $node)) {
                $node['marks'] = array_values(array_filter(
                    $node['marks'],
                    static fn (mixed $mark): bool => in_array(
                        $mark,
                        ['strong', 'em'],
                        true
                    )
                ));
            }
            $normalized[] = $node;
        }

        return $normalized;
    }

    /** @param list<array<string, mixed>> $content */
    private function hasMeaningfulInline(array $content): bool
    {
        $text = '';
        foreach ($content as $node) {
            $text .= ($node['type'] ?? null) === 'break'
                ? "\n"
                : (string) ($node['text'] ?? '');
        }

        return trim($text) !== '';
    }

    /** @param array<string, mixed> $module @return array<string, mixed>|null */
    private function quoteFallback(array $module): ?array
    {
        $content = $module['content'];
        if (!$this->hasMeaningfulInline($content)) {
            $content = [];
        }
        $credit = [];
        if ($module['author'] !== null) {
            $credit[] = $module['author'];
        }
        if ($module['source'] !== null) {
            $credit[] = $module['source'];
        }
        if (
            $credit !== []
            && count($content) <= BlogDocumentValidator::MAX_INLINE_NODES - 2
        ) {
            $content[] = ['type' => 'break'];
            $content[] = [
                'type' => 'text',
                'text' => implode(', ', $credit),
                'marks' => ['em'],
            ];
        }
        if (!$this->hasMeaningfulInline($content)) {
            return null;
        }

        return [
            'id' => $module['id'],
            'type' => 'callout',
            'tone' => 'neutral',
            'content' => $content,
        ];
    }

    /** @param array<string, mixed> $module @return array<string, mixed> */
    private function embedFallback(array $module): array
    {
        $text = $module['caption'] ?? 'Contenido multimedia incrustado';

        return [
            'id' => $module['id'],
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => $text,
                'marks' => [],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $module
     * @return list<array<string, mixed>>
     */
    private function advancedParagraphFallback(array $module): array
    {
        $html = $module['html'] ?? null;
        if (!is_string($html) || $html === '') {
            return [];
        }
        $text = $this->customTextHtml->plainText($html);
        if ($text === '') {
            return [];
        }
        $firstHeading = $this->customTextHtml->firstRootHeading($html);
        if ($firstHeading === null || $firstHeading['text'] === '') {
            return [[
                'id' => $module['id'],
                'type' => 'paragraph',
                'content' => $this->inlineFromPlainText($text),
            ]];
        }

        $modules = [[
            'id' => $module['id'],
            'type' => 'heading',
            'level' => $firstHeading['level'],
            'content' => $this->inlineFromPlainText($firstHeading['text']),
        ]];
        $remaining = str_starts_with($text, $firstHeading['text'])
            ? ltrim(substr($text, strlen($firstHeading['text'])), "\n")
            : $text;
        if ($remaining !== '') {
            $modules[] = [
                'id' => $this->derivedId($module['id'], 'advanced-rest'),
                'type' => 'paragraph',
                'content' => $this->inlineFromPlainText($remaining),
            ];
        }

        return $modules;
    }

    /** @return list<array<string, mixed>> */
    private function inlineFromPlainText(string $text): array
    {
        $content = [];
        foreach (explode("\n", $text) as $line => $remaining) {
            if ($line > 0) {
                $this->appendV1InlineNode($content, ['type' => 'break']);
            }
            while ($remaining !== '') {
                $part = $this->utf8ByteSlice(
                    $remaining,
                    BlogDocumentValidator::MAX_INLINE_TEXT_BYTES
                );
                $this->appendV1InlineNode($content, [
                    'type' => 'text',
                    'text' => $part,
                    'marks' => [],
                ]);
                $remaining = substr($remaining, strlen($part));
            }
        }

        return $content;
    }

    private function derivedId(string $moduleId, string $suffix): string
    {
        $hex = substr(hash(
            'sha256',
            'liquidstack.blog.unified-text|' . $moduleId . '|' . $suffix
        ), 0, 32);
        $hex[12] = '4';
        $hex[16] = '8';

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4)
            . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4)
            . '-' . substr($hex, 20, 12);
    }

    /**
     * @param list<array<string, mixed>> $content
     * @param array<string, mixed> $node
     */
    private function appendV1InlineNode(array &$content, array $node): void
    {
        if (count($content) >= BlogDocumentValidator::MAX_INLINE_NODES) {
            throw new BlogDocumentException(
                BlogDocumentException::PROJECTION_TOO_LARGE
            );
        }
        $content[] = $node;
    }

    private function utf8ByteSlice(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        $slice = substr($value, 0, $maxBytes);
        while ($slice !== '' && preg_match('//u', $slice) !== 1) {
            $slice = substr($slice, 0, -1);
        }
        if ($slice === '') {
            throw new BlogDocumentException(
                BlogDocumentException::PROJECTION_TOO_LARGE
            );
        }

        return $slice;
    }
}
