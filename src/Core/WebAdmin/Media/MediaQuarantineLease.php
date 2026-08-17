<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use Closure;
use Throwable;

/** Holds the storage mutex until DB commit or compensated rollback. */
final class MediaQuarantineLease
{
    private bool $active = true;

    public function __construct(
        private readonly MediaQuarantineManifest $manifest,
        private readonly Closure $restoreOperation,
        private readonly Closure $releaseOperation
    ) {
    }

    public function manifest(): MediaQuarantineManifest
    {
        return $this->manifest;
    }

    public function restore(): void
    {
        if (!$this->active) {
            throw new MediaException('webadmin.media.quarantine_lease_closed');
        }
        try {
            ($this->restoreOperation)();
        } finally {
            $this->release();
        }
    }

    public function release(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        ($this->releaseOperation)();
    }

    public function __destruct()
    {
        try {
            $this->release();
        } catch (Throwable) {
            // Destructors cannot safely report an operational failure. Every
            // service path releases explicitly before returning.
        }
    }
}
