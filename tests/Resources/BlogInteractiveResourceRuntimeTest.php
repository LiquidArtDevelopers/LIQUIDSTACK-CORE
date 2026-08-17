<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BlogInteractiveResourceRuntimeTest extends TestCase
{
    public function testSlider02IsAlwaysInfiniteWithoutAFiniteWrapApi(): void
    {
        $project = dirname(__DIR__, 2)
            . '/modules/blog/resources/project';
        $controller = (string) file_get_contents(
            $project . '/App/controllers/sectionBlogSlider02.php'
        );
        $template = (string) file_get_contents(
            $project . '/App/templates/_sectionBlogSlider02.html'
        );
        $javascript = (string) file_get_contents(
            $project . '/src/js/resources/_sectionBlogSlider02.js'
        );

        self::assertStringNotContainsString("\$params['wrap']", $controller);
        self::assertStringNotContainsString('{wrap}', $template);
        self::assertStringNotContainsString(
            'data-blog-slider02-wrap',
            $template
        );
        self::assertStringNotContainsString('blogSlider02Wrap', $javascript);
        self::assertStringContainsString(
            'loopEnabled = cards.length > 0;',
            $javascript
        );
    }

    public function testSliderAndStackExerciseTheirInteractiveLifecycle(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root
            . '/tests/Resources/fixtures/'
            . 'blog-interactive-resource-runtime-harness.mjs';
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

        self::assertSame(5, $result['slider']['instances']);
        self::assertSame(8, $result['slider']['clones']);
        self::assertSame(8, $result['slider']['singleClonesAfterAppend']);
        self::assertSame(8, $result['slider']['singleClonesAfterHotReload']);
        self::assertTrue($result['slider']['staleCleanupPreservedOwner']);
        self::assertSame(-960, $result['slider']['focusProxy']);
        self::assertTrue($result['slider']['autoplayInterruptNormalized']);
        self::assertTrue($result['slider']['pointerCtaClickPreserved']);
        self::assertTrue($result['slider']['pointerTitleClickPreserved']);
        self::assertTrue($result['slider']['clickSuppressed']);
        self::assertTrue($result['slider']['cleaned']);

        self::assertSame(
            ['top top+=80', 'top top+=95'],
            $result['stack']['starts']
        );
        self::assertSame(4, $result['stack']['creates']);
        self::assertGreaterThanOrEqual(2, $result['stack']['refreshes']);
        self::assertTrue($result['stack']['disabledForPendingBatch']);
    }
}
