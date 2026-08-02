<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\ModuleWebAdminNavigationProviderInterface;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;

/** Category navigation stays hidden until its own capability is available. */
final class BlogCategoryWebAdminNavigationProvider implements
    ModuleWebAdminNavigationProviderInterface
{
    public static function moduleId(): string
    {
        return 'blog';
    }

    public function webAdminNavigationItem(): WebAdminNavigationItem
    {
        return new WebAdminNavigationItem(
            self::moduleId(),
            'Gestionar categorías',
            '/blog/categories',
            'blog.categories.view'
        );
    }
}
