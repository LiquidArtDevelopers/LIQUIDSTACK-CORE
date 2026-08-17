<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV1CompatibilityProjector;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use PHPUnit\Framework\TestCase;

final class BlogTextListColorContractTest extends TestCase
{
    public function testTextFlowRendersParagraphsAndControlledListsServerSide(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->paragraph([
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Wake up',
                            'marks' => ['strong'],
                        ],
                        $this->text(', '),
                        [
                            'type' => 'link',
                            'text' => 'Neo',
                            'marks' => ['em'],
                            'href' => '/neo',
                            'title' => 'Neo',
                            'target' => 'new',
                        ],
                    ],
                ],
                [
                    'type' => 'list',
                    'ordered' => true,
                    'items' => [
                        ['content' => [$this->text('Follow the white rabbit.')]],
                        ['content' => [$this->text('Choose the red pill.')]],
                    ],
                ],
            ]),
        ]));

        $html = $this->renderer()->render($document);

        self::assertStringContainsString(
            '<div id="blog-block-' . $this->id(3)
                . '" class="blogDocument__module',
            $html
        );
        self::assertStringContainsString(
            '<p class="blogDocument__textParagraph"><strong>Wake up</strong>',
            $html
        );
        self::assertStringContainsString(
            '<ol class="blogDocument__textList">',
            $html
        );
        self::assertStringContainsString(
            '<strong>Wake up</strong>',
            $html
        );
        self::assertStringContainsString(
            'href="/neo" title="Neo" target="_blank" '
                . 'rel="noopener noreferrer"><em>Neo</em></a>',
            $html
        );
        self::assertStringContainsString(
            "Wake up, Neo\n\n1. Follow the white rabbit.",
            (new BlogDocumentTextProjector())->project($document)
        );

        $fallback = (new BlogDocumentV1CompatibilityProjector())
            ->project($document);
        self::assertSame(BlogDocument::VERSION, $fallback->version());
        self::assertSame('paragraph', $fallback->blocks()[1]['type']);
        self::assertSame(
            'link',
            $fallback->blocks()[1]['content'][2]['type']
        );
    }

    public function testListAcceptsOnlyItsModuleColorAndRendersTokenOrRgba(): void
    {
        foreach (['color03', 'rgba(17, 34, 51, 0.5)'] as $color) {
            $list = [
                'id' => $this->id(3),
                'type' => 'list',
                'ordered' => false,
                'marker' => 'disc',
                'items' => [[
                    'id' => $this->id(4),
                    'content' => [$this->text('There is no spoon.')],
                ]],
                'presentation' => $this->presentation() + [
                    'text_color' => $color,
                ],
            ];
            $html = $this->renderer()->render(
                BlogDocument::fromArray($this->document([$list]))
            );

            self::assertStringContainsString(
                $color === 'color03'
                    ? 'blogDocument__module--text-color03'
                    : 'data-blog-text-rgba="rgba(17, 34, 51, 0.5)"',
                $html
            );
        }
    }

    public function testIncompleteTextFlowAndEmptyListRemainDraftable(): void
    {
        $codec = new BlogDocumentCodec();
        $draft = $this->document([
            $this->paragraph([[
                'type' => 'paragraph',
                'content' => [],
            ]]),
            [
                'id' => $this->id(4),
                'type' => 'list',
                'ordered' => false,
                'marker' => 'disc',
                'items' => [],
                'presentation' => $this->presentation() + [
                    'text_color' => 'default',
                ],
            ],
        ]);

        $decoded = $codec->decodeDraft(json_encode($draft, JSON_THROW_ON_ERROR));

        $children = $decoded->blocks()[0]['children'];
        self::assertSame([], $children[1]['content'][0]['content']);
        self::assertSame([], $children[2]['items']);
        self::assertSame('default', $children[2]['presentation']['text_color']);
    }

    /** @param list<array<string, mixed>> $modules */
    private function document(array $modules): array
    {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'section',
                'children' => array_merge([[
                    'id' => $this->id(2),
                    'type' => 'heading',
                    'level' => 2,
                    'content' => [$this->text('Matrix')],
                    'presentation' => $this->presentation(),
                ]], $modules),
            ]],
        ];
    }

    /** @param list<array<string, mixed>> $content */
    private function paragraph(array $content): array
    {
        return [
            'id' => $this->id(3),
            'type' => 'paragraph',
            'content' => $content,
            'presentation' => $this->presentation(),
        ];
    }

    /** @return array<string, mixed> */
    private function text(string $value): array
    {
        return ['type' => 'text', 'text' => $value, 'marks' => []];
    }

    /** @return array<string, string> */
    private function presentation(): array
    {
        return [
            'width' => 'full',
            'align' => 'start',
            'text_align' => 'start',
            'size' => 'm',
        ];
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
        return sprintf('50000000-0000-4000-8000-%012d', $number);
    }
}
