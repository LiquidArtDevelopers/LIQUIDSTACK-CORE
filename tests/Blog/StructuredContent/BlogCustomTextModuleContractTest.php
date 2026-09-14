<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Http\BlogPublicHtmlRenderer;
use App\Core\Blog\Preview\BlogPreviewAssetContext;
use App\Core\Blog\Preview\BlogPreviewAssetSet;
use App\Core\Blog\Seo\BlogSeoSemanticProjector;
use App\Core\Blog\StructuredContent\Document\BlogCustomTextCssSanitizer;
use App\Core\Blog\StructuredContent\Document\BlogCustomTextHtmlSanitizer;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV1CompatibilityProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentValidator;
use App\Core\Blog\StructuredContent\Document\BlogSafeIframePolicy;
use App\Core\Blog\StructuredContent\Editing\BlogEditorTechnicalLimitCatalog;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use App\Core\Blog\StructuredContent\Rendering\BlogStructuredPrivateHtmlRenderer;
use DateTimeImmutable;
use ErrorException;
use PHPUnit\Framework\TestCase;

final class BlogCustomTextModuleContractTest extends TestCase
{
    public function testAdvancedParagraphIsExclusiveCanonicalAndScopedByBlock(): void
    {
        $sourceHtml = '<div id="hola" class="panel card" '
            . 'data-content-kind="lead" role="region" aria-label="Intro">'
            . '<p aria-labelledby="hola">Hello <a href="#hola">link</a></p>'
            . '</div>';
        $sourceCss = 'color: #123; .card { padding: 1rem; '
            . '& #hola { font-weight: 700; } }';
        $document = BlogDocument::fromArray($this->document([
            $this->advancedParagraph(3, $sourceHtml, $sourceCss),
            $this->advancedParagraph(4, $sourceHtml, $sourceCss),
        ]));

        $first = $document->blocks()[0]['children'][1];
        self::assertSame(['id', 'type', 'html', 'css', 'presentation'], array_keys($first));
        self::assertArrayNotHasKey('content', $first);
        self::assertSame($first['html'], (new BlogCustomTextHtmlSanitizer())->sanitize($first['html']));
        self::assertSame($first['css'], (new BlogCustomTextCssSanitizer())->sanitize($first['css']));

        $renderer = $this->renderer();
        $html = $renderer->render($document);
        $css = $renderer->renderScopedCss($document);
        $firstPrefix = 'lsb-' . str_replace('-', '', $this->id(3)) . '-';
        $secondPrefix = 'lsb-' . str_replace('-', '', $this->id(4)) . '-';

        foreach ([$firstPrefix, $secondPrefix] as $prefix) {
            self::assertStringContainsString('class="' . $prefix . 'card ' . $prefix . 'panel"', $html);
            self::assertStringContainsString('id="' . $prefix . 'hola"', $html);
            self::assertStringContainsString('href="#' . $prefix . 'hola"', $html);
            self::assertStringContainsString('aria-labelledby="' . $prefix . 'hola"', $html);
            self::assertStringContainsString('.' . $prefix . 'card', $css);
            self::assertStringContainsString('#' . $prefix . 'hola', $css);
        }
        self::assertStringContainsString(
            '[data-ls-blog-custom="' . $this->id(3) . '"]{'
                . 'position:relative!important;isolation:isolate!important;'
                . 'contain:paint!important;overflow:clip!important;'
                . 'box-sizing:border-box!important;max-inline-size:100%!important;'
                . 'color:#123;',
            $css
        );
        self::assertNotSame($firstPrefix, $secondPrefix);
    }

    public function testAdvancedVisualMarksRemainSanitizedAndKeepParentClass(): void
    {
        $sourceHtml = '<p class="miClase">'
            . '<span data-content-format-text-color="color04">'
            . '<strong>nuevo párrafo</strong></span></p>';
        $sourceCss = '& .miClase { color: red; }';
        $document = BlogDocument::fromArray($this->document([
            $this->advancedParagraph(3, $sourceHtml, $sourceCss),
        ]));

        $paragraph = $document->blocks()[0]['children'][1];
        self::assertSame($sourceHtml, $paragraph['html']);
        self::assertSame('& .miClase{color:red;}', $paragraph['css']);

        $rendered = $this->renderer()->render($document);
        $prefix = 'lsb-' . str_replace('-', '', $this->id(3)) . '-';
        self::assertStringContainsString(
            'class="' . $prefix
                . 'miClase blogDocument__textParagraph"',
            $rendered
        );
        self::assertStringContainsString(
            'class="blogDocument__inline '
                . 'blogDocument__inline--text-color04"',
            $rendered
        );
        self::assertStringNotContainsString(
            'data-content-format-text-color',
            $rendered
        );
        self::assertStringNotContainsString(
            $prefix . 'blogDocument__inline',
            $rendered
        );
        self::assertStringContainsString(
            '<strong>nuevo párrafo</strong>',
            $rendered
        );
    }

