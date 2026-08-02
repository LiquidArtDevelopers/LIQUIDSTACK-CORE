<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\WebAdmin\WebAdminMediaHttpSchemaGate;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\PdoLegacyMediaStorageAdopter;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use Closure;
use Throwable;

final class MediaInitCommandRuntimeFactory implements
    MediaInitCommandRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;
    private readonly ConfiguredModuleDatabaseConnectionResolver
        $databaseConnectionResolver;

    /**
     * @param null|callable(array<string, mixed>, string): PdoConnectionFactoryInterface $connectionFactoryResolver
     */
    public function __construct(
        private readonly ProjectEnvironmentLoader $environmentLoader =
            new ProjectEnvironmentLoader(),
        private readonly WebAdminConfigLoader $webAdminConfigLoader =
            new WebAdminConfigLoader(),
        ?callable $connectionFactoryResolver = null,
        ?ConfiguredModuleDatabaseConnectionResolver
            $databaseConnectionResolver = null,
        private readonly WebAdminMediaHttpSchemaGate $schemaGate =
            new WebAdminMediaHttpSchemaGate()
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (
                array $environment,
                string $connection
            ): PdoConnectionFactoryInterface =>
                (new ConfiguredPdoConnectionFactoryResolver())->resolve(
                    $connection,
                    $environment
                )
            : Closure::fromCallable($connectionFactoryResolver);
        $this->databaseConnectionResolver = $databaseConnectionResolver
            ?? new ConfiguredModuleDatabaseConnectionResolver(
                $webAdminConfigLoader
            );
    }

    public function create(
        string $projectRoot,
        string $coreRoot,
        bool $adoptExisting = false
    ): MediaInitCommandRuntimeInterface {
        try {
            $registry = ModuleRegistry::forProject($projectRoot, $coreRoot);
            if (!$registry->isEnabled('webadmin')) {
                throw new MediaInitCommandRuntimeException(
                    'webadmin.media.init.module_not_enabled'
                );
            }

            $environment = $this->environmentLoader->load($projectRoot);
            if (!$environment->isUsable()) {
                throw new MediaInitCommandRuntimeException(
                    'webadmin.media.init.environment_unusable'
                );
            }

            $storage = PrivateMediaStorage::forProject(
                $projectRoot,
                $environment->values()
            );
            if (!$adoptExisting
                || ($storage->diagnostic()['ready'] ?? false) === true) {
                return new MediaInitCommandRuntime($storage);
            }

            $config = $this->webAdminConfigLoader->load($projectRoot);
            $connectionFactory = ($this->connectionFactoryResolver)(
                $environment->values(),
                $this->databaseConnectionResolver->resolve(
                    $registry,
                    $projectRoot
                )
            );
            if (!$connectionFactory instanceof PdoConnectionFactoryInterface) {
                throw new MediaInitCommandRuntimeException(
                    'webadmin.media.init.connection_factory_invalid'
                );
            }
            $pdo = $connectionFactory->connect();
            $scope = MigrationScope::forTablePrefix(
                'webadmin',
                $config->tablePrefix()
            );
            if (!$this->schemaGate->isReady($pdo, $registry, $scope)) {
                throw new MediaInitCommandRuntimeException(
                    'webadmin.media.init.schema_not_ready'
                );
            }

            return new MediaInitCommandRuntime(
                $storage,
                new PdoLegacyMediaStorageAdopter(
                    $pdo,
                    WebAdminTableNames::fromPdo(
                        $pdo,
                        $config->tablePrefix()
                    )
                )
            );
        } catch (MediaInitCommandRuntimeException $exception) {
            throw $exception;
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaInitCommandRuntimeException(
                'webadmin.media.init.runtime_unavailable'
            );
        }
    }
}
