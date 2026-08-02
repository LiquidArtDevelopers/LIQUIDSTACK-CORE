<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\WebAdmin\Media\MediaStorageInitializationResult;
use App\Core\WebAdmin\Media\PdoLegacyMediaStorageAdopter;
use App\Core\WebAdmin\Media\PrivateMediaStorage;

final class MediaInitCommandRuntime implements MediaInitCommandRuntimeInterface
{
    public function __construct(
        private readonly PrivateMediaStorage $storage,
        private readonly ?PdoLegacyMediaStorageAdopter $legacyAdopter = null
    ) {
    }

    public function initialize(): MediaStorageInitializationResult
    {
        return $this->legacyAdopter === null
            ? $this->storage->initialize()
            : $this->legacyAdopter->adopt($this->storage);
    }
}
