<?php

declare(strict_types=1);

namespace App\Core\Modules;

interface ModuleProviderInterface
{
    public static function moduleId(): string;
}
