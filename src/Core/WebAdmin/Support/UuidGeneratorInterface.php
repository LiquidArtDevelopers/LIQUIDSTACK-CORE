<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Support;

interface UuidGeneratorInterface
{
    public function generateV4(): string;
}
