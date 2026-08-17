<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV1CompatibilityProjector;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use PHPUnit\Framework\TestCase;

final class BlogRichInlineV2Test extends TestCase
{
    public function testV2CanonicalizesAndRendersAllowlistedRichMarks(): void
    {
        $document = BlogDocument::fromArray($this->layoutDocument([
            'background-color03',
            'text-color00',
            'size-large',
            'underline',
            'em',
            'strong',
        ]));
        $paragraph = $document->blocks()[0]['children'][1];

        self::assertSame([
            'strong',
            'em',
            'underline',
            'size-large',
            'text-color00',
            'background-color03',
        ], $paragraph['content'][0]['marks']);

        $html = $this->renderer()->render($document);
        self::assertStringContainsString(
            '<strong><em><u class="blogDocument__inlineUnderline">'
            . '<span class="blogDocument__inline '
            . 'blogDocument__inline--size-large '
            . 'blogDocument__inline--text-color00 '
            . 'blogDocument__inline--background-color03">'
            . 'Rich &lt; copy</span></u></em></strong>',
            $html
        );
        self::assertStringNotContainsString(' style=', $html);
    }

    public function testV2RejectsUnknownDuplicateAndConflictingMarkGroups(): void
    {
        foreach ([
            ['strike'],
            ['underline', 'underline'],
            ['size-small', 'size-large'],
            ['text-color00', 'text-color03'],
            ['text-basic-red', 'text-basic-blue'],
            ['text-color02', 'text-basic-red'],
            ['background-color01', 'background-color02'],
            ['background-basic-yellow', 'background-basic-gray'],
            ['background-color03', 'background-basic-purple'],
        ] as $marks) {
            try {
                BlogDocument::fromArray($this->layoutDocument($marks));
                self::fail('The invalid rich mark set was accepted.');
            } catch (BlogDocumentException $exception) {
                self::assertSame(
                    BlogDocumentException::INVALID_INLINE,
                    $exception->issueCode()
                );
            }
        }
    }

    public function testV2CanonicalizesAndRendersFixedBasicColors(): void
    {
        $document = BlogDocument::fromArray($this->layoutDocument([
            'background-basic-yellow',
            'text-basic-blue',
            'strong',
        ]));
        $paragraph = $document->blocks()[0]['children'][1];

        self::assertSame([
            'strong',
            'text-basic-blue',
            'background-basic-yellow',
        ], $paragraph['content'][0]['marks']);
        self::assertStringContainsString(
            'blogDocument__inline--text-basic-blue '
                . 'blogDocument__inline--background-basic-yellow',
            $this->renderer()->render($document)
        );
    }

    public function testOldV2PresentationDefaultsTextAlignToStart(): void
    {
        $document = BlogDocument::fromArray($this->layoutDocument([]));
        $presentation = $document->blocks()[0]['children'][1]['presentation'];

        self::assertSame([
            'width' => 'full',
            'align' => 'start',
            'text_align' => 'start',
        ], $presentation);
        self::assertStringContainsString(
            'blogDocument__module--text-align-start',
            $this->renderer()->render($document)
        );
    }

    public function testV2AcceptsOnlyAllowlistedTextAlignments(): void
    {
        foreach (['start', 'center', 'end', 'justify'] as $textAlign) {
            $raw = $this->layoutDocument([]);
            $raw['blocks'][0]['children'][1]['presentation']['text_align'] =
                $textAlign;
            $document = BlogDocument::fromArray($raw);

            self::assertSame(
                $textAlign,
                $document->blocks()[0]['children'][1]['presentation']
                    ['text_align']
            );
            self::assertStringContainsString(
                'blogDocument__module--text-align-' . $textAlign,
                $this->renderer()->render($document)
            );
        }

        foreach (['left', '', 'space-between', null] as $textAlign) {
            $raw = $this->layoutDocument([]);
            $raw['blocks'][0]['children'][1]['presentation']['text_align'] =
                $textAlign;
            try {
                BlogDocument::fromArray($raw);
                self::fail('An invalid text alignment was accepted.');
            } catch (BlogDocumentException $exception) {
                self::assertSame(
                    BlogDocumentException::INVALID_BLOCK,
                    $exception->issueCode()
                );
            }
        }
    }

