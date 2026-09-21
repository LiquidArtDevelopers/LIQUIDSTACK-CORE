<?php

declare(strict_types=1);

namespace App\Core\Modules\Commerce;

use App\Core\Commerce\CommerceCapabilities;
use App\Core\Modules\ModuleWebAdminNavigationProviderInterface;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;

final class CommerceInquiryWebAdminNavigationProvider implements
    ModuleWebAdminNavigationProviderInterface
{
    public static function moduleId(): string
    {
        return 'commerce';
    }

    public function webAdminNavigationItem(): WebAdminNavigationItem
    {
        return new WebAdminNavigationItem(
            self::moduleId(),
            'Solicitudes comerciales',
            '/commerce/inquiries',
            CommerceCapabilities::INQUIRIES_VIEW
        );
    }
}
