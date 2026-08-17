<?php

declare(strict_types=1);

namespace Tests\Blog\EditorPreferences;

use App\Core\Blog\EditorPreferences\BlogSettingsCapabilities;
use App\Core\Modules\Blog\BlogEditorPreferencesWebAdminNavigationProvider;
use PHPUnit\Framework\TestCase;

final class BlogEditorPreferencesNavigationProviderTest extends TestCase
{
    public function testContributesTheCapabilityGatedEditorialStyleChild(): void
    {
        $provider = new BlogEditorPreferencesWebAdminNavigationProvider();
        $item = $provider->webAdminNavigationItem();

        self::assertSame('blog', $provider::moduleId());
        self::assertSame('blog', $item->module());
        self::assertSame('Estilo editorial', $item->label());
        self::assertSame('/blog/settings/presentation', $item->suffix());
        self::assertSame(
            BlogSettingsCapabilities::MANAGE,
            $item->requiredCapability()
        );

        $manifest = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 3) . '/modules/blog/module.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertContains(
            BlogEditorPreferencesWebAdminNavigationProvider::class,
            $manifest['providers']['navigation'] ?? []
        );
    }
}
