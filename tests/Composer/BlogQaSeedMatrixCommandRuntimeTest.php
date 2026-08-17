<?php

declare(strict_types=1);

use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureArticle;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureCatalog;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureException;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureMediaProbeInterface;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixturePersistencePortInterface;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureResult;
use App\Core\Composer\BlogQaSeedMatrixCommandRuntime;
use PHPUnit\Framework\TestCase;

final class BlogQaMatrixMediaProbeFixture implements
    BlogQaMatrixFixtureMediaProbeInterface
{
    public int $calls = 0;

    public function __construct(private readonly bool $available)
    {
    }

    public function assertUsable(string $mediaAssetPublicId): void
    {
        ++$this->calls;
        if (!$this->available) {
            throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.media_file_unavailable'
            );
        }
    }
}

final class BlogQaMatrixPersistenceFixture implements
    BlogQaMatrixFixturePersistencePortInterface
{
    public int $calls = 0;

    public function run(
        array $articles,
        array $requestedLocales,
        string $actorPublicId,
        bool $apply
    ): BlogQaMatrixFixtureResult {
        ++$this->calls;
        foreach ($articles as $article) {
            if (!$article instanceof BlogQaMatrixFixtureArticle) {
                throw new \RuntimeException('Invalid catalog fixture.');
            }
        }

        return new BlogQaMatrixFixtureResult(
            $apply,
            'sqlite',
            $requestedLocales,
            10,
            10 * count($requestedLocales),
            10,
            10 * count($requestedLocales),
            0
        );
    }
}

final class BlogQaSeedMatrixCommandRuntimeTest extends TestCase
{
    private const MEDIA = '11111111-1111-4111-8111-111111111111';
    private const ACTOR = '22222222-2222-4222-8222-222222222222';

    public function testMissingPhysicalMediaStopsBeforePersistencePlan(): void
    {
        $media = new BlogQaMatrixMediaProbeFixture(false);
        $persistence = new BlogQaMatrixPersistenceFixture();
        $runtime = new BlogQaSeedMatrixCommandRuntime(
            new BlogQaMatrixFixtureCatalog(),
            $media,
            $persistence
        );

        try {
            $runtime->run(['es'], self::MEDIA, self::ACTOR, true);
            self::fail('Persistence ran without a verified physical asset.');
        } catch (BlogQaMatrixFixtureException $exception) {
            self::assertSame(
                'blog.qa_fixture.media_file_unavailable',
                $exception->issueCode()
            );
        }
        self::assertSame(1, $media->calls);
        self::assertSame(0, $persistence->calls);
    }

    public function testVerifiedMediaAllowsTheGlobalPersistencePlan(): void
    {
        $media = new BlogQaMatrixMediaProbeFixture(true);
        $persistence = new BlogQaMatrixPersistenceFixture();
        $result = (new BlogQaSeedMatrixCommandRuntime(
            new BlogQaMatrixFixtureCatalog(),
            $media,
            $persistence
        ))->run(['es', 'en', 'eu'], self::MEDIA, self::ACTOR, false);

        self::assertFalse($result->applied());
        self::assertSame(30, $result->pendingVariantCount());
        self::assertSame(1, $persistence->calls);
    }
}
