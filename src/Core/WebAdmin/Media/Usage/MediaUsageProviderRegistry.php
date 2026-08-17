<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Usage;

use App\Core\WebAdmin\Media\MediaException;
use Throwable;

final class MediaUsageProviderRegistry
{
    /** @var list<MediaUsageProviderInterface> */
    private readonly array $providers;

    /** @param list<MediaUsageProviderInterface> $providers */
    public function __construct(
        array $providers = [],
        private readonly bool $complete = true
    ) {
        if (!array_is_list($providers)) {
            throw new MediaException('webadmin.media.usage_registry_invalid');
        }
        foreach ($providers as $provider) {
            if (!$provider instanceof MediaUsageProviderInterface) {
                throw new MediaException('webadmin.media.usage_registry_invalid');
            }
        }
        $this->providers = $providers;
    }

    /**
     * @param list<string> $mediaPublicIds
     * @return array<string, MediaUsageStatus>
     */
    public function statuses(array $mediaPublicIds): array
    {
        $requested = $this->requestedSet($mediaPublicIds);
        $used = [];
        try {
            foreach ($this->providers as $provider) {
                $providerIds = $provider->usedPublicIds(array_keys($requested));
                if (!array_is_list($providerIds)) {
                    throw new MediaException(
                        'webadmin.media.usage_provider_contract_invalid'
                    );
                }
                foreach ($providerIds as $publicId) {
                    if (!is_string($publicId) || !isset($requested[$publicId])) {
                        throw new MediaException(
                            'webadmin.media.usage_provider_contract_invalid'
                        );
                    }
                    $used[$publicId] = true;
                }
            }
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException('webadmin.media.usage_probe_failed');
        }

        $statuses = [];
        foreach (array_keys($requested) as $publicId) {
            $statuses[$publicId] = isset($used[$publicId])
                ? MediaUsageStatus::Used
                : ($this->complete
                    ? MediaUsageStatus::Unused
                    : MediaUsageStatus::Unknown);
        }

        return $statuses;
    }

    /** @param list<string> $mediaPublicIds @return array<string, true> */
    private function requestedSet(array $mediaPublicIds): array
    {
        if (!array_is_list($mediaPublicIds) || count($mediaPublicIds) > 200) {
            throw new MediaException('webadmin.media.usage_query_invalid');
        }
        $requested = [];
        foreach ($mediaPublicIds as $publicId) {
            if (
                !is_string($publicId)
                || preg_match(
                    '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                    $publicId
                ) !== 1
                || isset($requested[$publicId])
            ) {
                throw new MediaException('webadmin.media.usage_query_invalid');
            }
            $requested[$publicId] = true;
        }

        return $requested;
    }
}
