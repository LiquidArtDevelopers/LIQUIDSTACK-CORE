<?php

declare(strict_types=1);

use App\Core\Modules\Blog\BlogWebAdminNavigationProvider;
use PHPUnit\Framework\TestCase;

final class BlogWebAdminNavigationProviderTest extends TestCase
{
    public function testContributesOnlyTheCapabilityGatedBlogChild(): void
    {
        $provider = new BlogWebAdminNavigationProvider();
        $item = $provider->webAdminNavigationItem();

        self::assertSame('blog', $provider::moduleId());
        self::assertSame('blog', $item->module());
        self::assertSame('Gestionar Blog', $item->label());
        self::assertSame('/blog', $item->suffix());
        self::assertSame(
            'blog.articles.view',
            $item->requiredCapability()
        );
    }
}
