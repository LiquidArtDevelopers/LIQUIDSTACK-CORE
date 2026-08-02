<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use JsonException;

/** Strict structural and semantic validator for document schema v1. */
final class BlogDocumentValidator
{
    public const MAX_INLINE_NODES = 500;
    public const MAX_INLINE_TEXT_BYTES = 20_000;
    public const MAX_LABEL_BYTES = 255;
    public const MAX_ALT_BYTES = 500;
    public const MAX_TITLE_BYTES = 500;
    public const MAX_CAPTION_BYTES = 2_000;
    public const MAX_URL_BYTES = 2_048;
    public const MAX_VIDEO_START_SECONDS = 86_400;

    private const UUID_V4_PATTERN =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const MARK_ORDER = ['strong', 'em'];

    private readonly BlogDocumentTemplateRegistry $templates;

    public function __construct(
        ?BlogDocumentTemplateRegistry $templates = null
    ) {
        $this->templates = $templates ?? new BlogDocumentTemplateRegistry();
    }

    /**
     * @param array<string, mixed> $document
     * @return array{
     *   schema: string,
     *   version: int,
     *   template: string,
     *   blocks: list<array<string, mixed>>
     * }
     */
    public function validate(array $document): array
    {
        $this->assertExactKeys(
            $document,
            ['schema', 'version', 'template', 'blocks'],
            BlogDocumentException::INVALID_DOCUMENT
        );
        if (
            $document['schema'] !== BlogDocument::SCHEMA
            || $document['version'] !== BlogDocument::VERSION
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::UNSUPPORTED_SCHEMA
            );
        }
        if (!is_string($document['template'])) {
            throw new BlogDocumentException(
                BlogDocumentException::UNSUPPORTED_TEMPLATE
            );
        }
        $template = $this->singleLine(
            $document['template'],
            64,
            BlogDocumentException::UNSUPPORTED_TEMPLATE
        );
        $this->templates->assertSupported($template);

        $rawBlocks = $document['blocks'];
        if (
            !is_array($rawBlocks)
            || !array_is_list($rawBlocks)
            || count($rawBlocks) > BlogDocument::MAX_BLOCKS
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }

        $seenIds = [];
        $blocks = [];
        foreach ($rawBlocks as $block) {
            $blocks[] = $this->block($block, $seenIds);
        }
        $this->assertHeadingHierarchy($blocks);
        $this->templates->assertDocumentContract($template, $blocks);

        $normalized = [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => $template,
            'blocks' => $blocks,
        ];
        $this->assertCanonicalSize($normalized);

