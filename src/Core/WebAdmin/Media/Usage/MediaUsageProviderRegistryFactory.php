<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Usage;

use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\WebAdmin\Media\MediaException;
use PDO;
use ReflectionClass;

final class MediaUsageProviderRegistryFactory
{
    public function create(
        PDO $pdo,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes
    ): MediaUsageProviderRegistry {
        $providers = [];
        $complete = true;
        foreach ($registry->providers('services') as $definition) {
            $className = $definition['class'];
            if (!is_subclass_of(
                $className,
                MediaUsageModuleProviderInterface::class
            )) {
                continue;
            }
            $reflection = new ReflectionClass($className);
            $constructor = $reflection->getConstructor();
            if (
                !$reflection->isInstantiable()
                || ($constructor !== null
                    && $constructor->getNumberOfRequiredParameters() > 0)
            ) {
                throw new MediaException(
                    'webadmin.media.usage_module_provider_invalid'
                );
            }
            $moduleProvider = $reflection->newInstance();
            if (!$moduleProvider instanceof MediaUsageModuleProviderInterface) {
                throw new MediaException(
                    'webadmin.media.usage_module_provider_invalid'
                );
            }
            $provider = $moduleProvider->createMediaUsageProvider(
                $pdo,
                $registry,
                $scopes
            );
            if ($provider === null) {
                $complete = false;
                continue;
            }
            $providers[] = $provider;
        }

        return new MediaUsageProviderRegistry($providers, $complete);
    }
}
