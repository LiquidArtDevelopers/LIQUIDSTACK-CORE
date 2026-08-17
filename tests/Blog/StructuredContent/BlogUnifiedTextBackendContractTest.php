<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\Seo\BlogSeoSemanticProjector;
use App\Core\Blog\StructuredContent\Document\BlogCustomTextCssSanitizer;
use App\Core\Blog\StructuredContent\Document\BlogCustomTextHtmlSanitizer;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV1CompatibilityProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV2Projector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentValidator;
use App\Core\Blog\StructuredContent\Document\BlogDocumentWalker;
use App\Core\Blog\StructuredContent\Document\BlogUnifiedTextProjector;
use App\Core\Blog\StructuredContent\Editing\BlogEditorTechnicalLimitCatalog;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use PHPUnit\Framework\TestCase;

final class BlogUnifiedTextBackendContractTest extends TestCase
{
    public function testStandaloneWrittenModulesProjectLosslesslyToText(): void
    {
        $source = BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'section',
                'children' => [[
                    'id' => $this->id(2),
                    'type' => 'heading',
                    'level' => 2,
                    'content' => [$this->text('Titulo conservado')],
                    'preset' => 'accent-line',
                    'presentation' => $this->textPresentation(),
                ], [
                    'id' => $this->id(3),
                    'type' => 'list',
                    'ordered' => false,
                    'items' => [[
                        'id' => $this->id(4),
                        'content' => [$this->text('Primer punto')],
                    ], [
                        'id' => $this->id(5),
                        'content' => [$this->text('Segundo punto')],
                    ]],
                    'marker' => 'square',
                    'presentation' => [
                        'width' => '80',
                        'align' => 'center',
                        'text_align' => 'start',
                        'size' => 'm',
                        'text_color' => 'color02',
                        'spacing_before' => 's',
                        'spacing_after' => 'm',
                    ],
                ], [
                    'id' => $this->id(6),
                    'type' => 'article',
                    'layout' => [
                        'preset' => '1',
                        'columns' => [[
                            'id' => $this->id(7),
                            'children' => [[
                                'id' => $this->id(8),
                                'type' => 'quote',
                                'content' => [$this->text('Cita exacta')],
                                'author' => 'Autora',
                                'source' => 'Fuente',
                                'preset' => 'accent',
                                'presentation' => $this->basePresentation(),
                            ], [
                                'id' => $this->id(9),
                                'type' => 'callout',
                                'tone' => 'warning',
                                'content' => [$this->text('Aviso exacto')],
                                'presentation' => $this->basePresentation(),
                            ], [
                                'id' => $this->id(10),
                                'type' => 'paragraph',
                                'content' => [$this->text('Parrafo inline')],
                                'presentation' => $this->textPresentation(),
                            ]],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $textProjector = new BlogDocumentTextProjector();
        $before = $textProjector->project($source);
        $projector = new BlogUnifiedTextProjector();
        $projected = $projector->project($source);

        self::assertSame($before, $textProjector->project($projected));
        self::assertSame(
            $projected->toArray(),
            $projector->project($projected)->toArray()
        );
        self::assertSame(
            $projected->toArray(),
            (new BlogDocumentV2Projector())->project($source)->toArray()
        );

        $modules = (new BlogDocumentWalker())->modules($projected);
        self::assertSame(
            array_fill(0, 5, 'paragraph'),
            array_column($modules, 'type')
        );
        self::assertSame(
            [$this->id(2), $this->id(3), $this->id(8), $this->id(9), $this->id(10)],
            array_column($modules, 'id')
        );

        $heading = $modules[0]['content'][0];
        self::assertSame('accent-line', $heading['preset']);
        $list = $modules[1]['content'][0];
        self::assertSame('square', $list['marker']);
        self::assertSame(
            [$this->id(4), $this->id(5)],
            array_column($list['items'], 'id')
        );
        self::assertSame('default', $modules[1]['presentation']['font_weight']);
        self::assertSame('color02', $modules[1]['presentation']['text_color']);
        self::assertSame([
            'type' => 'quote',
            'content' => [$this->text('Cita exacta')],
            'author' => 'Autora',
            'source' => 'Fuente',
            'preset' => 'accent',
        ], $modules[2]['content'][0]);
        self::assertSame('warning', $modules[3]['content'][0]['tone']);
        self::assertSame('paragraph', $modules[4]['content'][0]['type']);

        $html = $this->renderer()->render($projected);
        self::assertStringContainsString(
            'blogDocument__heading--preset-accent-line',
            $html
        );
        self::assertStringContainsString(
            'blogDocument__list--marker-square',
            $html
        );
        self::assertStringContainsString(
            'id="blog-item-' . $this->id(4) . '"',
            $html
        );
        self::assertStringContainsString('Autora', $html);
        self::assertStringContainsString('Fuente', $html);
        self::assertStringContainsString(
            'blogDocument__callout--warning',
            $html
        );
        self::assertSame(
            $before,
            $textProjector->project(
                (new BlogDocumentV1CompatibilityProjector())->project(
                    $projected
                )
            )
        );
    }

    public function testStandaloneLinkProjectsLosslesslyToPrimaryCta(): void
    {
        $presentation = [
            'width' => '80',
            'align' => 'center',
            'text_align' => 'justify',
            'size' => 'l',
            'spacing_before' => 's',
            'spacing_after' => 'm',
        ];
        $projectedPresentation = $presentation;
        $projectedPresentation['text_align'] = 'start';
        $source = BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'section',
                'children' => [[
                    'id' => $this->id(2),
                    'type' => 'heading',
                    'level' => 2,
                    'content' => [$this->text('Titulo')],
                    'presentation' => $this->textPresentation(),
                ], [
                    'id' => $this->id(3),
                    'type' => 'link',
                    'label' => 'Conservar enlace',
                    'href' => '/destino?origen=blog#detalle',
                    'title' => 'Titulo del enlace',
                    'target' => 'new',
                    'presentation' => $presentation,
                ]],
            ]],
        ]);

        $textProjector = new BlogDocumentTextProjector();
        $projected = (new BlogUnifiedTextProjector())->project($source);
        $modules = (new BlogDocumentWalker())->modules($projected);

        self::assertSame(
            $textProjector->project($source),
            $textProjector->project($projected)
        );
        self::assertSame([
            'id' => $this->id(3),
            'type' => 'cta',
            'label' => 'Conservar enlace',
            'href' => '/destino?origen=blog#detalle',
            'title' => 'Titulo del enlace',
            'target' => 'new',
            'variant' => 'primary',
            'presentation' => $projectedPresentation,
        ], $modules[1]);
        self::assertSame(
            $projected->toArray(),
            (new BlogUnifiedTextProjector())->project($projected)->toArray()
        );

        $html = $this->renderer()->render($projected);
        self::assertStringContainsString('blogDocument__cta--primary', $html);
        self::assertStringContainsString(
            'target="_blank" rel="noopener noreferrer"',
            $html
        );

        $invalid = $projected->toArray();
        $invalid['blocks'][0]['children'][1]['presentation']['text_align'] =
            'justify';
        $this->expectException(BlogDocumentException::class);
        BlogDocument::fromArray($invalid);
    }

    public function testOneTextModuleOwnsTheSectionHeadingAndRichFlow(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->headingFlow(2, 'Guía Matrix'),
            $this->flow('paragraph', 'Introducción'),
            [
                'type' => 'list',
                'ordered' => false,
                'items' => [
                    ['content' => [$this->text('Píldora roja')]],
                    ['content' => [$this->text('Píldora azul')]],
                ],
            ],
            $this->flow('quote', 'No hay cuchara.'),
            $this->flow('callout', 'Decisión importante'),
            $this->headingFlow(3, 'Siguiente nivel'),
        ]));

        $html = $this->renderer()->render($document);
        $headingId = 'blog-flow-heading-'
            . str_replace('-', '', $this->id(2)) . '-0';
        self::assertStringContainsString(
            'aria-labelledby="' . $headingId . '"',
            $html
        );
        self::assertStringContainsString(
            '<h2 id="' . $headingId
                . '" class="blogDocument__textHeading blogDocument__heading">',
            $html
        );
        self::assertStringContainsString(
            'class="blogDocument__textQuote"',
            $html
        );
        self::assertStringContainsString(
            '<aside class="blogDocument__textCallout" role="note">',
            $html
        );
        self::assertStringContainsString(
            '<h3 id="blog-flow-heading-'
                . str_replace('-', '', $this->id(2)) . '-1"',
            $html
        );

        $bodyText = (new BlogDocumentTextProjector())->project($document);
        self::assertStringContainsString("Guía Matrix\n\nIntroducción", $bodyText);
        self::assertStringContainsString("- Píldora roja\n- Píldora azul", $bodyText);
        self::assertStringContainsString('No hay cuchara.', $bodyText);

        $fallback = (new BlogDocumentV1CompatibilityProjector())->project(
            $document
        );
        self::assertSame(
            ['heading', 'paragraph', 'list', 'callout', 'callout', 'heading'],
            array_column($fallback->blocks(), 'type')
        );
        self::assertSame(
            [[
                'level' => 2,
                'text' => 'Guía Matrix',
            ], [
                'level' => 3,
                'text' => 'Siguiente nivel',
            ]],
            (new BlogSeoSemanticProjector())->project($document)->headings()
        );
    }

    public function testRootFlowBreakRendersAsSiblingAndDowngradesWithoutEmptyParagraph(): void
    {
        $flow = [
            ['type' => 'break'],
            $this->headingFlow(2, 'Titulo con hueco'),
            ['type' => 'break'],
            ['type' => 'break'],
            $this->flow('paragraph', 'Contenido'),
            ['type' => 'break'],
            ['type' => 'break'],
        ];
        $document = BlogDocument::fromArray($this->document($flow));

        self::assertSame(
            $flow,
            $document->toArray()['blocks'][0]['children'][0]['content']
        );
        $html = $this->renderer()->render($document);
        self::assertSame(
            5,
            substr_count($html, '<br class="blogDocument__flowBreak">')
        );
        self::assertStringNotContainsString(
            '<p class="blogDocument__textParagraph"></p>',
            $html
        );
        self::assertSame(
            "Titulo con hueco\n\nContenido",
            (new BlogDocumentTextProjector())->project($document)
        );

        $fallback = (new BlogDocumentV1CompatibilityProjector())->project(
            $document
        );
        self::assertSame(
            ['heading', 'paragraph'],
            array_column($fallback->blocks(), 'type')
        );
        $fallbackBlocks = $fallback->blocks();
        $breaks = 0;
        array_walk_recursive(
            $fallbackBlocks,
            static function (mixed $value, mixed $key) use (&$breaks): void {
                if ($key === 'type' && $value === 'break') {
                    ++$breaks;
                }
            }
        );
        self::assertSame(5, $breaks);
        self::assertSame(
            (new BlogDocumentTextProjector())->project($document),
            (new BlogDocumentTextProjector())->project($fallback)
        );
    }

    public function testRootFlowBreakAloneIsDraftOnly(): void
    {
        $data = $this->document([$this->headingFlow(2, 'Titulo')]);
        $data['blocks'][0]['children'][] = [
            'id' => $this->id(3),
            'type' => 'paragraph',
            'content' => [['type' => 'break']],
            'presentation' => [
                'width' => 'full',
                'align' => 'start',
                'text_align' => 'start',
            ],
        ];

        try {
            BlogDocument::fromArray($data);
            self::fail('Expected a break-only published module to fail.');
        } catch (BlogDocumentException $exception) {
            self::assertSame(
                BlogDocumentException::INVALID_INLINE,
                $exception->issueCode()
            );
        }

        $draft = BlogDocument::fromArray(
            $data,
            (new BlogDocumentValidator())->forDrafts()
        );
        self::assertSame(
            [['type' => 'break']],
            $draft->toArray()['blocks'][0]['children'][1]['content']
        );
    }

    public function testRootFlowBreakCountsTowardAggregateTextBytes(): void
    {
        $flow = [$this->headingFlow(2, str_repeat('h', 20_000))];
        for ($index = 0; $index < 9; ++$index) {
            $flow[] = $this->flow('paragraph', str_repeat('p', 20_000));
        }
        BlogDocument::fromArray($this->document($flow));
        $flow[] = ['type' => 'break'];

        try {
            BlogDocument::fromArray($this->document($flow));
            self::fail('Expected the root break byte to exceed the limit.');
        } catch (BlogDocumentException $exception) {
            self::assertSame(
                BlogDocumentException::INVALID_INLINE,
                $exception->issueCode()
            );
        }
    }

    public function testLegacyTextFlowFallbackTrimsWithoutFlatteningInlineNodes(): void
    {
        $source = BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'section',
                'children' => [[
                    'id' => $this->id(2),
                    'type' => 'paragraph',
                    'content' => [$this->headingFlow(2, 'Titulo')],
                    'presentation' => $this->textPresentation(),
                ], [
                    'id' => $this->id(3),
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'break'],
                            $this->markedText('   Introduccion ', ['strong']),
                            $this->link('enlace   ', '/introduccion', ['em']),
                            ['type' => 'break'],
                        ],
                    ], [
                        'type' => 'list',
                        'ordered' => true,
                        'items' => [[
                            'content' => [
                                ['type' => 'break'],
                                $this->text('   '),
                                ['type' => 'break'],
                            ],
                        ], [
                            'content' => [
                                $this->text(' '),
                                $this->link(
                                    'Asset allocation   ',
                                    '/asset-allocation',
                                    ['em']
                                ),
                                ['type' => 'break'],
                            ],
                        ], [
                            'content' => [
                                ['type' => 'break'],
                                $this->markedText('  Segundo  ', ['strong']),
                                ['type' => 'break'],
                            ],
                        ]],
                    ]],
                    'presentation' => $this->textPresentation(),
                ]],
            ]],
        ], (new BlogDocumentValidator())->forDrafts());

        $textProjector = new BlogDocumentTextProjector();
        $fallback = (new BlogDocumentV1CompatibilityProjector())->project(
            $source
        );

        self::assertSame(
            "Titulo\n\nIntroduccion enlace\n\n1. Asset allocation\n2. Segundo",
            $textProjector->project($source)
        );
        self::assertSame(
            $textProjector->project($source),
            $textProjector->project($fallback)
        );
        self::assertSame([
            $this->markedText('Introduccion ', ['strong']),
            $this->link('enlace', '/introduccion', ['em']),
            ['type' => 'break'],
            ['type' => 'break'],
            $this->text('1. '),
            $this->link('Asset allocation', '/asset-allocation', ['em']),
            ['type' => 'break'],
            $this->text('2. '),
            $this->markedText('Segundo', ['strong']),
        ], $fallback->blocks()[1]['content']);
    }

    public function testAdvancedTextAllowsSafeHeadingsAndSemanticCallout(): void
    {
        $htmlSanitizer = new BlogCustomTextHtmlSanitizer();
        $source = '<h2>Inicio</h2><p>Texto</p>'
            . '<ul><li>Punto</li></ul>'
            . '<blockquote class="miClase"><p>Cita</p></blockquote>'
            . '<aside data-content-callout="true"><p>Aviso</p></aside>';
        $canonical = $htmlSanitizer->sanitize($source);

        self::assertStringContainsString(
            '<aside data-content-callout="true" role="note"><p>Aviso</p></aside>',
            $canonical
        );
        self::assertSame(
            ['level' => 2, 'text' => 'Inicio'],
            $htmlSanitizer->firstRootHeading($canonical)
        );
        self::assertSame(
            ['level' => 2, 'text' => 'Inicio'],
            $htmlSanitizer->firstRootHeading('<br><br><h2>Inicio</h2>')
        );
        $rendered = $htmlSanitizer->namespaceForRender(
            $canonical,
            $this->id(2)
        );
        self::assertStringContainsString(
            'id="blog-flow-heading-'
                . str_replace('-', '', $this->id(2)) . '-0"',
            $rendered
        );
        $prefix = $htmlSanitizer->namespacePrefix($this->id(2));
        foreach ([
            'class="blogDocument__textHeading blogDocument__heading"',
            'class="blogDocument__textParagraph"',
            'class="blogDocument__textList"',
            'class="blogDocument__textListItem"',
            'class="' . $prefix . 'miClase blogDocument__textQuote"',
            'class="blogDocument__textQuoteContent"',
            'class="blogDocument__textCallout"',
            'class="blogDocument__textCalloutContent"',
        ] as $publicContract) {
            self::assertStringContainsString($publicContract, $rendered);
        }
        self::assertSame(
            '& h2{color:red;}& aside[data-content-callout="true"]{padding:1rem;}',
            (new BlogCustomTextCssSanitizer())->sanitize(
                'h2 { color: red; } '
                    . 'aside[data-content-callout="true"] { padding: 1rem; }'
            )
        );

        $fourLevels = str_repeat('<ul><li>', 4)
            . 'Profundidad segura' . str_repeat('</li></ul>', 4);
        self::assertStringContainsString(
            'Profundidad segura',
            $htmlSanitizer->sanitize($fourLevels)
        );

        foreach ([
            '<h1>H1 prohibido</h1>',
            '<aside>Aviso sin contrato</aside>',
            str_repeat('<ul><li>', 5)
                . 'Demasiado profundo' . str_repeat('</li></ul>', 5),
        ] as $invalid) {
            try {
                $htmlSanitizer->sanitize($invalid);
                self::fail('Expected unsafe advanced HTML to fail closed.');
            } catch (BlogDocumentException) {
            }
        }
    }

    public function testTextLimitIsAggregateAndLargeEnoughForFiveThousandWords(): void
    {
        $paragraph = implode(' ', array_fill(0, 250, 'palabra'));
        $flow = [$this->headingFlow(2, 'Contenido extenso')];
        for ($index = 0; $index < 20; ++$index) {
            $flow[] = $this->flow('paragraph', $paragraph);
        }
        $document = BlogDocument::fromArray($this->document($flow));
        self::assertGreaterThan(
            30_000,
            strlen((new BlogDocumentTextProjector())->project($document))
        );

        $limits = (new BlogEditorTechnicalLimitCatalog())->toSafeArray();
        self::assertSame(
            BlogDocumentValidator::MAX_TEXT_MODULE_BYTES,
            $limits['block']['text_content']['bytes']
        );
        self::assertSame(
            BlogCustomTextHtmlSanitizer::MAX_HTML_BYTES,
            $limits['block']['html']['bytes']
        );
        self::assertSame(200_000, $limits['block']['html']['bytes']);

        $overflow = [$this->headingFlow(2, 'Desbordamiento')];
        for ($index = 0; $index < 11; ++$index) {
            $overflow[] = $this->flow(
                'paragraph',
                str_repeat((string) ($index % 10), 19_000)
            );
        }
        try {
            BlogDocument::fromArray($this->document($overflow));
            self::fail('Expected the aggregate Text limit to fail closed.');
        } catch (BlogDocumentException $exception) {
            self::assertSame(
                BlogDocumentException::INVALID_INLINE,
                $exception->issueCode()
            );
        }
    }

    /** @param list<array<string, mixed>> $flow */
    private function document(array $flow): array
    {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'section',
                'children' => [[
                    'id' => $this->id(2),
                    'type' => 'paragraph',
                    'content' => $flow,
                    'presentation' => [
                        'width' => 'full',
                        'align' => 'start',
                        'text_align' => 'start',
                    ],
                ]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function headingFlow(int $level, string $text): array
    {
        return [
            'type' => 'heading',
            'level' => $level,
            'content' => [$this->text($text)],
        ];
    }

    /** @return array<string, mixed> */
    private function flow(string $type, string $text): array
    {
        return ['type' => $type, 'content' => [$this->text($text)]];
    }

    /** @return array{type: string, text: string, marks: list<string>} */
    private function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text, 'marks' => []];
    }

    /** @param list<string> $marks @return array<string, mixed> */
    private function markedText(string $text, array $marks): array
    {
        return ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    /** @param list<string> $marks @return array<string, mixed> */
    private function link(string $text, string $href, array $marks): array
    {
        return [
            'type' => 'link',
            'text' => $text,
            'marks' => $marks,
            'href' => $href,
            'title' => null,
            'target' => 'same',
        ];
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
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

    /** @return array<string, string> */
    private function basePresentation(): array
    {
        return [
            'width' => 'full',
            'align' => 'start',
            'text_align' => 'start',
            'size' => 'm',
            'spacing_before' => 'none',
            'spacing_after' => 'none',
        ];
    }

    /** @return array<string, string> */
    private function textPresentation(): array
    {
        return $this->basePresentation() + [
            'font_weight' => 'default',
            'text_color' => 'default',
        ];
    }
}
