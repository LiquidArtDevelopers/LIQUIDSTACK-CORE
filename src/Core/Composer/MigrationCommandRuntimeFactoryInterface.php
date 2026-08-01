<?php

declare(strict_types=1);

namespace App\Core\Composer;

interface MigrationCommandRuntimeFactoryInterface
{
    public function create(
        string $projectRoot,
        string $coreRoot
    ): MigrationCommandRuntime;
}
