<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Delivery;

use App\Core\Modules\ModuleRuntimeContext;

interface BlogSitemapDeliveryFactoryInterface
{
    public function create(
        ModuleRuntimeContext $context
    ): BlogSitemapDeliveryService;
}
