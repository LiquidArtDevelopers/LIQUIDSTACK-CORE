<?php

declare(strict_types=1);

namespace App\Core\Composer;

interface BlogUnifiedTextAdoptionCommandRuntimeFactoryInterface
{
    public function create(
        string $projectRoot,
        string $coreRoot
    ): BlogUnifiedTextAdoptionCommandRuntimeInterface;
}
