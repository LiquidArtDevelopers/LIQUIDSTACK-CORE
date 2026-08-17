<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class ModuleBlogPagination01NeutralRootContractTest extends TestCase
{
    public function testItAlwaysUsesANeutralRootAndKeepsLinkSemantics(): void
    {
        foreach ([[], ['root_tag' => 'nav']] as $extra) {
            $html = $this->render($extra);

            self::assertStringStartsWith('<div ', trim($html));
            self::assertStringEndsWith('</div>', trim($html));
            self::assertStringNotContainsString('<nav', $html);
            self::assertStringNotContainsString('role="navigation"', $html);
            self::assertStringNotContainsString('role="group"', $html);
            self::assertStringContainsString(
                'class="moduleBlogPagination01 ',
                $html
            );
            self::assertStringContainsString('rel="prev"', $html);
            self::assertStringContainsString('rel="next"', $html);
            self::assertStringContainsString('aria-current="page"', $html);
            self::assertSame(3, substr_count($html, '<li>'));
        }
    }

    /** @param array<string, mixed> $overrides */
    private function render(array $overrides): string
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = (string) getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(self::coreRoot());

        try {
            return controller('moduleBlogPagination01', 0, array_replace([
                'id_prefix' => 'blog-pages',
                'previous_url' => '/es/noticias/pagina/1',
                'next_url' => '/es/noticias/pagina/3',
                'pages_data' => [
                    ['page' => 1, 'url' => '/es/noticias/pagina/1'],
                    ['page' => 2, 'current' => true],
                    ['page' => 3, 'url' => '/es/noticias/pagina/3'],
                ],
                'labels' => [
                    'previous' => 'Anterior',
                    'next' => 'Siguiente',
                    'page' => 'Pagina',
                ],
            ], $overrides));
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
