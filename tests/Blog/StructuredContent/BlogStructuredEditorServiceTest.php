<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Persistence\BlogRepositoryInterface;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredEditorService;
use App\Core\Blog\StructuredContent\Media\BlogMediaAvailabilityPortInterface;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredContentRepositoryInterface;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredDocumentRecord;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredRevisionRecord;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class StructuredEditorClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-02T00:30:00+00:00');
    }
}

final class StructuredEditorUuidSequence implements UuidGeneratorInterface
{
    /** @param list<string> $values */
    public function __construct(private array $values)
    {
    }

    public function generateV4(): string
    {
        $value = array_shift($this->values);
        if (!is_string($value)) {
            throw new \RuntimeException('UUID sequence exhausted.');
        }

        return $value;
    }

    public function remaining(): int
    {
        return count($this->values);
    }
}

final class StructuredEditorAuditRecorder implements
    BlogMutationAuditPortInterface
{
    /** @var list<string> */
    public array $operations = [];

    public function record(PDO $pdo, BlogMutationAuditEvent $event): void
    {
        if (!$pdo->inTransaction()) {
            throw new \RuntimeException('Audit escaped the transaction.');
        }
        $this->operations[] = $event->operation();
    }
}

final class BlogStructuredEditorServiceTest extends TestCase
{
    private const POST = '10000000-0000-4000-8000-000000000001';
    private const LOCALIZATION = '10000000-0000-4000-8000-000000000002';
    private const ACTOR = '10000000-0000-4000-8000-000000000003';
    private const DOCUMENT = '10000000-0000-4000-8000-000000000004';
    private const REVISION = '10000000-0000-4000-8000-000000000005';
    private const NEW_DOCUMENT = '10000000-0000-4000-8000-000000000006';
    private const NEW_REVISION = '10000000-0000-4000-8000-000000000007';

    public function testFirstSaveAdoptsLegacyVariantAndAppendsRevisionAtomically(): void
    {
        $pdo = $this->pdo();
        $draft = $this->structuredDraft('New structured body');
        $before = $this->variant(1, new BlogDraft('Old H1', 'Old body', 'old'));
        $stored = $this->variant(2, $draft->compatibilityDraft());
        $blog = $this->blogRepository($pdo, $before, $stored);
        $content = $this->createMock(
            BlogStructuredContentRepositoryInterface::class
        );
        $content->expects(self::once())->method('current')
            ->with(self::LOCALIZATION)->willReturn(null);
        $content->expects(self::once())->method('upsertCurrent')
            ->with(
                self::LOCALIZATION,
                self::NEW_DOCUMENT,
                self::identicalTo($draft),
                self::ACTOR,
                self::isInstanceOf(DateTimeImmutable::class)
            );
        $content->expects(self::once())->method('replaceCurrentMedia')
            ->with(self::LOCALIZATION, [], self::isInstanceOf(DateTimeImmutable::class));
        $content->expects(self::once())->method('appendRevision')
            ->with(
                self::LOCALIZATION,
                self::NEW_REVISION,
                2,
                self::identicalTo($draft),
                self::ACTOR,
                self::isInstanceOf(DateTimeImmutable::class)
            )->willReturn(1);
        $content->expects(self::once())->method('appendRevisionMedia')
            ->with(self::NEW_REVISION, [], self::isInstanceOf(DateTimeImmutable::class));
        $media = $this->createMock(BlogMediaAvailabilityPortInterface::class);
        $media->expects(self::once())->method('assertAvailable')
            ->with(self::identicalTo($pdo), []);
        $audit = new StructuredEditorAuditRecorder();
        $uuids = new StructuredEditorUuidSequence([
            self::NEW_DOCUMENT,
            self::NEW_REVISION,
        ]);

        $result = (new BlogStructuredEditorService(
            $blog,
            $content,
            $media,
            $uuids,
            new StructuredEditorClock(),
            $audit
        ))->save(
            static fn (PDO $transaction): string => self::ACTOR,
            self::POST,
            'es',
            1,
            $draft
        );

        self::assertSame($stored, $result);
        self::assertSame(['save'], $audit->operations);
        self::assertSame(0, $uuids->remaining());
        self::assertFalse($pdo->inTransaction());
    }

