<?php

declare(strict_types=1);

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCanonicalizer;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Document\BlogDocumentValidator;
use PHPUnit\Framework\TestCase;

final class BlogDocumentContractTest extends TestCase
{
    public function testCodecNormalizesExactKeyAndMarkOrder(): void
    {
        $input = [
            'blocks' => [[
                'content' => [[
                    'marks' => ['em', 'strong'],
                    'text' => 'Matríz / Neo',
                    'type' => 'text',
                ]],
                'type' => 'paragraph',
                'id' => $this->id(1),
            ]],
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'version' => 1,
            'schema' => BlogDocument::SCHEMA,
        ];
        $raw = json_encode(
            $input,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $codec = new BlogDocumentCodec();

        $document = $codec->decode($raw);
        $canonical = $codec->encode($document);

        self::assertSame(
            ['schema', 'version', 'template', 'blocks'],
            array_keys($document->toArray())
        );
        self::assertSame(
            ['id', 'type', 'content'],
            array_keys($document->blocks()[0])
        );
        self::assertSame(
            ['strong', 'em'],
            $document->blocks()[0]['content'][0]['marks']
        );
        self::assertStringContainsString('Matríz / Neo', $canonical);
        self::assertStringNotContainsString('\\/', $canonical);
        self::assertFalse($codec->isCanonical($raw));
        self::assertTrue($codec->isCanonical($canonical));
        self::assertSame($canonical, $codec->canonicalize($canonical));
        self::assertSame(
            hash('sha256', $canonical),
            (new BlogDocumentCanonicalizer())->sha256($document)
        );
    }

    public function testAllSchemaV1BlockAndInlineTypesAreAccepted(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->paragraph(1, [
                $this->text('Wake up, ', ['strong']),
                $this->inlineLink(
                    'Neo',
                    'https://example.com/neo',
                    ['em'],
                    'new'
                ),
                ['type' => 'break'],
                $this->text('The Matrix has you.'),
            ]),
            $this->heading(2, 2, 'Reality'),
            $this->heading(3, 3, 'The red pill'),
            $this->listBlock(4, false, [
                ['id' => $this->id(40), 'content' => [$this->text('One')]],
                ['id' => $this->id(41), 'content' => [$this->text('Two')]],
            ]),
            $this->callout(5, 'info', 'Follow the white rabbit.'),
            $this->linkBlock(6, '/contacto?from=blog#form'),
            $this->image(7, 'wide'),
            $this->video(8),
            $this->cta(9, 'mailto:hello@example.com'),
        ]));

        self::assertSame(BlogDocument::SCHEMA, $document->schema());
        self::assertSame(1, $document->version());
        self::assertSame(
            BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            $document->template()
        );
        self::assertSame(9, $document->blockCount());
        self::assertSame(
            [
                'paragraph', 'heading', 'heading', 'list', 'callout',
                'link', 'image', 'video', 'cta',
            ],
            array_column($document->blocks(), 'type')
        );

        $copy = $document->toArray();
        $copy['template'] = 'changed';
        self::assertSame(
            BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            $document->template()
        );
    }

    public function testBasicTemplateAllowsEmptyDraftAndForbidsCoverDisplay(): void
    {
        $empty = BlogDocument::fromArray($this->document([]));
        self::assertSame(0, $empty->blockCount());

        $invalid = $this->document([$this->image(1, 'cover')]);
        $this->assertIssue(
            BlogDocumentException::INVALID_TEMPLATE_CONTRACT,
            static fn (): BlogDocument => BlogDocument::fromArray($invalid)
        );
    }

    public function testCoverTemplateRequiresExactlyFirstCoverImage(): void
    {
        $valid = BlogDocument::fromArray($this->document(
            [
                $this->image(1, 'cover'),
                $this->paragraph(2, [$this->text('Introduction')]),
                $this->image(3, 'wide'),
            ],
            BlogDocumentTemplateRegistry::ARTICLE_COVER
        ));
        self::assertSame('cover', $valid->blocks()[0]['display']);

        foreach ([
            'empty' => [],
            'paragraph first' => [
                $this->paragraph(1, [$this->text('First')]),
                $this->image(2, 'cover'),
            ],
            'wide first' => [$this->image(1, 'wide')],
            'second cover' => [
                $this->image(1, 'cover'),
                $this->image(2, 'cover'),
            ],
        ] as $blocks) {
            $invalid = $this->document(
                $blocks,
                BlogDocumentTemplateRegistry::ARTICLE_COVER
            );
            $this->assertIssue(
                BlogDocumentException::INVALID_TEMPLATE_CONTRACT,
                static fn (): BlogDocument => BlogDocument::fromArray($invalid)
            );
        }
    }

    public function testTemplateRegistryIsClosedAndOrdered(): void
    {
        $registry = new BlogDocumentTemplateRegistry();

        self::assertSame(
            ['article-basic-01', 'article-cover-01'],
            $registry->keys()
        );
        self::assertTrue($registry->supports('article-basic-01'));
        self::assertFalse($registry->supports('article-free-html'));
        $this->assertIssue(
            BlogDocumentException::UNSUPPORTED_TEMPLATE,
            static fn () => $registry->assertSupported('article-free-html')
        );
    }

    public function testProjectorDerivesStablePlainBodyForEveryBlock(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->paragraph(1, [
                $this->text('Wake up, ', ['strong']),
                $this->inlineLink('Neo', '/neo'),
                ['type' => 'break'],
                $this->text('Now.'),
            ]),
            $this->heading(2, 2, 'Reality'),
            $this->listBlock(3, true, [
                ['id' => $this->id(30), 'content' => [$this->text('Red')]],
                ['id' => $this->id(31), 'content' => [$this->text('Blue')]],
            ]),
            $this->callout(4, 'warning', 'Choose wisely.'),
            $this->linkBlock(5, '/oracle', 'Visit the Oracle'),
            $this->image(6, 'content', false, 'Matrix code', 'A caption'),
            $this->video(7, 'What is the Matrix?'),
            $this->cta(8, 'tel:+34900111222', 'Call Zion'),
        ]));

        self::assertSame(
            "Wake up, Neo\nNow.\n\nReality\n\n1. Red\n2. Blue"
                . "\n\nChoose wisely.\n\nVisit the Oracle"
                . "\n\nA caption\n\nWhat is the Matrix?\n\nCall Zion",
            (new BlogDocumentTextProjector())->project($document)
        );
    }

    public function testProjectorUsesAltAndOmitsPurelyDecorativeImages(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->image(1, 'content', false, 'Informative alt', null),
            $this->image(2, 'wide', true, '', null),
            $this->image(3, 'wide', true, '', 'Visible caption'),
        ]));

        self::assertSame(
            "Informative alt\n\nVisible caption",
            (new BlogDocumentTextProjector())->project($document)
        );
        self::assertSame(
            '',
            (new BlogDocumentTextProjector())->project(
                BlogDocument::fromArray($this->document([]))
            )
        );
    }

    /** @dataProvider safeUrlProvider */
    public function testOnlyExplicitSafeUrlFamiliesAreAccepted(string $url): void
    {
        $document = BlogDocument::fromArray(
            $this->document([$this->linkBlock(1, $url)])
        );

        self::assertSame($url, $document->blocks()[0]['href']);
    }

    public static function safeUrlProvider(): iterable
    {
        yield 'root relative' => ['/es/noticias/matrix?x=1#neo'];
        yield 'query and fragment do not affect path segments' => [
            '/?next=%2e%2e%2Fadmin#section',
        ];
        yield 'https' => ['https://example.com/path?q=matrix#neo'];
        yield 'mailto' => ['mailto:neo@example.com'];
        yield 'telephone' => ['tel:+34 (900) 111-222'];
    }

    /** @dataProvider unsafeUrlProvider */
    public function testUnsafeUrlsAreRejected(string $url): void
    {
        $invalid = $this->document([$this->linkBlock(1, $url)]);
        $this->assertIssue(
            BlogDocumentException::INVALID_URL,
            static fn (): BlogDocument => BlogDocument::fromArray($invalid)
        );
    }

    public static function unsafeUrlProvider(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html,boom'];
        yield 'http' => ['http://example.com'];
        yield 'protocol relative' => ['//example.com/path'];
        yield 'credentials' => ['https://user:pass@example.com/path'];
        yield 'space' => ['https://example.com/a path'];
        yield 'backslash' => ['/safe\\unsafe'];
        yield 'invalid percent' => ['/safe/%xy'];
        yield 'raw current segment' => ['/safe/./admin'];
        yield 'raw parent segment' => ['/safe/../admin'];
        yield 'encoded current segment' => ['/%2e/admin'];
        yield 'encoded parent segment' => ['/%2e%2e/admin'];
        yield 'mixed encoded parent segment' => ['/%2E./admin'];
        yield 'double encoded parent segment' => ['/%252e%252e/admin'];
        yield 'raw duplicate slash' => ['/safe//admin'];
        yield 'encoded forward slash' => ['/safe%2F%2Fadmin'];
        yield 'double encoded forward slash' => ['/safe%252fadmin'];
        yield 'quadruple encoded forward slash' => ['/safe%2525252fadmin'];
        yield 'encoded backslash' => ['/safe%5cadmin'];
        yield 'double encoded backslash' => ['/safe%255cadmin'];
        yield 'encoded control' => ['/safe/%00admin'];
        yield 'encoded invalid utf8' => ['/safe/%C3%28'];
        yield 'mailto header injection' => ['mailto:a@example.com?bcc=b@example.com'];
        yield 'relative without slash' => ['contacto'];
        yield 'fragment only' => ['#contacto'];
    }

    public function testMalformedScalarAndOversizedJsonAreRejected(): void
    {
        $codec = new BlogDocumentCodec();
        foreach (['', '{', 'null', 'true', '"text"', '[]'] as $json) {
            $expected = in_array($json, ['', '{'], true)
                ? BlogDocumentException::INVALID_JSON
                : BlogDocumentException::INVALID_DOCUMENT;
            $this->assertIssue(
                $expected,
                static fn (): BlogDocument => $codec->decode($json)
            );
        }
        $this->assertIssue(
            BlogDocumentException::DOCUMENT_TOO_LARGE,
            static fn (): BlogDocument => $codec->decode(
                str_repeat(' ', BlogDocument::MAX_JSON_BYTES + 1)
            )
        );
    }

    public function testEnvelopeRequiresExactSchemaVersionKeysAndTypes(): void
    {
        $cases = [];
        $extra = $this->document([]);
        $extra['html'] = '<p>free</p>';
        $cases[BlogDocumentException::INVALID_DOCUMENT][] = $extra;
        $missing = $this->document([]);
        unset($missing['blocks']);
        $cases[BlogDocumentException::INVALID_DOCUMENT][] = $missing;
        $wrongSchema = $this->document([]);
        $wrongSchema['schema'] = 'liquidstack.blog.document.v2';
        $cases[BlogDocumentException::UNSUPPORTED_SCHEMA][] = $wrongSchema;
        $wrongVersion = $this->document([]);
        $wrongVersion['version'] = '1';
        $cases[BlogDocumentException::UNSUPPORTED_SCHEMA][] = $wrongVersion;
        $unknownTemplate = $this->document([]);
        $unknownTemplate['template'] = 'article-html-free-01';
        $cases[BlogDocumentException::UNSUPPORTED_TEMPLATE][] = $unknownTemplate;
        $nonListBlocks = $this->document([]);
        $nonListBlocks['blocks'] = ['first' => $this->paragraph(1)];
        $cases[BlogDocumentException::INVALID_DOCUMENT][] = $nonListBlocks;

        foreach ($cases as $issue => $documents) {
            foreach ($documents as $document) {
                $this->assertIssue(
                    $issue,
                    static fn (): BlogDocument => BlogDocument::fromArray(
                        $document
                    )
                );
            }
        }
    }

    public function testBlockAndListBoundsAreExact(): void
    {
        $blocks = [];
        for ($index = 1; $index <= BlogDocument::MAX_BLOCKS; ++$index) {
            $blocks[] = $this->paragraph($index);
        }
        self::assertSame(
            BlogDocument::MAX_BLOCKS,
            BlogDocument::fromArray($this->document($blocks))->blockCount()
        );
        $blocks[] = $this->paragraph(BlogDocument::MAX_BLOCKS + 1);
        $tooManyBlocks = $this->document($blocks);
        $this->assertIssue(
            BlogDocumentException::INVALID_DOCUMENT,
            static fn (): BlogDocument => BlogDocument::fromArray(
                $tooManyBlocks
            )
        );

        $items = [];
        for ($index = 1; $index <= BlogDocument::MAX_LIST_ITEMS; ++$index) {
            $items[] = [
                'id' => $this->id(1_000 + $index),
                'content' => [$this->text('Item')],
            ];
        }
        self::assertCount(
            BlogDocument::MAX_LIST_ITEMS,
            BlogDocument::fromArray(
                $this->document([$this->listBlock(1, false, $items)])
            )->blocks()[0]['items']
        );
        $items[] = [
            'id' => $this->id(2_000),
            'content' => [$this->text('Overflow')],
        ];
        $tooManyItems = $this->document([
            $this->listBlock(1, false, $items),
        ]);
        $this->assertIssue(
            BlogDocumentException::INVALID_BLOCK,
            static fn (): BlogDocument => BlogDocument::fromArray(
                $tooManyItems
            )
        );
    }

    public function testCanonicalDocumentByteLimitAlsoAppliesToArrayInput(): void
    {
        $blocks = [];
        for ($index = 1; $index <= 16; ++$index) {
            $blocks[] = $this->paragraph($index, [
                $this->text(str_repeat('a', BlogDocumentValidator::MAX_INLINE_TEXT_BYTES)),
            ]);
        }
        $invalid = $this->document($blocks);

        $this->assertIssue(
            BlogDocumentException::DOCUMENT_TOO_LARGE,
            static fn (): BlogDocument => BlogDocument::fromArray($invalid)
        );
    }

    public function testEveryStructuralUuidMustBeCanonicalV4AndUnique(): void
    {
        $invalidId = $this->document([$this->paragraph(1)]);
        $invalidId['blocks'][0]['id'] =
            '00000000-0000-1000-8000-000000000001';
        $this->assertIssue(
            BlogDocumentException::INVALID_BLOCK,
            static fn (): BlogDocument => BlogDocument::fromArray($invalidId)
        );

        $duplicate = $this->document([
            $this->paragraph(1),
            $this->listBlock(2, false, [[
                'id' => $this->id(1),
                'content' => [$this->text('Duplicate')],
            ]]),
        ]);
        $this->assertIssue(
            BlogDocumentException::DUPLICATE_ID,
            static fn (): BlogDocument => BlogDocument::fromArray($duplicate)
        );
    }

    public function testHeadingHierarchyAllowsH2ThenH3AndRejectsOrphanH3(): void
    {
        BlogDocument::fromArray($this->document([
            $this->paragraph(1),
            $this->heading(2, 2, 'Section'),
            $this->paragraph(3),
            $this->heading(4, 3, 'Subsection'),
        ]));

        $orphan = $this->document([
            $this->paragraph(1),
            $this->heading(2, 3, 'Orphan'),
        ]);
        $this->assertIssue(
            BlogDocumentException::INVALID_HEADING_HIERARCHY,
            static fn (): BlogDocument => BlogDocument::fromArray($orphan)
        );

        foreach ([1, 4, '2'] as $level) {
            $invalid = $this->document([$this->heading(1, 2, 'Heading')]);
            $invalid['blocks'][0]['level'] = $level;
            $this->assertIssue(
                BlogDocumentException::INVALID_BLOCK,
                static fn (): BlogDocument => BlogDocument::fromArray($invalid)
            );
        }
    }

    public function testHeadingRejectsBreakWhileRichBlocksAcceptIt(): void
    {
        $invalid = $this->document([$this->heading(1, 2, 'Heading')]);
        $invalid['blocks'][0]['content'][] = ['type' => 'break'];

        $this->assertIssue(
            BlogDocumentException::INVALID_INLINE,
            static fn (): BlogDocument => BlogDocument::fromArray($invalid)
        );
        self::assertSame(
            "Line one\nLine two",
            (new BlogDocumentTextProjector())->project(
                BlogDocument::fromArray($this->document([
                    $this->paragraph(1, [
                        $this->text('Line one'),
                        ['type' => 'break'],
                        $this->text('Line two'),
                    ]),
                ]))
            )
        );
    }

    public function testInlineContractRejectsFreeHtmlControlsAndInvalidMarks(): void
    {
        $invalidNodes = [
            ['type' => 'text', 'text' => '<strong>HTML</strong>', 'marks' => []],
            ['type' => 'text', 'text' => "Control\x07", 'marks' => []],
            ['type' => 'text', 'text' => "Bad \xC3\x28", 'marks' => []],
            ['type' => 'text', 'text' => 'Text', 'marks' => ['underline']],
            ['type' => 'text', 'text' => 'Text', 'marks' => ['strong', 'strong']],
            ['type' => 'text', 'text' => '', 'marks' => []],
            ['type' => 'html', 'html' => '<b>free</b>'],
            ['type' => 'break', 'extra' => true],
        ];

        foreach ($invalidNodes as $node) {
            $invalid = $this->document([$this->paragraph(1, [$node])]);
            $this->assertIssue(
                BlogDocumentException::INVALID_INLINE,
                static fn (): BlogDocument => BlogDocument::fromArray($invalid)
            );
        }

        $blank = $this->document([
            $this->paragraph(1, [$this->text('   '), ['type' => 'break']]),
        ]);
        $this->assertIssue(
            BlogDocumentException::INVALID_INLINE,
            static fn (): BlogDocument => BlogDocument::fromArray($blank)
        );
    }

    public function testInlineNodeCountAndTextBytesAreBounded(): void
    {
        $nodes = array_fill(
            0,
            BlogDocumentValidator::MAX_INLINE_NODES,
            $this->text('x')
        );
        self::assertCount(
            BlogDocumentValidator::MAX_INLINE_NODES,
            BlogDocument::fromArray(
                $this->document([$this->paragraph(1, $nodes)])
            )->blocks()[0]['content']
        );
        $nodes[] = $this->text('overflow');
        $tooMany = $this->document([$this->paragraph(1, $nodes)]);
        $this->assertIssue(
            BlogDocumentException::INVALID_INLINE,
            static fn (): BlogDocument => BlogDocument::fromArray($tooMany)
        );

        $tooLong = $this->document([$this->paragraph(1, [
            $this->text(str_repeat(
                'a',
                BlogDocumentValidator::MAX_INLINE_TEXT_BYTES + 1
            )),
        ])]);
        $this->assertIssue(
            BlogDocumentException::INVALID_INLINE,
            static fn (): BlogDocument => BlogDocument::fromArray($tooLong)
        );
    }

    public function testBlockObjectsRejectMissingAndUnknownFields(): void
    {
        $extra = $this->document([$this->paragraph(1)]);
        $extra['blocks'][0]['class'] = 'user-css';
        $this->assertIssue(
            BlogDocumentException::INVALID_BLOCK,
            static fn (): BlogDocument => BlogDocument::fromArray($extra)
        );

        $missing = $this->document([$this->paragraph(1)]);
        unset($missing['blocks'][0]['content']);
        $this->assertIssue(
            BlogDocumentException::INVALID_BLOCK,
            static fn (): BlogDocument => BlogDocument::fromArray($missing)
        );

        $unknown = $this->document([[
            'id' => $this->id(1),
            'type' => 'raw-html',
            'html' => '<p>No</p>',
        ]]);
        $this->assertIssue(
            BlogDocumentException::INVALID_BLOCK,
            static fn (): BlogDocument => BlogDocument::fromArray($unknown)
        );
    }

    public function testListRequiresBooleanOrderAndOneToOneHundredItems(): void
    {
        foreach ([[], 'not-a-list'] as $items) {
            $invalid = $this->document([$this->listBlock(1)]);
            $invalid['blocks'][0]['items'] = $items;
            $this->assertIssue(
                BlogDocumentException::INVALID_BLOCK,
                static fn (): BlogDocument => BlogDocument::fromArray($invalid)
            );
        }
        $invalidOrder = $this->document([$this->listBlock(1)]);
        $invalidOrder['blocks'][0]['ordered'] = 1;
        $this->assertIssue(
            BlogDocumentException::INVALID_BLOCK,
            static fn (): BlogDocument => BlogDocument::fromArray($invalidOrder)
        );
    }

    public function testCalloutLinkAndCtaEnumsAreClosed(): void
    {
        $callout = $this->document([$this->callout(1, 'danger', 'No')]);
        $this->assertIssue(
            BlogDocumentException::INVALID_BLOCK,
            static fn (): BlogDocument => BlogDocument::fromArray($callout)
        );

        $link = $this->document([$this->linkBlock(1, '/safe')]);
        $link['blocks'][0]['target'] = '_blank';
        $this->assertIssue(
            BlogDocumentException::INVALID_BLOCK,
            static fn (): BlogDocument => BlogDocument::fromArray($link)
        );

        $cta = $this->document([$this->cta(1, '/safe')]);
        $cta['blocks'][0]['variant'] = 'custom-css';
        $this->assertIssue(
            BlogDocumentException::INVALID_BLOCK,
            static fn (): BlogDocument => BlogDocument::fromArray($cta)
        );
    }

    public function testImageAccessibilityAndDisplayContractsAreStrict(): void
    {
        $decorative = BlogDocument::fromArray($this->document([
            $this->image(1, 'content', true, ''),
        ]));
        self::assertTrue($decorative->blocks()[0]['decorative']);

        $cases = [];
        $missingAlt = $this->image(1, 'content', false, '');
        $cases[] = $missingAlt;
        $decorativeAlt = $this->image(1, 'content', true, 'Not empty');
        $cases[] = $decorativeAlt;
        $display = $this->image(1);
        $display['display'] = 'full-css';
        $cases[] = $display;
        $media = $this->image(1);
        $media['media_asset_public_id'] = 'not-a-uuid';
        $cases[] = $media;
        $html = $this->image(1);
        $html['caption'] = '<em>Caption</em>';
        $cases[] = $html;

        foreach ($cases as $block) {
            $invalid = $this->document([$block]);
            $this->assertIssue(
                BlogDocumentException::INVALID_BLOCK,
                static fn (): BlogDocument => BlogDocument::fromArray($invalid)
            );
        }
    }

    public function testVideoIsOnlyBoundedYoutubeInSchemaV1(): void
    {
        self::assertSame(
            86_400,
            BlogDocument::fromArray($this->document([
                $this->video(1, 'Video', 86_400),
            ]))->blocks()[0]['start_seconds']
        );

        $mutations = [
            ['provider', 'vimeo'],
            ['video_id', 'short'],
            ['video_id', 'bad/videoid'],
            ['title', ''],
            ['title', '<b>Video</b>'],
            ['start_seconds', -1],
            ['start_seconds', 86_401],
            ['start_seconds', '0'],
        ];
        foreach ($mutations as [$field, $value]) {
            $invalid = $this->document([$this->video(1)]);
            $invalid['blocks'][0][$field] = $value;
            $this->assertIssue(
                BlogDocumentException::INVALID_BLOCK,
                static fn (): BlogDocument => BlogDocument::fromArray($invalid)
            );
        }
    }

    public function testExceptionsNeverExposeRejectedPayload(): void
    {
        $secret = '<script>secret-payload</script>';
        $invalid = $this->document([
            $this->paragraph(1, [$this->text($secret)]),
        ]);

        try {
            BlogDocument::fromArray($invalid);
            self::fail('Rejected content must throw.');
        } catch (BlogDocumentException $exception) {
            self::assertSame(
                BlogDocumentException::INVALID_INLINE,
                $exception->issueCode()
            );
            self::assertSame(
                'Invalid structured Blog document.',
                $exception->getMessage()
            );
            self::assertStringNotContainsString(
                'secret-payload',
                $exception->getMessage()
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function document(
        array $blocks,
        string $template = BlogDocumentTemplateRegistry::ARTICLE_BASIC
    ): array {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => $template,
            'blocks' => $blocks,
        ];
    }

    /** @param list<array<string, mixed>>|null $content */
    private function paragraph(int $id, ?array $content = null): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'paragraph',
            'content' => $content ?? [$this->text('Paragraph')],
        ];
    }

    private function heading(int $id, int $level, string $text): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'heading',
            'level' => $level,
            'content' => [$this->text($text)],
        ];
    }

    /** @param list<array<string, mixed>>|null $items */
    private function listBlock(
        int $id,
        bool $ordered = false,
        ?array $items = null
    ): array {
        return [
            'id' => $this->id($id),
            'type' => 'list',
            'ordered' => $ordered,
            'items' => $items ?? [[
                'id' => $this->id($id + 10_000),
                'content' => [$this->text('Item')],
            ]],
        ];
    }

    private function callout(int $id, string $tone, string $text): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'callout',
            'tone' => $tone,
            'content' => [$this->text($text)],
        ];
    }

    private function linkBlock(
        int $id,
        string $href,
        string $label = 'Read more'
    ): array {
        return [
            'id' => $this->id($id),
            'type' => 'link',
            'label' => $label,
            'href' => $href,
            'title' => null,
            'target' => 'same',
        ];
    }

    private function image(
        int $id,
        string $display = 'content',
        bool $decorative = false,
        string $alt = 'Matrix image',
        ?string $caption = null
    ): array {
        return [
            'id' => $this->id($id),
            'type' => 'image',
            'media_asset_public_id' => $this->id(900_000 + $id),
            'alt' => $alt,
            'title' => null,
            'caption' => $caption,
            'decorative' => $decorative,
            'display' => $display,
        ];
    }

    private function video(
        int $id,
        string $title = 'Matrix trailer',
        int $startSeconds = 0
    ): array {
        return [
            'id' => $this->id($id),
            'type' => 'video',
            'provider' => 'youtube',
            'video_id' => 'dQw4w9WgXcQ',
            'title' => $title,
            'start_seconds' => $startSeconds,
        ];
    }

    private function cta(
        int $id,
        string $href,
        string $label = 'Choose the red pill'
    ): array {
        return [
            'id' => $this->id($id),
            'type' => 'cta',
            'label' => $label,
            'href' => $href,
            'title' => null,
            'target' => 'same',
            'variant' => 'primary',
        ];
    }

    /** @param list<string> $marks */
    private function text(string $text, array $marks = []): array
    {
        return ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    /** @param list<string> $marks */
    private function inlineLink(
        string $text,
        string $href,
        array $marks = [],
        string $target = 'same'
    ): array {
        return [
            'type' => 'link',
            'text' => $text,
            'marks' => $marks,
            'href' => $href,
            'title' => null,
            'target' => $target,
        ];
    }

    private function id(int $number): string
    {
        return sprintf(
            '00000000-0000-4000-8000-%012d',
            $number
        );
    }

    /** @param callable(): mixed $operation */
    private function assertIssue(string $issueCode, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected structured document failure.');
        } catch (BlogDocumentException $exception) {
            self::assertSame($issueCode, $exception->issueCode());
        }
    }
}
