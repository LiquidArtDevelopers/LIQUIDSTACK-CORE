<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\StructuredContent\Adoption\BlogUnifiedTextAdoptionRequest;
use App\Core\Blog\StructuredContent\Adoption\BlogUnifiedTextAdoptionResult;
use App\Core\Blog\StructuredContent\Adoption\BlogUnifiedTextAdoptionService;

final class BlogUnifiedTextAdoptionCommandRuntime implements
    BlogUnifiedTextAdoptionCommandRuntimeInterface
{
    public function __construct(
        private readonly BlogUnifiedTextAdoptionService $service
    ) {
    }

    public function run(
        array $postPublicIds,
        array $locales,
        array $statuses,
        int $maximumCandidates,
        ?string $actorPublicId,
        bool $apply
    ): BlogUnifiedTextAdoptionResult {
        return $this->service->run(new BlogUnifiedTextAdoptionRequest(
            $postPublicIds,
            $locales,
            $statuses,
            $maximumCandidates,
            $apply,
            $actorPublicId
        ));
    }
}