    public function testV2RendersBothListTypesWithRichItemsAndTextAlignment(): void
    {
        foreach ([[false, 'ul'], [true, 'ol']] as [$ordered, $tag]) {
            $raw = $this->layoutDocument([]);
            $raw['blocks'][0]['children'][1] = [
                'id' => $this->id(3),
                'type' => 'list',
                'ordered' => $ordered,
                'items' => [[
                    'id' => $this->id(4),
                    'content' => [$this->text(
                        'First item',
                        ['strong', 'text-basic-green']
                    )],
                ], [
                    'id' => $this->id(5),
                    'content' => [$this->text(
                        'Second item',
                        ['background-basic-blue']
                    )],
                ]],
                'presentation' => [
                    'width' => 'full',
                    'align' => 'start',
                    'text_align' => 'justify',
                ],
            ];

            $html = $this->renderer()->render(BlogDocument::fromArray($raw));

            self::assertStringContainsString('<' . $tag . ' ', $html);
            self::assertStringContainsString('</' . $tag . '>', $html);
            self::assertStringContainsString(
                'blogDocument__module--text-align-justify',
                $html
            );
            self::assertStringContainsString(
                'blogDocument__inline--text-basic-green',
                $html
            );
            self::assertStringContainsString(
                'blogDocument__inline--background-basic-blue',
                $html
            );
            self::assertSame(2, substr_count(
                $html,
                'class="blogDocument__listItem"'
            ));
        }
    }

    public function testV1CompatibilityProjectionDropsEveryV2OnlyMark(): void
    {
        $document = BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'section',
                'children' => [[
                    'id' => $this->id(2),
                    'type' => 'heading',
                    'level' => 2,
                    'content' => [$this->text(
                        'Section',
                        ['underline', 'strong']
                    )],
                    'presentation' => $this->presentation(),
                ], [
                    'id' => $this->id(3),
                    'type' => 'paragraph',
                    'content' => [
                        $this->text(
                            'Paragraph',
                            ['text-basic-red', 'strong']
                        ),
                        [
                            'type' => 'link',
                            'text' => ' link',
                            'marks' => ['background-basic-blue', 'em'],
                            'href' => '/matrix',
                            'title' => null,
                            'target' => 'same',
                        ],
                    ],
                    'presentation' => $this->presentation(),
                ], [
                    'id' => $this->id(4),
                    'type' => 'callout',
                    'tone' => 'info',
                    'content' => [$this->text(
                        'Callout',
                        ['strong', 'em', 'size-xlarge']
                    )],
                    'presentation' => $this->presentation(),
                ], [
                    'id' => $this->id(5),
                    'type' => 'list',
                    'ordered' => false,
                    'items' => [[
                        'id' => $this->id(6),
                        'content' => [$this->text(
                            'Item',
                            ['size-small', 'em']
                        )],
                    ]],
                    'presentation' => $this->presentation(),
                ]],
            ]],
        ]);

        $fallback = (new BlogDocumentV1CompatibilityProjector())->project(
            $document
        );
        $blocks = $fallback->blocks();

        self::assertSame(BlogDocument::VERSION, $fallback->version());
        self::assertSame(['strong'], $blocks[0]['content'][0]['marks']);
        self::assertSame(['strong'], $blocks[1]['content'][0]['marks']);
        self::assertSame(['em'], $blocks[1]['content'][1]['marks']);
        self::assertSame(
            ['strong', 'em'],
            $blocks[2]['content'][0]['marks']
        );
        self::assertSame(['em'], $blocks[3]['items'][0]['content'][0]['marks']);
    }

    /** @param list<string> $marks @return array<string, mixed> */
    private function layoutDocument(array $marks): array
    {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'section',
                'children' => [[
                    'id' => $this->id(2),
                    'type' => 'heading',
                    'level' => 2,
                    'content' => [$this->text('Section')],
                    'presentation' => $this->presentation(),
                ], [
                    'id' => $this->id(3),
                    'type' => 'paragraph',
                    'content' => [$this->text('Rich < copy', $marks)],
                    'presentation' => $this->presentation(),
                ]],
            ]],
        ];
    }

    /** @param list<string> $marks @return array<string, mixed> */
    private function text(string $text, array $marks = []): array
    {
        return ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    /** @return array{width: string, align: string} */
    private function presentation(): array
    {
        return ['width' => 'full', 'align' => 'start'];
    }

    private function renderer(): BlogDocumentHtmlRenderer
    {
        return new BlogDocumentHtmlRenderer(
            new class implements BlogImageResolverInterface {
                public function resolve(
                    string $mediaAssetPublicId
                ): ?BlogResolvedImage {
                    return null;
                }
            }
        );
    }

    private function id(int $number): string
    {
        return sprintf('30000000-0000-4000-8000-%012d', $number);
    }
}
