<?php

declare(strict_types=1);

namespace App\Core\Composer;

interface WebAdminOnboardMailRuntimeFactoryInterface
{
    public function createOnboard(
        string $projectRoot,
        string $coreRoot
    ): WebAdminOnboardMailRuntimeInterface;
}
