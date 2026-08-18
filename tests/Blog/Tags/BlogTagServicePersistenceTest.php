<?php

declare(strict_types=1);

namespace Tests\Blog\Tags;

use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\EditorialWorkflow\BlogEditorialVariantState;
use App\Core\Blog\Tags\BlogTag;
use App\Core\Blog\Tags\BlogTagException;
use App\Core\Blog\Tags\BlogTagService;
use App\Core\Blog\Tags\Persistence\BlogTagPersistenceConflict;
use App\Core\Blog\Tags\Persistence\BlogTagPersistenceException;
use App\Core\Blog\Tags\Persistence\BlogTagRepositoryInterface;
use App\Core\Blog\Tags\Persistence\PdoBlogTagRepository;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlogTagTestClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-18T12:34:56.123456+00:00');
    }
}

final class BlogTagUuidSequence implements UuidGeneratorInterface
{
    /** @param list<string> $values */
    public function __construct(private array $values)
    {
    }

    public function generateV4(): string
    {
        $value = array_shift($this->values);
        if (!is_string($value)) {
            throw new RuntimeException('UUID sequence exhausted.');
        }

        return $value;
    }

    public function remaining(): int
    {
        return count($this->values);
    }
}

final class BlogTagAuditRecorder implements BlogMutationAuditPortInterface
{
    /** @var list<BlogMutationAuditEvent> */
    public array $events = [];

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function record(PDO $pdo, BlogMutationAuditEvent $event): void
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Tag audit escaped transaction.');
        }
        if ($this->fail) {
            throw new RuntimeException('Audit unavailable.');
        }
        $this->events[] = $event;
    }
}

final class BlogTagRetryPdo extends PDO
{
    public int $beginCalls = 0;
    public int $rollbackCalls = 0;
    public int $commitCalls = 0;
    private bool $active = false;

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            default => null,
        };
    }

    public function beginTransaction(): bool
    {
        ++$this->beginCalls;
        $this->active = true;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function commit(): bool
    {
        ++$this->commitCalls;
        $this->active = false;

        return true;
    }

    public function rollBack(): bool
    {
        ++$this->rollbackCalls;
        $this->active = false;

        return true;
    }
}

final class BlogTagServicePersistenceTest extends TestCase
{
    private const POST = '10000000-0000-4000-8000-000000000001';
    private const LOCALIZATION = '20000000-0000-4000-8000-000000000001';
    private const ACTOR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testAssignmentUsesPrivateWorkspaceCanonicalOrderAndNoOp(): void
    {
        [$pdo, $scope] = $this->database();
        $this->seedVariant($pdo, $scope, self::POST, self::LOCALIZATION, 'es');
        $uuids = new BlogTagUuidSequence([$this->id(10), $this->id(11)]);
        $audit = new BlogTagAuditRecorder();
        $repository = new PdoBlogTagRepository($pdo, $scope);
        $service = new BlogTagService(
            $repository,
            $uuids,
            new BlogTagTestClock(),
            $audit
        );

        $assigned = $service->assignToVariant(
            $this->gate(),
            self::POST,
            'es',
            1,
            0,
            ' C++, C# '
        );
        self::assertSame(1, $assigned->workspaceVersion());
        self::assertSame(
            ['c', 'c-' . substr(hash('sha256', 'c++'), 0, 16)],
            array_column(array_map(
                static fn ($tag): array => $tag->toPresentationArray(),
                $assigned->tags()
            ), 'slug')
        );
        self::assertSame([], $repository->liveTags(self::LOCALIZATION));
        self::assertCount(2, $repository->workspaceTags(self::LOCALIZATION));
        self::assertCount(1, $audit->events);
        self::assertSame(BlogMutationAuditEvent::SAVE, $audit->events[0]->operation());

        $noOp = $service->assignToVariant(
            $this->gate(),
            self::POST,
            'es',
            1,
            1,
            'c#,c++'
        );
        self::assertSame(1, $noOp->workspaceVersion());
        self::assertCount(1, $audit->events);
        self::assertSame(0, $uuids->remaining());

        $empty = $service->assignToVariant(
            $this->gate(),
            self::POST,
            'es',
            1,
            1,
            ''
        );
        self::assertSame(2, $empty->workspaceVersion());
        self::assertSame([], $empty->tags());
        self::assertSame([], $repository->workspaceTags(self::LOCALIZATION));
        self::assertSame(2, $service->workspaceVersion(self::POST, 'es'));
        self::assertCount(2, $audit->events);
    }

