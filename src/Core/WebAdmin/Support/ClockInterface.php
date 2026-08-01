<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Support;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
