<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\CommerceBasketService;
use App\Core\Commerce\CommerceInquiryAbuseGuard;
use App\Core\Commerce\CommerceInquiryService;
use App\Core\Commerce\Configuration\CommerceConfigLoader;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\Commerce\Persistence\PdoCommerceBasketRepository;
use App\Core\Commerce\Persistence\PdoCommerceCatalogRepository;
use App\Core\Commerce\Persistence\PdoCommerceInquiryRepository;
use App\Core\Commerce\Persistence\PdoCommerceInquiryRateLimitRepository;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Environment\ProjectRuntimeProfile;
use App\Core\Modules\Commerce\CommerceHttpSchemaGate;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Security\SecurityKey;
use Throwable;

final class CommerceInquiryHttpRuntimeFactory
{
    public function create(
        ModuleRuntimeContext $context
    ): CommerceInquiryHttpRuntime {
        try {
            if (!$context->environmentIsUsable()) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.environment_unusable'
                );
            }
            $root = $context->projectRoot();
            $registry = ModuleRegistry::forProject($root, dirname(__DIR__, 4));
            if (!$registry->isEnabled('commerce')) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.module_not_enabled'
                );
            }
            $config = (new CommerceConfigLoader())->load(
                $root,
                $context->languages()
            );
            if (!$config->publicEnabled()) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.public_disabled'
                );
            }
            $connection = (new ConfiguredModuleDatabaseConnectionResolver())
                ->resolve($registry, $root);
            if ($connection !== $config->databaseConnection()) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.database_connection_mismatch'
                );
            }
            $pdo = (new ConfiguredPdoConnectionFactoryResolver())
                ->resolve($connection, $context->environment())
                ->connect();
            $scopes = (new ConfiguredMigrationScopeFactory())
                ->create($registry, $root);
            $scope = $scopes->get('commerce');
            if (
                $scope === null
                || !(new CommerceHttpSchemaGate())->isPublicInquiryReady(
                    $pdo,
                    $registry,
                    $scopes
                )
            ) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.schema_not_ready'
                );
            }
            $tables = CommerceTableNames::fromPdo(
                $pdo,
                $scope->tablePrefix()
            );
            $catalog = new PdoCommerceCatalogRepository($pdo, $tables);
            $basketRepository = new PdoCommerceBasketRepository(
                $pdo,
                $tables,
                $catalog
            );
            $inquiryEnvironment = CommerceInquiryEnvironment::fromEnvironment(
                $context->environment()
            );
            $profile = ProjectRuntimeProfile::fromEnvironment(
                $context->environment()
            );
            $encodedSecurityKey = $context->environment()[
                WebAdminConfig::SECURITY_KEY_ENV
            ] ?? null;
            if (!is_string($encodedSecurityKey)) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.security_key_unavailable'
                );
            }
            $securityKey = SecurityKey::fromBase64Url($encodedSecurityKey);

            return new CommerceInquiryHttpRuntime(
                $config,
                new CommerceBasketService(
                    $basketRepository,
                    $config->defaultLocale()
                ),
                new CommerceInquiryService(
                    new PdoCommerceInquiryRepository(
                        $pdo,
                        $tables,
                        $catalog,
                        $inquiryEnvironment->recipientEmail()
                    ),
                    $config->defaultLocale()
                ),
                CommerceBasketCookie::forProject(
                    $root,
                    $context->environment(),
                    $config->basketTtlSeconds()
                ),
                $inquiryEnvironment,
                $profile->origin(),
                new CommerceInquiryAbuseGuard(
                    new PdoCommerceInquiryRateLimitRepository($pdo, $tables),
                    $securityKey
                )
            );
        } catch (CommercePublicHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CommercePublicHttpRuntimeException(
                'commerce.inquiry_runtime_unavailable'
            );
        }
    }
}
