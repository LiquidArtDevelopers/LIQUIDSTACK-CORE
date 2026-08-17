<?php

declare(strict_types=1);

namespace App\Core\Composer;

interface BlogQaSeedMatrixCommandRuntimeFactoryInterface
{
    public function create(
        string $projectRoot,
        string $coreRoot,
        bool $allowMysql
    ): BlogQaSeedMatrixCommandRuntimeInterface;
}
