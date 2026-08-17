<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV1CompatibilityProjector;
use PHPUnit\Framework\TestCase;

final class BlogIncompleteDraftContractTest extends TestCase
{
    public function testDraftDecoderAcceptsAnEmptyV2RootButStrictDecoderDoesNot(): void
    {
        $codec = new BlogDocumentCodec();
        $json = json_encode($this->document([]), JSON_THROW_ON_ERROR);

        $draft = $codec->decodeDraft($json);

        self::assertSame(BlogDocument::LAYOUT_VERSION, $draft->version());
        self::assertSame([], $draft->blocks());

        $this->expectException(BlogDocumentException::class);
        $codec->decode($json);
    }

    public function testDraftDecoderAcceptsEmptyTextModulesWithoutWeakeningV1(): void
    {
        $codec = new BlogDocumentCodec();
        $document = $this->document([[
            'id' => $this->id(1),
            'type' => 'section',
            'children' => [
                $this->heading(2, []),
                $this->paragraph(3, []),
                [
                    'id' => $this->id(4),
                    'type' => 'list',
                    'ordered' => false,
                    'presentation' => $this->presentation(),
                    'items' => [[
                        'id' => $this->id(5),
                        'content' => [$this->text('')],
                    ]],
                ],
                [
                    'id' => $this->id(6),
                    'type' => 'callout',
                    'tone' => 'neutral',
                    'content' => [],
                    'presentation' => $this->presentation(),
                ],
                [
                    'id' => $this->id(7),
                    'type' => 'quote',
                    'content' => [$this->text('')],
                    'author' => null,
                    'source' => null,
                    'preset' => 'default',
                    'presentation' => $this->presentation(),
                ],
            ],
        ]]);
        $json = json_encode($document, JSON_THROW_ON_ERROR);

        $draft = $codec->decodeDraft($json);
        $fallback = (new BlogDocumentV1CompatibilityProjector())->project(
            $draft
        );

        self::assertSame([], $fallback->blocks());
        self::assertSame('', (new BlogDocumentTextProjector())->project($draft));
        self::assertSame($draft->toArray(), $codec->decodeDraft(
            $codec->encode($draft)
        )->toArray());

        $v1Paragraph = $this->paragraph(8, []);
        unset($v1Paragraph['presentation']);
        $v1 = [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [$v1Paragraph],
        ];
        $this->expectException(BlogDocumentException::class);
        $codec->decodeDraft(json_encode($v1, JSON_THROW_ON_ERROR));
    }

    public function testCompatibilityOmitsBlankItemsAndNeverInventsQuoteCopy(): void
    {
        $codec = new BlogDocumentCodec();
        $draft = $codec->decodeDraft(json_encode($this->document([[
            'id' => $this->id(1),
            'type' => 'section',
            'children' => [
                $this->heading(2, []),
                [
                    'id' => $this->id(3),
                    'type' => 'list',
                    'ordered' => false,
                    'presentation' => $this->presentation(),
                    'items' => [
                        [
                            'id' => $this->id(4),
                            'content' => [$this->text('')],
                        ],
                        [
                            'id' => $this->id(5),
                            'content' => [$this->text('Neo')],
                        ],
                    ],
                ],
                [
                    'id' => $this->id(6),
                    'type' => 'quote',
                    'content' => [],
                    'author' => 'Morpheus',
                    'source' => null,
                    'preset' => 'default',
                    'presentation' => $this->presentation(),
                ],
            ],
        ]]), JSON_THROW_ON_ERROR));
        $fallback = (new BlogDocumentV1CompatibilityProjector())->project(
            $draft
        );
        $projector = new BlogDocumentTextProjector();

        self::assertCount(2, $fallback->blocks());
        self::assertCount(1, $fallback->blocks()[0]['items']);
        self::assertSame(
            $projector->project($draft),
            $projector->project($fallback)
        );
        self::assertSame("- Neo\n\nMorpheus", $projector->project($fallback));
        self::assertStringNotContainsString('—', $projector->project($fallback));
    }

    public function testDraftDecoderStillRejectsDuplicateIdsAndUnknownFields(): void
    {
        $codec = new BlogDocumentCodec();
        $document = $this->document([[
            'id' => $this->id(1),
            'type' => 'section',
            'children' => [
                $this->heading(2, []),
                $this->paragraph(2, []),
            ],
        ]]);

        try {
            $codec->decodeDraft(json_encode($document, JSON_THROW_ON_ERROR));
            self::fail('Duplicate structural ids must remain invalid.');
        } catch (BlogDocumentException) {
        }

        $document['blocks'][0]['children'][0]['unknown'] = true;
        $this->expectException(BlogDocumentException::class);
        $codec->decodeDraft(json_encode($document, JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string, mixed>> $blocks */
    private function document(array $blocks): array
    {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => $blocks,
        ];
    }

    /** @param list<array<string, mixed>> $content */
    private function heading(int $number, array $content): array
    {
        return [
            'id' => $this->id($number),
            'type' => 'heading',
            'level' => 2,
            'content' => $content,
            'presentation' => $this->presentation(),
        ];
    }

    /** @param list<array<string, mixed>> $content */
    private function paragraph(int $number, array $content): array
    {
        return [
            'id' => $this->id($number),
            'type' => 'paragraph',
            'content' => $content,
            'presentation' => $this->presentation(),
        ];
    }

    /** @return array{width: string, align: string} */
    private function presentation(): array
    {
        return ['width' => 'full', 'align' => 'start'];
    }

    /** @return array{type: string, text: string, marks: list<string>} */
    private function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text, 'marks' => []];
    }

    private function id(int $number): string
    {
        return sprintf(
            '50000000-0000-4000-8000-%012d',
            $number
        );
    }
}
