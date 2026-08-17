<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class BlogResponsiveCardMediaContractTest extends TestCase
{
    /** @var list<string> */
    private const RESOURCES = [
        'sectionBlogGrid01',
        'sectionBlogList01',
        'sectionBlogFeatured01',
        'sectionBlogRelated01',
        'sectionBlogSlider01',
        'moduleBlogGrid02',
        'sectionBlogSlider02',
        'sectionBlogStack01',
    ];

    public function testEveryCardResourceAcceptsTheSameResponsiveMediaShape(): void
    {
        $this->withModuleProject(static function (): void {
            foreach (self::RESOURCES as $position => $resource) {
                $item = self::item(1);
                $item['media'] = self::media(
                    '/_liquidstack/blog-media/original/1600.avif',
                    1600,
                    900,
                    'Portada grande'
                );
                $item['thumbnail'] = self::media(
                    '/_liquidstack/blog-media/card/640.avif',
                    640,
                    360,
                    'Miniatura "Matrix"',
                    '/_liquidstack/blog-media/card/320.avif 320w, '
                        . 'https://cdn.example.test/card/640.avif 640w',
                    '(min-width: 48rem) 20rem, 92vw'
                );

                $html = controller($resource, $position, [
                    'items_data' => [$item],
                ]);
                $xpath = self::document($html);
                $image = self::first($xpath, '//article//img');

                self::assertSame(
                    '/_liquidstack/blog-media/card/640.avif',
                    $image->getAttribute('src'),
                    $resource
                );
                self::assertSame(
                    '/_liquidstack/blog-media/card/320.avif 320w, '
                        . 'https://cdn.example.test/card/640.avif 640w',
                    $image->getAttribute('srcset'),
                    $resource
                );
                self::assertSame(
                    '(min-width: 48rem) 20rem, 92vw',
                    $image->getAttribute('sizes'),
                    $resource
                );
                self::assertSame('Miniatura "Matrix"', $image->getAttribute('alt'));
                self::assertSame('640', $image->getAttribute('width'));
                self::assertSame('360', $image->getAttribute('height'));
                self::assertSame('lazy', $image->getAttribute('loading'));
                self::assertSame('async', $image->getAttribute('decoding'));
                self::assertSame(
                    str_contains($resource, 'Slider') ? 'false' : '',
                    $image->getAttribute('draggable'),
                    $resource
                );
                self::assertStringNotContainsString('original/1600.avif', $html);
                self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
            }
        });
    }

    public function testZeroOneAndSeveralItemsDoNotRequireMedia(): void
    {
        $this->withModuleProject(static function (): void {
            foreach (self::RESOURCES as $position => $resource) {
                $emptyHtml = controller($resource, $position, [
                    'items_data' => [],
                ]);
                self::assertSame(
                    0,
                    self::nodesCount(self::document($emptyHtml), '//article'),
                    $resource
                );

                $singleHtml = controller($resource, $position, [
                    'items_data' => [self::item(1)],
                ]);
                $single = self::document($singleHtml);
                self::assertSame(1, self::nodesCount($single, '//article'), $resource);
                self::assertSame(0, self::nodesCount($single, '//img'), $resource);

                $severalHtml = controller($resource, $position, [
                    'items_data' => [
                        self::item(1),
                        self::item(2),
                        self::item(3),
                    ],
                ]);
                self::assertSame(
                    3,
                    self::nodesCount(self::document($severalHtml), '//article'),
                    $resource
                );
            }
        });
    }

    public function testUnsafeOptionalAttributesAreOmittedWithoutLosingASafeThumbnail(): void
    {
        $this->withModuleProject(static function (): void {
            $item = self::item(1);
            $item['media'] = self::media(
                '/_liquidstack/blog-media/original/1600.avif',
                1600,
                900,
                'Original'
            );
            $item['thumbnail'] = self::media(
                '/_liquidstack/blog-media/card/640.avif',
                640,
                360,
                '<svg onload="alert(1)">',
                '/_liquidstack/blog-media/card/320.avif 320w, '
                    . 'javascript:alert(1) 640w',
                '92vw" onerror="alert(1)'
            );

            $html = controller('moduleBlogGrid02', 9, [
                'items_data' => [$item],
            ]);
            $image = self::first(self::document($html), '//article//img');

            self::assertSame(
                '/_liquidstack/blog-media/card/640.avif',
                $image->getAttribute('src')
            );
            self::assertFalse($image->hasAttribute('srcset'));
            self::assertSame(
                '(min-width: 64rem) 42vw, 92vw',
                $image->getAttribute('sizes')
            );
            self::assertSame(
                '<svg onload="alert(1)">',
                $image->getAttribute('alt')
            );
            self::assertStringNotContainsString('<svg', $html);
            self::assertStringNotContainsString('javascript:', $html);
            self::assertStringNotContainsString(' onerror=', $html);
            self::assertStringNotContainsString('original/1600.avif', $html);
        });
    }

    public function testUnsafeThumbnailSourceFallsBackToSafeHttpsMedia(): void
    {
        $this->withModuleProject(static function (): void {
            $item = self::item(1);
            $item['thumbnail'] = self::media(
                'http://cdn.example.test/card-320.avif',
                320,
                180,
                'Insegura'
            );
            $item['media'] = self::media(
                'https://cdn.example.test/card-960.avif',
                960,
                540,
                'Segura',
                'https://cdn.example.test/card-480.avif 480w, '
                    . 'https://cdn.example.test/card-960.avif 960w',
                '92vw'
            );

            $html = controller('sectionBlogList01', 10, [
                'items_data' => [$item],
            ]);
            $image = self::first(self::document($html), '//article//img');

            self::assertSame(
                'https://cdn.example.test/card-960.avif',
                $image->getAttribute('src')
            );
            self::assertSame(
                'https://cdn.example.test/card-480.avif 480w, '
                    . 'https://cdn.example.test/card-960.avif 960w',
                $image->getAttribute('srcset')
            );
            self::assertStringNotContainsString('http://', $html);
        });
    }

    public function testEncodedTraversalAndAmbiguousMediaUrlsFailClosed(): void
    {
        $this->withModuleProject(static function (): void {
            $unsafeUrls = [
                '/media/../card.avif',
                '/media/%2e%2e/card.avif',
                '/media/%252e%252e/card.avif',
                '/media/a//card.avif',
                '/media/%2fcard.avif',
                '/media/%255ccard.avif',
                'https://user:password@cdn.example.test/card.avif',
                'https://cdn.example.test/media/a//card.avif',
                '/media/card.avif#fragment',
                '/media/%GG/card.avif',
                ' /media/card.avif',
                "/media/\u{200B}card.avif",
            ];

            foreach ($unsafeUrls as $position => $unsafeUrl) {
                $item = self::item($position + 1);
                $item['thumbnail'] = self::media(
                    $unsafeUrl,
                    640,
                    360,
                    'No segura'
                );
                $item['media'] = self::media(
                    '/media/fallback-' . $position . '.avif?rev=abc%201',
                    960,
                    540,
                    ''
                );

                $html = controller('moduleBlogGrid02', $position, [
                    'items_data' => [$item],
                ]);
                $image = self::first(self::document($html), '//article//img');

                self::assertSame(
                    '/media/fallback-' . $position . '.avif?rev=abc%201',
                    $image->getAttribute('src'),
                    $unsafeUrl
                );
                self::assertTrue($image->hasAttribute('alt'));
                self::assertSame('', $image->getAttribute('alt'));
                self::assertStringNotContainsString($unsafeUrl, $html);
            }
        });
    }

    public function testDecorativeEmptyAltIsPreservedLiterally(): void
    {
        $this->withModuleProject(static function (): void {
            $item = self::item(1);
            $item['thumbnail'] = self::media(
                '/media/decorative.avif',
                640,
                360,
                ''
            );

            $image = self::first(
                self::document(controller('sectionBlogSlider01', 1, [
                    'items_data' => [$item],
                ])),
                '//article//img'
            );

            self::assertTrue($image->hasAttribute('alt'));
            self::assertSame('', $image->getAttribute('alt'));
            self::assertSame('false', $image->getAttribute('draggable'));
        });
    }

    public function testValidAltTextIsNotSilentlyTrimmed(): void
    {
        $this->withModuleProject(static function (): void {
            $item = self::item(1);
            $item['media'] = self::media(
                '/media/card.avif',
                640,
                360,
                '  Matrix visual  '
            );

            $html = controller('sectionBlogList01', 1, [
                'items_data' => [$item],
            ]);
            $image = self::first(self::document($html), '//article//img');

            self::assertSame('  Matrix visual  ', $image->getAttribute('alt'));
            self::assertStringContainsString('alt="  Matrix visual  "', $html);
        });
    }

    public function testMissingOrInvalidThumbnailAltFallsBackToValidMedia(): void
    {
        $this->withModuleProject(static function (): void {
            $invalidAlts = [
                'missing' => null,
                'non-string' => 42,
                'control' => "Matrix\nvisual",
                'unicode-control' => "Matrix\u{200B}visual",
            ];

            foreach ($invalidAlts as $case => $invalidAlt) {
                $item = self::item(1);
                $thumbnail = self::media(
                    '/media/thumbnail-' . $case . '.avif',
                    640,
                    360,
                    'Temporal'
                );
                if ($case === 'missing') {
                    unset($thumbnail['alt']);
                } else {
                    $thumbnail['alt'] = $invalidAlt;
                }
                $item['thumbnail'] = $thumbnail;
                $item['media'] = self::media(
                    '/media/fallback-' . $case . '.avif',
                    960,
                    540,
                    'Fallback accesible'
                );

                $html = controller('sectionBlogSlider01', 1, [
                    'items_data' => [$item],
                ]);
                $image = self::first(self::document($html), '//article//img');

                self::assertSame(
                    '/media/fallback-' . $case . '.avif',
                    $image->getAttribute('src'),
                    $case
                );
                self::assertSame(
                    'Fallback accesible',
                    $image->getAttribute('alt'),
                    $case
                );
                self::assertStringNotContainsString(
                    '/media/thumbnail-' . $case . '.avif',
                    $html,
                    $case
                );
            }

            $withoutFallback = self::item(2);
            $withoutFallback['media'] = [
                'src' => '/media/no-alt.avif',
                'width' => 960,
                'height' => 540,
            ];
            $html = controller('sectionBlogList01', 2, [
                'items_data' => [$withoutFallback],
            ]);

            self::assertSame(1, self::nodesCount(self::document($html), '//article'));
            self::assertSame(0, self::nodesCount(self::document($html), '//img'));
        });
    }

    public function testSrcsetMustUseUniqueAscendingWidthDescriptors(): void
    {
        $this->withModuleProject(static function (): void {
            foreach ([
                '/media/card-960.avif 960w, /media/card-480.avif 480w',
                '/media/card-480.avif 480w, /media/card-copy.avif 480w',
                '/media/card-480.avif 1x, /media/card-960.avif 2x',
                '//evil.example/card.avif 480w',
                '/media/card-2561.avif 2561w',
            ] as $position => $srcset) {
                $item = self::item($position + 1);
                $item['media'] = self::media(
                    '/media/card.avif',
                    960,
                    540,
                    'Matrix',
                    $srcset,
                    '92vw'
                );
                $image = self::first(
                    self::document(controller('sectionBlogStack01', $position, [
                        'items_data' => [$item],
                    ])),
                    '//article//img'
                );

                self::assertFalse($image->hasAttribute('srcset'), $srcset);
                self::assertSame('/media/card.avif', $image->getAttribute('src'));
            }
        });
    }

    /** @return array<string, mixed> */
    private static function item(int $position): array
    {
        return [
            'url' => '/es/noticias/matrix-' . $position,
            'h1' => 'Entrada Matrix ' . $position,
            'excerpt' => 'Una entrada de prueba para el contrato responsive.',
            'published_at' => sprintf(
                '2026-08-%02dT09:00:00+00:00',
                min(28, $position)
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function media(
        string $src,
        int $width,
        int $height,
        string $alt,
        string $srcset = '',
        string $sizes = ''
    ): array {
        return [
            'src' => $src,
            'srcset' => $srcset,
            'sizes' => $sizes,
            'alt' => $alt,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function withModuleProject(callable $assertions): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(dirname(__DIR__, 2));

        try {
            $assertions();
        } finally {
            Paths::setProjectRoot($previousRoot);
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }
        }
    }

    private static function document(string $html): DOMXPath
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<!doctype html><html><body>' . $html . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private static function first(DOMXPath $xpath, string $query): DOMElement
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes);
        $element = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $element);

        return $element;
    }

    private static function nodesCount(DOMXPath $xpath, string $query): int
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes);

        return $nodes->length;
    }

    private static function moduleProjectRoot(): string
    {
        return dirname(__DIR__, 2)
            . '/modules/blog/resources/project';
    }
}
