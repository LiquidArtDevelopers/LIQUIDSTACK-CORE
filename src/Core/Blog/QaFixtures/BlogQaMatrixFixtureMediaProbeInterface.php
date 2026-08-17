<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

interface BlogQaMatrixFixtureMediaProbeInterface
{
    public function assertUsable(string $mediaAssetPublicId): void;
}