    public function testAdvancedFormatAttributesProjectToPublicInlineClasses(): void
    {
        $sanitizer = new BlogCustomTextHtmlSanitizer();
        $blockId = $this->id(3);
        $prefix = $sanitizer->namespacePrefix($blockId);
        $source = '<p class="miClase">Antes '
            . '<span class="seleccion" data-content-format-size="xlarge" '
            . 'data-content-format-text-color="basic-blue" '
            . 'data-content-format-background-color="color03">'
            . '<strong>nuevo párrafo</strong></span></p>';

        $canonical = $sanitizer->sanitize($source);
        self::assertStringContainsString(
            'data-content-format-text-color="basic-blue"',
            $canonical
        );
        $rendered = $sanitizer->namespaceForRender($canonical, $blockId);

        self::assertStringContainsString(
            'class="' . $prefix
                . 'miClase blogDocument__textParagraph"',
            $rendered
        );
        self::assertStringContainsString(
            'class="' . $prefix . 'seleccion blogDocument__inline '
                . 'blogDocument__inline--size-xlarge '
                . 'blogDocument__inline--text-basic-blue '
                . 'blogDocument__inline--background-color03"',
            $rendered
        );
        self::assertStringContainsString(
            '<strong>nuevo párrafo</strong>',
            $rendered
        );
        self::assertStringNotContainsString('data-content-format-', $rendered);
        self::assertStringNotContainsString(
            $prefix . 'blogDocument__inline',
            $rendered
        );
    }

    public function testAdvancedRgbaAttributesCanonicalizeAndProjectSafely(): void
    {
        $sanitizer = new BlogCustomTextHtmlSanitizer();
        $source = '<p><span data-content-format-text-rgba="'
            . 'rgba(10,20,30,0.2500)" '
            . 'data-content-format-background-rgba="'
            . 'rgba(240, 241, 242, 1.000)">RGBA</span></p>';

        $canonical = $sanitizer->sanitize($source);
        self::assertStringContainsString(
            'data-content-format-text-rgba="rgba(10, 20, 30, 0.25)"',
            $canonical
        );
        self::assertStringContainsString(
            'data-content-format-background-rgba="rgba(240, 241, 242, 1)"',
            $canonical
        );

        $rendered = $sanitizer->namespaceForRender($canonical, $this->id(3));
        self::assertStringContainsString(
            'class="blogDocument__inline blogDocument__inline--text-rgba '
                . 'blogDocument__inline--background-rgba"',
            $rendered
        );
        self::assertStringContainsString(
            'data-blog-inline-text-rgba="rgba(10, 20, 30, 0.25)"',
            $rendered
        );
        self::assertStringContainsString(
            'data-blog-inline-background-rgba="rgba(240, 241, 242, 1)"',
            $rendered
        );
        self::assertStringNotContainsString('data-content-format-', $rendered);
    }

    public function testAdvancedFormatAttributeContractFailsClosed(): void
    {
        $sanitizer = new BlogCustomTextHtmlSanitizer();
        foreach ([
            '<p data-content-format-size="large">Wrong element</p>',
            '<span data-content-format-shadow="large">Unknown</span>',
            '<span data-content-format-size="huge">Invalid size</span>',
            '<span data-content-format-text-color="brand">Invalid color</span>',
            '<span data-content-format-text-rgba="#fff">Invalid RGBA</span>',
            '<span data-content-format-text-color="color02" '
                . 'data-content-format-text-rgba="rgba(1,2,3,.5)">'
                . 'Conflicting text</span>',
            '<span data-content-format-background-color="basic-red" '
                . 'data-content-format-background-rgba="rgba(1,2,3,.5)">'
                . 'Conflicting background</span>',
        ] as $source) {
            $this->assertHtmlRejected($sanitizer, $source);
        }

        self::assertSame(
            '<span data-content-state="ready">Compatible</span>',
            $sanitizer->sanitize(
                '<span data-content-state="ready">Compatible</span>'
            )
        );
    }

