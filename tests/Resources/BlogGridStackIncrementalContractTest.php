<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class BlogGridStackIncrementalContractTest extends TestCase
{
    public function testZeroToManySynchronizesTheResourceModifier(): void
    {
        $root = self::coreRoot();
        $script = $root
            . '/tests/Resources/fixtures/blog-grid-stack-incremental-harness.mjs';
        $command = sprintf(
            'node %s %s 2>&1',
            escapeshellarg($script),
            escapeshellarg($root)
        );
        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertSame(
            ['grid' => 3, 'stack' => 2],
            json_decode(
                implode("\n", $output),
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );

        foreach ([
            'moduleBlogGrid02' => 'grid',
            'sectionBlogStack01' => 'list',
        ] as $resource => $container) {
            $scss = (string) file_get_contents(
                self::moduleProjectRoot()
                    . "/src/scss/resources/_{$resource}.scss"
            );
            self::assertStringNotContainsString(
                "&.{$resource}--items-0",
                $scss
            );
            self::assertStringContainsString(
                ":not(:has(.{$resource}-item))",
                $scss,
                $container
            );
        }
    }

    public function testSuccessiveLoadUrlIsStrictlyRootRelative(): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = (string) getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(self::coreRoot());

        $item = [[
            'url' => '/es/noticias/matrix',
            'h1' => 'Matrix',
            'excerpt' => 'Entrada focal.',
            'published_at' => '2026-08-14 10:00:00',
        ]];

        try {
            foreach (['moduleBlogGrid02', 'sectionBlogStack01'] as $resource) {
                $relative = controller($resource, 0, [
                    'items_data' => $item,
                    'next_url' => '/es/noticias?page=2&category=matrix',
                ]);
                self::assertStringContainsString(
                    'data-blog-collection-next',
                    $relative,
                    $resource
                );
                self::assertStringContainsString(
                    'href="/es/noticias?page=2&amp;category=matrix"',
                    $relative,
                    $resource
                );

                foreach ([
                    'https://example.test/es/noticias?page=2',
                    '//example.test/es/noticias?page=2',
                    '?page=2',
                    'javascript:alert(1)',
                ] as $unsafe) {
                    $html = controller($resource, 1, [
                        'items_data' => $item,
                        'next_url' => $unsafe,
                    ]);
                    self::assertStringNotContainsString(
                        'data-blog-collection-next',
                        $html,
                        $resource . ': ' . $unsafe
                    );
                }
            }
        } finally {
            Paths::setProjectRoot($previousRoot);
            if ($previousCwd !== '') {
                chdir($previousCwd);
            }
        }
    }

    private static function coreRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function moduleProjectRoot(): string
    {
        return self::coreRoot() . '/modules/blog/resources/project';
    }
}