        return $normalized;
    }

    /**
     * @param mixed $value
     * @param array<string, true> $seenIds
     * @return array<string, mixed>
     */
    private function block(mixed $value, array &$seenIds): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $type = $value['type'] ?? null;
        if (!is_string($type)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        return match ($type) {
            'paragraph' => $this->paragraph($value, $seenIds),
            'heading' => $this->heading($value, $seenIds),
            'list' => $this->listBlock($value, $seenIds),
            'callout' => $this->callout($value, $seenIds),
            'link' => $this->linkBlock($value, $seenIds),
            'image' => $this->image($value, $seenIds),
            'video' => $this->video($value, $seenIds),
            'cta' => $this->cta($value, $seenIds),
            default => throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            ),
        };
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function paragraph(array $block, array &$seen): array
    {
        $this->assertExactKeys(
            $block,
            ['id', 'type', 'content'],
            BlogDocumentException::INVALID_BLOCK
        );
        $content = $this->inlineContent($block['content'], true);
        $this->assertMeaningful($content);

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'paragraph',
            'content' => $content,
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function heading(array $block, array &$seen): array
    {
        $this->assertExactKeys(
            $block,
            ['id', 'type', 'level', 'content'],
            BlogDocumentException::INVALID_BLOCK
        );
        if (!is_int($block['level']) || !in_array($block['level'], [2, 3], true)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $content = $this->inlineContent($block['content'], false);
        $this->assertMeaningful($content);

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'heading',
            'level' => $block['level'],
            'content' => $content,
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function listBlock(array $block, array &$seen): array
    {
        $this->assertExactKeys(
            $block,
            ['id', 'type', 'ordered', 'items'],
            BlogDocumentException::INVALID_BLOCK
        );
        if (!is_bool($block['ordered'])) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $rawItems = $block['items'];
        if (
            !is_array($rawItems)
            || !array_is_list($rawItems)
            || $rawItems === []
            || count($rawItems) > BlogDocument::MAX_LIST_ITEMS
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem) || array_is_list($rawItem)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_BLOCK
                );
            }
            $this->assertExactKeys(
                $rawItem,
                ['id', 'content'],
                BlogDocumentException::INVALID_BLOCK
            );
            $content = $this->inlineContent($rawItem['content'], true);
            $this->assertMeaningful($content);
            $items[] = [
                'id' => $this->structuralId($rawItem['id'], $seen),
                'content' => $content,
            ];
        }

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'list',
            'ordered' => $block['ordered'],
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function callout(array $block, array &$seen): array
    {
        $this->assertExactKeys(
            $block,
            ['id', 'type', 'tone', 'content'],
            BlogDocumentException::INVALID_BLOCK
        );
        if (
            !is_string($block['tone'])
            || !in_array($block['tone'], ['neutral', 'info', 'warning'], true)
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $content = $this->inlineContent($block['content'], true);
        $this->assertMeaningful($content);

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'callout',
            'tone' => $block['tone'],
            'content' => $content,
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function linkBlock(array $block, array &$seen): array
    {
        $this->assertExactKeys(
            $block,
            ['id', 'type', 'label', 'href', 'title', 'target'],
            BlogDocumentException::INVALID_BLOCK
        );

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'link',
            'label' => $this->singleLine(
                $block['label'],
                self::MAX_LABEL_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'href' => $this->url($block['href']),
            'title' => $this->nullableSingleLine(
                $block['title'],
                self::MAX_TITLE_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'target' => $this->target($block['target']),
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function image(array $block, array &$seen): array
    {
        $this->assertExactKeys(
            $block,
            [
                'id', 'type', 'media_asset_public_id', 'alt', 'title',
                'caption', 'decorative', 'display',
            ],
            BlogDocumentException::INVALID_BLOCK
        );
        if (!is_bool($block['decorative'])) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        $alt = $this->singleLine(
            $block['alt'],
            self::MAX_ALT_BYTES,
            BlogDocumentException::INVALID_BLOCK,
            true
        );
        if (
            ($block['decorative'] && $alt !== '')
            || (!$block['decorative'] && $alt === '')
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }
        if (
            !is_string($block['display'])
            || !in_array(
                $block['display'],
                ['content', 'wide', 'cover'],
                true
            )
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'image',
            'media_asset_public_id' => $this->uuid(
                $block['media_asset_public_id'],
                BlogDocumentException::INVALID_BLOCK
            ),
            'alt' => $alt,
            'title' => $this->nullableSingleLine(
                $block['title'],
                self::MAX_TITLE_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'caption' => $this->nullableSingleLine(
                $block['caption'],
                self::MAX_CAPTION_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'decorative' => $block['decorative'],
            'display' => $block['display'],
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function video(array $block, array &$seen): array
    {
        $this->assertExactKeys(
            $block,
            [
                'id', 'type', 'provider', 'video_id', 'title',
                'start_seconds',
            ],
            BlogDocumentException::INVALID_BLOCK
        );
        if (
            $block['provider'] !== 'youtube'
            || !is_string($block['video_id'])
            || preg_match('/\A[A-Za-z0-9_-]{11}\z/', $block['video_id']) !== 1
            || !is_int($block['start_seconds'])
            || $block['start_seconds'] < 0
            || $block['start_seconds'] > self::MAX_VIDEO_START_SECONDS
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'video',
            'provider' => 'youtube',
            'video_id' => $block['video_id'],
            'title' => $this->singleLine(
                $block['title'],
                self::MAX_TITLE_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'start_seconds' => $block['start_seconds'],
        ];
    }

    /** @param array<string, mixed> $block @param array<string, true> $seen */
    private function cta(array $block, array &$seen): array
    {
        $this->assertExactKeys(
            $block,
            [
                'id', 'type', 'label', 'href', 'title', 'target',
                'variant',
            ],
            BlogDocumentException::INVALID_BLOCK
        );
        if (
            !is_string($block['variant'])
            || !in_array($block['variant'], ['primary', 'secondary'], true)
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        return [
            'id' => $this->structuralId($block['id'], $seen),
            'type' => 'cta',
            'label' => $this->singleLine(
                $block['label'],
                self::MAX_LABEL_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'href' => $this->url($block['href']),
            'title' => $this->nullableSingleLine(
                $block['title'],
                self::MAX_TITLE_BYTES,
                BlogDocumentException::INVALID_BLOCK
            ),
            'target' => $this->target($block['target']),
            'variant' => $block['variant'],
        ];
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private function inlineContent(mixed $value, bool $allowBreak): array
    {
        if (
            !is_array($value)
            || !array_is_list($value)
            || $value === []
            || count($value) > self::MAX_INLINE_NODES
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_INLINE
            );
        }
        $content = [];
        foreach ($value as $node) {
            if (!is_array($node) || array_is_list($node)) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_INLINE
                );
            }
            $type = $node['type'] ?? null;
            if ($type === 'text') {
                $this->assertExactKeys(
                    $node,
                    ['type', 'text', 'marks'],
                    BlogDocumentException::INVALID_INLINE
                );
                $content[] = [
                    'type' => 'text',
                    'text' => $this->inlineText($node['text']),
                    'marks' => $this->marks($node['marks']),
                ];
                continue;
            }
            if ($type === 'link') {
                $this->assertExactKeys(
                    $node,
                    [
                        'type', 'text', 'marks', 'href', 'title',
                        'target',
                    ],
                    BlogDocumentException::INVALID_INLINE
                );
                $content[] = [
                    'type' => 'link',
                    'text' => $this->inlineText($node['text']),
                    'marks' => $this->marks($node['marks']),
                    'href' => $this->url($node['href']),
                    'title' => $this->nullableSingleLine(
                        $node['title'],
                        self::MAX_TITLE_BYTES,
                        BlogDocumentException::INVALID_INLINE
                    ),
                    'target' => $this->target($node['target']),
                ];
                continue;
            }
            if ($type === 'break' && $allowBreak) {
                $this->assertExactKeys(
                    $node,
                    ['type'],
                    BlogDocumentException::INVALID_INLINE
                );
                $content[] = ['type' => 'break'];
                continue;
            }

            throw new BlogDocumentException(
                BlogDocumentException::INVALID_INLINE
            );
        }

        return $content;
    }

    /** @param list<array<string, mixed>> $content */
    private function assertMeaningful(array $content): void
    {
        $text = '';
        foreach ($content as $node) {
            if ($node['type'] === 'break') {
                $text .= "\n";
            } else {
                $text .= (string) $node['text'];
            }
        }
        if (trim($text) === '') {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_INLINE
            );
        }
    }

    /** @return list<string> */
    private function marks(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_INLINE
            );
        }
        $seen = [];
        foreach ($value as $mark) {
            if (
                !is_string($mark)
                || !in_array($mark, self::MARK_ORDER, true)
                || isset($seen[$mark])
            ) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_INLINE
                );
            }
            $seen[$mark] = true;
        }

        return array_values(array_filter(
            self::MARK_ORDER,
            static fn (string $mark): bool => isset($seen[$mark])
        ));
    }

    private function inlineText(mixed $value): string
    {
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > self::MAX_INLINE_TEXT_BYTES
            || !$this->isSafePlainText($value)
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_INLINE
            );
        }

        return $value;
    }

    private function target(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['same', 'new'], true)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_BLOCK
            );
        }

        return $value;
    }

    private function url(mixed $value): string
    {
        if (!is_string($value)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_URL
            );
        }
        $url = trim($value);
        if (
            $url === ''
            || strlen($url) > self::MAX_URL_BYTES
            || !$this->isSafePlainText($url)
            || str_contains($url, '\\')
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $url) === 1
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_URL
            );
        }
        $hasWhitespace = preg_match('/\s/u', $url) === 1;
        if (
            !$hasWhitespace
            && str_starts_with($url, '/')
            && !str_starts_with($url, '//')
        ) {
            if ($this->isSafeRootRelativeUrl($url)) {
                return $url;
            }

            throw new BlogDocumentException(
                BlogDocumentException::INVALID_URL
            );
        }
        if (!$hasWhitespace && str_starts_with($url, 'https://')) {
            $parts = parse_url($url);
            if (
                filter_var($url, FILTER_VALIDATE_URL) !== false
                && is_array($parts)
                && ($parts['scheme'] ?? null) === 'https'
                && is_string($parts['host'] ?? null)
                && ($parts['host'] ?? '') !== ''
                && !isset($parts['user'])
                && !isset($parts['pass'])
            ) {
                return $url;
            }
        }
        if (!$hasWhitespace && str_starts_with($url, 'mailto:')) {
            $address = substr($url, strlen('mailto:'));
            if (
                !str_contains($address, '?')
                && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            ) {
                return $url;
            }
        }
        if (
            str_starts_with($url, 'tel:')
            && preg_match(
                '/\Atel:\+?[0-9][0-9 .()\-]{2,31}\z/',
                $url
            ) === 1
        ) {
            return $url;
        }

        throw new BlogDocumentException(
            BlogDocumentException::INVALID_URL
        );
    }

    private function isSafeRootRelativeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            return false;
        }
        $path = $parts['path'] ?? null;
        if (
            !is_string($path)
            || !str_starts_with($path, '/')
            || str_contains($path, '//')
            || preg_match('/%(?:2f|5c)/i', $path) === 1
        ) {
            return false;
        }

        $decoded = $path;
        $stable = false;
        for ($pass = 0; $pass < 8; ++$pass) {
            if (
                preg_match('/%(?:2f|5c)/i', $decoded) === 1
                || preg_match('/%(?![0-9A-Fa-f]{2})/', $decoded) === 1
            ) {
                return false;
            }
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                $stable = true;
                break;
            }
            $decoded = $next;
        }
        if (
            !$stable
            || preg_match('//u', $decoded) !== 1
            || preg_match('/\p{Cc}/u', $decoded) === 1
            || str_contains($decoded, '\\')
            || str_contains($decoded, '//')
        ) {
            return false;
        }
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, true> $seenIds */
    private function structuralId(mixed $value, array &$seenIds): string
    {
        $id = $this->uuid($value, BlogDocumentException::INVALID_BLOCK);
        if (isset($seenIds[$id])) {
            throw new BlogDocumentException(
                BlogDocumentException::DUPLICATE_ID
            );
        }
        $seenIds[$id] = true;

        return $id;
    }

    private function uuid(mixed $value, string $issueCode): string
    {
        if (
            !is_string($value)
            || preg_match(self::UUID_V4_PATTERN, $value) !== 1
        ) {
            throw new BlogDocumentException($issueCode);
        }

        return $value;
    }

    private function singleLine(
        mixed $value,
        int $maxBytes,
        string $issueCode,
        bool $allowEmpty = false
    ): string {
        if (!is_string($value)) {
            throw new BlogDocumentException($issueCode);
        }
        $value = trim($value);
        if (
            (!$allowEmpty && $value === '')
            || strlen($value) > $maxBytes
            || !$this->isSafePlainText($value)
        ) {
            throw new BlogDocumentException($issueCode);
        }

        return $value;
    }

    private function nullableSingleLine(
        mixed $value,
        int $maxBytes,
        string $issueCode
    ): ?string {
        return $value === null
            ? null
            : $this->singleLine($value, $maxBytes, $issueCode);
    }

    private function isSafePlainText(string $value): bool
    {
        return preg_match('//u', $value) === 1
            && preg_match('/\p{Cc}/u', $value) !== 1
            && strip_tags($value) === $value;
    }

    /** @param list<array<string, mixed>> $blocks */
    private function assertHeadingHierarchy(array $blocks): void
    {
        $hasH2 = false;
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) !== 'heading') {
                continue;
            }
            if ($block['level'] === 2) {
                $hasH2 = true;
                continue;
            }
            if (!$hasH2) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_HEADING_HIERARCHY
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private function assertExactKeys(
        array $value,
        array $expected,
        string $issueCode
    ): void {
        if (array_is_list($value)) {
            throw new BlogDocumentException($issueCode);
        }
        $actual = array_keys($value);
        if (!array_is_list($actual)) {
            throw new BlogDocumentException($issueCode);
        }
        foreach ($actual as $key) {
            if (!is_string($key)) {
                throw new BlogDocumentException($issueCode);
            }
        }
        sort($actual, SORT_STRING);
        $sortedExpected = $expected;
        sort($sortedExpected, SORT_STRING);
        if ($actual !== $sortedExpected) {
            throw new BlogDocumentException($issueCode);
        }
    }

    /** @param array<string, mixed> $document */
    private function assertCanonicalSize(array $document): void
    {
        try {
            $json = json_encode(
                $document,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }
        if (strlen($json) > BlogDocument::MAX_JSON_BYTES) {
            throw new BlogDocumentException(
                BlogDocumentException::DOCUMENT_TOO_LARGE
            );
        }
    }
}
