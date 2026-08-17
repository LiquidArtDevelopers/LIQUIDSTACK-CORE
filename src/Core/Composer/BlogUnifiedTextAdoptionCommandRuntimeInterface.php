<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\StructuredContent\Adoption\BlogUnifiedTextAdoptionResult;

interface BlogUnifiedTextAdoptionCommandRuntimeInterface
{
    /**
     * @param list<string> $postPublicIds
     * @param list<string> $locales
     * @param list<string> $statuses
     */
    public function run(
        array $postPublicIds,
        array $locales,
        array $statuses,
        int $maximumCandidates,
        ?string $actorPublicId,
        bool $apply
    ): BlogUnifiedTextAdoptionResult;
}
