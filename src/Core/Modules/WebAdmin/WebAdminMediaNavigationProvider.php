<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Modules\ModuleWebAdminNavigationProviderInterface;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;

final class WebAdminMediaNavigationProvider implements
    ModuleWebAdminNavigationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public function webAdminNavigationItem(): WebAdminNavigationItem
    {
        return new WebAdminNavigationItem(
            'webadmin',
            'Biblioteca de medios',
            '/media',
            'webadmin.media.view'
        );
    }
}
