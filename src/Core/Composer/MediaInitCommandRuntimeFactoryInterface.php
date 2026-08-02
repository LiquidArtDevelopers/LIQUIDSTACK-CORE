<?php

declare(strict_types=1);

namespace App\Core\Composer;

interface MediaInitCommandRuntimeFactoryInterface
{
    public function create(
        string $projectRoot,
        string $coreRoot,
        bool $adoptExisting = false
    ): MediaInitCommandRuntimeInterface;
}
