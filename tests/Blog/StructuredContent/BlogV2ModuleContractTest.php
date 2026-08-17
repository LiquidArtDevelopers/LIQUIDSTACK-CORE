<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV1CompatibilityProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV2CompatibilityCanonicalizer;
use App\Core\Blog\StructuredContent\Document\BlogEmbedHtmlSanitizer;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use PHPUnit\Framework\TestCase;

final class BlogV2ModuleContractTest extends TestCase
{
    public function testTypographyIsExplicitAndRestrictedToTextModules(): void
    {
        $raw = $this->document([
            $this->paragraph(3, $this->typographyPresentation()),
        ]);
        $document = BlogDocument::fromArray($raw);

        self::assertSame(
            $this->typographyPresentation(),
            $document->blocks()[0]['children'][1]['presentation']
        );
        $html = $this->renderer()->render($document);
        self::assertStringContainsString(
            'blogDocument__module--font-size-large',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__module--font-weight-semibold',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__module--text-color02',
            $html
        );

        $historical = BlogDocument::fromArray($this->document([
            $this->paragraph(3, ['width' => 'full', 'align' => 'start']),
        ]));
        self::assertSame([
            'width' => 'full',
            'align' => 'start',
            'text_align' => 'start',
        ], $historical->blocks()[0]['children'][1]['presentation']);
        self::assertArrayNotHasKey(
            'font_size',
            $historical->blocks()[0]['children'][1]['presentation']
        );

        $list = $this->listBlock(3, false, 'disc');
        $list['presentation'] = $this->typographyPresentation();
        $this->assertInvalid($this->document([$list]));

        $partial = $this->paragraph(3, $this->presentation());
        $partial['presentation']['font_size'] = 'large';
        $this->assertInvalid($this->document([$partial]));
    }

    public function testClosedPresetsAndListMarkersRenderAsStableClasses(): void
    {
        $heading = $this->heading(2, 2);
        $heading['preset'] = 'accent-line';
        $raw = $this->document([
            $this->listBlock(3, false, 'square'),
            $this->listBlock(6, true, 'upper-alpha'),
            $this->cta(9, 'type03'),
            $this->cta(10, 'type04'),
        ], $heading);
        $html = $this->renderer()->render(BlogDocument::fromArray($raw));

        self::assertStringContainsString(
            'blogDocument__heading--preset-accent-line',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__list--marker-square',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__list--marker-upper-alpha',
            $html
        );
        self::assertStringContainsString('blogDocument__cta--type03', $html);
        self::assertStringContainsString('blogDocument__cta--type04', $html);

        $this->assertInvalid($this->document([
            $this->listBlock(3, false, 'decimal'),
        ]));
        $this->assertInvalid($this->document([
            $this->listBlock(3, true, 'circle'),
        ]));
        $invalidHeading = $this->heading(2, 2);
        $invalidHeading['preset'] = 'free-css';
        $this->assertInvalid($this->document([], $invalidHeading));
    }

