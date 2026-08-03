<?php

declare(strict_types=1);

use App\Core\WebAdmin\Http\WebAdminPageAssets;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellRenderer;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use PHPUnit\Framework\TestCase;

final class WebAdminShellRendererTest extends TestCase
{
    public function testAuthenticatedShellComposesNavigationInspectorAndAssets(): void
    {
        $context = new WebAdminShellContext(
            basePath: '/admin',
            logoutCsrf: 'private-csrf',
            activePath: '/blog/editor',
            showUsersLink: true,
            moduleNavigation: [
                new WebAdminNavigationItem(
                    'blog',
                    'Art&iacute;culos',
                    '/blog',
                    'blog.articles.view'
                ),
                new WebAdminNavigationItem(
                    'feature',
                    'Ruta reservada',
                    '/users/export',
                    'feature.users.export'
                ),
            ],
            trustedInspectorHtml: '<section aria-labelledby="tools-title">'
                . '<h2 id="tools-title">SEO</h2></section>',
            assets: new WebAdminPageAssets(
                [
                    '/assets/modules/blog/blog-admin.css',
                    '/assets/modules/blog/blog-admin.css',
                ],
                ['/assets/modules/blog/blog-editor.js']
            )
        );

        $html = (new WebAdminShellRenderer())->render(
            'Editor &amp; SEO',
            '<article aria-labelledby="page-title">'
                . '<h1 id="page-title">Editar</h1></article>',
            $context
        );

        self::assertSame(1, substr_count($html, '<main'));
        self::assertSame(1, substr_count($html, 'id="webadmin-main"'));
        self::assertStringContainsString(
            'href="/admin/blog" aria-current="page"',
            $html
        );
        self::assertStringNotContainsString(
            'href="/admin/users" aria-current="page"',
            $html
        );
        self::assertStringNotContainsString('/admin/users/export', $html);
        self::assertStringContainsString(
            'data-webadmin-shell-inspector',
            $html
        );
        self::assertStringContainsString(
            'data-webadmin-inspector-toggle',
            $html
        );
        self::assertStringContainsString(
            'aria-controls="webadmin-inspector" aria-expanded="true"',
            $html
        );
        self::assertStringContainsString(
            'data-webadmin-shell-toggle',
            $html
        );
        self::assertStringContainsString(
            'action="/admin/logout"',
            $html
        );
        self::assertStringContainsString(
            'name="csrf" value="private-csrf"',
            $html
        );
        self::assertSame(1, substr_count(
            $html,
            '/assets/modules/blog/blog-admin.css'
        ));
        self::assertSame(1, substr_count(
            $html,
            '/assets/modules/blog/blog-editor.js'
        ));
        self::assertStringContainsString(
            '<title>Editor &amp; SEO</title>',
            $html
        );
    }

    public function testShellWithoutInspectorOrLogoutOmitsBothControls(): void
    {
        $html = (new WebAdminShellRenderer())->render(
            'Resumen',
            '<article aria-labelledby="title"><h1 id="title">Resumen</h1>'
                . '</article>',
            new WebAdminShellContext('/admin', null)
        );

        self::assertStringNotContainsString(
            'data-webadmin-shell-inspector',
            $html
        );
        self::assertStringNotContainsString(
            'data-webadmin-inspector-toggle',
            $html
        );
        self::assertStringNotContainsString('action="/admin/logout"', $html);
        self::assertStringContainsString(
            'href="/admin" aria-current="page"',
            $html
        );
    }

    public function testOnlyTheMostSpecificVisibleNavigationItemIsCurrent(): void
    {
        $html = (new WebAdminShellRenderer())->render(
            'Categor&iacute;as',
            '<article><h1>Categor&iacute;as</h1></article>',
            new WebAdminShellContext(
                '/admin',
                null,
                '/blog/categories/edit',
                false,
                [
                    new WebAdminNavigationItem(
                        'blog',
                        'Art&iacute;culos',
                        '/blog',
                        'blog.articles.view'
                    ),
                    new WebAdminNavigationItem(
                        'blog',
                        'Categor&iacute;as',
                        '/blog/categories',
                        'blog.categories.view'
                    ),
                ]
            )
        );

        self::assertSame(1, substr_count($html, 'aria-current="page"'));
        self::assertStringContainsString(
            'href="/admin/blog/categories" aria-current="page"',
            $html
        );
        self::assertStringNotContainsString(
            'href="/admin/blog" aria-current="page"',
            $html
        );
    }

    /**
     * @dataProvider invalidAssetProvider
     * @param list<string> $stylesheets
     * @param list<string> $scripts
     */
    public function testPageAssetsRejectExternalOrNonModulePaths(
        array $stylesheets,
        array $scripts
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new WebAdminPageAssets($stylesheets, $scripts);
    }

    /** @return array<string, array{list<string>, list<string>}> */
    public static function invalidAssetProvider(): array
    {
        return [
            'external stylesheet' => [
                ['https://example.test/blog.css'],
                [],
            ],
            'project-owned stylesheet' => [
                ['/assets/css/app.css'],
                [],
            ],
            'traversal' => [
                ['/assets/modules/blog/../blog.css'],
                [],
            ],
            'wrong script extension' => [
                [],
                ['/assets/modules/blog/blog.css'],
            ],
        ];
    }

    public function testContextDebugInfoNeverExposesCsrfOrInspectorHtml(): void
    {
        $context = new WebAdminShellContext(
            '/admin',
            'never-log-this-csrf',
            '/users',
            true,
            [],
            '<p>never-log-this-inspector</p>'
        );
        ob_start();
        var_dump($context);
        $debug = (string) ob_get_clean();

        self::assertStringNotContainsString('never-log-this-csrf', $debug);
        self::assertStringNotContainsString(
            'never-log-this-inspector',
            $debug
        );
    }
}
