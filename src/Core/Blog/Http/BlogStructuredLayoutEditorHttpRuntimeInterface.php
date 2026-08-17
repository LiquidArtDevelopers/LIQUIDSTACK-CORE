<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

/** Optional capability exposed only when migration 0011 is fully ready. */
interface BlogStructuredLayoutEditorHttpRuntimeInterface extends
    BlogStructuredEditorHttpRuntimeInterface
{
    public function layoutEditorReady(): bool;
}
