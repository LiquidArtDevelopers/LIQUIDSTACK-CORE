<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Delivery;

use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Http\BlogPublicHttpRuntimeException;
use App\Core\Blog\Http\BlogSitemapRenderer;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheIdentity;
use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheLease;
use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheSnapshot;
use App\Core\Blog\Sitemap\Cache\PrivateBlogSitemapCacheStorage;
use App\Core\Blog\Sitemap\Persistence\BlogSitemapState;
use App\Core\Blog\Sitemap\Persistence\PdoBlogSitemapStateRepository;
use App\Core\Database\ConfiguredPdoConnectionFactoryResolver;
use App\Core\Database\DatabaseConnectionException;
use App\Core\Database\PdoConnectionFactoryInterface;
use App\Core\Modules\Blog\BlogHttpSchemaGate;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\ConfiguredModuleDatabaseConnectionResolver;
use App\Core\Modules\Migrations\ConfiguredMigrationScopeFactory;
use App\Core\Modules\Migrations\MigrationFeatureGate;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\Migrations\MigrationScopeCollection;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use Closure;
use PDO;
use Throwable;

/** Builds the sitemap separately so an unavailable PDO can use a valid LKG. */
final class BlogSitemapDeliveryFactory implements
    BlogSitemapDeliveryFactoryInterface
{
    /** @var Closure(array<string, mixed>, string): PdoConnectionFactoryInterface */
    private readonly Closure $connectionFactoryResolver;
    private readonly ConfiguredModuleDatabaseConnectionResolver
        $databaseConnectionResolver;

    /**
     * @param null|callable(array<string, mixed>, string): PdoConnectionFactoryInterface $connectionFactoryResolver
     */
    public function __construct(
        private readonly ?string $coreRoot = null,
        ?callable $connectionFactoryResolver = null,
        private readonly BlogConfigLoader $configLoader =
            new BlogConfigLoader(),
        private readonly ConfiguredMigrationScopeFactory $scopeFactory =
            new ConfiguredMigrationScopeFactory(),
        private readonly BlogHttpSchemaGate $schemaGate =
            new BlogHttpSchemaGate(),
        private readonly MigrationFeatureGate $featureGate =
            new MigrationFeatureGate(),
        ?ConfiguredModuleDatabaseConnectionResolver
            $databaseConnectionResolver = null,
        private readonly BlogSitemapRenderer $renderer =
            new BlogSitemapRenderer()
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
            ?? new ConfiguredModuleDatabaseConnectionResolver();
    }

    public function create(
        ModuleRuntimeContext $context
    ): BlogSitemapDeliveryService {
        try {
            if (!$context->environmentIsUsable()) {
                throw new BlogPublicHttpRuntimeException();
            }
            $registry = ModuleRegistry::forProject(
                $context->projectRoot(),
                $this->coreRoot ?? dirname(__DIR__, 5)
            );
            if (!$registry->isEnabled('blog')
                || !$registry->isEnabled('webadmin')) {
                throw new BlogPublicHttpRuntimeException();
            }
            $config = $this->configLoader->load(
                $context->projectRoot(),
                $context->languages()
            );
            $origin = BlogPublicOrigin::fromEnvironment(
                $context->environment()
            );
            $scopes = $this->scopeFactory->create(
                $registry,
                $context->projectRoot()
            );
            $blogScope = $scopes->get('blog');
            if ($blogScope === null) {
                throw new BlogPublicHttpRuntimeException();
            }
            $storage = null;
            $identity = null;
            if ($config->sitemapCache()->enabled()) {
                $storage = PrivateBlogSitemapCacheStorage::forProject(
                    $context->projectRoot(),
                    $context->environment()
                );
                if (($storage->diagnostic()['ready'] ?? false) !== true) {
                    throw new BlogPublicHttpRuntimeException();
                }
                $identity = BlogSitemapCacheIdentity::fromContract(
                    $config,
                    $origin,
                    $this->databaseIdentity(
                        $config,
                        $context->environment()
                    )
                );
            }

            return new BlogSitemapDeliveryService(
                fn (): BlogSitemapDeliveryDocument => $this->fresh(
                    $context,
                    $registry,
                    $scopes,
                    $blogScope,
                    $config,
                    $origin,
                    $storage,
                    $identity
                ),
                $storage,
                $identity
            );
        } catch (BlogPublicHttpRuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPublicHttpRuntimeException();
        }
    }

    private function fresh(
        ModuleRuntimeContext $context,
        ModuleRegistry $registry,
        MigrationScopeCollection $scopes,
        MigrationScope $blogScope,
        BlogConfig $config,
        BlogPublicOrigin $origin,
        ?PrivateBlogSitemapCacheStorage $storage,
        ?BlogSitemapCacheIdentity $identity
    ): BlogSitemapDeliveryDocument {
        $connectionFactory = ($this->connectionFactoryResolver)(
            $context->environment(),
            $this->databaseConnectionResolver->resolve(
                $registry,
                $context->projectRoot()
            )
        );
        if (!$connectionFactory instanceof PdoConnectionFactoryInterface) {
            throw new BlogPublicHttpRuntimeException();
        }
        try {
            $pdo = $connectionFactory->connect();
        } catch (DatabaseConnectionException $exception) {
            if ($exception->issueCode() === 'database.connection_unavailable') {
                throw new BlogSitemapSourceUnavailable();
            }
            throw new BlogPublicHttpRuntimeException();
        }
        if (!$this->schemaGate->isPublicReady($pdo, $registry, $scopes)) {
            throw new BlogPublicHttpRuntimeException();
        }

        $service = new BlogService(new PdoBlogRepository($pdo, $blogScope));
        if ($storage === null || $identity === null) {
            $xml = $this->renderer->render(
                $service->sitemapEntries(),
                $config,
                $origin
            );
            return new BlogSitemapDeliveryDocument(
                $xml,
                '"' . hash('sha256', $xml) . '"',
                false
            );
        }
        if (!$this->featureGate->isReady(
            $pdo,
            $registry,
            $scopes,
            BlogMigrationRequirements::sitemapCache()
        )) {
            throw new BlogPublicHttpRuntimeException();
        }
        $stateRepository = new PdoBlogSitemapStateRepository($pdo, $blogScope);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $before = $stateRepository->current();
            $this->assertActiveGeneration($before, $storage);
            $xml = $this->renderer->render(
                $service->sitemapEntries(),
                $config,
                $origin
            );
            $after = $stateRepository->current();
            if (!$this->sameState($before, $after)) {
                continue;
            }
            $snapshot = BlogSitemapCacheSnapshot::fresh(
                $xml,
                $after->publicRevision(),
                (string) $after->cacheGeneration(),
                $identity,
                time(),
                $config->sitemapCache()->ttlSeconds()
            );
            if (!$this->promoteIfCurrent(
                $pdo,
                $stateRepository,
                $after,
                $storage,
                $snapshot
            )) {
                continue;
            }

            return new BlogSitemapDeliveryDocument(
                $xml,
                $snapshot->etag(),
                false
            );
        }

        throw new BlogPublicHttpRuntimeException();
    }

    private function promoteIfCurrent(
        PDO $pdo,
        PdoBlogSitemapStateRepository $stateRepository,
        BlogSitemapState $expected,
        PrivateBlogSitemapCacheStorage $storage,
        BlogSitemapCacheSnapshot $snapshot
    ): bool {
        if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
            throw new BlogPublicHttpRuntimeException();
        }
        $lease = null;
        try {
            $locked = $stateRepository->lock();
            if (!$this->sameState($expected, $locked)) {
                $pdo->rollBack();
                return false;
            }
            $lease = $storage->acquireExclusive();
            $storage->promote($lease, $snapshot);
            if (!$pdo->commit()) {
                throw new BlogPublicHttpRuntimeException();
            }
            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($exception instanceof BlogPublicHttpRuntimeException) {
                throw $exception;
            }
            throw new BlogPublicHttpRuntimeException();
        } finally {
            $lease?->release();
        }
    }

    private function assertActiveGeneration(
        BlogSitemapState $state,
        PrivateBlogSitemapCacheStorage $storage
    ): void {
        $generation = $state->cacheGeneration();
        if ($generation === null
            || !hash_equals($generation, $storage->markerGeneration())) {
            throw new BlogPublicHttpRuntimeException();
        }
    }

    private function sameState(
        BlogSitemapState $left,
        BlogSitemapState $right
    ): bool {
        $leftGeneration = $left->cacheGeneration();
        $rightGeneration = $right->cacheGeneration();
        return $left->publicRevision() === $right->publicRevision()
            && $leftGeneration !== null
            && $rightGeneration !== null
            && hash_equals($leftGeneration, $rightGeneration);
    }

    /** @param array<string, mixed> $environment */
    private function databaseIdentity(
        BlogConfig $config,
        array $environment
    ): string {
        $names = $config->databaseConnection() === 'liquidstack'
            ? [
                'LIQUIDSTACK_DB_HOST', 'LIQUIDSTACK_DB_PORT',
                'LIQUIDSTACK_DB_NAME', 'LIQUIDSTACK_DB_USER',
                'LIQUIDSTACK_DB_CHARSET',
            ]
            : ['BBDD_SERVER', 'BBDD_NAME', 'BBDD_USER'];
        $values = ['profile' => $config->databaseConnection()];
        foreach ($names as $name) {
            $value = $environment[$name] ?? null;
            $values[$name] = is_string($value) ? $value : '';
        }

        return hash('sha256', (string) json_encode(
            $values,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }
}
