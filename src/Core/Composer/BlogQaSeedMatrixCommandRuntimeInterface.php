<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureResult;

interface BlogQaSeedMatrixCommandRuntimeInterface
{
    /** @param list<string> $locales */
    public function run(
        array $locales,
        string $mediaAssetPublicId,
        string $actorPublicId,
        bool $apply
    ): BlogQaMatrixFixtureResult;
}
