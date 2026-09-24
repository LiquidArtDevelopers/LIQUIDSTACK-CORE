<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Editing\BlogEditorTechnicalLimitCatalog;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImageCandidate;
use PHPUnit\Framework\TestCase;

final class BlogPublicPresentationContractTest extends TestCase
{
    public function testCanonicalScaleAndLegacyFontSizesShareSafeClasses(): void
    {
        $heading = $this->heading(2);
        $heading['presentation']['size'] = 'm';
        $modern = $this->paragraph(3);
        $modern['presentation'] += [
            'size' => 'xl',
            'font_weight' => 'bold',
            'text_color' => 'rgba(002, 040, 255, 0.5000)',
        ];
        $legacy = $this->paragraph(4);
        $legacy['presentation'] += [
            'font_size' => 'large',
            'font_weight' => 'semibold',
            'text_color' => 'color05',
        ];
        $list = $this->listBlock(5);
        $list['presentation']['size'] = 's';

        $document = BlogDocument::fromArray(
            $this->document([$modern, $legacy, $list], $heading)
        );
        $children = $document->blocks()[0]['children'];
        self::assertSame('m', $children[0]['presentation']['size']);
        self::assertSame('xl', $children[1]['presentation']['size']);
        self::assertSame(
            'rgba(2, 40, 255, 0.5)',
            $children[1]['presentation']['text_color']
        );
        self::assertSame(
            'large',
            $children[2]['presentation']['font_size']
        );

        $html = $this->renderer([])->render($document);
        self::assertStringContainsString('blogDocument--background-white', $html);
        self::assertStringContainsString('data-blog-canvas="white"', $html);
        self::assertStringContainsString('blogDocument__module--size-m', $html);
        self::assertStringContainsString('blogDocument__module--size-xl', $html);
        self::assertStringContainsString('blogDocument__module--size-l', $html);
        self::assertStringContainsString('blogDocument__module--size-s', $html);
        self::assertStringContainsString(
            'blogDocument__module--font-size-xlarge',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__module--font-size-large',
            $html
        );
        self::assertStringContainsString(
            'data-blog-text-rgba="rgba(2, 40, 255, 0.5)"',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__module--text-color05',
            $html
        );
    }

    public function testContainerPresentationIsCanonicalClosedData(): void
    {
        $article = $this->article(3, [$this->paragraph(6)]);
        $article['presentation'] = [
            'background' => ' RGBA(010, 020, 030, 0.2500) ',
            'padding' => 'none',
            'text_color' => 'color00',
        ];
        $division = $this->division(7, [$this->paragraph(10)]);
        $division['presentation'] = [
            'background' => 'color04',
            'padding' => 's',
            'text_color' => ' RGBA(240, 240, 240, 1.0000) ',
        ];
        $autoContrast = $this->division(20, [$this->paragraph(23)]);
        $autoContrast['presentation'] = [
            'background' => 'rgba(10, 20, 30, 1)',
            'padding' => 'm',
        ];
        $raw = $this->document([$article, $division, $autoContrast]);
        $raw['blocks'][0]['presentation'] = [
            'background' => 'color05',
            'padding' => 'l',
        ];

        $document = BlogDocument::fromArray($raw);
        self::assertSame(
            'rgba(10, 20, 30, 0.25)',
            $document->blocks()[0]['children'][1]['presentation']['background']
        );
        self::assertSame(
            'rgba(240, 240, 240, 1)',
            $document->blocks()[0]['children'][2]['presentation']['text_color']
        );
        $html = $this->renderer([])->render($document);
        self::assertStringContainsString(
            'blogDocument__container--background-color05',
            $html
        );
        self::assertStringContainsString(
            'data-blog-background-token="color04"',
            $html
        );
        self::assertStringContainsString(
            'data-blog-background-rgba="rgba(10, 20, 30, 0.25)"',
            $html
        );
        foreach ([
            'blogDocument__container--padding-none',
            'blogDocument__container--padding-s',
            'blogDocument__container--padding-m',
            'blogDocument__container--padding-l',
            'blogDocument__container--text-color00',
            'blogDocument__container--text-rgba',
            'blogDocument__container--contrast-color05',
            'blogDocument__container--contrast-light',
        ] as $class) {
            self::assertStringContainsString($class, $html);
        }
        self::assertStringContainsString(
            'data-blog-container-text-rgba="rgba(240, 240, 240, 1)"',
            $html
        );
        self::assertStringNotContainsString(' style=', $html);

        foreach ([
            ['background' => 'color06'],
            ['background' => 'rgba(256, 0, 0, 1)'],
            ['background' => 'rgba(0, 0, 0, 1);background:url(javascript:alert(1))'],
            ['padding' => 'xl'],
            ['text_color' => 'default'],
        ] as $invalidPresentation) {
            $candidate = $this->document([]);
            $candidate['blocks'][0]['presentation'] = $invalidPresentation;
            $this->assertInvalidBlock($candidate);
        }
    }

