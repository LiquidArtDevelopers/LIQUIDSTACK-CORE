<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BlogPublicArticleAdapterContractTest extends TestCase
{
    public function testManagedAdapterOwnsTheProjectArticleBootstrap(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root
            . '/modules/blog/resources/project/App/app/'
            . '_moduleBlogPublicArticle.php';
        $source = (string) file_get_contents($path);

        foreach ([
            'BlogPublicArticleViewModel',
            'BlogPublicArticleShellContext',
            '$blogArticleShell->nonce()',
            '$blogArticleShell->publicRuntimeUrl()',
            'ProjectEnvironmentLoader',
            'ProjectRuntimeProfile::fromEnvironment(',
            "manifest['src/js/blogArticle.js']",
            'config/languages/global/',
            '$articleMain = $blogArticle->mainHtml()',
            '$articleHero = $blogArticle->headerHtml()',
            '$articleCustomCss = $blogArticle->customCss()',
            '$relatedArticles = $blogArticle->relatedArticles()',
        ] as $contract) {
            self::assertStringContainsString($contract, $source);
        }

        foreach ([
            '<!DOCTYPE',
            'controller(',
            'header(',
            'random_bytes(',
            'new PDO',
            '_blogPublicSecurity.php',
            'method_exists(',
            'project_specific_brand',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }
}
