<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class SectionBlogSlider02CtaContractTest extends TestCase
{
    public function testEveryCardHasALocalizedAndAccessibleArticleCta(): void
    {
        $previousRoot = Paths::projectRoot();
        $previousCwd = getcwd();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(dirname(__DIR__, 2));

        try {
            $html = controller('sectionBlogSlider02', 0, [
                'cta_label' => 'Read article',
                'items_data' => [[
                    'url' => '/en/news/matrix-choice',
                    'h1' => 'Matrix & the architecture of choice',
                    'excerpt' => 'A bounded excerpt for the resource contract.',
                    'published_at' => '2026-08-14T09:00:00+00:00',
                ]],
            ]);

            self::assertSame(
                1,
                substr_count($html, 'class="sectionBlogSlider02-cta"')
            );
            self::assertStringContainsString(
                'href="/en/news/matrix-choice"',
                $html
            );
            self::assertStringContainsString(
                'aria-label="Read article: Matrix &amp; the architecture of choice"',
                $html
            );
            self::assertStringContainsString(
                '<span>Read article</span><span aria-hidden="true">&rarr;</span>',
                $html
            );
            self::assertMatchesRegularExpression(
                '/<h3\b[^>]*><a[^>]*>Matrix &amp; the architecture of choice<\/a><\/h3>/',
                $html
            );
        } finally {
            Paths::setProjectRoot($previousRoot);
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }
        }
    }

    public function testCtaAndTitleStylesRemainVisibleAndMotionSafe(): void
    {
        $scss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_sectionBlogSlider02.scss'
        );

        foreach ([
            '.sectionBlogSlider02-cta {',
            'margin-top: auto;',
            'background: c.$color02;',
            'font-weight: 700;',
            'font-size: inherit;',
            'letter-spacing: inherit;',
            'span {',
            'color: inherit;',
            "span[aria-hidden='true']",
            'padding: 2rem 0;',
            'padding: 4rem 0;',
            'border-inline-start: 0.25rem solid c.$color02;',
            'font-size: clamp(1.1rem, 4.5vw, 1.3rem);',
        ] as $contract) {
            self::assertStringContainsString($contract, $scss);
        }

        self::assertMatchesRegularExpression(
            "/@media \(prefers-reduced-motion: reduce\)[\\s\\S]*"
                . "\\.sectionBlogSlider02-cta[\\s\\S]*transition: none;/",
            $scss
        );
    }

    private static function moduleProjectRoot(): string
    {
        return dirname(__DIR__, 2)
            . '/modules/blog/resources/project';
    }
}
