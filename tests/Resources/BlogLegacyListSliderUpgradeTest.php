<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;
use function App\Core\Support\controller;

final class BlogLegacyListSliderUpgradeTest extends TestCase
{
    public function testListUsesOneReusableCenteredContentColumn(): void
    {
        $root = self::projectRoot();
        $template = (string) file_get_contents(
            $root . '/App/templates/_sectionBlogList01.html'
        );
        $scss = (string) file_get_contents(
            $root . '/src/scss/resources/_sectionBlogList01.scss'
        );

        self::assertStringContainsString(
            'class="sectionBlogList01-inner"',
            $template
        );
        self::assertMatchesRegularExpression(
            '/sectionBlogList01-inner[\s\S]*\{header-primary\}'
                . '[\s\S]*sectionBlogList01-list[\s\S]*\{items\}/',
            $template
        );
        self::assertStringContainsString('justify-content: center;', $scss);
        self::assertStringContainsString('justify-items: center;', $scss);
        self::assertMatchesRegularExpression(
            '/\.sectionBlogList01-inner\s*\{[\s\S]*?'
                . 'width:\s*min\(100%,\s*c\.\$textMax\);'
                . '[\s\S]*?margin-inline:\s*auto;/',
            $scss
        );
        self::assertStringContainsString(
            '@media (min-width: c.$tablet)',
            $scss
        );
    }

    public function testSliderRendersSafePreferredThumbnailsAndControls(): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = getcwd();
        Paths::setProjectRoot(self::projectRoot());
        chdir(dirname(__DIR__, 2));

        $baseItem = [
            'url' => '/es/noticias/matrix',
            'h1' => 'Una tarjeta de Matrix',
            'excerpt' => 'Contenido editorial de prueba.',
            'published_at' => '2026-08-14T09:00:00+00:00',
            'media' => [
                'src' => '/media/original-1800.avif',
                'alt' => 'Original',
                'width' => 1800,
                'height' => 1200,
            ],
            'thumbnail' => [
                'src' => '/media/thumbnail-640.avif',
                'alt' => 'Miniatura segura',
                'width' => 640,
                'height' => 400,
            ],
        ];

        try {
            $html = controller('sectionBlogSlider01', 4, [
                'items_data' => [
                    $baseItem,
                    array_replace($baseItem, [
                        'url' => '/es/noticias/trinity',
                        'h1' => 'Una tarjeta de Trinity',
                        'thumbnail' => [
                            'src' => 'javascript:alert(1)',
                            'alt' => 'No segura',
                            'width' => 640,
                            'height' => 400,
                        ],
                    ]),
                    $baseItem,
                ],
                'autoplay_delay' => 999,
                'transition_duration' => 0,
            ]);

            self::assertStringContainsString(
                'class="sectionBlogSlider01 sectionBlogSlider01--items-2',
                $html
            );
            self::assertSame(2, substr_count(
                $html,
                '<article class="sectionBlogSlider01-item"'
            ));
            self::assertSame(2, substr_count($html, '<h3 id="'));
            self::assertStringContainsString(
                'src="/media/thumbnail-640.avif"',
                $html
            );
            self::assertStringContainsString(
                'src="/media/original-1800.avif"',
                $html
            );
            self::assertStringNotContainsString('javascript:', $html);
            self::assertStringContainsString(
                'id="sectionBlogSlider01-04-viewport"',
                $html
            );
            self::assertSame(3, substr_count(
                $html,
                'aria-controls="sectionBlogSlider01-04-viewport"'
            ));
            self::assertStringContainsString(
                'data-blog-slider-autoplay-delay="60"',
                $html
            );
            self::assertStringContainsString(
                'data-blog-slider-duration="0.1"',
                $html
            );
            self::assertStringNotContainsString(
                'data-blog-slider-wrap',
                $html
            );
            $controller = (string) file_get_contents(
                self::projectRoot()
                    . '/App/controllers/sectionBlogSlider01.php'
            );
            self::assertStringNotContainsString("\$params['wrap']", $controller);
            self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
        } finally {
            Paths::setProjectRoot($previousRoot);
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }
        }
    }

    public function testSliderCssKeepsThumbnailAndNativeFallbackContracts(): void
    {
        $root = self::projectRoot();
        $scss = (string) file_get_contents(
            $root . '/src/scss/resources/_sectionBlogSlider01.scss'
        );
        $javascript = (string) file_get_contents(
            $root . '/src/js/resources/_sectionBlogSlider01.js'
        );

        foreach ([
            '.sectionBlogSlider01-media',
            'aspect-ratio: 16 / 10;',
            'max-height: 14rem;',
            'object-fit: cover;',
            'scroll-snap-type: inline mandatory;',
            '&.sectionBlogSlider01--enhanced',
            'touch-action: pan-y;',
        ] as $contract) {
            self::assertStringContainsString($contract, $scss);
        }
        foreach ([
            "import gsap from 'gsap';",
            'Draggable, InertiaPlugin',
            'inertia: true',
            'snap: { x: snappedX }',
            'gsap.utils.wrap(',
            'loopEnabled = cards.length > 0;',
            "event.key === 'ArrowLeft'",
            "event.key === 'Home'",
            "'(prefers-reduced-motion: reduce)'",
            'new Map()',
            'draggable?.kill?.()',
            'listenerController.abort()',
            'import.meta.hot.dispose(cleanupSectionBlogSlider01)',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }
        foreach (['localStorage', 'sessionStorage', 'document.cookie'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $javascript);
        }
        self::assertStringNotContainsString('blogSliderWrap', $javascript);
    }

    public function testBrowserlessHarnessExercisesInteractionAndCleanup(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root
            . '/tests/Resources/fixtures/'
            . 'blog-legacy-slider01-upgrade-harness.mjs';
        $command = sprintf(
            'node %s %s 2>&1',
            escapeshellarg($script),
            escapeshellarg($root)
        );
        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        $result = json_decode(
            implode("\n", $output),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(3, $result['enhancedInstances']);
        self::assertTrue($result['inertia']);
        self::assertSame(-960, $result['keyboardEnd']);
        self::assertTrue($result['dragClickSuppressed']);
        self::assertTrue($result['pauseExposed']);
        self::assertTrue($result['reducedMotionFallback']);
        self::assertSame(16, $result['loopCopies']);
        self::assertSame(2, $result['appendOriginals']);
        self::assertSame(4, $result['singleClonesAfterHotReload']);
        self::assertTrue($result['staleCleanupPreservedOwner']);
        self::assertTrue($result['hotReloadInteractionWorks']);
        self::assertTrue($result['cloneLoadIgnoredDuringMotion']);
        self::assertTrue($result['cleaned']);
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2)
            . '/modules/blog/resources/project';
    }
}
