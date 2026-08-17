<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentValidator;
use App\Core\Blog\StructuredContent\Document\BlogCustomTextCssSanitizer;
use App\Core\Blog\StructuredContent\Editing\BlogEditorSubmissionValidator;
use PHPUnit\Framework\TestCase;

final class BlogEditorSubmissionValidatorTest extends TestCase
{
    public function testCountsUtf8BytesAndNeverReturnsSubmittedValues(): void
    {
        $form = $this->form();
        $form['h1'] = str_repeat('€', 86);

        $issue = (new BlogEditorSubmissionValidator())->firstIssue($form);

        self::assertNotNull($issue);
        self::assertSame([
            'scope' => 'entry',
            'field' => 'h1',
            'block_id' => null,
            'code' => 'bytes_exceeded',
            'limit' => BlogDraft::MAX_H1_BYTES,
        ], $issue->toSafeArray());
        self::assertStringNotContainsString(
            $form['h1'],
            json_encode($issue->toSafeArray(), JSON_THROW_ON_ERROR)
        );
    }

    public function testSeoRecommendationDoesNotBlockDraftSave(): void
    {
        $form = $this->form();
        $form['meta_description'] = str_repeat('a', 200);

        self::assertNull(
            (new BlogEditorSubmissionValidator())->firstIssue($form)
        );
    }

    public function testReportsOversizedBlockWithStableBlockIdentity(): void
    {
        $form = $this->form();
        $blockId = '40000000-0000-4000-8000-000000000001';
        $document = json_decode($form['document_json'], true, 32, JSON_THROW_ON_ERROR);
        $document['blocks'][0]['content'][0]['text'] = str_repeat(
            'a',
            BlogDocumentValidator::MAX_INLINE_TEXT_BYTES + 1
        );
        $form['document_json'] = json_encode($document, JSON_THROW_ON_ERROR);

        $issue = (new BlogEditorSubmissionValidator())->firstIssue($form);

        self::assertNotNull($issue);
        self::assertSame([
            'scope' => 'block',
            'field' => 'content',
            'block_id' => $blockId,
            'code' => 'bytes_exceeded',
            'limit' => BlogDocumentValidator::MAX_INLINE_TEXT_BYTES,
        ], $issue->toSafeArray());
    }

