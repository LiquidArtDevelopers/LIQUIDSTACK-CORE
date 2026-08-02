<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Media;

use PDO;

interface BlogMediaAvailabilityPortInterface
{
    /** @param list<string> $mediaAssetPublicIds */
    public function assertAvailable(
        PDO $transaction,
        array $mediaAssetPublicIds
    ): void;
}
