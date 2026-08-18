<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Tags\BlogTagService;

/** Optional runtime boundary for localized Blog tag assignment. */
interface BlogTagAdminHttpRuntimeInterface extends BlogAdminHttpRuntimeInterface
{
    public function tagService(): ?BlogTagService;

    public function tagsReady(): bool;
}
