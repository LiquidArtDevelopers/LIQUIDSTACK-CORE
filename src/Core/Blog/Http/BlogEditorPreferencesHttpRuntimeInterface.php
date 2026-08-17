<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\EditorPreferences\BlogEditorPreferencesService;

/** Optional runtime boundary for global Blog editor presentation defaults. */
interface BlogEditorPreferencesHttpRuntimeInterface extends
    BlogAdminHttpRuntimeInterface
{
    public function editorPreferencesReady(): bool;

    public function editorPreferences(): BlogEditorPreferencesService;
}
