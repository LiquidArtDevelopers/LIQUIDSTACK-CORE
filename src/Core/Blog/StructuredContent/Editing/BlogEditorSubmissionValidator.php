<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Editing;

use JsonException;

/** Diagnoses the first technical editor failure without echoing submitted data. */
final class BlogEditorSubmissionValidator
{
    private const UUID =
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function __construct(
        private readonly BlogEditorTechnicalLimitCatalog $limits =
            new BlogEditorTechnicalLimitCatalog()
    ) {
    }

    /** @param array<string, mixed> $form */
    public function firstIssue(array $form): ?BlogEditorValidationIssue
    {
        foreach (['h1', 'slug', 'seo_title', 'meta_description', 'excerpt'] as $field) {
            $value = $form[$field] ?? null;
            if (!is_string($value)) {
                return new BlogEditorValidationIssue('entry', $field, 'invalid_format');
            }
            $limit = $this->limits->entryBytes($field);
            if ($limit !== null && strlen($value) > $limit) {
                return new BlogEditorValidationIssue(
                    'entry',
                    $field,
                    'bytes_exceeded',
                    $limit
                );
            }
            if (preg_match('//u', $value) !== 1) {
                return new BlogEditorValidationIssue('entry', $field, 'invalid_utf8');
            }
            $singleLine = $field !== 'excerpt';
            $controlPattern = $singleLine
                ? '/[\x00-\x1F\x7F]/'
                : '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';
            if (preg_match($controlPattern, $value) === 1) {
                return new BlogEditorValidationIssue(
                    'entry',
                    $field,
                    'control_character'
                );
            }
        }
        if (trim((string) $form['h1']) === '') {
            return new BlogEditorValidationIssue('entry', 'h1', 'required');
        }
        $slug = (string) $form['slug'];
        if ($slug !== '' && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
            return new BlogEditorValidationIssue('entry', 'slug', 'invalid_format');
        }

        $json = $form['document_json'] ?? null;
        if (!is_string($json) || $json === '') {
            return new BlogEditorValidationIssue(
                'document',
                'document_json',
                'required'
            );
        }
        $jsonLimit = $this->limits->documentBytes('document_json');
        if ($jsonLimit !== null && strlen($json) > $jsonLimit) {
            return new BlogEditorValidationIssue(
                'document',
                'document_json',
                'bytes_exceeded',
                $jsonLimit
            );
        }
        try {
            $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new BlogEditorValidationIssue(
                'document',
                'document_json',
                'invalid_json'
            );
        }
        if (!is_array($document)) {
            return new BlogEditorValidationIssue(
                'document',
                'document_json',
                'invalid_structure'
            );
        }

        return $this->inspectValue($document, null);
    }

    /** @param array<mixed> $value */
    private function inspectValue(array $value, ?string $blockId): ?BlogEditorValidationIssue
    {
        $candidateId = $value['id'] ?? null;
        if (is_string($candidateId) && preg_match(self::UUID, $candidateId) === 1) {
            $blockId = $candidateId;
        }

        if (
            isset($value['content'])
            && is_array($value['content'])
            && $this->isUnifiedTextFlow($value)
        ) {
            $issue = $this->plainBlockValue(
                'text_content',
                $this->textFlowValue($value['content']),
                $blockId
            );
            if ($issue !== null) {
                return $issue;
            }
        }
        foreach ([
            'label' => 'label',
            'alt' => 'alt',
            'title' => 'title',
            'caption' => 'caption',
            'href' => 'href',
            'url' => 'href',
            'html' => 'html',
            'css' => 'css',
            'text' => 'content',
        ] as $source => $field) {
            if (!array_key_exists($source, $value) || !is_string($value[$source])) {
                continue;
            }
            $issue = in_array($field, ['html', 'css'], true)
                ? $this->sourceBlockValue(
                    $field,
                    $value[$source],
                    $blockId
                )
                : $this->plainBlockValue(
                    $field,
                    $value[$source],
                    $blockId
                );
            if ($issue !== null) {
                return $issue;
            }
        }
        foreach ($value as $child) {
            if (!is_array($child)) {
                continue;
            }
            if (array_is_list($child)) {
                foreach ($child as $item) {
                    if (is_array($item)) {
                        $issue = $this->inspectValue($item, $blockId);
                        if ($issue !== null) {
                            return $issue;
                        }
                    }
                }
                continue;
            }
            $issue = $this->inspectValue($child, $blockId);
            if ($issue !== null) {
                return $issue;
            }
        }

        return null;
    }

    /** @param array<mixed> $value */
    private function isUnifiedTextFlow(array $value): bool
    {
        if (
            ($value['type'] ?? null) !== 'paragraph'
            || !is_array($value['content'] ?? null)
            || !array_is_list($value['content'])
        ) {
            return false;
        }
        foreach ($value['content'] as $node) {
            if (!is_array($node)) {
                return false;
            }
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

    /** @param array<mixed> $content */
    private function textFlowValue(array $content): string
    {
        $text = '';
        foreach ($content as $flowNode) {
            if (!is_array($flowNode)) {
                continue;
            }
            if (($flowNode['type'] ?? null) === 'break') {
                $text .= ' ';
                continue;
            }
            if (
                ($flowNode['type'] ?? null) === 'list'
                && is_array($flowNode['items'] ?? null)
            ) {
                foreach ($flowNode['items'] as $item) {
                    if (is_array($item) && is_array($item['content'] ?? null)) {
                        $text .= $this->inlineValue($item['content']);
                    }
                }
                continue;
            }
            if (is_array($flowNode['content'] ?? null)) {
                $text .= $this->inlineValue($flowNode['content']);
            }
            if (($flowNode['type'] ?? null) === 'quote') {
                foreach (['author', 'source'] as $credit) {
                    if (is_string($flowNode[$credit] ?? null)) {
                        $text .= $flowNode[$credit];
                    }
                }
            }
        }

        return $text;
    }

    /** @param array<mixed> $content */
    private function inlineValue(array $content): string
    {
        $text = '';
        foreach ($content as $node) {
            if (is_array($node) && is_string($node['text'] ?? null)) {
                $text .= $node['text'];
            }
        }

        return $text;
    }

    private function plainBlockValue(
        string $field,
        string $value,
        ?string $blockId
    ): ?BlogEditorValidationIssue {
        $limit = $this->limits->blockBytes($field);
        if ($limit !== null && strlen($value) > $limit) {
            return new BlogEditorValidationIssue(
                'block',
                $field,
                'bytes_exceeded',
                $limit,
                $blockId
            );
        }
        if (preg_match('//u', $value) !== 1) {
            return new BlogEditorValidationIssue(
                'block',
                $field,
                'invalid_utf8',
                null,
                $blockId
            );
        }
        if (preg_match('/\p{Cc}/u', $value) === 1) {
            return new BlogEditorValidationIssue(
                'block',
                $field,
                'control_character',
                null,
                $blockId
            );
        }

        return null;
    }

    private function sourceBlockValue(
        string $field,
        string $value,
        ?string $blockId
    ): ?BlogEditorValidationIssue {
        $limit = $this->limits->blockBytes($field);
        if ($limit !== null && strlen($value) > $limit) {
            return new BlogEditorValidationIssue(
                'block',
                $field,
                'bytes_exceeded',
                $limit,
                $blockId
            );
        }
        if (preg_match('//u', $value) !== 1) {
            return new BlogEditorValidationIssue(
                'block',
                $field,
                'invalid_utf8',
                null,
                $blockId
            );
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return new BlogEditorValidationIssue(
                'block',
                $field,
                'control_character',
                null,
                $blockId
            );
        }

        return null;
    }
}
