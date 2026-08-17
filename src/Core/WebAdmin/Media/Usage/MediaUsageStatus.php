<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Usage;

enum MediaUsageStatus: string
{
    case Used = 'used';
    case Unused = 'unused';
    case Unknown = 'unknown';
}
