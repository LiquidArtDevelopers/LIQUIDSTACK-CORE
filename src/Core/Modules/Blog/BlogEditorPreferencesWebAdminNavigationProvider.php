<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\EditorPreferences\BlogSettingsCapabilities;
use App\Core\Modules\ModuleWebAdminNavigationProviderInterface;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;

/** Navigation entry for the optional, capability-gated editorial defaults. */
final class BlogEditorPreferencesWebAdminNavigationProvider implements
    ModuleWebAdminNavigationProviderInterface
{
    public static function moduleId(): string
    {
        return 'blog';
    }

    public function webAdminNavigationItem(): WebAdminNavigationItem
    {
        return new WebAdminNavigationItem(
            self::moduleId(),
            'Estilo editorial',
            '/blog/settings/presentation',
            BlogSettingsCapabilities::MANAGE
        );
    }
}
