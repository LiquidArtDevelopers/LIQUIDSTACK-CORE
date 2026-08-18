<?php

declare(strict_types=1);

namespace Tests\Blog\Tags;

use App\Core\Blog\Audit\BlogMutationAuditEvent;
use App\Core\Blog\Audit\BlogMutationAuditPortInterface;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\EditorialWorkflow\Persistence\PdoBlogEditorialWorkspaceRepository;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredEditorService;
use App\Core\Blog\StructuredContent\Media\BlogMediaAvailabilityPortInterface;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Blog\Tags\BlogTagService;
use App\Core\Blog\Tags\Persistence\PdoBlogTagRepository;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class TagPublicationClock implements ClockInterface
{
    private int $tick = 0;

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf(
            '2026-08-18T14:00:%02d+00:00',
            $this->tick++
        ));
    }
}

final class TagPublicationUuids implements UuidGeneratorInterface
{
    private int $next = 1;

    public function generateV4(): string
    {
        return sprintf(
            '50000000-0000-4000-8000-%012d',
            $this->next++
        );
    }
}

final class TagPublicationMedia implements BlogMediaAvailabilityPortInterface
{
    public function assertAvailable(PDO $transaction, array $ids): void
    {
        if (!$transaction->inTransaction() || $ids !== []) {
            throw new \RuntimeException('Unexpected media contract.');
        }
    }
}

final class TagPublicationAudit implements BlogMutationAuditPortInterface
{
    public bool $fail = false;

    /** @var list<string> */
    public array $operations = [];

    public function record(PDO $pdo, BlogMutationAuditEvent $event): void
    {
        if (!$pdo->inTransaction()) {
            throw new \RuntimeException('Audit escaped transaction.');
        }
        if ($this->fail) {
            throw new \RuntimeException('Audit unavailable.');
        }
        $this->operations[] = $event->operation();
    }
}

final class BlogTagPublicationIntegrationTest extends TestCase
{
    private const ACTOR = '50000000-0000-4000-8000-999999999999';

    public function testTagsRemainPrivateAndPublishWithContentAtomically(): void
    {
        $fixture = $this->fixture();
        $variant = $fixture['blog']->createPost(
            $this->gate(),
            'es',
            $this->draft('Public', 'public')->compatibilityDraft()
        );
        $variant = $fixture['editor']->save(
            $this->gate(),
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            $this->draft('Public', 'public')
        );
        $tagState = $fixture['tags']->assignToVariant(
            $this->gate(),
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            0,
            'Fiscal, IA'
        );
        self::assertSame([], $fixture['tagRepository']->liveTags(
            $variant->localizationPublicId()
        ));

        $variant = $fixture['editor']->publishSaved(
            $this->gate(),
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            0,
            expectedTagWorkspaceVersion: $tagState->workspaceVersion()
        );
        self::assertSame(BlogPostVariant::PUBLISHED, $variant->status());
        self::assertSame(
            ['fiscal', 'ia'],
            $this->slugs($fixture['tagRepository']->liveTags(
                $variant->localizationPublicId()
            ))
        );
        self::assertNull($fixture['tagRepository']->workspaceState(
            $variant->localizationPublicId()
        ));
        self::assertSame(1, $fixture['tagRepository']->assignmentVersion(
            $variant->localizationPublicId()
        ));

        $private = $fixture['tags']->assignToVariant(
            $this->gate(),
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            0,
            'Legal'
        );
        self::assertSame(
            ['fiscal', 'ia'],
            $this->slugs($fixture['tagRepository']->liveTags(
                $variant->localizationPublicId()
            ))
        );
        self::assertSame(
            ['legal'],
            $this->slugs($fixture['tags']->assignedToVariant(
                $variant->postPublicId(),
                'es'
            ))
        );

        $this->expectBlogIssue(
            BlogException::LOCK_CONFLICT,
            fn () => $fixture['editor']->publishSaved(
                $this->gate(),
                $variant->postPublicId(),
                'es',
                $variant->lockVersion(),
                0,
                expectedTagWorkspaceVersion: 0
            )
        );
        self::assertSame($private->workspaceVersion(), $fixture['tags']
            ->workspaceVersion($variant->postPublicId(), 'es'));
    }

    public function testAuditFailureRollsBackTagPromotionAndEmptyWorkspace(): void
    {
        $fixture = $this->fixture();
        $variant = $fixture['blog']->createPost(
            $this->gate(),
            'es',
            $this->draft('Rollback', 'rollback')->compatibilityDraft()
        );
        $variant = $fixture['editor']->save(
            $this->gate(),
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            $this->draft('Rollback', 'rollback')
        );
        $initial = $fixture['tags']->assignToVariant(
            $this->gate(),
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            0,
            'Fiscal'
        );
        $variant = $fixture['editor']->publishSaved(
            $this->gate(),
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            0,
            expectedTagWorkspaceVersion: $initial->workspaceVersion()
        );
        $empty = $fixture['tags']->assignToVariant(
            $this->gate(),
            $variant->postPublicId(),
            'es',
            $variant->lockVersion(),
            0,
            ''
        );
        $fixture['audit']->fail = true;

        $this->expectBlogIssue(
            BlogStructuredContentException::STORAGE_UNAVAILABLE,
            fn () => $fixture['editor']->publishSaved(
                $this->gate(),
                $variant->postPublicId(),
                'es',
                $variant->lockVersion(),
                0,
                expectedTagWorkspaceVersion: $empty->workspaceVersion()
            )
        );
        self::assertSame(
            ['fiscal'],
            $this->slugs($fixture['tagRepository']->liveTags(
                $variant->localizationPublicId()
            ))
        );
        self::assertSame([], $fixture['tagRepository']->workspaceTags(
            $variant->localizationPublicId()
        ));
        self::assertSame(1, $fixture['tagRepository']->assignmentVersion(
            $variant->localizationPublicId()
        ));
        self::assertFalse($fixture['pdo']->inTransaction());
    }