    public function testArticleAndDivisionWidthsUseClosedResponsiveClasses(): void
    {
        $article = $this->article(3, [$this->paragraph(6)]);
        $article['presentation'] = [
            'width' => '60',
            'align' => 'center',
        ];
        $division = $this->division(7, [$this->paragraph(10)]);
        $division['presentation'] = [
            'width' => '40',
            'align' => 'end',
            'background' => 'color04',
        ];

        $document = BlogDocument::fromArray(
            $this->document([$article, $division])
        );
        self::assertSame(
            ['width' => '60', 'align' => 'center'],
            $document->blocks()[0]['children'][1]['presentation']
        );
        self::assertSame(
            [
                'width' => '40',
                'align' => 'end',
                'background' => 'color04',
            ],
            $document->blocks()[0]['children'][2]['presentation']
        );

        $html = $this->renderer([])->render($document);
        foreach ([
            'blogDocument__container--width-60',
            'blogDocument__container--align-center',
            'blogDocument__container--width-40',
            'blogDocument__container--align-end',
        ] as $class) {
            self::assertStringContainsString($class, $html);
        }

        $partial = $this->article(20, [$this->paragraph(23)]);
        $partial['presentation'] = ['width' => '60'];
        $this->assertInvalidBlock($this->document([$partial]));

        $invalidSection = $this->document([]);
        $invalidSection['blocks'][0]['presentation'] = [
            'width' => '60',
            'align' => 'center',
        ];
        $this->assertInvalidBlock($invalidSection);
    }

    public function testImageRadiusUsesContextualDefaultAndClosedPresets(): void
    {
        $directDefault = $this->image(3, 30);
        $directExplicit = $this->image(4, 40);
        $directExplicit['presentation']['radius'] = 'large';
        $nested = $this->image(8, 80);
        $article = $this->article(5, [$nested]);
        $document = BlogDocument::fromArray($this->document([
            $directDefault,
            $directExplicit,
            $article,
        ]));
        $html = $this->renderer([
            $this->id(30), $this->id(40), $this->id(80),
        ])->render($document);

        self::assertStringContainsString(
            'blog-block-' . $this->id(3)
                . '" class="blogDocument__module '
                . 'blogDocument__module--width-full',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__image--radius-none',
            $html
        );
        self::assertStringContainsString(
            'data-blog-image-radius="none"',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__image--radius-large',
            $html
        );
        self::assertStringContainsString(
            'data-blog-image-radius="large"',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__image--radius-medium',
            $html
        );
        self::assertStringContainsString(
            'data-blog-image-radius="medium"',
            $html
        );

        $invalid = $this->image(3, 30);
        $invalid['presentation']['radius'] = '2rem;display:none';
        $this->assertInvalidBlock($this->document([$invalid]));
    }