    public function testExactStructuredSnapshotIsAuthorizedButDoesNotWriteOrAudit(): void
    {
        $pdo = $this->pdo();
        $draft = $this->structuredDraft('Already current');
        $variant = $this->variant(4, $draft->compatibilityDraft());
        $blog = $this->blogRepository($pdo, $variant, null);
        $blog->expects(self::never())->method('updateDraft');
        $blog->expects(self::never())->method('touchPost');
        $content = $this->createMock(
            BlogStructuredContentRepositoryInterface::class
        );
        $content->method('current')->willReturn($this->documentRecord($draft));
        $content->expects(self::never())->method('upsertCurrent');
        $content->expects(self::never())->method('appendRevision');
        $media = $this->createMock(BlogMediaAvailabilityPortInterface::class);
        $media->expects(self::once())->method('assertAvailable');
        $audit = new StructuredEditorAuditRecorder();
        $uuids = new StructuredEditorUuidSequence([]);

        $result = (new BlogStructuredEditorService(
            $blog,
            $content,
            $media,
            $uuids,
            new StructuredEditorClock(),
            $audit
        ))->save(
            static fn (PDO $transaction): string => self::ACTOR,
            self::POST,
            'es',
            4,
            $draft
        );

        self::assertSame($variant, $result);
        self::assertSame([], $audit->operations);
        self::assertSame(0, $uuids->remaining());
    }

    public function testRestoreAlwaysCreatesANewRevisionAndUsesRestoreAuditCode(): void
    {
        $pdo = $this->pdo();
        $draft = $this->structuredDraft('Restored snapshot');
        $before = $this->variant(5, $draft->compatibilityDraft());
        $stored = $this->variant(6, $draft->compatibilityDraft());
        $blog = $this->blogRepository($pdo, $before, $stored, true);
        $content = $this->createMock(
            BlogStructuredContentRepositoryInterface::class
        );
        $content->expects(self::once())->method('revision')
            ->with(self::REVISION)->willReturn($this->revisionRecord($draft));
        $content->expects(self::once())->method('current')
            ->willReturn($this->documentRecord($draft));
        $content->expects(self::once())->method('upsertCurrent')
            ->with(
                self::LOCALIZATION,
                self::DOCUMENT,
                self::identicalTo($draft),
                self::ACTOR,
                self::isInstanceOf(DateTimeImmutable::class)
            );
        $content->expects(self::once())->method('replaceCurrentMedia');
        $content->expects(self::once())->method('appendRevision')
            ->with(
                self::LOCALIZATION,
                self::NEW_REVISION,
                6,
                self::identicalTo($draft),
                self::ACTOR,
                self::isInstanceOf(DateTimeImmutable::class)
            )->willReturn(3);
        $content->expects(self::once())->method('appendRevisionMedia');
        $media = $this->createMock(BlogMediaAvailabilityPortInterface::class);
        $media->expects(self::once())->method('assertAvailable');
        $audit = new StructuredEditorAuditRecorder();

        $result = (new BlogStructuredEditorService(
            $blog,
            $content,
            $media,
            new StructuredEditorUuidSequence([self::NEW_REVISION]),
            new StructuredEditorClock(),
            $audit
        ))->restore(
            static fn (PDO $transaction): string => self::ACTOR,
            self::POST,
            'es',
            5,
            self::REVISION
        );

        self::assertSame($stored, $result);
        self::assertSame(['restore'], $audit->operations);
    }