    public function testDuplicateCopiesEffectiveTagsButAddLocaleStartsEmpty(): void
    {
        $fixture = $this->fixture();
        $source = $fixture['blog']->createPost(
            $this->gate(),
            'es',
            $this->draft('Copy source', 'copy-source')->compatibilityDraft()
        );
        $source = $fixture['editor']->save(
            $this->gate(),
            $source->postPublicId(),
            'es',
            $source->lockVersion(),
            $this->draft('Copy source', 'copy-source')
        );
        $initial = $fixture['tags']->assignToVariant(
            $this->gate(),
            $source->postPublicId(),
            'es',
            $source->lockVersion(),
            0,
            'Fiscal'
        );
        $source = $fixture['editor']->publishSaved(
            $this->gate(),
            $source->postPublicId(),
            'es',
            $source->lockVersion(),
            0,
            expectedTagWorkspaceVersion: $initial->workspaceVersion()
        );
        $fixture['tags']->assignToVariant(
            $this->gate(),
            $source->postPublicId(),
            'es',
            $source->lockVersion(),
            0,
            'Legal'
        );

        $duplicate = $fixture['blog']->duplicatePost(
            $this->gate(),
            $source->postPublicId(),
            'es',
            $source->lockVersion()
        );
        self::assertSame(
            ['legal'],
            $this->slugs($fixture['tagRepository']->liveTags(
                $duplicate->localizationPublicId()
            ))
        );
        self::assertSame(
            ['fiscal'],
            $this->slugs($fixture['tagRepository']->liveTags(
                $source->localizationPublicId()
            ))
        );

        $localized = $fixture['blog']->addLocalizationCopy(
            $this->gate(),
            $source->postPublicId(),
            'es',
            'en',
            $source->lockVersion()
        );
        self::assertSame([], $fixture['tagRepository']->liveTags(
            $localized->localizationPublicId()
        ));
        self::assertSame('en', $localized->locale());
    }

    /** @return array{pdo:PDO,blog:BlogService,editor:BlogStructuredEditorService,tags:BlogTagService,tagRepository:PdoBlogTagRepository,audit:TagPublicationAudit} */
    private function fixture(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA ignore_check_constraints = OFF');
        $scope = MigrationScope::forTablePrefix('blog', 'tag_publish_');
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if (
                $migration->targetScopeModuleId() !== null
                || strcmp(
                    $migration->id(),
                    '0024_blog_tag_assignment_workspace_items'
                ) > 0
            ) {
                continue;
            }
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                $pdo->exec($sql);
            }
        }
        $clock = new TagPublicationClock();
        $uuids = new TagPublicationUuids();
        $audit = new TagPublicationAudit();
        $repository = new PdoBlogRepository($pdo, $scope);
        $content = new PdoBlogStructuredContentRepository(
            $pdo,
            $scope,
            layoutReady: true
        );
        $workflow = new PdoBlogEditorialWorkspaceRepository($pdo, $scope);
        $media = new TagPublicationMedia();
        $tagRepository = new PdoBlogTagRepository($pdo, $scope);

        return [
            'pdo' => $pdo,
            'blog' => new BlogService(
                $repository,
                $uuids,
                $clock,
                $audit,
                structuredContentRepository: $content,
                mediaAvailability: $media,
                editorialWorkflowRepository: $workflow,
                tagRepository: $tagRepository
            ),
            'editor' => new BlogStructuredEditorService(
                $repository,
                $content,
                $media,
                $uuids,
                $clock,
                $audit,
                layoutReady: true,
                workflowRepository: $workflow,
                tagRepository: $tagRepository
            ),
            'tags' => new BlogTagService(
                $tagRepository,
                $uuids,
                $clock,
                $audit
            ),
            'tagRepository' => $tagRepository,
            'audit' => $audit,
        ];
    }

    private function draft(string $h1, string $slug): BlogStructuredDraft
    {
        return new BlogStructuredDraft(
            $h1,
            BlogDocument::fromArray([
                'schema' => BlogDocument::SCHEMA,
                'version' => BlogDocument::VERSION,
                'template' => 'article-basic-01',
                'blocks' => [[
                    'id' => '51000000-0000-4000-8000-000000000001',
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => $h1 . ' body with enough content.',
                        'marks' => [],
                    ]],
                ]],
            ]),
            $slug,
            $h1 . ' SEO',
            $h1 . ' description.',
            $h1 . ' excerpt.'
        );
    }

    /** @param list<\App\Core\Blog\Tags\BlogTag> $tags @return list<string> */
    private function slugs(array $tags): array
    {
        return array_map(static fn ($tag): string => $tag->slug(), $tags);
    }

    /** @return callable(PDO): string */
    private function gate(): callable
    {
        return static fn (PDO $pdo): string => self::ACTOR;
    }

    /** @param callable(): mixed $operation */
    private function expectBlogIssue(string $issue, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected Blog issue ' . $issue);
        } catch (BlogException|BlogStructuredContentException $exception) {
            self::assertSame($issue, $exception->issueCode());
        }
    }
}
