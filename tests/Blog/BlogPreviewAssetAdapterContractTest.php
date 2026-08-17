<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Preview\BlogPreviewAssetAdapterLoader;
use App\Core\Blog\Preview\BlogPreviewAssetContext;
use App\Core\Blog\Preview\BlogPreviewAssetSet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlogPreviewAssetAdapterContractTest extends TestCase
{
    public function testDevelopmentAdapterDeclaresViteAssetsAndClosedCsp(): void
    {
        $root = $this->projectRoot();
        $adapter = $root . '/App/config/modules/blog-preview-assets.php';
        file_put_contents($adapter, <<<'PHP'
<?php
use App\Core\Blog\Preview\BlogPreviewAssetAdapterInterface;
use App\Core\Blog\Preview\BlogPreviewAssetContext;
use App\Core\Blog\Preview\BlogPreviewAssetSet;

return new class implements BlogPreviewAssetAdapterInterface {
    public function resolve(BlogPreviewAssetContext $context): BlogPreviewAssetSet
    {
        return new BlogPreviewAssetSet(
            $context,
            stylesheets: [
                'http://localhost:5173/src/scss/blogArticle.scss?direct',
            ],
            moduleScripts: [
                'http://localhost:5173/@vite/client',
                'http://localhost:5173/src/js/blogArticle.js',
            ],
            deferredScripts: ['/assets/modules/blog/blog-public.js'],
            connectSources: [
                'http://localhost:5173',
                'ws://localhost:5173',
            ]
        );
    }
};
PHP);
        file_put_contents(
            $root . '/App/config/modules/blog.php',
            "<?php\nreturn [\n"
                . "'public_paths' => ['es' => '/noticias'],\n"
                . "'preview_asset_adapter' => "
                . "'App/config/modules/blog-preview-assets.php',\n];\n"
        );

        try {
            $config = (new BlogConfigLoader())->load($root, ['es']);
            self::assertSame(
                'App/config/modules/blog-preview-assets.php',
                $config->previewAssetAdapter()
            );
            $context = new BlogPreviewAssetContext($root, true);
            $assets = (new BlogPreviewAssetAdapterLoader())->resolve(
                $config->previewAssetAdapterPath(),
                $context
            );

            self::assertSame([
                'http://localhost:5173/@vite/client',
                'http://localhost:5173/src/js/blogArticle.js',
            ], $assets->moduleScripts());
            self::assertSame([
                'http://localhost:5173/src/scss/blogArticle.scss?direct',
            ], $assets->stylesheets());
            $nonce = 'abcdefghijklmnopQRSTUVWX';
            $csp = $assets->contentSecurityPolicy($nonce);
            self::assertStringContainsString(
                "script-src 'self' http://localhost:5173 'nonce-{$nonce}'",
                $csp
            );
            self::assertStringContainsString(
                "script-src-elem 'self' http://localhost:5173 "
                    . "'nonce-{$nonce}'",
                $csp
            );
            self::assertStringContainsString(
                "style-src 'self' http://localhost:5173 'nonce-{$nonce}'",
                $csp
            );
            self::assertStringContainsString(
                "style-src-elem 'self' http://localhost:5173 'nonce-{$nonce}'",
                $csp
            );
            self::assertStringContainsString("style-src-attr 'none'", $csp);
            self::assertStringContainsString(
                "connect-src 'self' http://localhost:5173 ws://localhost:5173",
                $csp
            );
            self::assertStringContainsString("frame-ancestors 'self'", $csp);
            self::assertStringNotContainsString("'unsafe-eval'", $csp);
            self::assertStringNotContainsString("'unsafe-inline'", $csp);
        } finally {
            $this->removeProject($root);
        }
    }

    public function testProductionAdapterCanReturnManifestResolvedNames(): void
    {
        $context = new BlogPreviewAssetContext(dirname(__DIR__, 2), false);
        $assets = new BlogPreviewAssetSet(
            context: $context,
            stylesheets: ['/assets/css/blogArticle-CM5f.css'],
            moduleScripts: ['/assets/js/blogArticle-D8f3.js'],
            deferredScripts: ['/assets/modules/blog/blog-public.js'],
            editorStylesheets: [
                '/assets/css/blogEditorTheme-A1b2.css',
                '/assets/css/blogEditorTheme-A1b2.css',
            ]
        );

        self::assertSame(
            ['/assets/css/blogArticle-CM5f.css'],
            $assets->stylesheets()
        );
        self::assertSame(
            ['/assets/css/blogEditorTheme-A1b2.css'],
            $assets->editorStylesheets()
        );
        self::assertNotContains(
            '/assets/css/blogEditorTheme-A1b2.css',
            $assets->stylesheets()
        );
        self::assertStringContainsString(
            "script-src 'self'",
            $assets->contentSecurityPolicy()
        );
        self::assertStringNotContainsString(
            'localhost:5173',
            $assets->contentSecurityPolicy()
        );
    }

    public function testInsecureRemoteDevelopmentOriginIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlogPreviewAssetSet(
            new BlogPreviewAssetContext(dirname(__DIR__, 2), true),
            moduleScripts: ['http://example.test/src/js/blogArticle.js']
        );
    }

    public function testCspSourceInjectionShapeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlogPreviewAssetSet(
            new BlogPreviewAssetContext(dirname(__DIR__, 2), false),
            connectSources: ['https://example.test;script-src']
        );
    }

    public function testEditorStylesheetsRejectNonFlatViteCssPaths(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlogPreviewAssetSet(
            new BlogPreviewAssetContext(dirname(__DIR__, 2), false),
            editorStylesheets: ['/assets/css/nested/editor.css']
        );
    }

    private function projectRoot(): string
    {
        $root = sys_get_temp_dir() . '/ls-blog-preview-'
            . bin2hex(random_bytes(8));
        self::assertTrue(mkdir(
            $root . '/App/config/modules',
            0777,
            true
        ));

        return $root;
    }

    private function removeProject(string $root): void
    {
        $files = [
            $root . '/App/config/modules/blog-preview-assets.php',
            $root . '/App/config/modules/blog.php',
        ];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($root . '/App/config/modules');
        @rmdir($root . '/App/config');
        @rmdir($root . '/App');
        @rmdir($root);
    }
}
