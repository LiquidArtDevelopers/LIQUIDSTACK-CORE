<?php

declare(strict_types=1);

namespace App\Core\Composer;

interface WebAdminBootstrapCommandRuntimeFactoryInterface
{
    public function create(
        string $projectRoot,
        string $coreRoot
    ): WebAdminBootstrapCommandRuntimeInterface;
}
