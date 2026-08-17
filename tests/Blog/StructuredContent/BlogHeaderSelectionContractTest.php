<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Http\BlogPublicHtmlRenderer;
use App\Core\Blog\Preview\BlogPreviewAssetContext;
use App\Core\Blog\Preview\BlogPreviewAssetSet;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV1CompatibilityProjector;
use App\Core\Blog\StructuredContent\Presentation\BlogH1ModuleCatalog;
use App\Core\Blog\StructuredContent\Presentation\BlogHeaderSelection;
use App\Core\Blog\StructuredContent\Presentation\BlogHeroCatalog;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImageCandidate;
use App\Core\Blog\StructuredContent\Rendering\BlogStructuredEditorHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogStructuredPrivateHtmlRenderer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BlogHeaderSelectionContractTest extends TestCase
{
    public function testCatalogsAreSeparateTypedAndSafeToProject(): void
    {
        $heroes = new BlogHeroCatalog();
        $modules = new BlogH1ModuleCatalog();

        self::assertSame(['hero00', 'hero06', 'hero07'], $heroes->keys());
        self::assertSame(
            ['moduleH1Type01', 'moduleH1Type03', 'moduleH1Type04'],
            $modules->keys()
        );
        self::assertSame('hero06', $heroes->resource('hero06'));
        self::assertSame(
            'moduleH1Type04',
            $modules->resource('moduleH1Type04')
        );
        self::assertSame('Hero 00', $heroes->toSafeArray()[0]['label']);
    }

    public function testV2PersistsHeroAndH1AsIndependentSelection(): void
    {
        $raw = $this->coverDocument();
        $raw['header'] = [
            'hero' => BlogHeroCatalog::HERO06,
            'h1_module' => BlogH1ModuleCatalog::TYPE04,
        ];

        $document = BlogDocument::fromArray($raw);
        $selection = BlogHeaderSelection::forDocument($document);
        $canonical = (new BlogDocumentCodec())->encode($document);

        self::assertSame(BlogHeroCatalog::HERO06, $selection->hero());
        self::assertSame(
            BlogH1ModuleCatalog::TYPE04,
            $selection->h1Module()
        );
        self::assertSame($raw['header'], $document->headerSelectionData());
        self::assertStringContainsString(
            '"header":{"hero":"hero06","h1_module":"moduleH1Type04"}',
            $canonical
        );
        self::assertSame(
            $raw['header'],
            (new BlogDocumentCodec())->decode($canonical)
                ->headerSelectionData()
        );
        $draft = new BlogStructuredDraft('Matrix', $document);
        self::assertStringContainsString(
            '"header":{"hero":"hero06","h1_module":"moduleH1Type04"}',
            $draft->canonicalJson()
        );

        $compatibility = (new BlogDocumentV1CompatibilityProjector())
            ->project($document);
        self::assertNull($compatibility->headerSelectionData());
        self::assertSame(
            BlogDocumentTemplateRegistry::ARTICLE_COVER,
            $compatibility->template()
        );
    }

    public function testLegacyDocumentKeepsItsExactCanonicalShape(): void
    {
        $document = BlogDocument::fromArray($this->coverDocument());
        $canonical = (new BlogDocumentCodec())->encode($document);

        self::assertNull($document->headerSelectionData());
        self::assertStringNotContainsString('"header"', $canonical);
        self::assertSame(
            BlogHeroCatalog::HERO07,
            BlogHeaderSelection::forDocument($document)->hero()
        );
    }

    public function testHeaderSelectionIsClosedAndMatchesMediaContract(): void
    {
        $invalidHero = $this->coverDocument();
        $invalidHero['header'] = [
            'hero' => 'hero-free-html',
            'h1_module' => BlogH1ModuleCatalog::TYPE01,
        ];
        $this->assertIssue(
            BlogDocumentException::INVALID_HEADER_PRESENTATION,
            $invalidHero
        );

        $basicWithHero = $this->basicDocument();
        $basicWithHero['header'] = [
            'hero' => BlogHeroCatalog::HERO00,
            'h1_module' => BlogH1ModuleCatalog::TYPE03,
        ];
        $this->assertIssue(
            BlogDocumentException::INVALID_TEMPLATE_CONTRACT,
            $basicWithHero
        );

        $v1 = [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'header' => [
                'hero' => null,
                'h1_module' => BlogH1ModuleCatalog::TYPE04,
            ],
            'blocks' => [],
        ];
        $this->assertIssue(
            BlogDocumentException::INVALID_HEADER_PRESENTATION,
            $v1
        );
    }

    public function testPublicSsrUsesThePersistedIndependentComposition(): void
    {
        $raw = $this->coverDocument();
        $raw['header'] = [
            'hero' => BlogHeroCatalog::HERO06,
            'h1_module' => BlogH1ModuleCatalog::TYPE04,
        ];
        $document = BlogDocument::fromArray($raw);
        $mediaId = $this->id(2);
        $resolver = new class($mediaId) implements BlogImageResolverInterface {
            public function __construct(private readonly string $mediaId)
            {
            }

            public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
            {
                if ($mediaAssetPublicId !== $this->mediaId) {
                    return null;
                }

                return new BlogResolvedImage(
                    $this->mediaId,
                    [new BlogResolvedImageCandidate('/media/900.avif', 900)],
                    900,
                    600
                );
            }
        };
        $now = new DateTimeImmutable('2030-01-01 10:00:00 UTC');
        $variant = new BlogPostVariant(
            $this->id(10),
            $this->id(11),
            'es',
            new BlogDraft(
                'Matrix y decisiones',
                'Matrix',
                'matrix-decisiones',
                'Matrix y decisiones conscientes',
                'Una descripción de Matrix y sus decisiones.',
                'Una introducción a las decisiones de Neo.'
            ),
            BlogPostVariant::PUBLISHED,
            $now,
            1,
            $this->id(12),
            $this->id(12),
            $now,
            $now
        );

        $html = (new BlogPublicHtmlRenderer())->renderStructured(
            $variant,
            'https://example.test/es/noticias/matrix-decisiones',
            $document,
            $resolver
        );

        self::assertStringContainsString(
            'blogArticleHero hero06 blogArticleHero--hero06',
            $html
        );
        self::assertStringContainsString('moduleH1Type04', $html);
        self::assertStringNotContainsString('moduleH1Type03', $html);
        self::assertStringNotContainsString('Idioma ES', $html);

        $view = tempnam(sys_get_temp_dir(), 'blog-header-contract-');
        self::assertIsString($view);
        file_put_contents(
            $view,
            '<?php echo $blogArticle->headerHtml();'
        );
        try {
            $projectHtml = (new BlogPublicHtmlRenderer($view))
                ->renderStructured(
                    $variant,
                    'https://example.test/es/noticias/matrix-decisiones',
                    $document,
                    $resolver
                );
        } finally {
            @unlink($view);
        }
        self::assertStringContainsString('hero06', $projectHtml);
        self::assertStringContainsString('moduleH1Type04', $projectHtml);
    }

    public function testAllHeroAndH1PairsAreDistinctAndPreviewMatchesPublicSsr(): void
    {
        $mediaId = $this->id(2);
        $resolver = new class($mediaId) implements BlogImageResolverInterface {
            public function __construct(private readonly string $mediaId)
            {
            }

            public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
            {
                if ($mediaAssetPublicId !== $this->mediaId) {
                    return null;
                }

                return new BlogResolvedImage(
                    $this->mediaId,
                    [
                        new BlogResolvedImageCandidate('/media/480.avif', 480),
                        new BlogResolvedImageCandidate('/media/1800.avif', 1800),
                    ],
                    1800,
                    1200
                );
            }
        };
        $now = new DateTimeImmutable('2030-01-01 10:00:00 UTC');
        $variant = new BlogPostVariant(
            $this->id(30),
            $this->id(31),
            'es',
            new BlogDraft(
                'Matrix y decisiones',
                'Matrix',
                'matrix-decisiones',
                'Matrix y decisiones conscientes',
                'Una descripción de Matrix y sus decisiones.',
                'Una introducción a las decisiones de Neo.'
            ),
            BlogPostVariant::PUBLISHED,
            $now,
            1,
            $this->id(32),
            $this->id(32),
            $now,
            $now
        );
        $assets = new BlogPreviewAssetSet(
            new BlogPreviewAssetContext(dirname(__DIR__, 3), false),
            ['/assets/css/blog-article.test.css'],
            ['/assets/js/blog-article.test.js'],
            ['/assets/modules/blog/blog-public.js']
        );
        $public = new BlogPublicHtmlRenderer();
        $private = new BlogStructuredPrivateHtmlRenderer(
            new BlogDocumentHtmlRenderer($resolver)
        );
        $signatures = [];

        foreach ((new BlogHeroCatalog())->keys() as $hero) {
            foreach ((new BlogH1ModuleCatalog())->keys() as $h1Module) {
                $raw = $this->coverDocument();
                $raw['header'] = [
                    'hero' => $hero,
                    'h1_module' => $h1Module,
                ];
                $document = BlogDocument::fromArray($raw);
                $publicHtml = $public->renderStructured(
                    $variant,
                    'https://example.test/es/noticias/matrix-decisiones',
                    $document,
                    $resolver
                );
                $previewHtml = $private->preview(
                    '/admin/blog',
                    $variant,
                    $document,
                    null,
                    $assets
                );
                $publicHeader = $this->headerHtml($publicHtml);
                $previewHeader = $this->headerHtml($previewHtml);

                self::assertSame($publicHeader, $previewHeader);
                self::assertStringContainsString($hero, $publicHeader);
                self::assertStringContainsString($h1Module, $publicHeader);
                $signatures[] = hash('sha256', $publicHeader);
            }
        }

        self::assertCount(9, array_unique($signatures));
    }

    public function testEditorSsrProjectsTypedCatalogsAndSemanticHeroLabel(): void
    {
        $document = BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [],
        ]);
        $now = new DateTimeImmutable('2030-01-01 10:00:00 UTC');
        $variant = new BlogPostVariant(
            $this->id(20),
            $this->id(21),
            'es',
            new BlogDraft('Matrix', ''),
            BlogPostVariant::DRAFT,
            null,
            1,
            $this->id(22),
            $this->id(22),
            $now,
            $now
        );

        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'csrf-safe_token-123',
            $variant,
            $document,
            (new BlogDocumentCodec())->encode($document)
        );

        self::assertStringContainsString('data-blog-header-label="HERO"', $html);
        self::assertStringContainsString('data-blog-hero-catalog="[', $html);
        self::assertStringContainsString(
            'data-blog-h1-module-catalog="[',
            $html
        );
        self::assertStringContainsString(
            'data-blog-header-selection="{&quot;hero&quot;:null,',
            $html
        );
    }

    /** @return array<string, mixed> */
    private function coverDocument(): array
    {
        $document = $this->basicDocument();
        $document['template'] = BlogDocumentTemplateRegistry::ARTICLE_COVER;
        array_unshift($document['blocks'], [
            'id' => $this->id(1),
            'type' => 'image',
            'media_asset_public_id' => $this->id(2),
            'alt' => 'Neo elige la píldora roja',
            'title' => null,
            'caption' => null,
            'decorative' => false,
            'display' => 'cover',
            'presentation' => $this->presentation(),
        ]);

        return $document;
    }

    /** @return array<string, mixed> */
    private function basicDocument(): array
    {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id(3),
                'type' => 'section',
                'children' => [[
                    'id' => $this->id(4),
                    'type' => 'heading',
                    'level' => 2,
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Matrix',
                        'marks' => [],
                    ]],
                    'presentation' => $this->presentation(),
                ]],
            ]],
        ];
    }

    /** @return array<string, string> */
    private function presentation(): array
    {
        return [
            'width' => 'full',
            'align' => 'center',
            'text_align' => 'start',
        ];
    }

    /** @param array<string, mixed> $document */
    private function assertIssue(string $issue, array $document): void
    {
        try {
            BlogDocument::fromArray($document);
            self::fail('Expected Blog header contract failure.');
        } catch (BlogDocumentException $exception) {
            self::assertSame($issue, $exception->issueCode());
        }
    }

    private function id(int $number): string
    {
        return sprintf('90000000-0000-4000-8000-%012d', $number);
    }

    private function headerHtml(string $document): string
    {
        self::assertSame(1, preg_match(
            '/<header\\b[^>]*>.*?<\\/header>/s',
            $document,
            $matches
        ));

        return $matches[0];
    }
}