    public function testStructuredFailureRollsBackAndPreventsAudit(): void
    {
        $pdo = $this->pdo();
        $draft = $this->structuredDraft('Will roll back');
        $before = $this->variant(1, new BlogDraft('Old', 'Old', 'old'));
        $stored = $this->variant(2, $draft->compatibilityDraft());
        $blog = $this->blogRepository($pdo, $before, $stored);
        $content = $this->createMock(
            BlogStructuredContentRepositoryInterface::class
        );
        $content->method('current')->willReturn(null);
        $content->method('appendRevision')->willThrowException(
            new BlogStructuredContentException(
                BlogStructuredContentException::STORAGE_UNAVAILABLE
            )
        );
        $media = $this->createMock(BlogMediaAvailabilityPortInterface::class);
        $audit = new StructuredEditorAuditRecorder();
        $service = new BlogStructuredEditorService(
            $blog,
            $content,
            $media,
            new StructuredEditorUuidSequence([
                self::NEW_DOCUMENT,
                self::NEW_REVISION,
            ]),
            new StructuredEditorClock(),
            $audit
        );

        try {
            $service->save(
                static fn (PDO $transaction): string => self::ACTOR,
                self::POST,
                'es',
                1,
                $draft
            );
            self::fail('Structured failure should escape.');
        } catch (BlogStructuredContentException $exception) {
            self::assertSame(
                BlogStructuredContentException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
        }
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $audit->operations);
    }

    private function blogRepository(
        PDO $pdo,
        BlogPostVariant $before,
        ?BlogPostVariant $stored,
        bool $readBeforeTransaction = false
    ): BlogRepositoryInterface {
        $repository = $this->createMock(BlogRepositoryInterface::class);
        $repository->method('transactional')->willReturnCallback(
            static function (callable $operation) use ($pdo) {
                $pdo->beginTransaction();
                try {
                    $result = $operation($pdo);
                    $pdo->commit();
                    return $result;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $exception;
                }
            }
        );
        $repository->method('lockVariant')->willReturn($before);
        $repository->method('slugExists')->willReturn(false);
        $repository->method('updateDraft')->willReturn(true);
        if ($readBeforeTransaction) {
            $repository->method('variant')->willReturnOnConsecutiveCalls(
                $before,
                $stored
            );
        } elseif ($stored !== null) {
            $repository->method('variant')->willReturn($stored);
        }

        return $repository;
    }

    private function structuredDraft(string $body): BlogStructuredDraft
    {
        return new BlogStructuredDraft(
            'Matrix H1',
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => 'article-basic-01',
                'blocks' => [[
                    'id' => '20000000-0000-4000-8000-000000000001',
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => $body,
                        'marks' => [],
                    ]],
                ]],
            ]),
            'matrix-slug',
            'Matrix SEO title',
            'Matrix meta description.',
            'Matrix excerpt.'
        );
    }

    private function variant(int $version, BlogDraft $draft): BlogPostVariant
    {
        $now = (new StructuredEditorClock())->now();

        return new BlogPostVariant(
            self::POST,
            self::LOCALIZATION,
            'es',
            $draft,
            BlogPostVariant::DRAFT,
            null,
            $version,
            self::ACTOR,
            self::ACTOR,
            $now,
            $now
        );
    }

    private function documentRecord(
        BlogStructuredDraft $draft
    ): BlogStructuredDocumentRecord {
        $now = (new StructuredEditorClock())->now();

        return new BlogStructuredDocumentRecord(
            1,
            self::DOCUMENT,
            self::LOCALIZATION,
            $draft,
            $draft->schemaVersion(),
            $draft->templateKey(),
            $draft->documentBytes(),
            $draft->documentSha256(),
            $draft->bodyTextSha256(),
            $draft->snapshotSha256(),
            self::ACTOR,
            self::ACTOR,
            $now,
            $now
        );
    }

    private function revisionRecord(
        BlogStructuredDraft $draft
    ): BlogStructuredRevisionRecord {
        $now = (new StructuredEditorClock())->now();

        return new BlogStructuredRevisionRecord(
            1,
            self::REVISION,
            self::LOCALIZATION,
            2,
            5,
            $draft,
            $draft->schemaVersion(),
            $draft->templateKey(),
            $draft->documentBytes(),
            $draft->documentSha256(),
            $draft->bodyTextSha256(),
            $draft->snapshotSha256(),
            self::ACTOR,
            $now
        );
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