    public function testReportsOversizedCustomCssAgainstItsOwnFieldLimit(): void
    {
        $form = $this->form();
        $blockId = '40000000-0000-4000-8000-000000000001';
        $document = json_decode(
            $form['document_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        unset($document['blocks'][0]['content']);
        $document['blocks'][0]['html'] = '<p>Safe source</p>';
        $document['blocks'][0]['css'] = str_repeat(
            'a',
            BlogCustomTextCssSanitizer::MAX_CSS_BYTES + 1
        );
        $form['document_json'] = json_encode($document, JSON_THROW_ON_ERROR);

        $issue = (new BlogEditorSubmissionValidator())->firstIssue($form);

        self::assertNotNull($issue);
        self::assertSame([
            'scope' => 'block',
            'field' => 'css',
            'block_id' => $blockId,
            'code' => 'bytes_exceeded',
            'limit' => BlogCustomTextCssSanitizer::MAX_CSS_BYTES,
        ], $issue->toSafeArray());
    }

    public function testUnifiedTextUsesItsAggregateLimitInsteadOfLegacyLeafLimit(): void
    {
        $form = $this->unifiedTextForm(3, 10_000);

        self::assertNull(
            (new BlogEditorSubmissionValidator())->firstIssue($form)
        );
    }

    public function testUnifiedTextDoesNotApplyLeafLimitToNestedAggregates(): void
    {
        $form = $this->unifiedTextForm(1, 1);
        $document = json_decode(
            $form['document_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $document['blocks'][0]['children'][0]['content'] = [[
            'type' => 'heading',
            'level' => 2,
            'content' => [[
                'type' => 'text',
                'text' => str_repeat('a', 11_000),
                'marks' => [],
            ], [
                'type' => 'text',
                'text' => str_repeat('b', 11_000),
                'marks' => ['strong'],
            ]],
        ], [
            'type' => 'list',
            'ordered' => false,
            'items' => [[
                'id' => '40000000-0000-4000-8000-000000000003',
                'content' => [[
                    'type' => 'text',
                    'text' => str_repeat('c', 11_000),
                    'marks' => [],
                ], [
                    'type' => 'text',
                    'text' => str_repeat('d', 11_000),
                    'marks' => ['em'],
                ]],
            ]],
        ]];
        BlogDocument::fromArray($document);
        $form['document_json'] = json_encode($document, JSON_THROW_ON_ERROR);

        self::assertNull(
            (new BlogEditorSubmissionValidator())->firstIssue($form)
        );
    }

    public function testLegacyBlockDoesNotApplyLeafLimitToItsAggregate(): void
    {
        $form = $this->form();
        $document = json_decode(
            $form['document_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $document['blocks'][0]['content'] = [[
            'type' => 'text',
            'text' => str_repeat('a', 11_000),
            'marks' => [],
        ], [
            'type' => 'text',
            'text' => str_repeat('b', 11_000),
            'marks' => ['strong'],
        ]];
        BlogDocument::fromArray($document);
        $form['document_json'] = json_encode($document, JSON_THROW_ON_ERROR);

        self::assertNull(
            (new BlogEditorSubmissionValidator())->firstIssue($form)
        );
    }

    public function testUnifiedTextStillReportsAnOversizedLeaf(): void
    {
        $form = $this->unifiedTextForm(1, 1);
        $document = json_decode(
            $form['document_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $document['blocks'][0]['children'][0]['content'][0]['content'][0]['text'] =
            str_repeat('a', BlogDocumentValidator::MAX_INLINE_TEXT_BYTES + 1);
        $form['document_json'] = json_encode($document, JSON_THROW_ON_ERROR);

        $issue = (new BlogEditorSubmissionValidator())->firstIssue($form);

        self::assertNotNull($issue);
        self::assertSame([
            'scope' => 'block',
            'field' => 'content',
            'block_id' => '40000000-0000-4000-8000-000000000002',
            'code' => 'bytes_exceeded',
            'limit' => BlogDocumentValidator::MAX_INLINE_TEXT_BYTES,
        ], $issue->toSafeArray());
    }

    public function testReportsUnifiedTextAggregateOverflowWithStableIdentity(): void
    {
        $form = $this->unifiedTextForm(11, 19_000);
        $issue = (new BlogEditorSubmissionValidator())->firstIssue($form);

        self::assertNotNull($issue);
        self::assertSame([
            'scope' => 'block',
            'field' => 'text_content',
            'block_id' => '40000000-0000-4000-8000-000000000002',
            'code' => 'bytes_exceeded',
            'limit' => BlogDocumentValidator::MAX_TEXT_MODULE_BYTES,
        ], $issue->toSafeArray());
    }

    public function testRootFlowBreakCountsTowardUnifiedSubmissionLimit(): void
    {
        $form = $this->unifiedTextForm(10, 20_000);
        $document = json_decode(
            $form['document_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        array_unshift(
            $document['blocks'][0]['children'][0]['content'],
            ['type' => 'break']
        );
        $form['document_json'] = json_encode($document, JSON_THROW_ON_ERROR);

        $issue = (new BlogEditorSubmissionValidator())->firstIssue($form);

        self::assertNotNull($issue);
        self::assertSame([
            'scope' => 'block',
            'field' => 'text_content',
            'block_id' => '40000000-0000-4000-8000-000000000002',
            'code' => 'bytes_exceeded',
            'limit' => BlogDocumentValidator::MAX_TEXT_MODULE_BYTES,
        ], $issue->toSafeArray());
    }

    public function testSemanticBreakIsNotDiagnosedAsAControlCharacter(): void
    {
        $form = $this->unifiedTextForm(1, 20);
        $document = json_decode(
            $form['document_json'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $document['blocks'][0]['children'][0]['content'][0]['content'][] = [
            'type' => 'break',
        ];
        $document['blocks'][0]['children'][0]['content'][0]['content'][] = [
            'type' => 'text',
            'text' => 'Second line',
            'marks' => [],
        ];
        $form['document_json'] = json_encode($document, JSON_THROW_ON_ERROR);

        self::assertNull(
            (new BlogEditorSubmissionValidator())->firstIssue($form)
        );
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return [
            'h1' => 'Matrix H1',
            'slug' => 'matrix',
            'seo_title' => 'Matrix SEO',
            'meta_description' => 'Matrix description',
            'excerpt' => 'Matrix excerpt',
            'document_json' => json_encode([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
                'blocks' => [[
                    'id' => '40000000-0000-4000-8000-000000000001',
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Follow the white rabbit.',
                        'marks' => [],
                    ]],
                ]],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<string, string> */
    private function unifiedTextForm(int $paragraphs, int $bytes): array
    {
        $form = $this->form();
        $content = [];
        for ($index = 0; $index < $paragraphs; ++$index) {
            $content[] = [
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => str_repeat((string) ($index % 10), $bytes),
                    'marks' => [],
                ]],
            ];
        }
        $form['document_json'] = json_encode([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => '40000000-0000-4000-8000-000000000001',
                'type' => 'section',
                'children' => [[
                    'id' => '40000000-0000-4000-8000-000000000002',
                    'type' => 'paragraph',
                    'content' => $content,
                    'presentation' => [
                        'width' => 'full',
                        'align' => 'start',
                        'text_align' => 'start',
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        return $form;
    }
}