    public function testFirstWriterNameIsCanonicalAndTermsAreLocalized(): void
    {
        [$pdo, $scope] = $this->database();
        $this->seedVariant($pdo, $scope, self::POST, self::LOCALIZATION, 'es');
        $enPost = $this->id(20);
        $enLocalization = $this->localizationId(20);
        $this->seedVariant($pdo, $scope, $enPost, $enLocalization, 'en');
        $repository = new PdoBlogTagRepository($pdo, $scope);
        $service = new BlogTagService(
            $repository,
            new BlogTagUuidSequence([$this->id(21), $this->id(22)]),
            new BlogTagTestClock()
        );

        $service->assignToVariant(
            $this->gate(), self::POST, 'es', 1, 0, 'Fiscal'
        );
        $same = $service->assignToVariant(
            $this->gate(), self::POST, 'es', 1, 1, 'FISCAL'
        );
        self::assertSame('Fiscal', $same->tags()[0]->name());
        $english = $service->assignToVariant(
            $this->gate(), $enPost, 'en', 1, 0, 'FISCAL'
        );
        self::assertSame('FISCAL', $english->tags()[0]->name());
        self::assertNotSame(
            $same->tags()[0]->publicId(),
            $english->tags()[0]->publicId()
        );
        self::assertSame(2, (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $scope->quotedTable('tags', 'sqlite')
        )->fetchColumn());
    }

    public function testStaleCasAcrossTwoPdoConnectionsRollsBack(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ls-blog-tags-');
        self::assertIsString($file);
        try {
            $firstPdo = $this->pdo('sqlite:' . $file);
            $scope = $this->scope();
            $this->applySchema($firstPdo, $scope);
            $this->seedVariant(
                $firstPdo,
                $scope,
                self::POST,
                self::LOCALIZATION,
                'es'
            );
            $secondPdo = $this->pdo('sqlite:' . $file);
            $first = new BlogTagService(
                new PdoBlogTagRepository($firstPdo, $scope),
                new BlogTagUuidSequence([$this->id(30), $this->id(31)]),
                new BlogTagTestClock()
            );
            $second = new BlogTagService(
                new PdoBlogTagRepository($secondPdo, $scope),
                new BlogTagUuidSequence([$this->id(32)]),
                new BlogTagTestClock()
            );
            $first->assignToVariant(
                $this->gate(), self::POST, 'es', 1, 0, 'Fiscal'
            );
            self::assertSame(1, $second->workspaceVersion(self::POST, 'es'));
            $first->assignToVariant(
                $this->gate(), self::POST, 'es', 1, 1, 'Fiscal, Laboral'
            );
            $this->expectTagIssue(
                BlogTagException::LOCK_CONFLICT,
                fn () => $second->assignToVariant(
                    $this->gate(), self::POST, 'es', 1, 1, 'Legal'
                )
            );
            self::assertSame(2, $second->workspaceVersion(self::POST, 'es'));
            self::assertSame(
                ['fiscal', 'laboral'],
                array_map(
                    static fn ($tag): string => $tag->slug(),
                    $second->assignedToVariant(self::POST, 'es')
                )
            );
            self::assertSame(2, (int) $secondPdo->query(
                'SELECT COUNT(*) FROM '
                    . $scope->quotedTable('tags', 'sqlite')
            )->fetchColumn());
        } finally {
            if (isset($first)) {
                unset($first);
            }
            if (isset($second)) {
                unset($second);
            }
            if (isset($firstPdo)) {
                unset($firstPdo);
            }
            if (isset($secondPdo)) {
                unset($secondPdo);
            }
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testAuditFailureRollsBackTermAndWorkspace(): void
    {
        [$pdo, $scope] = $this->database();
        $this->seedVariant($pdo, $scope, self::POST, self::LOCALIZATION, 'es');
        $service = new BlogTagService(
            new PdoBlogTagRepository($pdo, $scope),
            new BlogTagUuidSequence([$this->id(40)]),
            new BlogTagTestClock(),
            new BlogTagAuditRecorder(true)
        );

        $this->expectTagIssue(
            BlogTagException::STORAGE_UNAVAILABLE,
            fn () => $service->assignToVariant(
                $this->gate(), self::POST, 'es', 1, 0, 'Rollback'
            )
        );
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $scope->quotedTable('tags', 'sqlite')
        )->fetchColumn());
        self::assertSame(0, $service->workspaceVersion(self::POST, 'es'));
        self::assertFalse($pdo->inTransaction());
    }

