<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class ModuleBlogGrid02MediaContractTest extends TestCase
{
    public function testAValidatedThumbnailIsPreferredWithoutInventingSources(): void
    {
        $this->withModuleProject(static function (): void {
            $item = self::item();
            $item['media'] = self::media(
                '/media/original-2400.avif',
                'Portada original',
                2400,
                1600
            );
            $item['thumbnail'] = self::media(
                '/media/thumbnail-640.avif',
                'Miniatura editorial',
                640,
                360
            );

            $html = controller('moduleBlogGrid02', 0, [
                'items_data' => [$item],
            ]);

            self::assertStringContainsString(
                'src="/media/thumbnail-640.avif"',
                $html
            );
            self::assertStringContainsString(
                'alt="Miniatura editorial"',
                $html
            );
            self::assertStringContainsString('width="640"', $html);
            self::assertStringContainsString('height="360"', $html);
            self::assertStringNotContainsString('original-2400.avif', $html);
            self::assertStringNotContainsString('srcset=', $html);
        });
    }

    public function testInvalidOrMissingThumbnailFallsBackToSafeMedia(): void
    {
        $this->withModuleProject(static function (): void {
            $invalidThumbnail = self::item(1);
            $invalidThumbnail['media'] = self::media(
                '/media/card-1.avif'
            );
            $invalidThumbnail['thumbnail'] = self::media(
                '//evil.example/thumbnail.avif',
                'No debe salir',
                320,
                180
            );
            $withoutThumbnail = self::item(2);
            $withoutThumbnail['media'] = self::media(
                '/media/card-2.avif'
            );

            $html = controller('moduleBlogGrid02', 1, [
                'items_data' => [$invalidThumbnail, $withoutThumbnail],
            ]);

            self::assertStringContainsString('src="/media/card-1.avif"', $html);
            self::assertStringContainsString('src="/media/card-2.avif"', $html);
            self::assertStringNotContainsString('evil.example', $html);
        });
    }

    public function testMediaBoxHasABoundedRatioAndSafeCrop(): void
    {
        $scss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_moduleBlogGrid02.scss'
        );

        foreach ([
            'position: relative;',
            'max-height: 16rem;',
            'aspect-ratio: 16 / 9;',
            'flex: 0 0 auto;',
            'position: absolute;',
            'inset: 0;',
            'height: 100%;',
            'object-fit: cover;',
            'object-position: 50% 50%;',
        ] as $contract) {
            self::assertStringContainsString($contract, $scss);
        }

        self::assertDoesNotMatchRegularExpression(
            '/@media\s*\([^)]*max-width/i',
            $scss
        );
    }

    /** @return array<string, mixed> */
    private static function item(int $position = 1): array
    {
        return [
            'url' => '/es/noticias/matrix-' . $position,
            'h1' => 'Entrada Matrix ' . $position,
            'excerpt' => 'Una entrada de prueba para comprobar la rejilla.',
            'published_at' => '2026-01-0' . $position . 'T09:00:00+00:00',
        ];
    }

    /** @return array{src:string,alt:string,width:int,height:int} */
    private static function media(
        string $src,
        string $alt = 'Imagen Matrix',
        int $width = 1200,
        int $height = 800
    ): array {
        return [
            'src' => $src,
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

    private static function moduleProjectRoot(): string
    {
        return dirname(__DIR__, 2)
            . '/modules/blog/resources/project';
    }
}
