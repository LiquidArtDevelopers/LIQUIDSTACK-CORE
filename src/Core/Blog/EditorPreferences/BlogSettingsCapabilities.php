<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences;

/** Protected authorization contract for code-wide Blog defaults. */
final class BlogSettingsCapabilities
{
    public const MANAGE = 'blog.settings.manage';
    public const MANAGE_LABEL = 'blog.capabilities.settings_manage';

    private function __construct()
    {
    }
}