    public function testVariantLockAndWorkspaceCasFailClosed(): void
    {
        [$pdo, $scope] = $this->database();
        $this->seedVariant($pdo, $scope, self::POST, self::LOCALIZATION, 'es');
        $service = new BlogTagService(
            new PdoBlogTagRepository($pdo, $scope),
            new BlogTagUuidSequence([$this->id(50)]),
            new BlogTagTestClock()
        );
        $this->expectTagIssue(
            BlogTagException::LOCK_CONFLICT,
            fn () => $service->assignToVariant(
                $this->gate(), self::POST, 'es', 2, 0, 'Fiscal'
            )
        );
        $service->assignToVariant(
            $this->gate(), self::POST, 'es', 1, 0, 'Fiscal'
        );
        $this->expectTagIssue(
            BlogTagException::LOCK_CONFLICT,
            fn () => $service->assignToVariant(
                $this->gate(), self::POST, 'es', 1, 0, 'Legal'
            )
        );
        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $scope->quotedTable('tags', 'sqlite')
        )->fetchColumn());
    }

    public function testRepositoryRetriesOneInnoDbDeadlockTransaction(): void
    {
        $pdo = new BlogTagRetryPdo();
        $repository = new PdoBlogTagRepository($pdo, $this->scope());
        $attempts = 0;

        $result = $repository->transactional(
            function () use (&$attempts): string {
                ++$attempts;
                if ($attempts === 1) {
                    throw $this->simulatedDeadlock();
                }

                return 'retried';
            }
        );

        self::assertSame('retried', $result);
        self::assertSame(2, $attempts);
        self::assertSame(2, $pdo->beginCalls);
        self::assertSame(1, $pdo->rollbackCalls);
        self::assertSame(1, $pdo->commitCalls);
    }

    public function testRepositoryBoundsRepeatedInnoDbDeadlockRetry(): void
    {
        $pdo = new BlogTagRetryPdo();
        $repository = new PdoBlogTagRepository($pdo, $this->scope());
        $attempts = 0;

        try {
            $repository->transactional(
                function () use (&$attempts): never {
                    ++$attempts;
                    throw $this->simulatedDeadlock();
                }
            );
            self::fail('A repeated deadlock must fail after one retry.');
        } catch (BlogTagPersistenceException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        }

        self::assertSame(2, $attempts);
        self::assertSame(2, $pdo->beginCalls);
        self::assertSame(2, $pdo->rollbackCalls);
        self::assertSame(0, $pdo->commitCalls);
    }

    public function testProhibitedUnicodeFailsBeforePersistenceAndAudit(): void
    {
        $repository = $this->createMock(BlogTagRepositoryInterface::class);
        $repository->expects(self::never())->method('transactional');
        $audit = new BlogTagAuditRecorder();
        $service = new BlogTagService(
            $repository,
            new BlogTagUuidSequence([]),
            new BlogTagTestClock(),
            $audit
        );

        $this->expectTagIssue(
            BlogTagException::INVALID_INPUT,
            fn () => $service->assignToVariant(
                $this->gate(),
                self::POST,
                'es',
                1,
                0,
                "Fiscal\u{200B}Legal"
            )
        );
        self::assertSame([], $audit->events);
    }

    public function testDuplicateIdentityUsesLockedCurrentRead(): void
    {
        $repository = $this->createMock(BlogTagRepositoryInterface::class);
        $pdo = $this->pdo('sqlite::memory:');
        $winner = new BlogTag(
            $this->id(70),
            'es',
            'fiscal',
            'Fiscal',
            hash('sha256', 'fiscal')
        );
        $identityReads = [];
        $repository->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation($pdo)
        );
        $repository->method('variantState')->willReturn(
            new BlogEditorialVariantState(
                self::POST,
                self::LOCALIZATION,
                'es',
                BlogPostVariant::DRAFT,
                1
            )
        );
        $repository->method('workspaceState')->willReturn(null);
        $repository->method('assignmentVersion')->willReturn(0);
        $repository->method('workspaceTags')->willReturn(null);
        $repository->method('liveTags')->willReturn([]);
        $repository->method('tagByIdentity')->willReturnCallback(
            static function (
                string $locale,
                string $hash,
                bool $lock = false
            ) use (&$identityReads, $winner): ?BlogTag {
                $identityReads[] = $lock;
                return $lock ? $winner : null;
            }
        );
        $repository->method('tagBySlug')->willReturn(null);
        $repository->method('insertTag')->willThrowException(
            new BlogTagPersistenceConflict()
        );
        $repository->method('replaceWorkspace')->willReturn(1);

        $result = (new BlogTagService(
            $repository,
            new BlogTagUuidSequence([$this->id(71)]),
            new BlogTagTestClock()
        ))->assignToVariant(
            $this->gate(), self::POST, 'es', 1, 0, 'FISCAL'
        );

        self::assertSame([false, true], $identityReads);
        self::assertSame($winner->publicId(), $result->tags()[0]->publicId());
    }

    public function testDuplicateSlugUsesLockedCurrentReadThenHashSuffix(): void
    {
        $repository = $this->createMock(BlogTagRepositoryInterface::class);
        $pdo = $this->pdo('sqlite::memory:');
        $owner = new BlogTag(
            $this->id(80),
            'es',
            'c',
            'C++',
            hash('sha256', 'c++')
        );
        $identityReads = [];
        $slugReads = [];
        $insertions = [];
        $repository->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation($pdo)
        );
        $repository->method('variantState')->willReturn(
            new BlogEditorialVariantState(
                self::POST,
                self::LOCALIZATION,
                'es',
                BlogPostVariant::DRAFT,
                1
            )
        );
        $repository->method('workspaceState')->willReturn(null);
        $repository->method('assignmentVersion')->willReturn(0);
        $repository->method('workspaceTags')->willReturn(null);
        $repository->method('liveTags')->willReturn([]);
        $repository->method('tagByIdentity')->willReturnCallback(
            static function (
                string $locale,
                string $hash,
                bool $lock = false
            ) use (&$identityReads): ?BlogTag {
                $identityReads[] = $lock;
                return null;
            }
        );
        $repository->method('tagBySlug')->willReturnCallback(
            static function (
                string $locale,
                string $slug,
                bool $lock = false
            ) use (&$slugReads, $owner): ?BlogTag {
                $slugReads[] = $lock;
                return $lock ? $owner : null;
            }
        );
        $repository->method('insertTag')->willReturnCallback(
            static function (...$arguments) use (&$insertions): void {
                $insertions[] = $arguments[2] ?? null;
                if (count($insertions) === 1) {
                    throw new BlogTagPersistenceConflict();
                }
            }
        );
        $repository->method('replaceWorkspace')->willReturn(1);
        $hash = hash('sha256', 'c#');

        $result = (new BlogTagService(
            $repository,
            new BlogTagUuidSequence([$this->id(81)]),
            new BlogTagTestClock()
        ))->assignToVariant(
            $this->gate(), self::POST, 'es', 1, 0, 'C#'
        );

        self::assertSame([false, true], $identityReads);
        self::assertSame([false, true], $slugReads);
        self::assertSame(['c', 'c-' . substr($hash, 0, 16)], $insertions);
        self::assertSame(
            'c-' . substr($hash, 0, 16),
            $result->tags()[0]->slug()
        );
    }

    /** @return array{PDO, MigrationScope} */
    private function database(): array
    {
        $pdo = $this->pdo('sqlite::memory:');
        $scope = $this->scope();
        $this->applySchema($pdo, $scope);

        return [$pdo, $scope];
    }

    private function applySchema(PDO $pdo, MigrationScope $scope): void
    {
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if (
                $migration->targetScopeModuleId() === null
                && strcmp($migration->id(), '0024_blog_tag_assignment_workspace_items') <= 0
            ) {
                $this->apply($pdo, $migration, $scope);
            }
        }
    }

    private function seedVariant(
        PDO $pdo,
        MigrationScope $scope,
        string $post,
        string $localization,
        string $locale
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO ' . $scope->quotedTable('posts', 'sqlite')
                . ' (public_id, created_by_user_public_id) VALUES (:post, :actor)'
        );
        $statement->execute(['post' => $post, 'actor' => self::ACTOR]);
        $postId = (int) $pdo->lastInsertId();
        $statement = $pdo->prepare(
            'INSERT INTO ' . $scope->quotedTable('post_localizations', 'sqlite')
                . ' (public_id, post_id, locale, slug, h1, seo_title, '
                . 'meta_description, excerpt, body_text, status, published_at, '
                . 'created_by_user_public_id, updated_by_user_public_id) VALUES '
                . '(:public, :post, :locale, NULL, :h1, NULL, NULL, NULL, '
                . ":body, 'draft', NULL, :actor, :actor)"
        );
        $statement->execute([
            'public' => $localization,
            'post' => $postId,
            'locale' => $locale,
            'h1' => 'Artículo de prueba',
            'body' => 'Contenido de prueba suficiente.',
            'actor' => self::ACTOR,
        ]);
    }

    private function apply(
        PDO $pdo,
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $statement) {
            $pdo->exec($statement);
        }
    }

    private function pdo(string $dsn): PDO
    {
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function scope(): MigrationScope
    {
        return MigrationScope::forTablePrefix('blog', 'tag_service_');
    }

    /** @return callable(PDO): string */
    private function gate(): callable
    {
        return static fn (PDO $pdo): string => self::ACTOR;
    }

    private function id(int $sequence): string
    {
        return sprintf('30000000-0000-4000-8000-%012x', $sequence);
    }

    private function localizationId(int $sequence): string
    {
        return sprintf('20000000-0000-4000-8000-%012x', $sequence);
    }

    private function simulatedDeadlock(): BlogTagPersistenceException
    {
        $driver = new PDOException('simulated deadlock', 40001);
        $driver->errorInfo = ['40001', 1213, 'simulated'];

        return new BlogTagPersistenceException('', 0, $driver);
    }

    /** @param callable(): mixed $operation */
    private function expectTagIssue(string $issue, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected Blog tag issue ' . $issue);
        } catch (BlogTagException $exception) {
            self::assertSame($issue, $exception->issueCode());
        }
    }
}