    public function testQuoteAndEmbedAreSanitizedAndRenderedServerSide(): void
    {
        $document = BlogDocument::fromArray($this->document([
            [
                'id' => $this->id(3),
                'type' => 'quote',
                'content' => [$this->text('There is no spoon.', ['em'])],
                'author' => 'Neo',
                'source' => 'The Matrix',
                'preset' => 'accent',
                'presentation' => $this->presentation(),
            ],
            [
                'id' => $this->id(4),
                'type' => 'embed',
                'html' => '<script>alert(1)</script>'
                    . '<p class="z a" onclick="bad()" style="color:red">'
                    . 'Vídeo seguro</p><iframe onload="bad()" '
                    . 'src="https://www.youtube.com/embed/dQw4w9WgXcQ" '
                    . 'width="560" height="315"></iframe>',
                'caption' => 'Escena de Matrix',
                'presentation' => $this->presentation(),
            ],
        ]));
        $blocks = $document->blocks()[0]['children'];
        $embed = $blocks[2];

        self::assertStringNotContainsString('<script', $embed['html']);
        self::assertStringNotContainsString('onclick', $embed['html']);
        self::assertStringNotContainsString('onload', $embed['html']);
        self::assertStringNotContainsString('style=', $embed['html']);
        self::assertStringContainsString('class="a z"', $embed['html']);
        self::assertStringContainsString(
            'sandbox="allow-scripts allow-same-origin allow-presentation"',
            $embed['html']
        );

        $html = $this->renderer()->render($document);
        self::assertStringContainsString('<blockquote ', $html);
        self::assertStringContainsString(
            'blogDocument__quote--preset-accent',
            $html
        );
        self::assertStringContainsString('<cite ', $html);
        self::assertStringContainsString(
            'data-blog-consent-iframe=',
            $html
        );
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString(
            'src="https://www.youtube.com',
            $html
        );
        self::assertStringContainsString(
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            $html
        );
        self::assertStringContainsString('Escena de Matrix', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testEmbedRejectsDangerousOnlyOrUntrustedFrames(): void
    {
        foreach ([
            '<script>alert(1)</script>',
            '<iframe src="https://evil.example/embed/1"></iframe>',
            '<img src="data:image/svg+xml,evil" onload="evil()">',
        ] as $html) {
            $this->assertInvalid($this->document([[
                'id' => $this->id(3),
                'type' => 'embed',
                'html' => $html,
                'caption' => null,
                'presentation' => $this->presentation(),
            ]]));
        }

        $vimeo = BlogDocument::fromArray($this->document([[
            'id' => $this->id(3),
            'type' => 'embed',
            'html' => '<iframe src="https://player.vimeo.com/video/123" '
                . 'title="Matrix"></iframe>',
            'caption' => null,
            'presentation' => $this->presentation(),
        ]]));
        self::assertStringContainsString(
            'https://player.vimeo.com/video/123',
            $vimeo->blocks()[0]['children'][1]['html']
        );
    }

    public function testEmbedCssIsCanonicalScopedAndLegacyCompatible(): void
    {
        $embed = [
            'id' => $this->id(3),
            'type' => 'embed',
            'html' => '<div id="player" class="video"><a href="#player">'
                . 'Vídeo</a><iframe '
                . 'src="https://player.vimeo.com/video/123"></iframe></div>',
            'css' => '.video{max-width:60rem;}#player{margin:auto;}'
                . 'iframe{width:100%;}',
            'caption' => null,
            'presentation' => $this->presentation(),
        ];
        $document = BlogDocument::fromArray($this->document([$embed]));
        $canonical = $document->blocks()[0]['children'][1];
        self::assertSame(
            '& .video{max-width:60rem;}& #player{margin:auto;}'
                . '& iframe{width:100%;}',
            $canonical['css']
        );

        $renderer = $this->renderer();
        $html = $renderer->render($document);
        $prefix = 'lsb-' . str_replace('-', '', $this->id(3)) . '-';
        $namespaced = (new BlogEmbedHtmlSanitizer())->namespaceForRender(
            $canonical['html'],
            $this->id(3)
        );
        self::assertStringContainsString('id="' . $prefix . 'player"', $namespaced);
        self::assertStringContainsString('href="#' . $prefix . 'player"', $namespaced);
        self::assertStringContainsString('class="' . $prefix . 'video"', $html);
        self::assertStringContainsString('data-ls-blog-custom="' . $this->id(3) . '"', $html);
        $css = $renderer->renderScopedCss($document);
        self::assertStringContainsString('.' . $prefix . 'video', $css);
        self::assertStringContainsString('#' . $prefix . 'player', $css);
        self::assertStringContainsString('& iframe{width:100%;}', $css);

        unset($embed['css']);
        $legacy = BlogDocument::fromArray($this->document([$embed]));
        self::assertSame('', $legacy->blocks()[0]['children'][1]['css']);
        $legacyRaw = $legacy->toArray();
        unset($legacyRaw['blocks'][0]['children'][1]['css']);
        $legacyJson = json_encode(
            $legacyRaw,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        self::assertContains(
            $legacyJson,
            (new BlogDocumentV2CompatibilityCanonicalizer())
                ->candidates($legacy)
        );
    }

    public function testCompatibilityProjectionsRemoveEveryV2OnlyField(): void
    {
        $heading = $this->heading(2, 2);
        $heading['preset'] = 'accent-block';
        $document = BlogDocument::fromArray($this->document([
            $this->paragraph(3, $this->typographyPresentation()),
            $this->listBlock(4, false, 'circle'),
            [
                'id' => $this->id(7),
                'type' => 'quote',
                'content' => [$this->text('Wake up, Neo.', ['underline'])],
                'author' => 'Morpheus',
                'source' => null,
                'preset' => 'minimal',
                'presentation' => $this->presentation(),
            ],
            [
                'id' => $this->id(8),
                'type' => 'embed',
                'html' => '<iframe src="https://player.vimeo.com/video/123" '
                    . 'title="Matrix"></iframe>',
                'caption' => 'Vídeo de Matrix',
                'presentation' => $this->presentation(),
            ],
            $this->cta(9, 'type04'),
        ], $heading));

        $fallback = (new BlogDocumentV1CompatibilityProjector())->project(
            $document
        );
        $blocks = $fallback->blocks();
        self::assertSame(BlogDocument::VERSION, $fallback->version());
        self::assertArrayNotHasKey('preset', $blocks[0]);
        self::assertArrayNotHasKey('presentation', $blocks[1]);
        self::assertArrayNotHasKey('marker', $blocks[2]);
        self::assertSame('callout', $blocks[3]['type']);
        self::assertSame(['em'], $blocks[3]['content'][2]['marks']);
        self::assertSame('paragraph', $blocks[4]['type']);
        self::assertSame('Vídeo de Matrix', $blocks[4]['content'][0]['text']);
        self::assertSame('secondary', $blocks[5]['variant']);

        $text = (new BlogDocumentTextProjector())->project($document);
        self::assertStringContainsString("Wake up, Neo.\nMorpheus", $text);
        self::assertStringContainsString('Vídeo de Matrix', $text);
    }

    public function testSeparatorAndControlledSpacingRoundTripSafely(): void
    {
        $presentation = $this->presentation() + [
            'size' => 'm',
            'spacing_before' => 'l',
            'spacing_after' => 's',
        ];
        $document = BlogDocument::fromArray($this->document([[
            'id' => $this->id(3),
            'type' => 'separator',
            'line_style' => 'dashed',
            'thickness' => 'medium',
            'color' => 'rgba(20, 40, 60, 0.5)',
            'presentation' => $presentation,
        ]]));
        $separator = $document->blocks()[0]['children'][1];

        self::assertSame($presentation, $separator['presentation']);
        self::assertSame(
            'Matrix',
            (new BlogDocumentTextProjector())->project($document)
        );
        $html = $this->renderer()->render($document);
        self::assertStringContainsString('<hr id=', $html);
        self::assertStringContainsString(
            'blogDocument__separator--dashed',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__separator--medium',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__module--spacing-l-before',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__module--spacing-s-after',
            $html
        );
        self::assertStringContainsString(
            'data-blog-separator-rgba="rgba(20, 40, 60, 0.5)"',
            $html
        );

        $fallback = (new BlogDocumentV1CompatibilityProjector())->project(
            $document
        );
        self::assertCount(1, $fallback->blocks());

        foreach ([
            ['line_style' => 'free-css'],
            ['thickness' => '12px'],
            ['color' => 'var(--evil)'],
        ] as $mutation) {
            $invalid = $this->document([array_replace([
                'id' => $this->id(3),
                'type' => 'separator',
                'line_style' => 'solid',
                'thickness' => 'thin',
                'color' => 'color01',
                'presentation' => $presentation,
            ], $mutation)]);
            $this->assertInvalid($invalid);
        }

        $invalidSpacing = $presentation;
        $invalidSpacing['spacing_after'] = '17rem';
        $this->assertInvalid($this->document([[
            'id' => $this->id(3),
            'type' => 'separator',
            'line_style' => 'solid',
            'thickness' => 'thin',
            'color' => 'color01',
            'presentation' => $invalidSpacing,
        ]]));
    }

    /**
     * @param list<array<string, mixed>> $modules
     * @param ?array<string, mixed> $heading
     * @return array<string, mixed>
     */
    private function document(array $modules, ?array $heading = null): array
    {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'section',
                'children' => array_merge([
                    $heading ?? $this->heading(2, 2),
                ], $modules),
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function heading(int $id, int $level): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'heading',
            'level' => $level,
            'content' => [$this->text('Matrix')],
            'presentation' => $this->presentation(),
        ];
    }

    /** @param array<string, string> $presentation @return array<string, mixed> */
    private function paragraph(int $id, array $presentation): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'paragraph',
            'content' => [$this->text('Follow the white rabbit.')],
            'presentation' => $presentation,
        ];
    }

