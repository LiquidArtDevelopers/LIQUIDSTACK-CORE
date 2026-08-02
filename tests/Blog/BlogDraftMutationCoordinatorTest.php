<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\Editing\BlogDraftMutationCoordinator;
use App\Core\Blog\Editing\BlogPlainDraftWriteGuardInterface;
use App\Core\Blog\Persistence\BlogRepositoryInterface;
use App\Core\WebAdmin\Support\ClockInterface;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class CoordinatorClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-01T10:00:00+00:00');
    }
}

final class CoordinatorAuditRecorder implements BlogMutationAuditPortInterface
{
    /** @var list<string> */
    public array $events = [];

    public function record(PDO $pdo, BlogMutationAuditEvent $event): void
    {
        $this->events[] = 'audit:' . $event->operation();
    }
}

final class CoordinatorPlainGuard implements BlogPlainDraftWriteGuardInterface
{
    public int $calls = 0;

    public function assertPlainSaveAllowed(
        PDO $pdo,
        string $localizationPublicId
    ): void {
        ++$this->calls;
    }
}

final class BlogDraftMutationCoordinatorTest extends TestCase
{
    private const POST = '00000000-0000-4000-8000-000000000001';
    private const LOCALIZATION = '00000000-0000-4000-8000-000000000002';
    private const ACTOR = '00000000-0000-4000-8000-000000000003';

    public function testAuthorizedNoOpDoesNotWriteIncrementOrAudit(): void
    {
        $repository = $this->createMock(BlogRepositoryInterface::class);
        $current = $this->variant(4, new BlogDraft('H1', 'Body', 'slug'));
        $repository->expects(self::once())->method('lockVariant')
            ->willReturn($current);
        $repository->expects(self::once())->method('slugExists')
            ->willReturn(false);
        $repository->expects(self::never())->method('updateDraft');
        $repository->expects(self::never())->method('touchPost');
        $repository->expects(self::never())->method('variant');
        $audit = new CoordinatorAuditRecorder();
        $pdo = new PDO('sqlite::memory:');
        $beforeCalls = 0;

        $stored = (new BlogDraftMutationCoordinator(
            $repository,
            new CoordinatorClock(),
            $audit
        ))->saveWithinTransaction(
            $pdo,
            static fn (PDO $transaction): string => self::ACTOR,
            self::POST,
            'es',
            4,
            $current->draft(),
            static function () use (&$beforeCalls): bool {
                ++$beforeCalls;
                return false;
            },
            static function (): void {
                self::fail('After-write hook must not run for a no-op.');
            }
        );

        self::assertSame($current, $stored);
        self::assertSame(1, $beforeCalls);
        self::assertSame([], $audit->events);
    }

    public function testHooksAndAuditRunInDeterministicWriteOrder(): void
    {
        $repository = $this->createMock(BlogRepositoryInterface::class);
        $before = $this->variant(2, new BlogDraft('H1', 'Old', 'slug'));
        $draft = new BlogDraft('H1', 'New', 'slug');
        $after = $this->variant(3, $draft);
        $events = [];
        $repository->method('lockVariant')->willReturn($before);
        $repository->method('slugExists')->willReturn(false);
        $repository->expects(self::once())->method('updateDraft')
            ->willReturnCallback(static function () use (&$events): bool {
                $events[] = 'update';
                return true;
            });
        $repository->expects(self::once())->method('touchPost')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'touch';
            });
        $repository->expects(self::once())->method('variant')
            ->willReturnCallback(static function () use (&$events, $after) {
                $events[] = 'load';
                return $after;
            });
        $audit = new class($events) implements BlogMutationAuditPortInterface {
            /** @param list<string> $events */
            public function __construct(private array &$events) {}
            public function record(PDO $pdo, BlogMutationAuditEvent $event): void
            {
                $this->events[] = 'audit';
            }
        };

        $stored = (new BlogDraftMutationCoordinator(
            $repository,
            new CoordinatorClock(),
            $audit
        ))->saveWithinTransaction(
            new PDO('sqlite::memory:'),
            static function (PDO $transaction) use (&$events): string {
                $events[] = 'actor';
                return self::ACTOR;
            },
            self::POST,
            'es',
            2,
            $draft,
            static function () use (&$events): bool {
                $events[] = 'before';
                return true;
            },
            static function () use (&$events): void {
                $events[] = 'after';
            }
        );

        self::assertSame($after, $stored);
        self::assertSame(
            ['actor', 'before', 'update', 'touch', 'load', 'after', 'audit'],
            $events
        );
    }

    public function testCallerMayLabelARevisionRestoreDistinctly(): void
    {
        $repository = $this->createMock(BlogRepositoryInterface::class);
        $before = $this->variant(2, new BlogDraft('H1', 'Old', 'slug'));
        $draft = new BlogDraft('H1', 'Restored', 'slug');
        $after = $this->variant(3, $draft);
        $repository->method('lockVariant')->willReturn($before);
        $repository->method('slugExists')->willReturn(false);
        $repository->method('updateDraft')->willReturn(true);
        $repository->method('variant')->willReturn($after);
        $audit = new CoordinatorAuditRecorder();

        (new BlogDraftMutationCoordinator(
            $repository,
            new CoordinatorClock(),
            $audit
        ))->saveWithinTransaction(
            new PDO('sqlite::memory:'),
            static fn (PDO $transaction): string => self::ACTOR,
            self::POST,
            'es',
            2,
            $draft,
            null,
            null,
            BlogMutationAuditEvent::RESTORE
        );

        self::assertSame(['audit:restore'], $audit->events);
    }

    public function testBlogServiceInvokesOptionalPlainGuardInsideTransaction(): void
    {
        $repository = $this->createMock(BlogRepositoryInterface::class);
        $pdo = new PDO('sqlite::memory:');
        $current = $this->variant(1, new BlogDraft('H1', 'Old', 'slug'));
        $stored = $this->variant(2, new BlogDraft('H1', 'New', 'slug'));
        $repository->method('transactional')->willReturnCallback(
            static fn (callable $operation) => $operation($pdo)
        );
        $repository->method('lockVariant')->willReturn($current);
        $repository->method('slugExists')->willReturn(false);
        $repository->method('updateDraft')->willReturn(true);
        $repository->method('variant')->willReturn($stored);
        $guard = new CoordinatorPlainGuard();

        $result = (new BlogService(
            $repository,
            clock: new CoordinatorClock(),
            plainDraftWriteGuard: $guard
        ))->saveDraft(
            static fn (PDO $transaction): string => self::ACTOR,
            self::POST,
            'es',
            1,
            $stored->draft()
        );

        self::assertSame($stored, $result);
        self::assertSame(1, $guard->calls);
    }

    private function variant(int $lockVersion, BlogDraft $draft): BlogPostVariant
    {
        $now = new DateTimeImmutable('2026-08-01T10:00:00+00:00');

        return new BlogPostVariant(
            self::POST,
            self::LOCALIZATION,
            'es',
            $draft,
            BlogPostVariant::DRAFT,
            null,
            $lockVersion,
            self::ACTOR,
            self::ACTOR,
            $now,
            $now
        );
    }
}
