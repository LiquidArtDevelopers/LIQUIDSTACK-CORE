<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\StructuredContent\Media\PdoBlogMediaUsageProvider;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\WebAdmin\Media\Usage\MediaUsageModuleProviderInterface;
use App\Core\WebAdmin\Media\Usage\MediaUsageProviderInterface;
use PDO;

final class BlogMediaUsageModuleProvider implements
    MediaUsageModuleProviderInterface
{
    public function __construct(
        private readonly BlogStructuredContentSchemaGate $schemaGate =
            new BlogStructuredContentSchemaGate()
    ) {
    }

    public static function moduleId(): string
    {
        return 'blog';
    }

    public function createMediaUsageProvider(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): ?MediaUsageProviderInterface {
        $scope = $scopes->get('blog');
        if (
            $scope === null
            || !$this->schemaGate->isReady($pdo, $registry, $scopes)
        ) {
            return null;
        }

        return new PdoBlogMediaUsageProvider($pdo, $scope);
    }
}