    public function testImagePresentationIsClosedScopedAndCspNeutral(): void
    {
        $image = $this->image(3, 30);
        $image['presentation'] += [
            'height_dvh' => 55,
            'object_fit' => 'contain',
            'object_position_y' => 'bottom',
            'radius_percent' => 24,
            'overlay_mode' => 'multiply',
            'overlay_color' => ' RGBA(010, 020, 030, 0.5000) ',
            'overlay_opacity' => 35,
        ];
        $document = BlogDocument::fromArray($this->document([$image]));
        $presentation = $document->blocks()[0]['children'][1]['presentation'];
        self::assertSame('rgba(10, 20, 30, 0.5)', $presentation['overlay_color']);

        $renderer = $this->renderer([$this->id(30)]);
        $html = $renderer->render($document);
        $css = $renderer->renderScopedCss($document);
        foreach ([
            'blogDocument__image--height-custom',
            'blogDocument__image--fit-contain',
            'blogDocument__image--position-y-bottom',
            'blogDocument__image--radius-percent',
            'blogDocument__image--overlay-multiply',
            'data-blog-image-height-dvh="55"',
            'data-blog-image-radius-percent="24"',
        ] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        self::assertStringNotContainsString(' style=', $html);
        self::assertStringContainsString(
            '#blog-block-' . $this->id(3)
                . ' .blogDocument__imageElement{height:55vh;height:55dvh;}',
            $css
        );
        self::assertStringContainsString('border-radius:24%', $css);
        self::assertStringContainsString(
            'background-color:rgba(10, 20, 30, 0.5);opacity:0.35',
            $css
        );

        $policy = (new BlogEditorTechnicalLimitCatalog())->toSafeArray()[
            'image_presentation_policy'
        ];
        self::assertSame([5, 100, 1], [
            $policy['height_dvh']['min'],
            $policy['height_dvh']['max'],
            $policy['height_dvh']['step'],
        ]);
        self::assertSame(
            ['top', 'center', 'bottom'],
            $policy['object_position_y']['values']
        );

        $conflict = $image;
        $conflict['presentation']['radius'] = 'large';
        $this->assertInvalidBlock($this->document([$conflict]));
        foreach ([
            ['height_dvh' => 4],
            ['object_fit' => 'fill'],
            ['object_position_y' => 'left'],
            ['overlay_mode' => 'multiply'],
            [
                'overlay_mode' => 'difference',
                'overlay_color' => 'color02',
                'overlay_opacity' => 50,
            ],
        ] as $invalidPresentation) {
            $invalid = $this->image(3, 30);
            $invalid['presentation'] += $invalidPresentation;
            $this->assertInvalidBlock($this->document([$invalid]));
        }
    }

    public function testInlineRgbaColorsAreCanonicalAndMutuallyExclusive(): void
    {
        $paragraph = $this->paragraph(3);
        $paragraph['content'] = [[
            'type' => 'text',
            'text' => 'Wake up, Neo.',
            'marks' => [
                'strong',
                'text-rgba:RGBA(001, 002, 003, 0.7500)',
                'background-rgba:rgba(240, 241, 242, 1.000)',
            ],
        ]];
        $document = BlogDocument::fromArray($this->document([$paragraph]));
        $marks = $document->blocks()[0]['children'][1]['content'][0]['marks'];
        self::assertSame([
            'strong',
            'text-rgba:rgba(1, 2, 3, 0.75)',
            'background-rgba:rgba(240, 241, 242, 1)',
        ], $marks);

        $html = $this->renderer([])->render($document);
        self::assertStringContainsString(
            'data-blog-inline-text-rgba="rgba(1, 2, 3, 0.75)"',
            $html
        );
        self::assertStringContainsString(
            'data-blog-inline-background-rgba="rgba(240, 241, 242, 1)"',
            $html
        );
        self::assertStringNotContainsString(' style=', $html);

        $conflict = $paragraph;
        $conflict['content'][0]['marks'][] = 'text-color02';
        $this->assertInvalidInline($this->document([$conflict]));

        $injection = $paragraph;
        $injection['content'][0]['marks'] = [
            'text-rgba:rgba(0,0,0,1);font-size:99rem',
        ];
        $this->assertInvalidInline($this->document([$injection]));
    }

    /** @param list<array<string, mixed>> $children */
    private function document(array $children, ?array $heading = null): array
    {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'section',
                'children' => array_merge([
                    $heading ?? $this->heading(2),
                ], $children),
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function heading(int $number): array
    {
        return [
            'id' => $this->id($number),
            'type' => 'heading',
            'level' => 2,
            'content' => [$this->text('Matrix')],
            'presentation' => $this->presentation(),
        ];
    }

    /** @return array<string, mixed> */
    private function paragraph(int $number): array
    {
        return [
            'id' => $this->id($number),
            'type' => 'paragraph',
            'content' => [$this->text('Follow the white rabbit.')],
            'presentation' => $this->presentation(),
        ];
    }

    /** @return array<string, mixed> */
    private function listBlock(int $number): array
    {
        return [
            'id' => $this->id($number),
            'type' => 'list',
            'ordered' => false,
            'marker' => 'disc',
            'items' => [[
                'id' => $this->id($number + 1),
                'content' => [$this->text('Red pill')],
            ]],
            'presentation' => $this->presentation(),
        ];
    }

    /** @param list<array<string, mixed>> $children */
    private function article(int $number, array $children): array
    {
        return [
            'id' => $this->id($number),
            'type' => 'article',
            'layout' => $this->layout($number + 1, $children),
        ];
    }

    /** @param list<array<string, mixed>> $children */
    private function division(int $number, array $children): array
    {
        return [
            'id' => $this->id($number),
            'type' => 'div',
            'layout' => $this->layout($number + 1, $children),
        ];
    }

    /** @param list<array<string, mixed>> $children @return array<string, mixed> */
    private function layout(int $column, array $children): array
    {
        return [
            'preset' => '1',
            'columns' => [[
                'id' => $this->id($column),
                'children' => $children,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function image(int $number, int $media): array
    {
        return [
            'id' => $this->id($number),
            'type' => 'image',
            'media_asset_public_id' => $this->id($media),
            'alt' => 'Matrix',
            'title' => null,
            'caption' => null,
            'decorative' => false,
            'display' => 'content',
            'presentation' => $this->presentation(),
        ];
    }

    /** @return array<string, string> */
    private function presentation(): array
    {
        return [
            'width' => 'full',
            'align' => 'start',
            'text_align' => 'start',
        ];
    }

    /** @return array<string, mixed> */
    private function text(string $value): array
    {
        return ['type' => 'text', 'text' => $value, 'marks' => []];
    }

    /** @param list<string> $mediaIds */
    private function renderer(array $mediaIds): BlogDocumentHtmlRenderer
    {
        $images = [];
        foreach ($mediaIds as $mediaId) {
            $images[$mediaId] = new BlogResolvedImage(
                $mediaId,
                [new BlogResolvedImageCandidate('/media/900.avif', 900)],
                900,
                600
            );
        }

        return new BlogDocumentHtmlRenderer(
            new class($images) implements BlogImageResolverInterface {
                /** @param array<string, BlogResolvedImage> $images */
                public function __construct(private readonly array $images)
                {
                }

                public function resolve(
                    string $mediaAssetPublicId
                ): ?BlogResolvedImage {
                    return $this->images[$mediaAssetPublicId] ?? null;
                }
            }
        );
    }

    /** @param array<string, mixed> $document */
    private function assertInvalidBlock(array $document): void
    {
        $this->assertInvalid($document, BlogDocumentException::INVALID_BLOCK);
    }

    /** @param array<string, mixed> $document */
    private function assertInvalidInline(array $document): void
    {
        $this->assertInvalid($document, BlogDocumentException::INVALID_INLINE);
    }

    /** @param array<string, mixed> $document */
    private function assertInvalid(array $document, string $issueCode): void
    {
        try {
            BlogDocument::fromArray($document);
            self::fail('Invalid Blog presentation was accepted.');
        } catch (BlogDocumentException $exception) {
            self::assertSame($issueCode, $exception->issueCode());
        }
    }

    private function id(int $number): string
    {
        return sprintf('70000000-0000-4000-8000-%012d', $number);
    }
}
