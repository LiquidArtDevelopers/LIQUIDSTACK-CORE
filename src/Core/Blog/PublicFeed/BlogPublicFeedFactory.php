<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicFeed;

use App\Core\Blog\Http\BlogPublicHttpRuntimeFactory;
use App\Core\Blog\Http\BlogPublicHttpRuntimeFactoryInterface;
use App\Core\Modules\ModuleRuntimeContext;

/** Builds the public read adapter without exposing module infrastructure. */
final class BlogPublicFeedFactory
{
    public function __construct(
        private readonly BlogPublicHttpRuntimeFactoryInterface $runtimeFactory =
            new BlogPublicHttpRuntimeFactory()
    ) {
    }

    /** @param array<string, mixed> $environment */
    public function create(
        string $projectRoot,
        array $environment,
        bool $environmentUsable = true
    ): BlogPublicFeed {
        $runtime = $this->runtimeFactory->create(
            new ModuleRuntimeContext(
                $projectRoot,
                $environment,
                $environmentUsable
            )
        );

        return new BlogPublicFeed(
            $runtime->config(),
            $runtime->service(),
            $runtime->categoryProjection(),
            $runtime->catalogRepository()
        );
    }
}
