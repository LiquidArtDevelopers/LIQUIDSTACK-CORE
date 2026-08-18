<?php

declare(strict_types=1);

namespace App\Core\Composer;

interface WebAdminOnboardCommandRuntimeFactoryInterface
{
    public function create(
        string $projectRoot,
        string $coreRoot
    ): WebAdminOnboardCommandRuntimeInterface;
}
