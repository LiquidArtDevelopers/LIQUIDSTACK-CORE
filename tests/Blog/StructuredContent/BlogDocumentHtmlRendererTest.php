<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogRenderingException;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImageCandidate;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlogDocumentHtmlRendererTest extends TestCase
{
    public function testItRendersEveryV1BlockAsOneSemanticBodyFragment(): void
    {
        $imageId = $this->id(900_008);
        $decorativeImageId = $this->id(900_009);
        $document = BlogDocument::fromArray($this->document([
            $this->paragraph(1, [
                $this->text('2 < 3 & "quoted" \'single\'', ['strong', 'em']),
                $this->inlineLink(
                    ' and linked',
                    '/news?tag=matrix&mode=deep',
                    ['strong'],
                    'new',
                    'Link "title" & detail'
                ),
                ['type' => 'break'],
                $this->text('Next line'),
            ]),
            $this->heading(2, 2, 'Main body section'),
            $this->heading(3, 3, 'Nested section'),
            $this->listBlock(4, false, [
                $this->listItem(10_004, 'First item'),
                $this->listItem(10_005, 'Second item'),
            ]),
            $this->listBlock(5, true, [
                $this->listItem(10_006, 'Ordered item'),
            ]),
            $this->callout(6, 'warning', 'Choose carefully'),
            $this->linkBlock(
                7,
                'https://example.test/path?a=1&b=2',
                'External link',
                'new',
                'External "title"'
            ),
            $this->image(
                8,
                $imageId,
                'wide',
                false,
                'Neo & "Trinity"',
                'Image "title"',
                'A caption & context'
            ),
            $this->image(9, $decorativeImageId, 'content', true, ''),
            $this->video(10, 'Matrix trailer & analysis', 42),
            $this->cta(
                11,
                '/contact?from=blog&kind=cta',
                'Contact us',
                'new',
                'CTA "title"',
                'secondary'
            ),
        ]));

        $renderer = new BlogDocumentHtmlRenderer($this->resolver([
            $imageId => $this->resolvedImage($imageId, '/media/wide'),
            $decorativeImageId => $this->resolvedImage(
                $decorativeImageId,
                '/media/decorative'
            ),
        ]));
        $html = $renderer->render($document);

        self::assertStringStartsWith(
            '<div class="blogDocument blogDocument--basic">',
            $html
        );
        self::assertStringEndsWith('</div>', $html);
        self::assertStringContainsString(
            '<p id="blog-block-' . $this->id(1)
            . '" class="blogDocument__paragraph">',
            $html
        );
        self::assertStringContainsString(
            '<strong><em>2 &lt; 3 &amp; &quot;quoted&quot; &apos;single&apos;</em></strong>',
            $html
        );
        self::assertStringContainsString(
            '<a class="blogDocument__inlineLink" href="/news?tag=matrix&amp;mode=deep" title="Link &quot;title&quot; &amp; detail" target="_blank" rel="noopener noreferrer"><strong> and linked</strong></a><br>Next line',
            $html
        );
        self::assertStringContainsString(
            '<h2 id="blog-block-' . $this->id(2)
            . '" class="blogDocument__heading">Main body section</h2>',
            $html
        );
        self::assertStringContainsString(
            '<h3 id="blog-block-' . $this->id(3)
            . '" class="blogDocument__heading">Nested section</h3>',
            $html
        );
        self::assertStringContainsString(
            '<ul id="blog-block-' . $this->id(4)
            . '" class="blogDocument__list">',
            $html
        );
        self::assertStringContainsString(
            '<ol id="blog-block-' . $this->id(5)
            . '" class="blogDocument__list">',
            $html
        );
        self::assertStringContainsString(
            '<li id="blog-item-' . $this->id(10_004)
            . '" class="blogDocument__listItem">First item</li>',
            $html
        );
        self::assertStringContainsString(
            '<aside id="blog-block-' . $this->id(6)
            . '" class="blogDocument__callout blogDocument__callout--warning" role="note">',
            $html
        );
        self::assertStringContainsString(
            '<figure id="blog-block-' . $this->id(8)
            . '" class="blogDocument__image blogDocument__image--wide">',
            $html
        );
        self::assertStringContainsString('<picture class="blogDocument__picture">', $html);
        self::assertStringContainsString(
            'srcset="/media/wide/480.avif?token=a&amp;b=1 480w, /media/wide/900.avif?token=a&amp;b=1 900w"',
            $html
        );
        self::assertStringContainsString(
            'sizes="(max-width: 72rem) 100vw, 72rem"',
            $html
        );
        self::assertStringContainsString(
            'src="/media/wide/900.avif?token=a&amp;b=1" width="900" height="600" alt="Neo &amp; &quot;Trinity&quot;" title="Image &quot;title&quot;" loading="lazy" decoding="async">',
            $html
        );
        self::assertStringContainsString(
            '<figcaption class="blogDocument__imageCaption">A caption &amp; context</figcaption>',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<img[^>]+src="\/media\/decorative\/900\.avif[^>]+alt=""[^>]*>/',
            $html
        );
        self::assertStringContainsString(
            '<div class="blogDocument__liteYoutube" data-blog-lite-youtube data-video-id="dQw4w9WgXcQ" data-start-seconds="42">',
            $html
        );
        self::assertStringContainsString(
            'href="https://www.youtube.com/watch?v=dQw4w9WgXcQ&amp;t=42s" target="_blank" rel="noopener noreferrer"',
            $html
        );
        self::assertStringContainsString(
            '<figcaption id="blog-video-caption-' . $this->id(10)
            . '" class="blogDocument__videoCaption">Matrix trailer &amp; analysis</figcaption>',
            $html
        );
        self::assertStringContainsString(
            '<p id="blog-block-' . $this->id(11)
            . '" class="blogDocument__cta blogDocument__cta--secondary">',
            $html
        );
        self::assertSame($html, $renderer->render($document));
    }

    public function testHeadingsProjectTheFlatDocumentIntoSectionsAndArticles(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->paragraph(1, [$this->text('Preamble')]),
            $this->heading(2, 2, 'First section'),
            $this->paragraph(3, [$this->text('Direct section content')]),
            $this->heading(4, 3, 'First article'),
            $this->paragraph(5, [$this->text('Article content')]),
            $this->heading(6, 4, 'Article detail'),
            $this->heading(7, 5, 'Deep detail'),
            $this->heading(8, 6, 'Deepest detail'),
            $this->heading(9, 3, 'Second article'),
            $this->paragraph(10, [$this->text('Second article content')]),
            $this->heading(11, 2, 'Second section'),
            $this->paragraph(12, [$this->text('Second section content')]),
        ]));
        $html = (new BlogDocumentHtmlRenderer($this->resolver([])))
            ->render($document);
        $xpath = $this->xpath($html);

        self::assertCount(1, $xpath->query('/html/body/div'));
        self::assertCount(1, $xpath->query('/html/body/div/*[1][self::p]'));
        self::assertCount(2, $xpath->query('/html/body/div/section'));
        self::assertCount(1, $xpath->query('/html/body/div/section[1]/h2'));
        self::assertCount(1, $xpath->query('/html/body/div/section[1]/p'));
        self::assertCount(2, $xpath->query('/html/body/div/section[1]/article'));
        self::assertCount(
            1,
            $xpath->query('/html/body/div/section[1]/article[1]/h3')
        );
        foreach ([4, 5, 6] as $level) {
            self::assertCount(
                1,
                $xpath->query(
                    "/html/body/div/section[1]/article[1]/h{$level}"
                )
            );
        }
        self::assertCount(
            1,
            $xpath->query('/html/body/div/section[2]/p')
        );
        self::assertCount(
            0,
            $xpath->query('/html/body/div/section[2]/article')
        );
        self::assertCount(0, $xpath->query('//article/section'));
        self::assertCount(
            1,
            $xpath->query(
                '/html/body/div/section[@aria-labelledby="blog-block-'
                    . $this->id(2) . '"]'
            )
        );
        self::assertCount(
            1,
            $xpath->query(
                '//article[@aria-labelledby="blog-block-'
                    . $this->id(4) . '"]'
            )
        );
    }

    public function testOutputIsCspNeutralAndNeverContainsDocumentChromeOrH1(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->paragraph(1),
            $this->video(2),
        ]));
        $html = (new BlogDocumentHtmlRenderer($this->resolver([])))
            ->render($document);

        foreach (['<!doctype', '<html', '<head', '<body', '<h1', '<iframe', '<script'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($html));
        }
        self::assertDoesNotMatchRegularExpression(
            '/\s(?:style|on[a-z]+)\s*=/i',
            $html
        );
        self::assertSame(1, substr_count($html, 'data-blog-lite-youtube'));
        self::assertSame(1, substr_count($html, 'data-blog-youtube-play'));
    }

    public function testAllNewWindowLinksReceiveBothIsolationRelations(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->paragraph(1, [
                $this->inlineLink('Inline', '/inline', [], 'new'),
            ]),
            $this->linkBlock(2, '/standalone', 'Standalone', 'new'),
            $this->cta(3, '/cta', 'CTA', 'new'),
            $this->video(4),
        ]));
        $html = (new BlogDocumentHtmlRenderer($this->resolver([])))
            ->render($document);

        self::assertSame(4, substr_count($html, 'target="_blank"'));
        self::assertSame(
            4,
            substr_count($html, 'rel="noopener noreferrer"')
        );
        self::assertDoesNotMatchRegularExpression(
            '/target="_blank"(?! rel="noopener noreferrer")/',
            $html
        );
    }

    public function testSameWindowLinksDoNotReceiveTargetOrRel(): void
    {
        $document = BlogDocument::fromArray($this->document([
            $this->paragraph(1, [
                $this->inlineLink('Inline', '/inline'),
            ]),
            $this->linkBlock(2, '/standalone'),
            $this->cta(3, '/cta'),
        ]));
        $html = (new BlogDocumentHtmlRenderer($this->resolver([])))
            ->render($document);

        self::assertStringNotContainsString('target=', $html);
        self::assertStringNotContainsString('rel=', $html);
    }

    public function testCoverTemplateUsesOnlyItsCodeOwnedClassAndSizing(): void
    {
        $imageId = $this->id(900_001);
        $document = BlogDocument::fromArray($this->document(
            [
                $this->image(1, $imageId, 'cover'),
                $this->heading(2, 2, 'Cover section'),
                $this->paragraph(3),
            ],
            BlogDocumentTemplateRegistry::ARTICLE_COVER
        ));
        $renderer = new BlogDocumentHtmlRenderer($this->resolver([
            $imageId => $this->resolvedImage($imageId, '/media/cover'),
        ]));
        $html = $renderer->render($document);
        $mainHtml = $renderer->renderMain($document);
        $headerMedia = $renderer->renderHeaderMedia($document);

        self::assertStringStartsWith(
            '<div class="blogDocument blogDocument--cover">',
            $html
        );
        self::assertStringContainsString(
            'class="blogDocument__image blogDocument__image--cover"',
            $html
        );
        self::assertStringNotContainsString(
            'class="blogDocument__image blogDocument__image--cover"',
            $mainHtml
        );
        self::assertStringContainsString(
            'class="blogDocument__image blogDocument__image--cover"',
            $headerMedia
        );
        self::assertStringContainsString('sizes="100vw"', $headerMedia);
        self::assertStringContainsString(
            'loading="eager" fetchpriority="high" decoding="async"',
            $headerMedia
        );
        self::assertStringNotContainsString('loading="lazy"', $headerMedia);
        $xpath = $this->xpath($mainHtml);
        self::assertCount(
            0,
            $xpath->query('/html/body/div/*[1][self::figure]')
        );
        self::assertCount(1, $xpath->query('/html/body/div/section'));
        self::assertSame(1, substr_count($headerMedia, '<figure'));
        self::assertSame('', (new BlogDocumentHtmlRenderer(
            $this->resolver([])
        ))->renderHeaderMedia(BlogDocument::fromArray($this->document([]))));
    }

    public function testEmptyBasicDocumentRendersAnEmptyBodyContainer(): void
    {
        $document = BlogDocument::fromArray($this->document([]));

        self::assertSame(
            '<div class="blogDocument blogDocument--basic"></div>',
            (new BlogDocumentHtmlRenderer($this->resolver([])))
                ->render($document)
        );
    }

    public function testMissingImageFailsClosedWithoutLeakingItsIdentifier(): void
    {
        $imageId = $this->id(900_001);
        $document = BlogDocument::fromArray($this->document([
            $this->image(1, $imageId),
        ]));

        $this->assertRenderingIssue(
            BlogRenderingException::MEDIA_UNAVAILABLE,
            static fn (): string => (new BlogDocumentHtmlRenderer(
                new class implements BlogImageResolverInterface {
                    public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
                    {
                        return null;
                    }
                }
            ))->render($document),
            $imageId
        );
    }

    public function testResolverFailureIsNormalizedAndPayloadFree(): void
    {
        $imageId = $this->id(900_001);
        $document = BlogDocument::fromArray($this->document([
            $this->image(1, $imageId),
        ]));

        $this->assertRenderingIssue(
            BlogRenderingException::MEDIA_UNAVAILABLE,
            static fn (): string => (new BlogDocumentHtmlRenderer(
                new class implements BlogImageResolverInterface {
                    public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
                    {
                        throw new RuntimeException(
                            'Storage secret for ' . $mediaAssetPublicId
                        );
                    }
                }
            ))->render($document),
            $imageId
        );
    }

    public function testResolverCannotSubstituteAnotherAsset(): void
    {
        $requestedId = $this->id(900_001);
        $otherId = $this->id(900_002);
        $document = BlogDocument::fromArray($this->document([
            $this->image(1, $requestedId),
        ]));
        $wrongImage = $this->resolvedImage($otherId, '/media/wrong');

        $this->assertRenderingIssue(
            BlogRenderingException::MEDIA_UNAVAILABLE,
            static fn (): string => (new BlogDocumentHtmlRenderer(
                new class($wrongImage) implements BlogImageResolverInterface {
                    public function __construct(
                        private readonly BlogResolvedImage $image
                    ) {
                    }

                    public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
                    {
                        return $this->image;
                    }
                }
            ))->render($document),
            $requestedId
        );
    }

    public function testCorruptResolverReturnTypeAlsoFailsClosed(): void
    {
        $imageId = $this->id(900_001);
        $document = BlogDocument::fromArray($this->document([
            $this->image(1, $imageId),
        ]));

        $this->assertRenderingIssue(
            BlogRenderingException::MEDIA_UNAVAILABLE,
            static fn (): string => (new BlogDocumentHtmlRenderer(
                new class implements BlogImageResolverInterface {
                    public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
                    {
                        /** @phpstan-ignore-next-line Deliberate corrupt port. */
                        return 'corrupt';
                    }
                }
            ))->render($document),
            $imageId
        );
    }

    public function testResolvedImageNormalizesSrcsetOrder(): void
    {
        $imageId = $this->id(900_001);
        $image = new BlogResolvedImage(
            $imageId,
            [
                new BlogResolvedImageCandidate('/media/900.avif', 900),
                new BlogResolvedImageCandidate('/media/480.avif', 480),
            ],
            900,
            600
        );

        self::assertSame(
            [480, 900],
            array_map(
                static fn (BlogResolvedImageCandidate $candidate): int =>
                    $candidate->width(),
                $image->candidates()
            )
        );
        self::assertSame('/media/900.avif', $image->sourceUrl());
    }

    public function testResolvedImageRejectsUnsafeUrlsAndInvalidDimensions(): void
    {
        $invalidUrls = [
            'javascript:alert(1)',
            'http://example.test/image.avif',
            '//example.test/image.avif',
            '/media/../secret.avif',
            '/media/%252e%252e/secret.avif',
            '/media/%25252fsecret.avif',
            '/media/bad%value.avif',
            "/media/control\n.avif",
            '/media/image.avif#fragment',
            '/media/image.avif,640w',
            '/media/"quoted".avif',
            'https://user:pass@example.test/image.avif',
        ];
        foreach ($invalidUrls as $url) {
            $this->assertRenderingIssue(
                BlogRenderingException::INVALID_IMAGE_PRESENTATION,
                static fn (): BlogResolvedImageCandidate =>
                    new BlogResolvedImageCandidate($url, 480)
            );
        }

        $imageId = $this->id(900_001);
        $candidate = new BlogResolvedImageCandidate('/media/480.avif', 480);
        foreach ([
            [$imageId, [$candidate], 479, 320],
            [$imageId, [$candidate], 480, 0],
            [$imageId, [$candidate, $candidate], 480, 320],
            ['not-a-uuid', [$candidate], 480, 320],
            [$imageId, [], 480, 320],
        ] as [$publicId, $candidates, $width, $height]) {
            $this->assertRenderingIssue(
                BlogRenderingException::INVALID_IMAGE_PRESENTATION,
                static fn (): BlogResolvedImage => new BlogResolvedImage(
                    $publicId,
                    $candidates,
                    $width,
                    $height
                )
            );
        }
    }

    public function testResolvedImageAcceptsSafeLocalAndHttpsCandidates(): void
    {
        $imageId = $this->id(900_001);
        $image = new BlogResolvedImage(
            $imageId,
            [
                new BlogResolvedImageCandidate(
                    '/admin/media/file?asset=' . $imageId . '&width=480',
                    480
                ),
                new BlogResolvedImageCandidate(
                    'https://cdn.example.test/media/image.avif?v=2',
                    900
                ),
            ],
            900,
            600
        );

        self::assertCount(2, $image->candidates());
        self::assertSame(
            'https://cdn.example.test/media/image.avif?v=2',
            $image->sourceUrl()
        );
    }

    public function testPreviewAndPublicUseTheSameRendererContract(): void
    {
        $imageId = $this->id(900_001);
        $document = BlogDocument::fromArray($this->document([
            $this->image(1, $imageId),
            $this->paragraph(2),
        ]));

        $preview = (new BlogDocumentHtmlRenderer($this->resolver([
            $imageId => $this->resolvedImage($imageId, '/admin/media'),
        ])))->render($document);
        $public = (new BlogDocumentHtmlRenderer($this->resolver([
            $imageId => $this->resolvedImage($imageId, '/media/public'),
        ])))->render($document);

        self::assertSame(
            str_replace('/admin/media/', '/media/public/', $preview),
            $public
        );
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

    /** @param list<array<string, mixed>> $items */
    private function listBlock(int $id, bool $ordered, array $items): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'list',
            'ordered' => $ordered,
            'items' => $items,
        ];
    }

    private function listItem(int $id, string $text): array
    {
        return [
            'id' => $this->id($id),
            'content' => [$this->text($text)],
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
        string $label = 'Read more',
        string $target = 'same',
        ?string $title = null
    ): array {
        return [
            'id' => $this->id($id),
            'type' => 'link',
            'label' => $label,
            'href' => $href,
            'title' => $title,
            'target' => $target,
        ];
    }

    private function image(
        int $id,
        string $mediaAssetPublicId,
        string $display = 'content',
        bool $decorative = false,
        string $alt = 'Matrix image',
        ?string $title = null,
        ?string $caption = null
    ): array {
        return [
            'id' => $this->id($id),
            'type' => 'image',
            'media_asset_public_id' => $mediaAssetPublicId,
            'alt' => $alt,
            'title' => $title,
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
        string $label = 'Choose the red pill',
        string $target = 'same',
        ?string $title = null,
        string $variant = 'primary'
    ): array {
        return [
            'id' => $this->id($id),
            'type' => 'cta',
            'label' => $label,
            'href' => $href,
            'title' => $title,
            'target' => $target,
            'variant' => $variant,
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
        string $target = 'same',
        ?string $title = null
    ): array {
        return [
            'type' => 'link',
            'text' => $text,
            'marks' => $marks,
            'href' => $href,
            'title' => $title,
            'target' => $target,
        ];
    }

    /**
     * @param array<string, BlogResolvedImage> $images
     */
    private function resolver(array $images): BlogImageResolverInterface
    {
        return new class($images) implements BlogImageResolverInterface {
            /** @param array<string, BlogResolvedImage> $images */
            public function __construct(private readonly array $images)
            {
            }

            public function resolve(string $mediaAssetPublicId): ?BlogResolvedImage
            {
                return $this->images[$mediaAssetPublicId] ?? null;
            }
        };
    }

    private function resolvedImage(
        string $mediaAssetPublicId,
        string $prefix
    ): BlogResolvedImage {
        return new BlogResolvedImage(
            $mediaAssetPublicId,
            [
                new BlogResolvedImageCandidate(
                    $prefix . '/480.avif?token=a&b=1',
                    480
                ),
                new BlogResolvedImageCandidate(
                    $prefix . '/900.avif?token=a&b=1',
                    900
                ),
            ],
            900,
            600
        );
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }

    private function xpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($loaded);

        return new DOMXPath($document);
    }

    /** @param callable(): mixed $operation */
    private function assertRenderingIssue(
        string $issueCode,
        callable $operation,
        ?string $secret = null
    ): void {
        try {
            $operation();
            self::fail('Expected Blog rendering failure.');
        } catch (BlogRenderingException $exception) {
            self::assertSame($issueCode, $exception->issueCode());
            self::assertSame(
                'Unable to render structured Blog content.',
                $exception->getMessage()
            );
            if ($secret !== null) {
                self::assertStringNotContainsString(
                    $secret,
                    $exception->getMessage()
                );
            }
        }
    }
}
