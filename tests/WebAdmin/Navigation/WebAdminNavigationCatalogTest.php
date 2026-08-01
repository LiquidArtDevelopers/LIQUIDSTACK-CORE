<?php

declare(strict_types=1);

use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebAdminNavigationCatalogTest extends TestCase
{
    public function testItemExposesOnlyValidatedImmutableValues(): void
    {
        $item = new WebAdminNavigationItem(
            'blog',
            'Noticias & publicaciones',
            '/blog/posts',
            'blog.articles.view'
        );

        self::assertSame('blog', $item->module());
        self::assertSame('Noticias & publicaciones', $item->label());
        self::assertSame('/blog/posts', $item->suffix());
        self::assertSame(
            'blog.articles.view',
            $item->requiredCapability()
        );
    }

    #[DataProvider('invalidItemProvider')]
    public function testItemRejectsUnsafeLabelsPathsAndCapabilities(
        string $module,
        string $label,
        string $suffix,
        string $capability
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new WebAdminNavigationItem(
            $module,
            $label,
            $suffix,
            $capability
        );
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function invalidItemProvider(): array
    {
        return [
            'invalid module' => [
                'Blog', 'Noticias', '/blog', 'blog.articles.view',
            ],
            'empty label' => [
                'blog', '', '/blog', 'blog.articles.view',
            ],
            'padded label' => [
                'blog', ' Noticias', '/blog', 'blog.articles.view',
            ],
            'html label' => [
                'blog', '<img src=x>', '/blog', 'blog.articles.view',
            ],
            'control in label' => [
                'blog', "Noticias\nprivadas", '/blog', 'blog.articles.view',
            ],
            'invalid utf8 label' => [
                'blog', "Noticias\xFF", '/blog', 'blog.articles.view',
            ],
            'absolute URL' => [
                'blog', 'Noticias', 'https://evil.test', 'blog.articles.view',
            ],
            'scheme-relative URL' => [
                'blog', 'Noticias', '//evil.test', 'blog.articles.view',
            ],
            'root is not a child' => [
                'blog', 'Noticias', '/', 'blog.articles.view',
            ],
            'query' => [
                'blog', 'Noticias', '/blog?admin=1', 'blog.articles.view',
            ],
            'fragment' => [
                'blog', 'Noticias', '/blog#admin', 'blog.articles.view',
            ],
            'traversal' => [
                'blog', 'Noticias', '/blog/../admin', 'blog.articles.view',
            ],
            'encoded separator' => [
                'blog', 'Noticias', '/blog%2Fadmin', 'blog.articles.view',
            ],
            'html path' => [
                'blog', 'Noticias', '/blog<script>', 'blog.articles.view',
            ],
            'invalid capability' => [
                'blog', 'Noticias', '/blog', 'blog.<script>',
            ],
            'foreign capability' => [
                'blog', 'Noticias', '/blog', 'webadmin.access',
            ],
        ];
    }

    public function testCatalogDeduplicatesAndSortsDeterministically(): void
    {
        $blog = new WebAdminNavigationItem(
            'blog',
            'Noticias',
            '/blog',
            'blog.articles.view'
        );
        $media = new WebAdminNavigationItem(
            'media',
            'Medios',
            '/media',
            'media.library.view'
        );

        $catalog = new WebAdminNavigationCatalog([$media, $blog, $blog]);

        self::assertSame([$blog, $media], $catalog->items());
    }

    public function testCatalogRejectsConflictingDestinationOwnership(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting');

        new WebAdminNavigationCatalog([
            new WebAdminNavigationItem(
                'blog',
                'Noticias',
                '/content',
                'blog.articles.view'
            ),
            new WebAdminNavigationItem(
                'media',
                'Medios',
                '/content',
                'media.library.view'
            ),
        ]);
    }

    public function testCatalogRejectsNonListAndNonItemInput(): void
    {
        try {
            new WebAdminNavigationCatalog(['item' => 'invalid']);
            self::fail('An associative navigation catalog must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        new WebAdminNavigationCatalog(['invalid']);
    }
}
