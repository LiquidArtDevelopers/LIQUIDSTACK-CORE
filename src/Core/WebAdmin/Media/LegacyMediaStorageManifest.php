<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use InvalidArgumentException;

/** Complete read-only DB projection used to verify a legacy storage root. */
final class LegacyMediaStorageManifest
{
    /** @var list<LegacyMediaStorageVariant> */
    private readonly array $variants;

    /** @param list<LegacyMediaStorageVariant> $variants */
    public function __construct(array $variants)
    {
        if (!array_is_list($variants)) {
            throw new InvalidArgumentException(
                'Invalid legacy media storage manifest.'
            );
        }
        foreach ($variants as $variant) {
            if (!$variant instanceof LegacyMediaStorageVariant) {
                throw new InvalidArgumentException(
                    'Invalid legacy media storage manifest.'
                );
            }
        }

        $this->variants = $variants;
    }

    /** @return list<LegacyMediaStorageVariant> */
    public function variants(): array
    {
        return $this->variants;
    }
}
