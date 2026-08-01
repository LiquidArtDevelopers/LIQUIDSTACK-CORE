<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Modules\ModuleWebAdminNavigationProviderInterface;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;

final class BlogWebAdminNavigationProvider implements
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
            'Gestionar Blog',
            '/blog',
            'blog.articles.view'
        );
    }
}
