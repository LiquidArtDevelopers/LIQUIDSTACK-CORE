<?php

declare(strict_types=1);

use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredDocumentRecord;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredRevisionRecord;
use PHPUnit\Framework\TestCase;

final class BlogStructuredRecordIntegrityTest extends TestCase
{
    public function testCurrentRecordAcceptsOnlyMatchingPersistedIntegrityData(): void
    {
        $draft = $this->draft();
        $record = $this->documentRecord($draft);

        self::assertSame(7, $record->internalId());
        self::assertSame($draft, $record->snapshot());
        self::assertSame($this->id(10), $record->documentPublicId());
    }

    public function testRevisionPreservesVariantAndRevisionVersions(): void
    {
        $draft = $this->draft();
        $revision = new BlogStructuredRevisionRecord(
            8,
            $this->id(30),
            $this->id(20),
            3,
            9,
            $draft,
            $draft->schemaVersion(),
            $draft->templateKey(),
            $draft->documentBytes(),
            $draft->documentSha256(),
            $draft->bodyTextSha256(),
            $draft->snapshotSha256(),
            $this->id(40),
            new DateTimeImmutable('2026-08-01T10:00:00+00:00')
        );

        self::assertSame(3, $revision->revisionNumber());
        self::assertSame(9, $revision->variantLockVersion());
        self::assertSame($draft, $revision->snapshot());
    }

    /** @dataProvider corruptFieldProvider */
    public function testPersistedDriftFailsClosed(string $field): void
    {
        $draft = $this->draft();
        $values = [
            'schema' => $draft->schemaVersion(),
            'template' => $draft->templateKey(),
            'bytes' => $draft->documentBytes(),
            'document_hash' => $draft->documentSha256(),
            'body_hash' => $draft->bodyTextSha256(),
            'snapshot_hash' => $draft->snapshotSha256(),
        ];
        $values[$field] = match ($field) {
            'schema' => 2,
            'template' => 'article-unknown-99',
            'bytes' => $draft->documentBytes() + 1,
            default => str_repeat('0', 64),
        };

        try {
            new BlogStructuredDocumentRecord(
                7,
                $this->id(10),
                $this->id(20),
                $draft,
                $values['schema'],
                $values['template'],
                $values['bytes'],
                $values['document_hash'],
                $values['body_hash'],
                $values['snapshot_hash'],
                $this->id(40),
                $this->id(40),
                new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
                new DateTimeImmutable('2026-08-01T10:00:00+00:00')
            );
            self::fail('Persisted drift must be rejected.');
        } catch (BlogStructuredContentException $exception) {
            self::assertSame(
                BlogStructuredContentException::CORRUPT_DOCUMENT,
                $exception->issueCode()
            );
        }
    }

    public static function corruptFieldProvider(): iterable
    {
        yield ['schema'];
        yield ['template'];
        yield ['bytes'];
        yield ['document_hash'];
        yield ['body_hash'];
        yield ['snapshot_hash'];
    }

    private function documentRecord(
        BlogStructuredDraft $draft
    ): BlogStructuredDocumentRecord {
        return new BlogStructuredDocumentRecord(
            7,
            $this->id(10),
            $this->id(20),
            $draft,
            $draft->schemaVersion(),
            $draft->templateKey(),
            $draft->documentBytes(),
            $draft->documentSha256(),
            $draft->bodyTextSha256(),
            $draft->snapshotSha256(),
            $this->id(40),
            $this->id(40),
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            new DateTimeImmutable('2026-08-01T10:00:00+00:00')
        );
    }

    private function draft(): BlogStructuredDraft
    {
        return new BlogStructuredDraft(
            'Matrix heading',
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
                'blocks' => [[
                    'id' => $this->id(1),
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Follow the white rabbit.',
                        'marks' => [],
                    ]],
                ]],
            ]),
            'matrix-heading',
            'Matrix SEO title',
            'Matrix meta description',
            'Matrix excerpt'
        );
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }
}
