<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\CommerceBasketService;
use App\Core\Commerce\Configuration\CommerceConfigLoader;
use App\Core\Commerce\Media\CommercePublicMediaDelivery;
use App\Core\Commerce\Media\PdoCommercePublicMediaRepository;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\Commerce\Persistence\PdoCommerceBasketRepository;
use App\Core\Commerce\Persistence\PdoCommerceCatalogRepository;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Environment\ProjectRuntimeProfile;
use App\Core\Modules\Commerce\CommerceHttpSchemaGate;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\WebAdmin\WebAdminMediaHttpSchemaGate;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use Throwable;

final class CommercePublicHttpRuntimeFactory
{
    public function __construct(
        private readonly ?string $coreRoot = null,
        private readonly CommerceConfigLoader $configLoader =
            new CommerceConfigLoader(),
        private readonly ConfiguredModuleDatabaseConnectionResolver
            $connectionResolver =
                new ConfiguredModuleDatabaseConnectionResolver(),
        private readonly ConfiguredMigrationScopeFactory $scopeFactory =
            new ConfiguredMigrationScopeFactory(),
        private readonly CommerceHttpSchemaGate $schemaGate =
            new CommerceHttpSchemaGate(),
        private readonly ConfiguredPdoConnectionFactoryResolver
            $pdoResolver = new ConfiguredPdoConnectionFactoryResolver(),
        private readonly WebAdminMediaHttpSchemaGate $mediaSchemaGate =
            new WebAdminMediaHttpSchemaGate()
    ) {
    }

    public function create(
        ModuleRuntimeContext $context
    ): CommercePublicHttpRuntime {
        try {
            if (!$context->environmentIsUsable()) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.environment_unusable'
                );
            }
            $projectRoot = $context->projectRoot();
            $registry = ModuleRegistry::forProject(
                $projectRoot,
                $this->coreRoot ?? dirname(__DIR__, 4)
            );
            if (!$registry->isEnabled('commerce')) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.module_not_enabled'
                );
            }
            $config = $this->configLoader->load(
                $projectRoot,
                $context->languages()
            );
            if (!$config->publicEnabled()) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.public_disabled'
                );
            }
            $connection = $this->connectionResolver->resolve(
                $registry,
                $projectRoot
            );
            if ($connection !== $config->databaseConnection()) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.database_connection_mismatch'
                );
            }
            $pdo = $this->pdoResolver
                ->resolve($connection, $context->environment())
                ->connect();
            $scopes = $this->scopeFactory->create($registry, $projectRoot);
            $scope = $scopes->get('commerce');
            $webAdminScope = $scopes->get('webadmin');
            if (
                $scope === null
                || $webAdminScope === null
                || !$this->schemaGate->isPublicCatalogReady(
                    $pdo,
                    $registry,
                    $scopes
                )
            ) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.schema_not_ready'
                );
            }
            $profile = ProjectRuntimeProfile::fromEnvironment(
                $context->environment()
            );
            $commerceTables = CommerceTableNames::fromPdo(
                $pdo,
                $scope->tablePrefix()
            );
            $catalog = new PdoCommerceCatalogRepository(
                $pdo,
                $commerceTables
            );
            $baskets = null;
            $basketCookie = null;
            if ($this->schemaGate->isPublicInquiryReady(
                $pdo,
                $registry,
                $scopes
            )) {
                $baskets = new CommerceBasketService(
                    new PdoCommerceBasketRepository(
                        $pdo,
                        $commerceTables,
                        $catalog
                    ),
                    $config->defaultLocale()
                );
                $basketCookie = CommerceBasketCookie::forProject(
                    $projectRoot,
                    $context->environment(),
                    $config->basketTtlSeconds()
                );
            }
            $webAdminTables = null;
            $mediaDelivery = null;
            if ($this->mediaSchemaGate->isReady(
                $pdo,
                $registry,
                $webAdminScope
            )) {
                $webAdminTables = WebAdminTableNames::fromPdo(
                    $pdo,
                    $webAdminScope->tablePrefix()
                );
                try {
                    $storage = PrivateMediaStorage::forProject(
                        $projectRoot,
                        $context->environment()
                    );
                    if (($storage->diagnostic()['ready'] ?? false) === true) {
                        $mediaDelivery = new CommercePublicMediaDelivery(
                            new PdoCommercePublicMediaRepository(
                                $pdo,
                                $commerceTables,
                                $webAdminTables
                            ),
                            $storage
                        );
                    }
                } catch (Throwable) {
                    // Text-only catalog delivery remains available. Public
                    // media requests fail closed until storage is ready.
                    $mediaDelivery = null;
                }
            }

            return new CommercePublicHttpRuntime(
                $config,
                $webAdminTables === null
                    ? $catalog
                    : new PdoCommerceCatalogRepository(
                        $pdo,
                        $commerceTables,
                        $webAdminTables
                    ),
                $profile->origin(),
                $mediaDelivery,
                $baskets,
                $basketCookie
            );
        } catch (CommercePublicHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CommercePublicHttpRuntimeException(
                'commerce.public_runtime_unavailable'
            );
        }
    }
}