    /** @return array<string, mixed> */
    private function listBlock(int $id, bool $ordered, string $marker): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'list',
            'ordered' => $ordered,
            'marker' => $marker,
            'items' => [[
                'id' => $this->id($id + 1),
                'content' => [$this->text('Red pill')],
            ], [
                'id' => $this->id($id + 2),
                'content' => [$this->text('Blue pill')],
            ]],
            'presentation' => $this->presentation(),
        ];
    }

    /** @return array<string, mixed> */
    private function cta(int $id, string $variant): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'cta',
            'label' => 'Entrar en Matrix',
            'href' => '/matrix',
            'title' => null,
            'target' => 'same',
            'variant' => $variant,
            'presentation' => $this->presentation(),
        ];
    }

    /** @param list<string> $marks @return array<string, mixed> */
    private function text(string $text, array $marks = []): array
    {
        return ['type' => 'text', 'text' => $text, 'marks' => $marks];
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

    /** @return array<string, string> */
    private function typographyPresentation(): array
    {
        return $this->presentation() + [
            'font_size' => 'large',
            'font_weight' => 'semibold',
            'text_color' => 'color02',
        ];
    }

    /** @param array<string, mixed> $raw */
    private function assertInvalid(array $raw): void
    {
        try {
            BlogDocument::fromArray($raw);
            self::fail('An invalid V2 Blog module was accepted.');
        } catch (BlogDocumentException $exception) {
            self::assertSame(
                BlogDocumentException::INVALID_BLOCK,
                $exception->issueCode()
            );
        }
    }

    private function renderer(): BlogDocumentHtmlRenderer
    {
        return new BlogDocumentHtmlRenderer(
            new class implements BlogImageResolverInterface {
                public function resolve(
                    string $mediaAssetPublicId
                ): ?BlogResolvedImage {
                    return null;
                }
            }
        );
    }

    private function id(int $number): string
    {
        return sprintf('40000000-0000-4000-8000-%012d', $number);
    }
}
