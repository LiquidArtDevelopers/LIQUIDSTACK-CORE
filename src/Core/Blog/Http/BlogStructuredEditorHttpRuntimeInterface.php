<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\StructuredContent\Editing\BlogStructuredEditorService;
use App\Core\Blog\StructuredContent\Media\BlogEditorMediaCatalogInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use Closure;
use PDO;

/** Optional runtime extension used only after structured schema adoption. */
interface BlogStructuredEditorHttpRuntimeInterface extends
    BlogAdminHttpRuntimeInterface
{
    public function structuredEditor(): BlogStructuredEditorService;

    public function editorMediaCatalog(): BlogEditorMediaCatalogInterface;

    public function editorImageResolver(): BlogImageResolverInterface;

    /**
     * @param list<string> $capabilities
     * @return Closure(PDO): string
     */
    public function mutationGateAll(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        array $capabilities
    ): Closure;
}
