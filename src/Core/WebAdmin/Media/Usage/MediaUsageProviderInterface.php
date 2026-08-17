<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Usage;

interface MediaUsageProviderInterface
{
    /**
     * Returns only identifiers referenced by this provider.
     *
     * @param list<string> $mediaPublicIds
     * @return list<string>
     */
    public function usedPublicIds(array $mediaPublicIds): array;
}
