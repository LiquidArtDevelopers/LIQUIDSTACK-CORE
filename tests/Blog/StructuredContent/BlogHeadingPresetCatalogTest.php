<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Presentation\BlogHeadingPresetCatalog;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use PHPUnit\Framework\TestCase;

final class BlogHeadingPresetCatalogTest extends TestCase
{
    public function testCatalogDescribesRealShowroomHeadingPatterns(): void
    {
        $catalog = BlogHeadingPresetCatalog::defaults();

        self::assertSame('default', $catalog->defaultKey());
        self::assertSame([
            'default',
            'accent-line',
            'accent-block',
        ], $catalog->keys());
        self::assertSame([
            'base',
            'moduleH2Type01',
            'moduleH2Type02',
        ], array_column($catalog->toSafeArray(), 'showroom_resource'));
        self::assertSame(
            ['Base', "L\u{00ED}nea", 'Degradado'],
            array_column($catalog->toSafeArray(), 'label')
        );
        self::assertSame([
            'blogEditor__headingPreset--base',
            'blogEditor__headingPreset--moduleH2Type01',
            'blogEditor__headingPreset--moduleH2Type02',
        ], array_column($catalog->toSafeArray(), 'preview_class'));
        self::assertSame([
            'blogDocument__heading--preset-default',
            'blogDocument__heading--preset-accent-line',
            'blogDocument__heading--preset-accent-block',
        ], array_column($catalog->toSafeArray(), 'ssr_class'));
        self::assertFalse($catalog->isAllowed('free-css'));
        self::assertTrue($catalog->has('accent-line'));
        self::assertSame(
            'blogEditor__headingPreset--moduleH2Type01',
            $catalog->previewClass('accent-line')
        );
        self::assertSame(
            'blogDocument__heading--preset-accent-line',
            $catalog->publicClass('accent-line')
        );
        self::assertNull($catalog->find('free-css'));
    }

    public function testValidatorAndSsrRendererConsumeEveryCatalogToken(): void
    {
        $catalog = new BlogHeadingPresetCatalog();
        foreach ($catalog->presets() as $position => $preset) {
            $document = BlogDocument::fromArray(
                $this->document($preset->token(), $position + 1)
            );
            self::assertSame(
                $preset->token(),
                $document->blocks()[0]['children'][0]['preset']
            );
            self::assertStringContainsString(
                $preset->ssrClass(),
                $this->renderer($catalog)->render($document)
            );
        }

        $this->expectException(BlogDocumentException::class);
        BlogDocument::fromArray($this->document('free-css', 9));
    }

    public function testCatalogResourcesAndPublishedClassesExist(): void
    {
        $root = dirname(__DIR__, 3);
        $catalog = BlogHeadingPresetCatalog::defaults();
        $publicCss = (string) file_get_contents(
            $root . '/modules/blog/published/assets/blog-public.css'
        );
        $adminCss = (string) file_get_contents(
            $root . '/modules/blog/published/assets/blog-admin.css'
        );

        foreach ($catalog->presets() as $preset) {
            self::assertStringContainsString($preset->ssrClass(), $publicCss);
            if ($preset->showroomResource() === 'base') {
                continue;
            }
            self::assertFileExists(
                $root . '/resources/scss/_'
                    . $preset->showroomResource() . '.scss'
            );
            self::assertFileExists(
                $root . '/stubs/App/controllers/'
                    . $preset->showroomResource() . '.php'
            );
            self::assertStringContainsString(
                $preset->previewClass(),
                $adminCss
            );
        }
    }

    /** @return array<string, mixed> */
    private function document(string $preset, int $number): array
    {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id($number),
                'type' => 'section',
                'children' => [[
                    'id' => $this->id($number + 20),
                    'type' => 'heading',
                    'level' => 2,
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Matrix heading',
                        'marks' => [],
                    ]],
                    'preset' => $preset,
                    'presentation' => [
                        'width' => 'full',
                        'align' => 'start',
                        'text_align' => 'start',
                    ],
                ]],
            ]],
        ];
    }

    private function renderer(
        BlogHeadingPresetCatalog $catalog
    ): BlogDocumentHtmlRenderer {
        return new BlogDocumentHtmlRenderer(
            new class implements BlogImageResolverInterface {
                public function resolve(
                    string $mediaAssetPublicId
                ): ?BlogResolvedImage {
                    return null;
                }
            },
            $catalog
        );
    }

    private function id(int $number): string
    {
        return sprintf('51000000-0000-4000-8000-%012d', $number);
    }
}