    public function testAdvancedParagraphProjectsTextForCompatibilityAndSeo(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->advancedParagraph(
                3,
                '<div><p>Hello <strong>Neo</strong>.</p><ul><li>Red pill</li></ul></div>',
                ''
            ),
        ]));

        self::assertStringContainsString(
            "Hello Neo.\nRed pill",
            (new BlogDocumentTextProjector())->project($document)
        );
        $flat = (new BlogDocumentV1CompatibilityProjector())->project($document);
        self::assertSame(BlogDocument::VERSION, $flat->version());
        self::assertStringContainsString(
            'Hello Neo.',
            (new BlogDocumentTextProjector())->project($flat)
        );
        self::assertStringContainsString(
            'Hello Neo.',
            (new BlogSeoSemanticProjector())->project($document)->bodyText()
        );
    }

    public function testStructuredDraftPreservesAdvancedParagraphBreaksExactly(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->advancedParagraph(
                3,
                '<p>uno</p><p class="miClase">dos</p>',
                '.miClase { color: red; }'
            ),
        ]));
        $draft = new BlogStructuredDraft('Matrix article', $document);
        $textProjector = new BlogDocumentTextProjector();

        self::assertSame(
            $textProjector->project($document),
            $draft->compatibilityDraft()->bodyText()
        );
        self::assertSame(
            "Matrix\n\nuno\ndos",
            $draft->compatibilityDraft()->bodyText()
        );
        self::assertSame([
            ['type' => 'text', 'text' => 'uno', 'marks' => []],
            ['type' => 'break'],
            ['type' => 'text', 'text' => 'dos', 'marks' => []],
        ], $draft->compatibilityDocument()->blocks()[1]['content']);
    }

    public function testAdvancedFallbackHonoursV1TextAndNodeLimits(): void
    {
        $projector = new BlogDocumentV1CompatibilityProjector();
        $textProjector = new BlogDocumentTextProjector();
        $longText = str_repeat(
            'a',
            BlogDocumentValidator::MAX_INLINE_TEXT_BYTES
        ) . "\u{20AC}";
        $longDocument = BlogDocument::fromArray($this->document([
            $this->advancedParagraph(3, '<p>' . $longText . '</p>', ''),
        ]));
        $longFallback = $projector->project($longDocument);

        self::assertSame(
            $textProjector->project($longDocument),
            $textProjector->project($longFallback)
        );
        self::assertSame(
            BlogDocumentValidator::MAX_INLINE_TEXT_BYTES,
            strlen($longFallback->blocks()[1]['content'][0]['text'])
        );
        self::assertSame(
            "\u{20AC}",
            $longFallback->blocks()[1]['content'][1]['text']
        );

        $maximumLines = array_merge(
            ['<p>' . str_repeat(
                'a',
                BlogDocumentValidator::MAX_INLINE_TEXT_BYTES + 1
            ) . '</p>'],
            array_fill(0, 249, '<p>x</p>')
        );
        $maximumDocument = BlogDocument::fromArray($this->document([
            $this->advancedParagraph(3, implode('', $maximumLines), ''),
        ]));
        $maximumFallback = $projector->project($maximumDocument);
        self::assertCount(
            BlogDocumentValidator::MAX_INLINE_NODES,
            $maximumFallback->blocks()[1]['content']
        );
        self::assertSame(
            $textProjector->project($maximumDocument),
            $textProjector->project($maximumFallback)
        );

        $overflowDocument = BlogDocument::fromArray($this->document([
            $this->advancedParagraph(
                3,
                implode('', array_fill(0, 251, '<p>x</p>')),
                ''
            ),
        ]));
        try {
            $projector->project($overflowDocument);
            self::fail('Expected the V1 inline-node limit to fail closed.');
        } catch (BlogDocumentException $exception) {
            self::assertSame(
                BlogDocumentException::PROJECTION_TOO_LARGE,
                $exception->issueCode()
            );
        }
    }

    public function testCanonicalJsonRoundTripPreservesAdvancedSources(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->advancedParagraph(
                3,
                '<div id="intro" class="lead"><p>Stored source</p></div>',
                'color:#272727;.lead #intro{font-weight:700;}'
            ),
        ]));
        $codec = new BlogDocumentCodec();
        $json = $codec->encode($document);
        $restored = $codec->decode($json);

        self::assertSame($document->toArray(), $restored->toArray());
        self::assertSame($json, $codec->encode($restored));
        self::assertStringContainsString('"html":', $json);
        self::assertStringContainsString('"css":', $json);
    }

    public function testDraftMayKeepEmptyAdvancedParagraphButStrictDocumentMayNot(): void
    {
        $raw = $this->document([$this->advancedParagraph(3, '', '')]);
        $draft = BlogDocument::fromArray(
            $raw,
            (new BlogDocumentValidator())->forDrafts()
        );

        self::assertSame('', $draft->blocks()[0]['children'][1]['html']);
        $this->assertInvalidDocument($raw);
    }

    public function testStandardAndAdvancedParagraphSourcesCannotBeMixed(): void
    {
        $advanced = $this->advancedParagraph(3, '<p>Safe</p>', '');
        $advanced['content'] = [$this->text('Ambiguous')];
        $this->assertInvalidDocument($this->document([$advanced]));

        $standard = [
            'id' => $this->id(3),
            'type' => 'paragraph',
            'content' => [$this->text('Standard')],
            'css' => '',
            'presentation' => $this->presentation(),
        ];
        $this->assertInvalidDocument($this->document([$standard]));

        $v1 = [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id(3),
                'type' => 'paragraph',
                'html' => '<p>Not V1</p>',
                'css' => '',
            ]],
        ];
        $this->assertInvalidDocument($v1);
    }

    public function testHtmlPolicyFailsClosedForStoredXssAndDomHooks(): void
    {
        $sanitizer = new BlogCustomTextHtmlSanitizer();
        $cases = [
            '<script>alert(1)</script>',
            '<style>p{color:red}</style><p>x</p>',
            '<iframe src="https://example.test"></iframe>',
            '<form><input name="x"></form>',
            '<svg><a href="javascript:alert(1)">x</a></svg>',
            '<math><mtext>x</mtext></math>',
            '<template><p>x</p></template>',
            '<noscript><p>x</p></noscript>',
            '<p onclick="bad()">x</p>',
            '<p style="color:red">x</p>',
            '<p data-blog-action="save">x</p>',
            '<p data-ls-size="large">x</p>',
            '<p data-webadmin-hook="x">x</p>',
            '<p name="constructor">x</p>',
            '<p contenteditable="true">x</p>',
            '<p tabindex="0">x</p>',
            '<p class="blogDocument__paragraph">x</p>',
            '<p class="webadminSave">x</p>',
            '<a href="java&#x73;cript:alert(1)">x</a>',
            '<a href="//evil.test/x">x</a>',
            '<a href="data:text/html,x">x</a>',
            '<a href="https://user@example.test/x">x</a>',
            '<a href="/%00x">x</a>',
            '<a href="/%0d%0ax">x</a>',
            '<a href="/%252e%252e%252fsecret">x</a>',
            '<div id="same">a</div><p id="same">b</p>',
            '<a href="#missing">x</a>',
            '</div><p>outside</p>',
        ];

        set_error_handler(static function (
            int $severity,
            string $message,
            string $file,
            int $line
        ): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            foreach ($cases as $case) {
                $this->assertHtmlRejected($sanitizer, $case);
            }
        } finally {
            restore_error_handler();
        }
    }

    public function testHtmlPolicyCapsComplexityAndIsIdempotent(): void
    {
        $sanitizer = new BlogCustomTextHtmlSanitizer();
        $canonical = $sanitizer->sanitize(
            '<div class="z a" data-content-state="ready">'
                . '<p title="Safe">Text <mark>marked</mark></p></div>'
        );
        self::assertSame($canonical, $sanitizer->sanitize($canonical));

        $this->assertHtmlRejected(
            $sanitizer,
            str_repeat('<br>', BlogCustomTextHtmlSanitizer::MAX_NODES + 1)
                . '<p>x</p>'
        );
        $deep = str_repeat('<span>', BlogCustomTextHtmlSanitizer::MAX_DEPTH + 2)
            . 'x'
            . str_repeat('</span>', BlogCustomTextHtmlSanitizer::MAX_DEPTH + 2);
        $this->assertHtmlRejected($sanitizer, $deep);
    }

    public function testAdvancedTextAcceptsOnlyCanonicalProviderIframes(): void
    {
        $sanitizer = new BlogCustomTextHtmlSanitizer();
        $source = '<iframe class="frame" src="https://player.vimeo.com/video/123"'
            . ' title="Matrix" width="560" height="315"'
            . ' sandbox="allow-top-navigation"></iframe>';
        $canonical = $sanitizer->sanitize($source);

        self::assertStringContainsString('<iframe ', $canonical);
        self::assertStringContainsString(
            'sandbox="allow-scripts allow-same-origin allow-presentation"',
            $canonical
        );
        self::assertSame($canonical, $sanitizer->sanitize($canonical));
        self::assertSame('', $sanitizer->plainText($canonical));

        foreach ([
            'http://player.vimeo.com/video/123',
            'https://user@player.vimeo.com/video/123',
            'https://player.vimeo.com:443/video/123',
            'https://player.vimeo.com/video/123#fragment',
            'https://evil.player.vimeo.com/video/123',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/embed/',
            'https://www.youtube.com/embed/../watch',
            'https://www.youtube.com/embed/%2e%2e/watch',
            'https://www.youtube.com/embed/%252e%252e%252fwatch',
            'https://www.youtube.com/embed/video%2fwatch',
            'https://www.youtube.com/embed/video%5cwatch',
            'https://www.youtube.com/embed/video\\watch',
            'https://www.youtube.com/embed/video%00watch',
            'https://www.google.com/maps/place/Madrid',
        ] as $url) {
            $this->assertHtmlRejected(
                $sanitizer,
                '<iframe src="' . $url . '"></iframe>'
            );
        }
    }

    public function testAdvancedTextIframeRendersNetworkInertUntilConsent(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->advancedParagraph(
                3,
                '<div class="video"><iframe id="player" class="frame" '
                    . 'src="https://player.vimeo.com/video/123" '
                    . 'title="Matrix" aria-label="Vídeo Matrix">'
                    . '</iframe></div>',
                '.video{max-width:60rem;}iframe{width:100%;}'
            ),
        ]));

        $html = $this->renderer()->render($document);
        self::assertStringContainsString(
            'blogDocument__text blogDocument__text--custom"',
            $html
        );
        self::assertStringContainsString(
            'data-blog-consent-iframe=',
            $html
        );
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString(
            'src="https://player.vimeo.com',
            $html
        );
        self::assertStringContainsString(
            'https://player.vimeo.com/video/123',
            $html
        );
        $prefix = 'lsb-' . str_replace('-', '', $this->id(3)) . '-';
        self::assertStringContainsString(
            'class="blogDocument__consentFrame ' . $prefix . 'frame"',
            $html
        );
        self::assertStringContainsString(
            'id="' . $prefix . 'player"',
            $html
        );
        self::assertStringContainsString('aria-label="Vídeo Matrix"', $html);
    }

    public function testCssAllowsSafeRootDeclarationsNestingAndConditions(): void
    {
        $sanitizer = new BlogCustomTextCssSanitizer();
        $source = 'color: #272727; background-color:#11223344; padding: 1rem; .card {'
            . 'display: grid; & > p { text-align: justify; }'
            . '@media (min-width: 40rem) { & #hola { font-weight: 700; } }'
            . '} @supports (display: grid) { .card { gap: 1rem; } }';
        $canonical = $sanitizer->sanitize($source);

        self::assertSame($canonical, $sanitizer->sanitize($canonical));
        $scoped = $sanitizer->renderScoped($canonical, $this->id(3));
        self::assertStringContainsString(
            'color:#272727;background-color:#11223344;padding:1rem;',
            $scoped
        );
        self::assertStringContainsString('@media (min-width: 40rem)', $scoped);
        self::assertStringContainsString('@supports (display: grid)', $scoped);
        self::assertStringContainsString(
            '.lsb-' . str_replace('-', '', $this->id(3)) . '-card',
            $scoped
        );
        self::assertSame(
            '& .x{height:100000px;}',
            $sanitizer->sanitize('.x{height:100000px;}')
        );
        self::assertSame(
            '& .prueba{color:red;}',
            $sanitizer->sanitize(".prueba{\n    color: red;\n}")
        );
    }

    public function testCssTreatsEmptyAndWhitespaceOnlyInputAsNoCustomStyles(): void
    {
        $sanitizer = new BlogCustomTextCssSanitizer();

        self::assertSame('', $sanitizer->sanitize(''));
        self::assertSame('', $sanitizer->sanitize(" \t\r\n  "));
        self::assertSame('color:#272727;', $sanitizer->sanitize(' color: #272727; '));
        self::assertSame('', $sanitizer->renderScoped('', $this->id(3)));

        $document = BlogDocument::fromArray($this->document([
            $this->advancedParagraph(3, '<div>Safe content</div>', " \n\t "),
        ]));
        self::assertSame('', $document->blocks()[0]['children'][1]['css']);
        self::assertStringContainsString(
            '"css":""',
            (new BlogDocumentCodec())->encode($document)
        );

        $this->assertCssRejected(
            $sanitizer,
            'background:url(https://example.test/unsafe)'
        );
    }

    public function testCssPolicyRejectsEscapesGlobalSelectorsAndBreakoutValues(): void
    {
        $sanitizer = new BlogCustomTextCssSanitizer();
        $cases = [
            'a{background:u\\72l(https://evil.test/x)}',
            '@\\69mport "https://evil.test/x";',
            '.x{background:image-set("https://evil.test/x" 1x)}',
            '.x{background:src("https://evil.test/x")}',
            '.x{--u:url(https://evil.test/x);background:var(--u)}',
            '.x{p\\6fsition:f\\69xed}',
            '.x{position:fixed}',
            '.x{position:sticky}',
            '.x{z-index:calc(999999)}',
            '.x{all:revert!important}',
            '.x{display:contents}',
            ':r\\6fot{color:red}',
            ':root{color:red}',
            ':h\\61s(*){color:red}',
            ':has(*){color:red}',
            '.x{& + *{color:red}}',
            '.x{& ~ *{color:red}}',
            '.x{background:image("https://evil.test/x")}',
            '.x{background:paint(evil)}',
            '.x{background:-moz-element(#outside)}',
            '.x{background:cross-fade("https://evil.test/x",red)}',
            '.x{height:999999999999999999999vh}',
            '.x{grid-template-columns:repeat(999999999,1fr)}',
            '.x{grid-template-columns:repeat(100000,1px)}',
            '.x{padding:calc(100000px + 100000px + 100000px)}',
            'padding:99999e999px;',
            'padding:4097px;',
            '.x{font-size:100001px}',
            '.x{content:"</style><script>alert(1)</script>"}',
            'transform:translateX(-100vw);',
            'margin:-100vh;',
            'position:relative;',
            'width:200vw;',
        ];
        foreach ($cases as $case) {
            $this->assertCssRejected($sanitizer, $case);
        }
    }

    public function testEveryAcceptedCssProjectionFitsItsNamespacedRenderer(): void
    {
        $sanitizer = new BlogCustomTextCssSanitizer();
        $selectors = implode(',', array_fill(0, 16, '.x'));
        $source = implode('', array_fill(0, 128, $selectors . '{color:red;}'));

        $canonical = $sanitizer->sanitize($source);
        $scoped = $sanitizer->renderScoped($canonical, $this->id(3));

        self::assertNotSame('', $scoped);
        self::assertLessThanOrEqual(
            BlogCustomTextCssSanitizer::MAX_RENDERED_CSS_BYTES + 256,
            strlen($scoped)
        );
    }

    public function testTechnicalPolicyIsProjectedFromBackendAuthority(): void
    {
        $limits = (new BlogEditorTechnicalLimitCatalog())->toSafeArray();

        self::assertSame(
            BlogCustomTextCssSanitizer::MAX_CSS_BYTES,
            $limits['block']['css']['bytes']
        );
        self::assertSame(
            'data-content-',
            $limits['custom_text_policy']['html']['data_attribute_prefix']
        );
        self::assertContains(
            'webadmin',
            $limits['custom_text_policy']['html']['reserved_class_prefixes']
        );
        self::assertContains(
            'color',
            $limits['custom_text_policy']['css']['root_properties']
        );
        self::assertContains(
            'data-content-*',
            $limits['custom_text_policy']['css']['attribute_selectors']
        );
        self::assertContains(
            'iframe',
            $limits['custom_text_policy']['html']['tags']
        );
        self::assertSame(
            BlogSafeIframePolicy::sources(),
            $limits['custom_text_policy']['html']['iframe_sources']
        );
        self::assertContains(
            'figure',
            $limits['embed_policy']['html']['tags']
        );
        self::assertContains(
            'id',
            $limits['embed_policy']['html']['global_attributes']
        );
        self::assertSame(
            BlogSafeIframePolicy::attributes(),
            $limits['embed_policy']['html']['special_attributes']['iframe']
        );
        foreach (['iframe', 'img', 'figure', 'figcaption'] as $tag) {
            self::assertContains($tag, $limits['embed_policy']['css']['tags']);
        }
    }

    public function testPrivatePreviewCoordinatesScopedStyleWithItsCspNonce(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->advancedParagraph(
                3,
                '<p class="lead">Private preview</p>',
                'color:#123;.lead{font-weight:700;}'
            ),
        ]));
        $variant = new BlogPostVariant(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'es',
            new BlogDraft('Matrix', ''),
            BlogPostVariant::DRAFT,
            null,
            1,
            '33333333-3333-4333-8333-333333333333',
            '33333333-3333-4333-8333-333333333333',
            new DateTimeImmutable('2030-01-01 10:00:00 UTC'),
            new DateTimeImmutable('2030-01-01 10:00:00 UTC')
        );
        $assets = new BlogPreviewAssetSet(
            new BlogPreviewAssetContext(dirname(__DIR__, 3), true),
            stylesheets: [
                'http://localhost:5173/src/scss/blogArticle.scss?direct',
            ],
            moduleScripts: [
                'http://localhost:5173/@vite/client',
                'http://localhost:5173/src/js/blogArticlePreview.js',
            ],
            connectSources: [
                'http://localhost:5173',
                'ws://localhost:5173',
            ]
        );
        $nonce = 'abcdefghijklmnopQRSTUVWX';
        $html = (new BlogStructuredPrivateHtmlRenderer($this->renderer()))
            ->preview('/admin/blog', $variant, $document, null, $assets, $nonce);
        $csp = $assets->contentSecurityPolicy($nonce);

        self::assertStringContainsString('<style nonce="' . $nonce . '">', $html);
        self::assertStringContainsString(
            '<meta property="csp-nonce" nonce="' . $nonce . '">',
            $html
        );
        self::assertStringContainsString(
            '[data-ls-blog-custom="' . $this->id(3) . '"]',
            $html
        );
        self::assertStringContainsString("'nonce-" . $nonce . "'", $csp);
        self::assertStringContainsString("style-src-attr 'none'", $csp);
        self::assertStringNotContainsString("'unsafe-inline'", $csp);
        $stylesheetPosition = strpos(
            $html,
            '<link rel="stylesheet" href="http://localhost:5173/'
                . 'src/scss/blogArticle.scss?direct">'
        );
        $nonceMetaPosition = strpos(
            $html,
            '<meta property="csp-nonce" nonce="' . $nonce . '">'
        );
        $viteClientPosition = strpos(
            $html,
            '<script type="module" src="http://localhost:5173/'
                . '@vite/client"></script>'
        );
        $customStylePosition = strpos(
            $html,
            '<style nonce="' . $nonce . '">'
        );
        self::assertIsInt($nonceMetaPosition);
        self::assertIsInt($stylesheetPosition);
        self::assertIsInt($viteClientPosition);
        self::assertIsInt($customStylePosition);
        self::assertTrue(
            $nonceMetaPosition < $stylesheetPosition
                && $stylesheetPosition < $viteClientPosition
                && $viteClientPosition < $customStylePosition,
            'The nonce meta and blocking public stylesheet must precede '
                . 'custom module CSS.'
        );
    }

    public function testPublicStandaloneAndProjectViewReceiveOnlyScopedCss(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->advancedParagraph(
                3,
                '<p id="intro" class="lead">Public article</p>',
                'color:#123;.lead #intro{font-weight:700;}'
            ),
        ]));
        $nonce = 'abcdefghijklmnopQRSTUVWX';
        $standalone = (new BlogPublicHtmlRenderer())->renderStructured(
            $this->publishedVariant(),
            'https://example.test/news/matrix',
            $document,
            $this->resolver(),
            styleNonce: $nonce
        );
        self::assertStringContainsString('<style nonce="' . $nonce . '">', $standalone);
        self::assertStringContainsString(
            '[data-ls-blog-custom="' . $this->id(3) . '"]',
            $standalone
        );
        self::assertStringNotContainsString('<style>', $standalone);

        $view = tempnam(sys_get_temp_dir(), 'liquidstack-custom-css-');
        self::assertIsString($view);
        file_put_contents($view, '<?php echo $blogArticle->customCss();');
        try {
            $projected = (new BlogPublicHtmlRenderer($view))->renderStructured(
                $this->publishedVariant(),
                'https://example.test/news/matrix',
                $document,
                $this->resolver()
            );
        } finally {
            @unlink($view);
        }
        self::assertStringStartsWith(
            '[data-ls-blog-custom="' . $this->id(3) . '"]',
            $projected
        );
        self::assertStringNotContainsString('</style>', $projected);
        self::assertStringNotContainsString('<script', $projected);
    }

    /** @param list<array<string, mixed>> $modules @return array<string, mixed> */
    private function document(array $modules): array
    {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'section',
                'children' => array_merge([[
                    'id' => $this->id(2),
                    'type' => 'heading',
                    'level' => 2,
                    'content' => [$this->text('Matrix')],
                    'presentation' => $this->presentation(),
                ]], $modules),
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function advancedParagraph(
        int $number,
        string $html,
        string $css
    ): array {
        return [
            'id' => $this->id($number),
            'type' => 'paragraph',
            'html' => $html,
            'css' => $css,
            'presentation' => $this->presentation(),
        ];
    }

    /** @return array{type:string,text:string,marks:list<string>} */
    private function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text, 'marks' => []];
    }

    /** @return array<string, string> */
    private function presentation(): array
    {
        return ['width' => 'full', 'align' => 'start', 'text_align' => 'start'];
    }

    private function renderer(): BlogDocumentHtmlRenderer
    {
        return new BlogDocumentHtmlRenderer($this->resolver());
    }

    private function resolver(): BlogImageResolverInterface
    {
        return new class implements BlogImageResolverInterface {
            public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
            {
                return null;
            }
        };
    }

    private function publishedVariant(): BlogPostVariant
    {
        $now = new DateTimeImmutable('2030-01-01 10:00:00 UTC');

        return new BlogPostVariant(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'es',
            new BlogDraft(
                'Matrix',
                'Public article',
                'matrix',
                'Matrix title',
                'Matrix description',
                'Matrix excerpt'
            ),
            BlogPostVariant::PUBLISHED,
            $now,
            1,
            '33333333-3333-4333-8333-333333333333',
            '33333333-3333-4333-8333-333333333333',
            $now,
            $now
        );
    }

    private function assertInvalidDocument(array $raw): void
    {
        try {
            BlogDocument::fromArray($raw);
            self::fail('An invalid custom Text document was accepted.');
        } catch (BlogDocumentException $exception) {
            self::assertSame(BlogDocumentException::INVALID_BLOCK, $exception->issueCode());
        }
    }

    private function assertHtmlRejected(
        BlogCustomTextHtmlSanitizer $sanitizer,
        string $html
    ): void {
        try {
            $sanitizer->sanitize($html);
            self::fail('Unsafe custom HTML was accepted: ' . $html);
        } catch (BlogDocumentException $exception) {
            self::assertSame(BlogDocumentException::INVALID_BLOCK, $exception->issueCode());
        }
    }

    private function assertCssRejected(
        BlogCustomTextCssSanitizer $sanitizer,
        string $css
    ): void {
        try {
            $sanitizer->sanitize($css);
            self::fail('Unsafe custom CSS was accepted: ' . $css);
        } catch (BlogDocumentException $exception) {
            self::assertSame(BlogDocumentException::INVALID_BLOCK, $exception->issueCode());
        }
    }

    private function id(int $number): string
    {
        return sprintf('40000000-0000-4000-8000-%012d', $number);
    }
}
