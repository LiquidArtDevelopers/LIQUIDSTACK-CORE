<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureCatalog;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureMediaProbeInterface;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixturePersistencePortInterface;
use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureResult;

final class BlogQaSeedMatrixCommandRuntime implements
    BlogQaSeedMatrixCommandRuntimeInterface
{
    public function __construct(
        private readonly BlogQaMatrixFixtureCatalog $catalog,
        private readonly BlogQaMatrixFixtureMediaProbeInterface $mediaProbe,
        private readonly BlogQaMatrixFixturePersistencePortInterface
            $persistence
    ) {
    }

    public function run(
        array $locales,
        string $mediaAssetPublicId,
        string $actorPublicId,
        bool $apply
    ): BlogQaMatrixFixtureResult {
        $articles = $this->catalog->articles($mediaAssetPublicId);
        // Physical AVIF verification always precedes the global DB plan.
        $this->mediaProbe->assertUsable($mediaAssetPublicId);

        return $this->persistence->run(
            $articles,
            $locales,
            $actorPublicId,
            $apply
        );
    }
}
