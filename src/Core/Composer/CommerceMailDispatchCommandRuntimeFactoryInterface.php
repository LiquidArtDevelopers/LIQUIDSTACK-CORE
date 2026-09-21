<?php

declare(strict_types=1);

namespace App\Core\Composer;

interface CommerceMailDispatchCommandRuntimeFactoryInterface
{
    public function create(string $projectRoot, string $coreRoot): CommerceMailDispatchCommandRuntimeInterface;
}
