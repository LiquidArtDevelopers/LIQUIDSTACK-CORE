<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;
use function App\Core\Support\controller;

final class SectionBlogSlider02MediaContractTest extends TestCase
{
    public function testZeroOneAndSeveralItemsKeepTheirProgressiveStates(): void
    {
        $this->withModuleProject(static function (): void {
            $empty = self::document(controller('sectionBlogSlider02', 0, [
                'items_data' => [],
            ]));
            self::assertSame(
                'empty',
                self::first($empty, '//section')->getAttribute('data-state')
            );
            self::assertSame(0, self::nodesCount($empty, '//article'));
            self::assertFalse(
                self::first(
                    $empty,
                    '//*[contains(concat(" ", normalize-space(@class), " "), '
                        . '" sectionBlogSlider02-viewport ")]'
                )->hasAttribute('tabindex')
            );

            $single = self::document(controller('sectionBlogSlider02', 1, [
                'items_data' => [self::item(1)],
            ]));
            self::assertSame(1, self::nodesCount($single, '//article'));
            self::assertFalse(
                self::first(
                    $single,
                    '//*[contains(concat(" ", normalize-space(@class), " "), '
                        . '" sectionBlogSlider02-viewport ")]'
                )->hasAttribute('tabindex')
            );

            $several = self::document(controller('sectionBlogSlider02', 2, [
                'items_data' => [
                    self::item(1),
                    self::item(2),
                    self::item(3),
                ],
            ]));
            self::assertSame(3, self::nodesCount($several, '//article'));
            self::assertSame(
                '0',
                self::first(
                    $several,
                    '//*[contains(concat(" ", normalize-space(@class), " "), '
                        . '" sectionBlogSlider02-viewport ")]'
                )->getAttribute('tabindex')
            );
            self::assertSame(
                3,
                self::nodesCount($several, '//article[@data-blog-card-key]')
            );
        });
    }

    public function testAValidatedThumbnailIsPreferredWithoutInventingSources(): void
    {
        $this->withModuleProject(static function (): void {
            $item = self::item(1);
            $item['media'] = [
                'src' => '/media/original-2400.avif',
                'alt' => 'Portada original',
                'width' => 2400,
                'height' => 1600,
            ];
            $item['thumbnail'] = [
                'src' => '/media/thumbnail-640.avif',
                'alt' => 'Miniatura recortada',
                'width' => 640,
                'height' => 360,
            ];

            $html = controller('sectionBlogSlider02', 3, [
                'items_data' => [$item],
            ]);
            $xpath = self::document($html);
            $image = self::first($xpath, '//article//img');

            self::assertSame('/media/thumbnail-640.avif', $image->getAttribute('src'));
            self::assertSame('Miniatura recortada', $image->getAttribute('alt'));
            self::assertSame('640', $image->getAttribute('width'));
            self::assertSame('360', $image->getAttribute('height'));
            self::assertSame(
                '(min-width: 64rem) 27rem, (min-width: 48rem) 42vw, 82vw',
                $image->getAttribute('sizes')
            );
            self::assertSame('lazy', $image->getAttribute('loading'));
            self::assertSame('async', $image->getAttribute('decoding'));
            self::assertSame('false', $image->getAttribute('draggable'));
            self::assertStringNotContainsString('original-2400.avif', $html);
            self::assertStringNotContainsString('srcset=', $html);
        });
    }

    public function testInvalidOrMissingThumbnailFallsBackToTheValidatedMedia(): void
    {
        $this->withModuleProject(static function (): void {
            $invalidThumbnail = self::item(1);
            $invalidThumbnail['media'] = self::media('/media/card-1.avif');
            $invalidThumbnail['thumbnail'] = [
                'src' => '//evil.example/thumbnail.avif',
                'alt' => 'No debe salir',
                'width' => 320,
                'height' => 180,
            ];
            $singleSource = self::item(2);
            $singleSource['media'] = self::media('/media/card-2.avif');
            $withoutMedia = self::item(3);

            $xpath = self::document(controller('sectionBlogSlider02', 4, [
                'items_data' => [
                    $invalidThumbnail,
                    $singleSource,
                    $withoutMedia,
                ],
            ]));
            $images = $xpath->query('//article//img');

            self::assertNotFalse($images);
            self::assertSame(2, $images->length);
            self::assertSame(
                '/media/card-1.avif',
                $images->item(0)?->attributes?->getNamedItem('src')?->nodeValue
            );
            self::assertSame(
                '/media/card-2.avif',
                $images->item(1)?->attributes?->getNamedItem('src')?->nodeValue
            );
            self::assertSame(3, self::nodesCount($xpath, '//article'));
        });
    }

    public function testResponsiveMediaBoxHasABoundedRatioAndSafeCrop(): void
    {
        $scss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_sectionBlogSlider02.scss'
        );

        foreach ([
            'position: relative;',
            'max-height: 13.5rem;',
            'aspect-ratio: 16 / 9;',
            'position: absolute;',
            'inset: 0;',
            'height: 100%;',
            'object-fit: cover;',
            'object-position: 50% 50%;',
            '@media (min-width: c.$tablet)',
            'width: min(42vw, 26rem);',
            '@media (min-width: c.$desktop)',
            'width: min(30vw, 27rem);',
        ] as $contract) {
            self::assertStringContainsString($contract, $scss);
        }

        self::assertDoesNotMatchRegularExpression(
            '/@media\s*\([^)]*max-width/i',
            $scss
        );
    }

    /** @return array<string, mixed> */
    private static function item(int $position): array
    {
        return [
            'url' => '/es/noticias/matrix-' . $position,
            'h1' => 'Entrada Matrix ' . $position,
            'excerpt' => 'Una entrada de prueba para comprobar el carrusel.',
            'published_at' => '2026-01-0' . $position . 'T09:00:00+00:00',
        ];
    }

    /** @return array{src:string,alt:string,width:int,height:int} */
    private static function media(string $src): array
    {
        return [
            'src' => $src,
            'alt' => 'Imagen Matrix',
            'width' => 1200,
            'height' => 800,
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

    private static function document(string $html): \DOMXPath
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
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

        return new \DOMXPath($document);
    }

    private static function first(
        \DOMXPath $xpath,
        string $query
    ): \DOMElement
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes);
        $element = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $element);

        return $element;
    }

    private static function nodesCount(\DOMXPath $xpath, string $query): int
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
