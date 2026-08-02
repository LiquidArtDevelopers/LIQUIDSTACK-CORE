<?php

declare(strict_types=1);

use App\Core\Blog\BlogException;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredPlainDraftWriteGuard;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdoBlogStructuredContentRepositoryTest extends TestCase
{
    private const POST = '00000000-0000-4000-8000-000000000001';
    private const LOCALIZATION = '00000000-0000-4000-8000-000000000002';
    private const DOCUMENT = '00000000-0000-4000-8000-000000000003';
    private const DOCUMENT_UNUSED = '00000000-0000-4000-8000-000000000004';
    private const REVISION_ONE = '00000000-0000-4000-8000-000000000005';
    private const REVISION_TWO = '00000000-0000-4000-8000-000000000006';
    private const ACTOR_ONE = '00000000-0000-4000-8000-000000000007';
    private const ACTOR_TWO = '00000000-0000-4000-8000-000000000008';
    private const MEDIA_ONE = '00000000-0000-4000-8000-000000000009';

    private PDO $pdo;
    private MigrationScope $scope;
    private PdoBlogStructuredContentRepository $repository;
    private PdoBlogRepository $blogRepository;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = $this->sqlite();
        $this->scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $this->applyBlogMigrations(
            $this->pdo,
            $this->scope,
            ['0001_blog_posts', '0005_blog_structured_content']
        );
        $this->repository = new PdoBlogStructuredContentRepository(
            $this->pdo,
            $this->scope
        );
        $this->blogRepository = new PdoBlogRepository(
            $this->pdo,
            $this->scope
        );
    }

    public function testRoundTripCurrentRevisionMediaAndContentFreeSummary(): void
    {
        $draft = $this->draft('Wake up, Neo.', true);
        $this->seedLocalization($draft);

        $revisionNumber = $this->blogRepository->transactional(
            function (PDO $transaction) use ($draft): int {
                self::assertSame($this->pdo, $transaction);
                $this->repository->upsertCurrent(
                    self::LOCALIZATION,
                    self::DOCUMENT,
                    $draft,
                    self::ACTOR_ONE,
                    $this->now(0)
                );
                $this->repository->replaceCurrentMedia(
                    self::LOCALIZATION,
                    $draft->mediaReferences(),
                    $this->now(0)
                );
                $number = $this->repository->appendRevision(
                    self::LOCALIZATION,
                    self::REVISION_ONE,
                    1,
                    $draft,
                    self::ACTOR_ONE,
                    $this->now(0)
                );
                $this->repository->appendRevisionMedia(
                    self::REVISION_ONE,
                    $draft->mediaReferences(),
                    $this->now(0)
                );

                return $number;
            }
        );

        self::assertSame(1, $revisionNumber);
        self::assertTrue($this->repository->hasCurrent(self::LOCALIZATION));
        $current = $this->repository->current(self::LOCALIZATION);
        self::assertNotNull($current);
        self::assertSame(self::DOCUMENT, $current->documentPublicId());
        self::assertSame(
            $draft->canonicalJson(),
            $current->snapshot()->canonicalJson()
        );
        self::assertSame(self::ACTOR_ONE, $current->createdByUserPublicId());

        $revision = $this->repository->revision(self::REVISION_ONE);
        self::assertNotNull($revision);
        self::assertSame(1, $revision->revisionNumber());
        self::assertSame(
            $draft->snapshotSha256(),
            $revision->snapshot()->snapshotSha256()
        );
        self::assertSame(1, $this->countRows('content_media'));
        self::assertSame(1, $this->countRows('revision_media'));

        $summaries = $this->repository->listRevisions(
            self::LOCALIZATION,
            10,
            0
        );
        self::assertCount(1, $summaries);
        self::assertSame(self::REVISION_ONE, $summaries[0]->revisionPublicId());
        self::assertSame(1, $summaries[0]->mediaCount());
        ob_start();
        var_dump($summaries[0]);
        $debug = (string) ob_get_clean();
        self::assertStringNotContainsString('Wake up, Neo.', $debug);
        self::assertStringNotContainsString('matrix-article', $debug);

        self::assertNull($this->repository->current($this->id(900)));
        self::assertNull($this->repository->revision($this->id(901)));
        self::assertSame([], $this->repository->listRevisions(
            $this->id(902),
            10,
            0
        ));
    }

    public function testUpsertPreservesIdentityAndCreationFields(): void
    {
        $first = $this->draft('First body');
        $this->seedLocalization($first);
        $this->blogRepository->transactional(function () use ($first): void {
            $this->repository->upsertCurrent(
                self::LOCALIZATION,
                self::DOCUMENT,
                $first,
                self::ACTOR_ONE,
                $this->now(0)
            );
            $this->repository->replaceCurrentMedia(
                self::LOCALIZATION,
                [],
                $this->now(0)
            );
        });

        $second = $this->draft('Second body');
        $this->blogRepository->transactional(function (PDO $pdo) use ($second): void {
            $this->updateLocalization($pdo, $second, 2);
            $this->repository->upsertCurrent(
                self::LOCALIZATION,
                self::DOCUMENT_UNUSED,
                $second,
                self::ACTOR_TWO,
                $this->now(60)
            );
            $this->repository->replaceCurrentMedia(
                self::LOCALIZATION,
                [],
                $this->now(60)
            );
        });

        $record = $this->repository->current(self::LOCALIZATION);
        self::assertNotNull($record);
        self::assertSame(self::DOCUMENT, $record->documentPublicId());
        self::assertSame(self::ACTOR_ONE, $record->createdByUserPublicId());
        self::assertSame(self::ACTOR_TWO, $record->updatedByUserPublicId());
        self::assertSame(
            '2030-01-01 00:00:00.000000',
            $record->createdAt()->format('Y-m-d H:i:s.u')
        );
        self::assertSame(
            '2030-01-01 00:01:00.000000',
            $record->updatedAt()->format('Y-m-d H:i:s.u')
        );
        self::assertSame(
            'Second body',
            $record->snapshot()->compatibilityDraft()->bodyText()
        );
    }

    public function testRevisionNumbersAdvanceUnderLocalizationLock(): void
    {
        $draft = $this->draft('Revision body');
        $this->seedLocalization($draft);

        $first = $this->blogRepository->transactional(
            fn (): int => $this->appendRevision(
                self::REVISION_ONE,
                1,
                $draft
            )
        );
        $second = $this->blogRepository->transactional(
            function (PDO $pdo) use ($draft): int {
                $statement = $pdo->prepare(
                    'UPDATE ls_blog_post_localizations '
                    . 'SET lock_version = 2 WHERE public_id = :public_id'
                );
                self::assertNotFalse($statement);
                self::assertTrue($statement->execute([
                    'public_id' => self::LOCALIZATION,
                ]));

                return $this->appendRevision(
                    self::REVISION_TWO,
                    2,
                    $draft
                );
            }
        );

        self::assertSame(1, $first);
        self::assertSame(2, $second);
        $summaries = $this->repository->listRevisions(
            self::LOCALIZATION,
            100,
            0
        );
        self::assertSame([2, 1], array_map(
            static fn ($summary): int => $summary->revisionNumber(),
            $summaries
        ));
        self::assertSame(
            self::REVISION_TWO,
            $summaries[0]->revisionPublicId()
        );
    }

    public function testExternalRollbackRemovesEveryStructuredWrite(): void
    {
        $draft = $this->draft('Rollback body', true);
        $this->seedLocalization($draft);

        try {
            $this->blogRepository->transactional(
                function () use ($draft): void {
                    $this->repository->upsertCurrent(
                        self::LOCALIZATION,
                        self::DOCUMENT,
                        $draft,
                        self::ACTOR_ONE,
                        $this->now(0)
                    );
                    $this->repository->replaceCurrentMedia(
                        self::LOCALIZATION,
                        $draft->mediaReferences(),
                        $this->now(0)
                    );
                    $this->appendRevision(self::REVISION_ONE, 1, $draft);
                    throw new RuntimeException('force outer rollback');
                }
            );
            self::fail('Rollback failure was expected.');
        } catch (BlogPersistenceException) {
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertFalse($this->repository->hasCurrent(self::LOCALIZATION));
        self::assertNull($this->repository->revision(self::REVISION_ONE));
        self::assertSame(0, $this->countRows('content_media'));
        self::assertSame(0, $this->countRows('revision_media'));
    }

    #[DataProvider('writeWithoutTransactionProvider')]
    public function testWritesRejectCallsWithoutOuterTransaction(
        string $operation
    ): void {
        $draft = $this->draft('Outside transaction');
        $this->seedLocalization($draft);

        try {
            match ($operation) {
                'upsert' => $this->repository->upsertCurrent(
                    self::LOCALIZATION,
                    self::DOCUMENT,
                    $draft,
                    self::ACTOR_ONE,
                    $this->now(0)
                ),
                'replace-media' => $this->repository->replaceCurrentMedia(
                    self::LOCALIZATION,
                    [],
                    $this->now(0)
                ),
                'append-revision' => $this->repository->appendRevision(
                    self::LOCALIZATION,
                    self::REVISION_ONE,
                    1,
                    $draft,
                    self::ACTOR_ONE,
                    $this->now(0)
                ),
                'append-revision-media' =>
                    $this->repository->appendRevisionMedia(
                        self::REVISION_ONE,
                        [],
                        $this->now(0)
                    ),
            };
            self::fail('A write outside the Blog transaction was accepted.');
        } catch (BlogStructuredContentException $exception) {
            self::assertSame(
                BlogStructuredContentException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
        }
    }

    public static function writeWithoutTransactionProvider(): array
    {
        return [
            ['upsert'],
            ['replace-media'],
            ['append-revision'],
            ['append-revision-media'],
        ];
    }

    #[DataProvider('currentCorruptionProvider')]
    public function testCurrentHydrationRejectsHashesBytesBodyAndJsonCorruption(
        string $sql
    ): void {
        $draft = $this->draft('Integrity body');
        $this->seedLocalization($draft);
        $this->storeCurrent($draft);
        self::assertNotNull($this->repository->current(self::LOCALIZATION));
        $this->pdo->exec($sql);

        $this->expectStructuredIssue(
            BlogStructuredContentException::CORRUPT_DOCUMENT,
            fn () => $this->repository->current(self::LOCALIZATION)
        );
    }

    public static function currentCorruptionProvider(): array
    {
        return [
            'document hash' => [
                "UPDATE ls_blog_content_docs SET document_sha256 = '"
                . str_repeat('0', 64) . "'",
            ],
            'snapshot hash' => [
                "UPDATE ls_blog_content_docs SET snapshot_sha256 = '"
                . str_repeat('0', 64) . "'",
            ],
            'bytes' => [
                'UPDATE ls_blog_content_docs '
                . 'SET document_bytes = document_bytes + 1',
            ],
            'derived body' => [
                "UPDATE ls_blog_post_localizations SET body_text = 'tampered'",
            ],
            'json' => [
                "UPDATE ls_blog_content_docs SET document_json = '{}'",
            ],
        ];
    }

    public function testRevisionAndMediaCorruptionFailClosed(): void
    {
        $draft = $this->draft('Media integrity', true);
        $this->seedLocalization($draft);
        $this->blogRepository->transactional(function () use ($draft): void {
            $this->repository->upsertCurrent(
                self::LOCALIZATION,
                self::DOCUMENT,
                $draft,
                self::ACTOR_ONE,
                $this->now(0)
            );
            $this->repository->replaceCurrentMedia(
                self::LOCALIZATION,
                $draft->mediaReferences(),
                $this->now(0)
            );
            $this->appendRevision(self::REVISION_ONE, 1, $draft);
        });
        $this->pdo->exec(
            "UPDATE ls_blog_content_media SET media_asset_public_id = '"
            . $this->id(999) . "'"
        );
        $this->expectStructuredIssue(
            BlogStructuredContentException::CORRUPT_DOCUMENT,
            fn () => $this->repository->current(self::LOCALIZATION)
        );

        $this->pdo->exec(
            "UPDATE ls_blog_content_revisions SET body_text_sha256 = '"
            . str_repeat('0', 64) . "'"
        );
        $this->expectStructuredIssue(
            BlogStructuredContentException::CORRUPT_DOCUMENT,
            fn () => $this->repository->revision(self::REVISION_ONE)
        );
    }

    public function testListAndMediaInputLimitsAreBounded(): void
    {
        $draft = $this->draft('Bounded input');
        $this->seedLocalization($draft);
        foreach ([[0, 0], [101, 0], [1, 1_000_001]] as [$limit, $offset]) {
            $this->expectStructuredIssue(
                BlogStructuredContentException::INVALID_INPUT,
                fn () => $this->repository->listRevisions(
                    self::LOCALIZATION,
                    $limit,
                    $offset
                )
            );
        }

        $reference = $this->draft('Image', true)->mediaReferences()[0];
        $this->blogRepository->transactional(function () use ($reference): void {
            $this->expectStructuredIssue(
                BlogStructuredContentException::INVALID_INPUT,
                fn () => $this->repository->replaceCurrentMedia(
                    self::LOCALIZATION,
                    array_fill(0, 201, $reference),
                    $this->now(0)
                )
            );
        });
    }

    public function testPlainDraftGuardUsesSamePdoAndDoesNotHydrateCopy(): void
    {
        $draft = $this->draft('Guard body');
        $this->seedLocalization($draft);
        $guard = new BlogStructuredPlainDraftWriteGuard(
            $this->pdo,
            $this->repository
        );

        $this->blogRepository->transactional(function (PDO $pdo) use ($guard): void {
            $guard->assertPlainSaveAllowed($pdo, self::LOCALIZATION);
        });
        $this->storeCurrent($draft);
        try {
            $this->blogRepository->transactional(
                function (PDO $pdo) use ($guard): void {
                    $guard->assertPlainSaveAllowed($pdo, self::LOCALIZATION);
                }
            );
            self::fail('Structured content was exposed to the plain writer.');
        } catch (BlogException $exception) {
            self::assertSame(BlogException::INVALID_STATE, $exception->issueCode());
        }

        $other = $this->sqlite();
        try {
            $this->blogRepository->transactional(
                function () use ($guard, $other): void {
                    $guard->assertPlainSaveAllowed($other, self::LOCALIZATION);
                }
            );
            self::fail('A different PDO was accepted.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
        }
    }

    public function testStructuredDomainFailureCrossesBaseTransactionBoundary(): void
    {
        try {
            $this->blogRepository->transactional(
                static function (): void {
                    throw new BlogStructuredContentException(
                        BlogStructuredContentException::MEDIA_NOT_FOUND
                    );
                }
            );
            self::fail('The structured domain failure was hidden.');
        } catch (BlogStructuredContentException $exception) {
            self::assertSame(
                BlogStructuredContentException::MEDIA_NOT_FOUND,
                $exception->issueCode()
            );
        }

        self::assertFalse($this->pdo->inTransaction());
    }

    public function testPdoContractAndMaximumPrefixAreEnforced(): void
    {
        $withoutForeignKeys = new PDO('sqlite::memory:');
        $withoutForeignKeys->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );
        $this->expectStructuredIssue(
            BlogStructuredContentException::STORAGE_UNAVAILABLE,
            fn () => new PdoBlogStructuredContentRepository(
                $withoutForeignKeys,
                $this->scope
            )
        );

        $this->expectStructuredIssue(
            BlogStructuredContentException::STORAGE_UNAVAILABLE,
            fn () => new PdoBlogStructuredContentRepository(
                $this->pdo,
                MigrationScope::forTablePrefix('webadmin', 'ls_blog_')
            )
        );

        $maxPrefix = 'b' . str_repeat('x', 44) . '_';
        self::assertSame(46, strlen($maxPrefix));
        self::assertInstanceOf(
            PdoBlogStructuredContentRepository::class,
            new PdoBlogStructuredContentRepository(
                $this->pdo,
                MigrationScope::forTablePrefix('blog', $maxPrefix)
            )
        );
        $tooLong = 'b' . str_repeat('x', 45) . '_';
        $this->expectStructuredIssue(
            BlogStructuredContentException::STORAGE_UNAVAILABLE,
            fn () => new PdoBlogStructuredContentRepository(
                $this->pdo,
                MigrationScope::forTablePrefix('blog', $tooLong)
            )
        );
    }

    private function storeCurrent(BlogStructuredDraft $draft): void
    {
        $this->blogRepository->transactional(function () use ($draft): void {
            $this->repository->upsertCurrent(
                self::LOCALIZATION,
                self::DOCUMENT,
                $draft,
                self::ACTOR_ONE,
                $this->now(0)
            );
            $this->repository->replaceCurrentMedia(
                self::LOCALIZATION,
                $draft->mediaReferences(),
                $this->now(0)
            );
        });
    }

    private function appendRevision(
        string $publicId,
        int $lockVersion,
        BlogStructuredDraft $draft
    ): int {
        $number = $this->repository->appendRevision(
            self::LOCALIZATION,
            $publicId,
            $lockVersion,
            $draft,
            self::ACTOR_ONE,
            $this->now($lockVersion - 1)
        );
        $this->repository->appendRevisionMedia(
            $publicId,
            $draft->mediaReferences(),
            $this->now($lockVersion - 1)
        );

        return $number;
    }

    private function seedLocalization(BlogStructuredDraft $draft): void
    {
        $timestamp = '2030-01-01 00:00:00.000000';
        $post = $this->pdo->prepare(
            'INSERT INTO ls_blog_posts '
            . '(public_id, created_by_user_public_id, created_at, updated_at) '
            . 'VALUES (:public_id, :actor, :created_at, :updated_at)'
        );
        self::assertNotFalse($post);
        self::assertTrue($post->execute([
            'public_id' => self::POST,
            'actor' => self::ACTOR_ONE,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]));
        $this->insertLocalization($draft, 1, $timestamp);
    }

    private function insertLocalization(
        BlogStructuredDraft $draft,
        int $lockVersion,
        string $timestamp
    ): void {
        $compatibility = $draft->compatibilityDraft();
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_post_localizations '
            . '(public_id, post_id, locale, slug, h1, seo_title, '
            . 'meta_description, excerpt, body_text, status, published_at, '
            . 'lock_version, created_by_user_public_id, '
            . 'updated_by_user_public_id, created_at, updated_at) '
            . 'SELECT :public_id, id, :locale, :slug, :h1, :seo_title, '
            . ':description, :excerpt, :body_text, :status, NULL, '
            . ':lock_version, :actor, :actor, :created_at, :updated_at '
            . 'FROM ls_blog_posts WHERE public_id = :post_public_id'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'public_id' => self::LOCALIZATION,
            'locale' => 'en',
            'slug' => $compatibility->slug(),
            'h1' => $compatibility->h1(),
            'seo_title' => $compatibility->seoTitle(),
            'description' => $compatibility->metaDescription(),
            'excerpt' => $compatibility->excerpt(),
            'body_text' => $compatibility->bodyText(),
            'status' => 'draft',
            'lock_version' => $lockVersion,
            'actor' => self::ACTOR_ONE,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'post_public_id' => self::POST,
        ]));
    }

    private function updateLocalization(
        PDO $pdo,
        BlogStructuredDraft $draft,
        int $lockVersion
    ): void {
        $compatibility = $draft->compatibilityDraft();
        $statement = $pdo->prepare(
            'UPDATE ls_blog_post_localizations SET h1 = :h1, slug = :slug, '
            . 'seo_title = :seo_title, meta_description = :description, '
            . 'excerpt = :excerpt, body_text = :body_text, '
            . 'lock_version = :lock_version WHERE public_id = :public_id'
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            'h1' => $compatibility->h1(),
            'slug' => $compatibility->slug(),
            'seo_title' => $compatibility->seoTitle(),
            'description' => $compatibility->metaDescription(),
            'excerpt' => $compatibility->excerpt(),
            'body_text' => $compatibility->bodyText(),
            'lock_version' => $lockVersion,
            'public_id' => self::LOCALIZATION,
        ]));
    }

    private function draft(string $body, bool $withImage = false): BlogStructuredDraft
    {
        $blocks = [];
        $template = BlogDocumentTemplateRegistry::ARTICLE_BASIC;
        if ($withImage) {
            $template = BlogDocumentTemplateRegistry::ARTICLE_COVER;
            $blocks[] = [
                'id' => $this->id(100),
                'type' => 'image',
                'media_asset_public_id' => self::MEDIA_ONE,
                'alt' => 'Matrix still',
                'title' => null,
                'caption' => null,
                'decorative' => false,
                'display' => 'cover',
            ];
        }
        $blocks[] = [
            'id' => $this->id(101),
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => $body,
                'marks' => [],
            ]],
        ];

        return new BlogStructuredDraft(
            'Matrix article',
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => $template,
                'blocks' => $blocks,
            ]),
            'matrix-article',
            'Matrix article SEO',
            'Matrix description.',
            'Matrix excerpt.'
        );
    }

    private function now(int $seconds): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2030-01-01 00:00:00 +' . $seconds . ' seconds',
            new DateTimeZone('UTC')
        );
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    /** @param list<string> $ids */
    private function applyBlogMigrations(
        PDO $pdo,
        MigrationScope $scope,
        array $ids
    ): void {
        $pending = array_fill_keys($ids, true);
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if (!isset($pending[$migration->id()])) {
                continue;
            }
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                self::assertNotFalse($pdo->exec($sql));
            }
            unset($pending[$migration->id()]);
        }
        self::assertSame([], array_keys($pending));
    }

    private function countRows(string $suffix): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_' . $suffix
        )->fetchColumn();
    }

    private function expectStructuredIssue(
        string $issueCode,
        callable $operation
    ): void {
        try {
            $operation();
            self::fail('Structured content failure was expected.');
        } catch (BlogStructuredContentException $exception) {
            self::assertSame($issueCode, $exception->issueCode());
        }
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }
}
