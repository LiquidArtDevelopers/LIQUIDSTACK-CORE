<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Database\DatabaseConnectionException;
use App\Core\Database\DatabaseConnectionProfile;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;

/** Resolves the single physical database used by all active modules. */
final class ConfiguredModuleDatabaseConnectionResolver
{
    public function __construct(
        private readonly WebAdminConfigLoader $webAdminConfigLoader =
            new WebAdminConfigLoader(),
        private readonly BlogConfigLoader $blogConfigLoader =
            new BlogConfigLoader()
    ) {
    }

    public function resolve(
        ModuleRegistry $registry,
        string $projectRoot
    ): string {
        $connections = [];

        if ($registry->isEnabled('webadmin')) {
            $connections['webadmin'] = $this->webAdminConfigLoader
                ->load($projectRoot)
                ->databaseConnection();
        }
        if ($registry->isEnabled('blog')) {
            $connections['blog'] = $this->blogConfigLoader
                ->databaseConnection($projectRoot);
        }

        $unique = array_values(array_unique(array_values($connections)));
        if (count($unique) > 1) {
            throw new DatabaseConnectionException(
                'database.connection_mismatch'
            );
        }

        return $unique[0] ?? DatabaseConnectionProfile::SHARED;
    }
}
