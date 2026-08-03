<?php

declare(strict_types=1);

namespace App\Core\Composer;

interface BlogSitemapCacheInitCommandRuntimeFactoryInterface
{
    public function create(
        string $projectRoot,
        string $coreRoot,
        bool $sharedStorageConfirmed
    ): BlogSitemapCacheInitCommandRuntimeInterface;
}
