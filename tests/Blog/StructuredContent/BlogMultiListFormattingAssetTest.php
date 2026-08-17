<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogMultiListFormattingAssetTest extends TestCase
{
    public function testFormattingAcrossListItemsKeepsTheListContract(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([
            'node',
            $root . '/tests/Blog/StructuredContent/fixtures/'
                . 'blog-editor-multi-list-format-harness.mjs',
            $root . '/modules/blog/published/assets/blog-editor.js',
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame($result['originalShape'], $result['finalShape']);
        self::assertTrue($result['strongRemovedGlobally']);
        self::assertTrue($result['unsafeRejected']);
        self::assertSame('/neo', $result['linkAfterClear']['href']);
        self::assertSame([], $result['linkAfterClear']['marks']);
    }
}
