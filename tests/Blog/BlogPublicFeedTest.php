<?php

declare(strict_types=1);

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpRuntime;
use App\Core\Blog\Http\BlogPublicHttpRuntimeFactoryInterface;
use App\Core\Blog\Persistence\BlogRepositoryInterface;
use App\Core\Blog\PublishedPostCard;
use App\Core\Blog\PublicFeed\BlogPublicFeed;
use App\Core\Blog\PublicFeed\BlogPublicFeedFactory;
use App\Core\Modules\ModuleRuntimeContext;
use PHPUnit\Framework\TestCase;

final class BlogPublicFeedTest extends TestCase
{
    public function testItReturnsOnlyPublishedPresentationDataAndLocalizedUrls(): void
    {
        $repository = $this->createMock(BlogRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('listPublishedCards')
            ->with('es', 8, 4)
            ->willReturn([$this->card()]);
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($repository)
        );

        $items = $feed->cards('es', 8, 4);

        self::assertSame([[
            'locale' => 'es',
            'slug' => 'matrix-despierta',
            'url' => '/es/noticias/matrix-despierta',
            'h1' => 'Matrix despierta',
            'excerpt' => 'Neo descubre que el mundo no es lo que parece.',
            'published_at' => '2030-01-02T12:00:00+00:00',
            'updated_at' => '2030-01-03T13:00:00+00:00',
        ]], $items);
        self::assertArrayNotHasKey('body_text', $items[0]);
        self::assertArrayNotHasKey('post_public_id', $items[0]);
    }

    public function testItFailsClosedForALocaleWithoutAPublicBasePath(): void
    {
        $repository = $this->createMock(BlogRepositoryInterface::class);
        $repository->expects(self::never())->method('listPublishedCards');
        $feed = new BlogPublicFeed(
            $this->config(),
            new BlogService($repository)
        );

        $this->expectException(BlogException::class);
        $feed->cards('eu');
    }

    public function testFactoryBuildsTheFeedThroughTheExistingRuntimeBoundary(): void
    {
        $repository = $this->createMock(BlogRepositoryInterface::class);
        $repository->method('listPublishedCards')->willReturn([]);
        $runtime = new BlogPublicHttpRuntime(
            $this->config(),
            BlogPublicOrigin::fromEnvironment([
                'DEV_MODE' => '1',
                'RAIZ' => 'http://localhost:1309',
            ]),
            new BlogService($repository)
        );
        $runtimeFactory = $this->createMock(
            BlogPublicHttpRuntimeFactoryInterface::class
        );
        $runtimeFactory->expects(self::once())
            ->method('create')
            ->with(self::callback(
                static fn (ModuleRuntimeContext $context): bool =>
                    $context->projectRoot() === 'C:\\project'
                    && $context->environment()['DEV_MODE'] === '1'
                    && $context->environmentIsUsable()
            ))
            ->willReturn($runtime);

        $feed = (new BlogPublicFeedFactory($runtimeFactory))->create(
            'C:\\project',
            ['DEV_MODE' => '1']
        );

        self::assertSame([], $feed->cards('es'));
    }

    private function config(): BlogConfig
    {
        return new BlogConfig(
            ['es' => '/es/noticias', 'en' => '/en/news'],
            '/blog-sitemap.xml',
            'ls_blog_',
            'test'
        );
    }

    private function card(): PublishedPostCard
    {
        return new PublishedPostCard(
            'es',
            'matrix-despierta',
            'Matrix despierta',
            'Neo descubre que el mundo no es lo que parece.',
            new DateTimeImmutable('2030-01-02 12:00:00 UTC'),
            new DateTimeImmutable('2030-01-03 13:00:00 UTC')
        );
    }
}
