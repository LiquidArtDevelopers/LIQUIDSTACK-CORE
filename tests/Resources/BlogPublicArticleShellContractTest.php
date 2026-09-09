<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BlogPublicArticleShellContractTest extends TestCase
{
    public function testViewComposesTheTypedArticleInsideTheProjectShell(): void
    {
        $source = $this->source('App/views/blog-article.php');

        foreach ([
            "require __DIR__ . '/../app/_moduleBlogPublicArticle.php'",
            "include_once __DIR__ . '/../includes/_globalHead.php'",
            "include_once __DIR__ . '/../includes/_globalBody.php'",
            "include __DIR__ . '/../includes/_nav.php'",
            "include __DIR__ . '/../includes/_footer.php'",
            'id="smooth-wrapper"',
            'id="smooth-content"',
            'class="blogArticleMain blog-article artBlogArticle01',
            'data-blog-analytics-enabled="true"',
            'data-blog-analytics-retention-days=',
            'data-blog-analytics-session-timeout=',
            'data-blog-analytics-page-grant=',
            '$articleCustomCss',
            '$cspNonce',
            '$blogPublicRuntimeUrl',
            '$articleHero',
            '$articleTaxonomiesHtml',
            '$articleMain',
            "controller('sectionBlogRelated01'",
            "controller('moduleButtonType04'",
            "'{cta-link-attributes}' => ' rel=\"up\"'",
        ] as $contract) {
            self::assertStringContainsString($contract, $source);
        }

        $doctype = strpos($source, '<!DOCTYPE html>');
        $bootstrap = strpos($source, '_moduleBlogPublicArticle.php');
        $hero = strpos($source, '<?= $articleHero ?>');
        $taxonomies = strpos($source, '<?= $articleTaxonomiesHtml ?>');
        $article = strpos($source, '<?= $articleMain ?>');
        $related = strpos($source, "controller('sectionBlogRelated01'");
        $footer = strpos($source, "../includes/_footer.php");

        foreach ([
            $doctype,
            $bootstrap,
            $hero,
            $taxonomies,
            $article,
            $related,
            $footer,
        ] as $position) {
            self::assertIsInt($position);
        }
        self::assertLessThan($doctype, $bootstrap);
        self::assertLessThan($taxonomies, $hero);
        self::assertLessThan($article, $taxonomies);
        self::assertLessThan($related, $article);
        self::assertLessThan($footer, $related);

        foreach ([
            'AIWA',
            'AIWAsesores',
            'ARRO',
            'arro-comunicacion',
            'aiwasesores.com',
            'new PDO',
            'header(',
            '$_ENV',
            'getenv(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testJavascriptEntryOwnsGlobalAndLanguageRuntimes(): void
    {
        $source = $this->source('src/js/blogArticle.js');

        foreach ([
            "import '../scss/blogArticle.scss'",
            "import './_global.js'",
            "from './resources/_languagePreference.mjs'",
            'bindLanguageNavigation(window, document)',
            'import.meta.hot.dispose(unbindLanguageNavigation)',
        ] as $contract) {
            self::assertStringContainsString($contract, $source);
        }

        foreach (['localStorage', 'sessionStorage', 'document.cookie'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testStylesheetUsesOnlyNeutralThemeAndBlogResources(): void
    {
        $source = $this->source('src/scss/blogArticle.scss');

        foreach ([
            "@use './config' as c",
            "@use './global'",
            "@use './resources/hero00'",
            "@use './resources/hero06'",
            "@use './resources/hero07'",
            "@use './resources/artBlogArticle01'",
            "@use './resources/moduleButtonType04'",
            "@use './resources/sectionBlogRelated01'",
            'body.blog-article-page',
            '.blog-article',
            '.artBlogArticle01-footer--newsIndex',
        ] as $contract) {
            self::assertStringContainsString($contract, $source);
        }

        foreach ([
            'Aiwa',
            'AIWA',
            'Arro',
            'ARRO',
            'preFooter',
            '$color04',
            '$color05',
            'http://',
            'https://',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2)
            . '/modules/blog/resources/project/'
            . $relative;
        $source = file_get_contents($path);
        self::assertIsString($source, $relative);

        return $source;
    }
}
