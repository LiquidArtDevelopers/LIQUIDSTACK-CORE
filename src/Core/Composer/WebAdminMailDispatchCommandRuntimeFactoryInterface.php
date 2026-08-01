<?php

declare(strict_types=1);

namespace App\Core\Composer;

interface WebAdminMailDispatchCommandRuntimeFactoryInterface
{
    public function create(
        string $projectRoot,
        string $coreRoot
    ): WebAdminMailDispatchCommandRuntimeInterface;
}
