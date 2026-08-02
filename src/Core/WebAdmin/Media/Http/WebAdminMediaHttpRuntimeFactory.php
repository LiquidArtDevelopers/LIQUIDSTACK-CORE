<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\WebAdmin\WebAdminMediaHttpSchemaGate;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Media\ImagickAvifImageProcessor;
use App\Core\WebAdmin\Media\MediaImageProcessorInterface;
use App\Core\WebAdmin\Media\MediaService;
use App\Core\WebAdmin\Media\MediaStorageInterface;
use App\Core\WebAdmin\Media\PdoMediaRepository;
use App\Core\WebAdmin\Media\PrivateMediaStorage;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\ExceptionTraceGuard;
use App\Core\WebAdmin\Security\InvalidSecurityKey;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use Closure;
use Throwable;

final class WebAdminMediaHttpRuntimeFactory implements
    WebAdminMediaHttpRuntimeFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;
    /** @var Closure(string, array<string, mixed>): MediaStorageInterface */
    private readonly Closure $storageResolver;

    public function __construct(
        private readonly ?string $coreRoot = null,
        ?callable $connectionFactoryResolver = null,
        ?ConfiguredModuleDatabaseConnectionResolver $databaseConnectionResolver = null,
        ?callable $storageResolver = null,
        private readonly ?MediaImageProcessorInterface $processor = null
    ) {
        $this->connectionFactoryResolver = $connectionFactoryResolver === null
            ? static fn (array $environment, string $connection): PdoConnectionFactoryInterface =>
                (new ConfiguredPdoConnectionFactoryResolver())->resolve(
                    $connection,
                    $environment
                )
            : Closure::fromCallable($connectionFactoryResolver);
        $this->databaseConnectionResolver = $databaseConnectionResolver
            ?? new ConfiguredModuleDatabaseConnectionResolver();
        $this->storageResolver = $storageResolver === null
            ? static fn (string $root, array $environment): MediaStorageInterface =>
                PrivateMediaStorage::forProject($root, $environment)
            : Closure::fromCallable($storageResolver);
    }

    private readonly ConfiguredModuleDatabaseConnectionResolver
        $databaseConnectionResolver;

    public function create(
        ModuleRuntimeContext $context,
        WebAdminConfig $config
    ): WebAdminMediaHttpRuntime {
        try {
            ExceptionTraceGuard::assertEnabled();
            if (!PasswordHasher::runtimeSupportsArgon2id()) {
                throw new WebAdminMediaHttpRuntimeException(
                    'webadmin.media.password_policy_unsupported'
                );
            }
            $environment = $context->environment();
            $encodedKey = $environment[WebAdminConfig::SECURITY_KEY_ENV] ?? null;
            if (!is_string($encodedKey) || $encodedKey === '') {
                throw new WebAdminMediaHttpRuntimeException(
                    'webadmin.media.security_key_missing'
                );
            }
            try {
                $securityKey = SecurityKey::fromBase64Url($encodedKey);
            } catch (InvalidSecurityKey) {
                throw new WebAdminMediaHttpRuntimeException(
                    'webadmin.media.security_key_invalid'
                );
            }
            $registry = ModuleRegistry::forProject(
                $context->projectRoot(),
                $this->coreRoot ?? dirname(__DIR__, 5)
            );
            if (!$registry->isEnabled('webadmin')) {
                throw new WebAdminMediaHttpRuntimeException(
                    'webadmin.media.module_not_enabled'
                );
            }
            $connection = $this->databaseConnectionResolver->resolve(
                $registry,
                $context->projectRoot()
            );
            if ($connection !== $config->databaseConnection()) {
                throw new WebAdminMediaHttpRuntimeException(
                    'webadmin.media.database_connection_mismatch'
                );
            }
            $factory = ($this->connectionFactoryResolver)(
                $environment,
                $connection
            );
            if (!$factory instanceof PdoConnectionFactoryInterface) {
                throw new WebAdminMediaHttpRuntimeException(
                    'webadmin.media.connection_factory_invalid'
                );
            }
            $pdo = $factory->connect();
            $scope = MigrationScope::forTablePrefix(
                'webadmin',
                $config->tablePrefix()
            );
            if (!(new WebAdminMediaHttpSchemaGate())->isReady(
                $pdo,
                $registry,
                $scope
            )) {
                throw new WebAdminMediaHttpRuntimeException(
                    'webadmin.media.schema_not_ready'
                );
            }
            $storage = ($this->storageResolver)(
                $context->projectRoot(),
                $environment
            );
            if (!$storage instanceof MediaStorageInterface) {
                throw new WebAdminMediaHttpRuntimeException(
                    'webadmin.media.storage_invalid'
                );
            }
            $storageStatus = $storage->diagnostic();
            if (($storageStatus['ready'] ?? false) !== true) {
                throw new WebAdminMediaHttpRuntimeException(
                    'webadmin.media.storage_not_ready'
                );
            }
            $tables = WebAdminTableNames::fromPdo($pdo, $config->tablePrefix());
            $clock = new SystemClock();
            $uuid = new RandomUuidV4Generator();
            $hasher = PasswordHasher::productive();
            $authentication = new WebAdminAuthenticationService(
                new WebAdminAuthenticationRepository($pdo, $tables),
                $config,
                $securityKey,
                $clock,
                $uuid,
                $hasher
            );
            $authorization = new WebAdminAuthorizationService(
                $pdo,
                $tables,
                $clock,
                passwordHasher: $hasher
            );
            $mutationGate = new WebAdminMutationActorGate(
                $pdo,
                $tables,
                $config,
                $securityKey,
                $clock,
                passwordHasher: $hasher
            );
            $media = new MediaService(
                new PdoMediaRepository($pdo, $tables),
                $storage,
                $this->processor ?? new ImagickAvifImageProcessor(),
                $mutationGate,
                $securityKey,
                $clock,
                $uuid
            );

            return new WebAdminMediaHttpRuntime(
                $config,
                $authentication,
                $authorization,
                $media
            );
        } catch (WebAdminMediaHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new WebAdminMediaHttpRuntimeException(
                'webadmin.media.runtime_unavailable'
            );
        }
    }
}
