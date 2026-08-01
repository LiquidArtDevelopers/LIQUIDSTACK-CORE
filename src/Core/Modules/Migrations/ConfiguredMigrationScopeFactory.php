<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;

final class ConfiguredMigrationScopeFactory
{
    public function __construct(
        private readonly WebAdminConfigLoader $webAdminConfigLoader =
            new WebAdminConfigLoader(),
        private readonly BlogConfigLoader $blogConfigLoader =
            new BlogConfigLoader()
    ) {
    }

    public function create(
        ModuleRegistry $registry,
        string $projectRoot
    ): MigrationScopeCollection {
        $prefixes = [];
        if ($registry->isEnabled('webadmin')) {
            $prefixes['webadmin'] = $this->webAdminConfigLoader
                ->load($projectRoot)
                ->tablePrefix();
        }
        if ($registry->isEnabled('blog')) {
            $languages = (new ModuleRuntimeContext($projectRoot))
                ->languages();
            $prefixes['blog'] = $this->blogConfigLoader
                ->load($projectRoot, $languages)
                ->tablePrefix();
        }

        return MigrationScopeCollection::fromTablePrefixes($prefixes);
    }
}
