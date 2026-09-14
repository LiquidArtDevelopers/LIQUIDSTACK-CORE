<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;

final class BlogPublicResponsiveCssContractTest extends TestCase
{
    private string $publicCss;
    private string $projectScss;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->publicCss = $this->read(
            $root . '/modules/blog/published/assets/blog-public.css'
        );
        $this->projectScss = $this->read(
            $root . '/modules/blog/resources/project/src/scss/resources/'
                . '_artBlogArticle01.scss'
        );
    }

    public function testPublicCanvasAndSectionsAreMobileFirst(): void
    {
        foreach ([
            'background: #fff',
            '.blogDocument--background-white',
            'flex-direction: column',
            'align-items: center',
            'justify-content: flex-start',
            'align-items: stretch',
            '.blogDocument__column:has(> :only-child)',
            'row-gap: 2rem',
            'row-gap: 3rem',
            'row-gap: 5rem',
            'padding-inline: 1.5rem',
            'main:has(.blogDocument--layout)',
            '.blogDocument:not(.blogDocument--layout) .blogDocument__section',
            'padding: 2rem 1.5rem',
            'padding: 3rem',
            'padding: 4rem',
            '> .blogDocument__image.blogDocument__module--width-full',
            '.blogDocument--layout .blogDocument__section > *',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->publicCss);
        }
        self::assertMatchesRegularExpression(
            '/main:has\(\.blogDocument--layout\)\s*\{[^}]*'
                . 'width:\s*100%;[^}]*max-width:\s*none;[^}]*padding:\s*0;/s',
            $this->publicCss
        );
        self::assertMatchesRegularExpression(
            '/\.blogDocument--layout \.blogDocument__section\s*\{[^}]*'
                . 'max-width:\s*none;[^}]*padding:\s*2rem 0;'
                . '[^}]*row-gap:\s*2rem;/s',
            $this->publicCss
        );

        self::assertMatchesRegularExpression(
            '/\.blogDocument__module--width-80\s*\{\s*width:\s*100%;/s',
            $this->publicCss
        );
        self::assertMatchesRegularExpression(
            '/@media \(min-width: 48rem\).*?'
                . '\.blogDocument__module--width-80\s*\{\s*width:\s*90%;'
                . '.*?\.blogDocument__module--width-60\s*\{\s*width:\s*80%;'
                . '.*?\.blogDocument__module--width-40\s*\{\s*width:\s*60%;/s',
            $this->publicCss
        );
        self::assertMatchesRegularExpression(
            '/@media \(min-width: 64rem\).*?'
                . '\.blogDocument__module--width-80\s*\{\s*width:\s*80%;'
                . '.*?\.blogDocument__module--width-60\s*\{\s*width:\s*60%;'
                . '.*?\.blogDocument__module--width-40\s*\{\s*width:\s*40%;/s',
            $this->publicCss
        );
    }

    public function testDirectFullModulesKeepSectionInsetExceptImages(): void
    {
        self::assertMatchesRegularExpression(
            '/\.blogDocument__section\s*>\s*\.blogDocument__module\s*\{'
                . '[^}]*padding-inline:\s*1\.5rem;/s',
            $this->publicCss
        );
        self::assertMatchesRegularExpression(
            '/\.blogDocument__section\s*'
                . '>\s*\.blogDocument__image\.blogDocument__module--width-full'
                . '\s*\{[^}]*padding-inline:\s*0;/s',
            $this->publicCss
        );
        self::assertMatchesRegularExpression(
            '/@media \(min-width: 48rem\).*?'
                . '\.blogDocument__section\s*'
                . '>\s*\.blogDocument__module:not\('
                . '\.blogDocument__module--width-full\)\s*\{'
                . '[^}]*padding-inline:\s*0;/s',
            $this->publicCss
        );
        self::assertStringContainsString(
            '> .blogDocument__module:not(.blogDocument__module--width-full)',
            $this->projectScss
        );
    }

    public function testPublicPresentationHasClosedThemeAndRgbaProjection(): void
    {
        foreach (['00', '01', '02', '03', '04', '05'] as $suffix) {
            self::assertStringContainsString(
                '.blogDocument__module--text-color' . $suffix,
                $this->publicCss
            );
            self::assertStringContainsString(
                '.blogDocument__container--background-color' . $suffix,
                $this->publicCss
            );
        }
        foreach (['00', '01', '02', '03'] as $suffix) {
            self::assertStringContainsString(
                'c.$color' . $suffix,
                $this->projectScss
            );
        }
        foreach (['04', '05'] as $suffix) {
            self::assertStringContainsString(
                '--ls-blog-color' . $suffix,
                $this->projectScss
            );
        }

        foreach ([
            'data-blog-text-rgba type(<color>)',
            'data-blog-background-rgba type(<color>)',
            'data-blog-inline-text-rgba type(<color>)',
            'data-blog-inline-background-rgba type(<color>)',
            '.blogDocument__image--radius-none',
            '.blogDocument__image--radius-small',
            '.blogDocument__image--radius-medium',
            '.blogDocument__image--radius-large',
            '.blogDocument__module--size-s',
            '.blogDocument__module--size-m',
            '.blogDocument__module--size-l',
            '.blogDocument__module--size-xl',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->publicCss);
        }
    }

    public function testProjectResourceKeepsV2WideAndV1Constrained(): void
    {
        foreach ([
            '&-body {',
            '&--layout {',
            'width: 100%',
            '&:not(.blogDocument--layout)',
            'row-gap: 2rem',
            'row-gap: 3rem',
            'row-gap: 5rem',
            '@media (min-width: c.$tablet)',
            '@media (min-width: c.$desktop)',
            '> .artBlogArticle01-body',
            'padding: 2rem 1.5rem',
            'padding: 3rem',
            'padding: 4rem',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->projectScss);
        }
    }

    public function testUnifiedTextListsQuotesAndCalloutsStayReadable(): void
    {
        foreach ([
            '.blogDocument__textHeading',
            '.blogDocument__textList',
            'padding-inline-start: 1.5rem',
            '.blogDocument__textCallout',
            '.blogDocument__textQuote',
            'border-inline-start: 0.2rem solid',
            'font-style: italic',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->publicCss);
            self::assertStringContainsString($contract, $this->projectScss);
        }
        foreach ([
            '.blogDocument__textCalloutContent',
            '.blogDocument__textQuoteContent',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->publicCss);
        }
        self::assertGreaterThanOrEqual(
            2,
            substr_count($this->projectScss, '&Content')
        );

        self::assertMatchesRegularExpression(
            '/\.blogDocument__callout\s*\{[^}]*border:\s*0;/s',
            $this->publicCss
        );
        self::assertMatchesRegularExpression(
            '/\.blogDocument__quote\s*\{[^}]*'
                . 'border-inline-start:\s*0\.2rem/s',
            $this->publicCss
        );
    }

    public function testEveryPublicListKeepsNativeMarkersAndBodyRhythm(): void
    {
        foreach ([
            '.blogDocument__list',
            '.blogDocument__textList',
            '.blogDocument__text--custom :is(ul, ol)',
            '.blogDocument__embedContent :is(ul, ol)',
            'row-gap: 1.2rem',
            'padding-block: 0.75rem',
            'padding-inline-start: 1.5rem',
            'list-style-position: outside',
            'font: inherit',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->publicCss);
        }

        self::assertMatchesRegularExpression(
            '/:where\(ul\)\.blogDocument__list,.*?\{\s*'
                . 'list-style-type:\s*disc;/s',
            $this->publicCss
        );
        self::assertMatchesRegularExpression(
            '/:where\(ol\)\.blogDocument__list,.*?\{\s*'
                . 'list-style-type:\s*decimal;/s',
            $this->publicCss
        );
        self::assertStringContainsString(
            '.blogDocument__text--custom :where(ul)',
            $this->publicCss
        );
        self::assertStringContainsString(
            '.blogDocument__embedContent :where(ol)',
            $this->publicCss
        );
        self::assertLessThan(
            strpos($this->publicCss, '.blogDocument__list--marker-upper-alpha'),
            strpos($this->publicCss, ':where(ul).blogDocument__list')
        );
        self::assertMatchesRegularExpression(
            '/li::marker[^}]*color:\s*var\('
                . '--ls-blog-color02,\s*#24658e\);[^}]*font-size:\s*1em;/s',
            $this->publicCss
        );
        self::assertMatchesRegularExpression(
            '/@media \(min-width:\s*48rem\).*?padding-block:\s*1rem;'
                . '.*?padding-inline-start:\s*2rem;/s',
            $this->publicCss
        );

        foreach ([
            '.blogDocument__text--custom ul',
            '.blogDocument__text--custom li',
            '.blogDocument__embedContent p',
            '.blogDocument__embedContent ol',
            'row-gap: 1.2rem',
            'color: c.$color02',
            'font-family: c.$fuente02',
            'font-size: clamp(1rem, 1.8vw, 1.15rem)',
            '@media (min-width: c.$tablet)',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->projectScss);
        }
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
