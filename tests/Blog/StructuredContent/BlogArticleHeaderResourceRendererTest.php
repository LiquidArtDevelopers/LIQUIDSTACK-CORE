<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Presentation\BlogHeaderPresetCatalog;
use App\Core\Blog\StructuredContent\Presentation\BlogH1ModuleCatalog;
use App\Core\Blog\StructuredContent\Presentation\BlogHeaderSelection;
use App\Core\Blog\StructuredContent\Presentation\BlogHeroCatalog;
use App\Core\Blog\StructuredContent\Rendering\BlogArticleHeaderMedia;
use App\Core\Blog\StructuredContent\Rendering\BlogArticleHeaderResourceRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogHeaderResourceAdapterInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImageCandidate;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlogArticleHeaderResourceRendererTest extends TestCase
{
    public function testUnavailableResourcesUseCanonicalMediaWithoutParsing(): void
    {
        $adapter = new class implements BlogHeaderResourceAdapterInterface {
            public function supports(array $resources): bool
            {
                return false;
            }

            public function render(string $resource, array $parameters): string
            {
                throw new RuntimeException('Must not render.');
            }
        };
        $media = $this->media();
        $html = (new BlogArticleHeaderResourceRenderer(
            new BlogHeaderPresetCatalog(),
            $adapter
        ))->render(
            BlogDocumentTemplateRegistry::ARTICLE_HERO06,
            'Matrix & decisions',
            'Choose carefully.',
            null,
            $media
        );

        self::assertStringContainsString($media->html(), $html);
        self::assertStringContainsString(
            '/media/480.avif?token=a&amp;b=1 480w, '
                . '/media/1200.avif?token=a&amp;b=1 1200w',
            $html
        );
        self::assertStringNotContainsString('dummy_2560', $html);
    }

    public function testProjectHeroReceivesAllTypedResponsiveAttributes(): void
    {
        $adapter = new class implements BlogHeaderResourceAdapterInterface {
            /** @var list<array{resource:string,parameters:array<string,string>}> */
            public array $calls = [];

            public function supports(array $resources): bool
            {
                return true;
            }

            public function render(string $resource, array $parameters): string
            {
                $this->calls[] = compact('resource', 'parameters');

                return $resource === 'moduleH1Type03'
                    ? '<div class="moduleH1Type03">Content</div>'
                    : '<header class="hero06">Rendered</header>';
            }
        };
        $renderer = new BlogArticleHeaderResourceRenderer(
            new BlogHeaderPresetCatalog(),
            $adapter
        );

        $html = $renderer->render(
            BlogDocumentTemplateRegistry::ARTICLE_HERO06,
            'Matrix',
            'Excerpt',
            'Eyebrow',
            $this->media()
        );

        self::assertSame('<header class="hero06">Rendered</header>', $html);
        self::assertCount(2, $adapter->calls);
        self::assertSame('hero06', $adapter->calls[1]['resource']);
        $parameters = $adapter->calls[1]['parameters'];
        self::assertSame(
            '/media/480.avif?token=a&amp;b=1 480w, '
                . '/media/1200.avif?token=a&amp;b=1 1200w',
            $parameters['{img-srcset}']
        );
        self::assertSame('100vw', $parameters['{img-sizes}']);
        self::assertSame('Cover &amp; decisions', $parameters['{img-alt}']);
        self::assertSame('The &quot;choice&quot;', $parameters['{img-title}']);
        self::assertSame('1200', $parameters['{img-width}']);
        self::assertSame('800', $parameters['{img-height}']);
        self::assertSame('center', $parameters['{img-object-position-y}']);
    }

    public function testHeroAndH1ModuleComposeIndependently(): void
    {
        $adapter = new class implements BlogHeaderResourceAdapterInterface {
            /** @var list<string> */
            public array $calls = [];

            public function supports(array $resources): bool
            {
                return $resources === ['moduleH1Type04', 'hero06'];
            }

            public function render(string $resource, array $parameters): string
            {
                $this->calls[] = $resource;

                return $resource === 'moduleH1Type04'
                    ? '<div class="moduleH1Type04">H1</div>'
                    : '<header class="hero06">Composed</header>';
            }
        };
        $renderer = new BlogArticleHeaderResourceRenderer(
            new BlogHeaderPresetCatalog(),
            $adapter
        );

        $html = $renderer->renderSelection(
            new BlogHeaderSelection(
                BlogHeroCatalog::HERO06,
                BlogH1ModuleCatalog::TYPE04
            ),
            'Matrix',
            'Excerpt',
            null,
            $this->media()
        );

        self::assertSame('<header class="hero06">Composed</header>', $html);
        self::assertSame(['moduleH1Type04', 'hero06'], $adapter->calls);
    }

    public function testHero00UsesRealResponsiveImageWithoutInlineStyle(): void
    {
        $adapter = new class implements BlogHeaderResourceAdapterInterface {
            /** @var array<string, string> */
            public array $hero = [];
            public function supports(array $resources): bool { return true; }
            public function render(string $resource, array $parameters): string
            {
                if ($resource === 'hero00') {
                    $this->hero = $parameters;
                    return '<header>Hero</header>';
                }
                return '<div>H1</div>';
            }
        };
        $image = new BlogResolvedImage(
            '11111111-1111-4111-8111-111111111111',
            array_map(
                static fn (int $width): BlogResolvedImageCandidate =>
                    new BlogResolvedImageCandidate('/media/' . $width . '.avif', $width),
                [480, 900, 1800, 2200]
            ),
            2200,
            1400
        );
        $media = new BlogArticleHeaderMedia(
            $image,
            '22222222-2222-4222-8222-222222222222',
            'Alt editable',
            'Title editable',
            null,
            'medium',
            objectPositionY: 'top'
        );

        (new BlogArticleHeaderResourceRenderer(
            new BlogHeaderPresetCatalog(),
            $adapter
        ))->render(
            BlogDocumentTemplateRegistry::ARTICLE_HERO00,
            'Matrix',
            null,
            null,
            $media
        );

        self::assertSame('/media/2200.avif', $adapter->hero['{img-src}']);
        self::assertSame(
            '/media/480.avif 480w, /media/900.avif 900w, '
                . '/media/1800.avif 1800w, /media/2200.avif 2200w',
            $adapter->hero['{img-srcset}']
        );
        self::assertSame('top', $adapter->hero['{img-object-position-y}']);
        self::assertArrayNotHasKey('{bg-fallback-style}', $adapter->hero);
    }

    public function testLegacySafeHtmlRemainsCompatibleWithoutBeingParsed(): void
    {
        $adapter = new class implements BlogHeaderResourceAdapterInterface {
            public int $renderCalls = 0;

            public function supports(array $resources): bool
            {
                return true;
            }

            public function render(string $resource, array $parameters): string
            {
                ++$this->renderCalls;

                return '<div>Unexpected resource</div>';
            }
        };
        $legacy = '<figure data-legacy="true"><img src="/legacy.avif" '
            . 'alt="Legacy"></figure>';
        $html = (new BlogArticleHeaderResourceRenderer(
            new BlogHeaderPresetCatalog(),
            $adapter
        ))->render(
            BlogDocumentTemplateRegistry::ARTICLE_HERO06,
            'Matrix',
            null,
            null,
            $legacy
        );

        self::assertSame(0, $adapter->renderCalls);
        self::assertStringContainsString($legacy, $html);
        self::assertStringNotContainsString('/assets/img/dummy', $html);
    }

    public function testRealResourceFailureIsNotConvertedIntoFallback(): void
    {
        $adapter = new class implements BlogHeaderResourceAdapterInterface {
            public function supports(array $resources): bool
            {
                return true;
            }

            public function render(string $resource, array $parameters): string
            {
                throw new RuntimeException('Project resource failed.');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Project resource failed.');

        (new BlogArticleHeaderResourceRenderer(
            new BlogHeaderPresetCatalog(),
            $adapter
        ))->render(
            BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'Matrix',
            null,
            null
        );
    }

    private function media(): BlogArticleHeaderMedia
    {
        $image = new BlogResolvedImage(
            '11111111-1111-4111-8111-111111111111',
            [
                new BlogResolvedImageCandidate(
                    '/media/480.avif?token=a&b=1',
                    480
                ),
                new BlogResolvedImageCandidate(
                    '/media/1200.avif?token=a&b=1',
                    1200
                ),
            ],
            1200,
            800
        );
        return new BlogArticleHeaderMedia(
            $image,
            '22222222-2222-4222-8222-222222222222',
            'Cover & decisions',
            'The "choice"',
            null,
            'medium'
        );
    }
}
