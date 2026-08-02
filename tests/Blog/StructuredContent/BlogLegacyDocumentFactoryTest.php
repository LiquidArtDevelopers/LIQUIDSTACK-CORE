<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentValidator;
use App\Core\Blog\StructuredContent\Document\BlogLegacyDocumentFactory;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use PHPUnit\Framework\TestCase;

final class BlogLegacyDocumentFactoryTest extends TestCase
{
    public function testItConvertsLegacyParagraphsAndLineBreaksWithoutWriting(): void
    {
        $uuids = new LegacyDocumentUuidSequence([
            $this->id(1),
            $this->id(2),
        ]);
        $document = (new BlogLegacyDocumentFactory($uuids))->create(
            " First line\r\nSecond\tline\r\n\r\n Third paragraph \r\n"
        );

        self::assertSame('article-basic-01', $document->template());
        self::assertSame(2, $document->blockCount());
        self::assertSame([
            'id' => $this->id(1),
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'First line', 'marks' => []],
                ['type' => 'break'],
                ['type' => 'text', 'text' => 'Second line', 'marks' => []],
            ],
        ], $document->blocks()[0]);
        self::assertSame([
            'id' => $this->id(2),
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'Third paragraph',
                'marks' => [],
            ]],
        ], $document->blocks()[1]);
        self::assertSame(
            "First line\nSecond line\n\nThird paragraph",
            (new BlogDocumentTextProjector())->project($document)
        );
        self::assertSame(2, $uuids->calls());
    }

    public function testEmptyLegacyBodyCreatesAnEmptyDocumentWithoutUuidCalls(): void
    {
        $uuids = new LegacyDocumentUuidSequence([]);
        $document = (new BlogLegacyDocumentFactory($uuids))->create(
            " \r\n\t\r\n "
        );

        self::assertSame([], $document->blocks());
        self::assertSame(0, $uuids->calls());
    }

    public function testLongAsciiLineIsSplitIntoAdjacentBoundedTextNodes(): void
    {
        $body = str_repeat(
            'a',
            BlogDocumentValidator::MAX_INLINE_TEXT_BYTES + 1
        );
        $document = (new BlogLegacyDocumentFactory(
            new LegacyDocumentUuidSequence([$this->id(1)])
        ))->create($body);
        $content = $document->blocks()[0]['content'];

        self::assertCount(2, $content);
        self::assertSame(
            BlogDocumentValidator::MAX_INLINE_TEXT_BYTES,
            strlen($content[0]['text'])
        );
        self::assertSame('a', $content[1]['text']);
        self::assertSame(
            $body,
            (new BlogDocumentTextProjector())->project($document)
        );
    }

    public function testLongUtf8LineIsNeverSplitInsideACharacter(): void
    {
        $body = str_repeat('é', 10_001);
        $document = (new BlogLegacyDocumentFactory(
            new LegacyDocumentUuidSequence([$this->id(1)])
        ))->create($body);
        $content = $document->blocks()[0]['content'];

        self::assertCount(2, $content);
        self::assertSame(20_000, strlen($content[0]['text']));
        self::assertSame(2, strlen($content[1]['text']));
        self::assertSame(1, preg_match('//u', $content[0]['text']));
        self::assertSame(1, preg_match('//u', $content[1]['text']));
        self::assertSame(
            $body,
            (new BlogDocumentTextProjector())->project($document)
        );
    }

    public function testExactParagraphLimitIsAcceptedAndOverflowIsRejected(): void
    {
        $paragraphs = array_fill(0, BlogDocument::MAX_BLOCKS, 'Paragraph');
        $uuids = [];
        for ($index = 1; $index <= BlogDocument::MAX_BLOCKS; ++$index) {
            $uuids[] = $this->id($index);
        }
        $document = (new BlogLegacyDocumentFactory(
            new LegacyDocumentUuidSequence($uuids)
        ))->create(implode("\n\n", $paragraphs));
        self::assertSame(BlogDocument::MAX_BLOCKS, $document->blockCount());

        $paragraphs[] = 'Overflow';
        $this->assertIssue(
            BlogDocumentException::INVALID_DOCUMENT,
            fn (): BlogDocument => (new BlogLegacyDocumentFactory(
                new LegacyDocumentUuidSequence([])
            ))->create(implode("\n\n", $paragraphs))
        );
    }

    public function testInlineNodeOverflowIsRejectedBeforeDocumentCreation(): void
    {
        $body = implode("\n", array_fill(0, 251, 'Line'));

        $this->assertIssue(
            BlogDocumentException::INVALID_INLINE,
            fn (): BlogDocument => (new BlogLegacyDocumentFactory(
                new LegacyDocumentUuidSequence([$this->id(1)])
            ))->create($body)
        );
    }

    public function testInvalidLegacyTextAndOversizedInputFailClosed(): void
    {
        foreach ([
            '<script>not legacy plain text</script>',
            "Invalid\x07control",
            "Invalid \xC3\x28",
            str_repeat('a', BlogDocument::MAX_BODY_TEXT_BYTES + 1),
        ] as $body) {
            $this->assertIssue(
                BlogDocumentException::INVALID_DOCUMENT,
                fn (): BlogDocument => (new BlogLegacyDocumentFactory(
                    new LegacyDocumentUuidSequence([])
                ))->create($body)
            );
        }
    }

    public function testCanonicalJsonLimitStillAppliesToLegacyInput(): void
    {
        $body = str_repeat('a', BlogDocument::MAX_BODY_TEXT_BYTES - 20);

        $this->assertIssue(
            BlogDocumentException::DOCUMENT_TOO_LARGE,
            fn (): BlogDocument => (new BlogLegacyDocumentFactory(
                new LegacyDocumentUuidSequence([$this->id(1)])
            ))->create($body)
        );
    }

    public function testInjectedGeneratorMustReturnUniqueCanonicalV4Ids(): void
    {
        $this->assertIssue(
            BlogDocumentException::INVALID_BLOCK,
            fn (): BlogDocument => (new BlogLegacyDocumentFactory(
                new LegacyDocumentUuidSequence(['not-a-uuid'])
            ))->create('Paragraph')
        );

        $same = $this->id(1);
        $this->assertIssue(
            BlogDocumentException::DUPLICATE_ID,
            fn (): BlogDocument => (new BlogLegacyDocumentFactory(
                new LegacyDocumentUuidSequence([$same, $same])
            ))->create("One\n\nTwo")
        );
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }

    /** @param callable(): mixed $operation */
    private function assertIssue(string $issueCode, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected legacy document conversion failure.');
        } catch (BlogDocumentException $exception) {
            self::assertSame($issueCode, $exception->issueCode());
            self::assertSame(
                'Invalid structured Blog document.',
                $exception->getMessage()
            );
        }
    }
}

final class LegacyDocumentUuidSequence implements UuidGeneratorInterface
{
    private int $position = 0;

    /** @param list<string> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function generateV4(): string
    {
        if (!isset($this->values[$this->position])) {
            throw new \RuntimeException('Unexpected UUID request.');
        }

        return $this->values[$this->position++];
    }

    public function calls(): int
    {
        return $this->position;
    }
}
