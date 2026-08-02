<?php

declare(strict_types=1);

namespace Tests\Blog\Categories;

use App\Core\Modules\Blog\BlogCategoryWebAdminNavigationProvider;
use PHPUnit\Framework\TestCase;

final class BlogCategoryWebAdminNavigationProviderTest extends TestCase
{
    public function testItDeclaresTheCapabilityBoundedChildNavigation(): void
    {
        $item = (new BlogCategoryWebAdminNavigationProvider())
            ->webAdminNavigationItem();

        self::assertSame('blog', $item->module());
        self::assertSame('Gestionar categorías', $item->label());
        self::assertSame('/blog/categories', $item->suffix());
        self::assertSame(
            'blog.categories.view',
            $item->requiredCapability()
        );
    }
}
