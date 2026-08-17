<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Usage;

use App\Core\WebAdmin\Media\MediaException;

/** Fail-closed reference prerequisite for recoverable media quarantine. */
final class MediaDeletionReferenceGate
{
    public function __construct(
        private readonly MediaUsageProviderRegistry $usageProviders
    ) {
    }

    public function assertUnreferenced(string $mediaPublicId): void
    {
        $status = $this->usageProviders
            ->statuses([$mediaPublicId])[$mediaPublicId];

        if ($status === MediaUsageStatus::Used) {
            throw new MediaException('webadmin.media.delete_asset_in_use');
        }
        if ($status === MediaUsageStatus::Unknown) {
            throw new MediaException('webadmin.media.delete_usage_unavailable');
        }
    }
}
