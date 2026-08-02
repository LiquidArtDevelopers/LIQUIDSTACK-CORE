<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\WebAdmin\Media\MediaStorageInitializationResult;

interface MediaInitCommandRuntimeInterface
{
    public function initialize(): MediaStorageInitializationResult;
}
