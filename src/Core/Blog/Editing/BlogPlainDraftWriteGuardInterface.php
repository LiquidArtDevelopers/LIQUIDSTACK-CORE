<?php

declare(strict_types=1);

namespace App\Core\Blog\Editing;

use PDO;

/** Optional feature guard invoked inside the Blog write transaction. */
interface BlogPlainDraftWriteGuardInterface
{
    public function assertPlainSaveAllowed(
        PDO $pdo,
        string $localizationPublicId
    ): void;
}
