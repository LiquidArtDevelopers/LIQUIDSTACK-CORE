<?php

declare(strict_types=1);

namespace App\Core\Modules\Diagnostics;

use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\Diagnostics\BlogDiagnosticService;
use App\Core\Composer\MigrationCommandRuntimeFactory;
use App\Core\Composer\MigrationCommandRuntimeFactoryInterface;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Environment\ProjectEnvironmentLoadResult;
use App\Core\Modules\Migrations\MigrationCatalog;
use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\Modules\Migrations\MigrationPlan;
use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\ModuleSelection;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Diagnostics\WebAdminDatabaseDiagnostic;
use App\Core\WebAdmin\Diagnostics\WebAdminDiagnosticService;
use App\Core\WebAdmin\Routing\WebAdminRoutePolicy;

final class ModuleDoctor
{
    public function __construct(
        private readonly WebAdminDiagnosticService $webAdminDiagnostics =
            new WebAdminDiagnosticService(),
        private readonly WebAdminRoutePolicy $webAdminRoutePolicy =
            new WebAdminRoutePolicy(),
        private readonly ProjectEnvironmentLoader $environmentLoader =
            new ProjectEnvironmentLoader(),
        private readonly ?MigrationCommandRuntimeFactoryInterface $migrationRuntimeFactory = null,
        private readonly BlogDiagnosticService $blogDiagnostics =
            new BlogDiagnosticService()
    ) {
    }

    public function inspect(
        string $projectRoot,
        string $coreRoot,
        bool $inspectDatabase = true
    ): DoctorReport {
        $projectRoot = rtrim($projectRoot, '/\\');
        $coreRoot = rtrim($coreRoot, '/\\');
        $checks = [];
        $requested = [];
        $enabled = [];
        $providerCounts = [];
        $migrationPlan = MigrationPlan::empty();
        $migrationCatalog = null;
        $databasePlan = null;
        $moduleDiagnostics = [];
        $languages = [];
        $webAdminRegisteredPath = null;
        $webAdminRuntimeReady = null;

        $environment = $this->inspectProjectFiles($projectRoot, $checks);

        try {
            $catalog = ModuleCatalog::fromCoreRoot($coreRoot);
            $checks[] = DiagnosticCheck::ok(
                'modules.catalog',
                sprintf(
                    'Catálogo modular válido: %d módulo(s).',
                    count($catalog->all())
                )
            );

            $selection = ModuleSelection::fromComposerJson(
                $catalog,
                $projectRoot . '/composer.json'
            );
            $requested = $selection->requestedIds();
            $enabled = $selection->enabledIds();
            $checks[] = DiagnosticCheck::ok(
                'modules.selection',
                $enabled === []
                    ? 'No hay módulos opcionales activos.'
                    : sprintf(
                        'Módulos activos en orden de dependencias: %s.',
                        implode(', ', $enabled)
                    )
            );

            $registry = ModuleRegistry::fromSelection($selection);
            $migrationProvidersValid = true;
            $allProvidersValid = true;

            foreach (ModuleDefinition::providerTypes() as $type) {
                try {
                    if ($type === 'routes') {
                        $privateProviders = $registry->routeProviders();
                        $publicProviders = $registry->publicRouteProviders();
                        $providerCounts[$type] = count($privateProviders)
                            + count($publicProviders);
                    } elseif ($type === 'navigation') {
                        $providerCounts[$type] = count(
                            $registry->webAdminNavigationProviders()
                        );
                    } else {
                        $providerCounts[$type] = count(
                            $registry->providers($type)
                        );
                    }
                } catch (\Throwable) {
                    $providerCounts[$type] = 0;
                    $allProvidersValid = false;
                    $checks[] = DiagnosticCheck::error(
                        'modules.providers.' . $type,
                        sprintf(
                            'Un provider activo de tipo %s no cumple el contrato.',
                            $type
                        )
                    );
                    if ($type === 'migrations') {
                        $migrationProvidersValid = false;
                    }
                }
            }

            if ($allProvidersValid) {
                $checks[] = DiagnosticCheck::ok(
                    'modules.providers',
                    sprintf(
                        'Providers activos validados: %d.',
                        array_sum($providerCounts)
                    )
                );
            }

            if ($migrationProvidersValid) {
                try {
                    $migrationCatalog = MigrationCatalog::fromRegistry(
                        $registry
                    );
                    $migrationPlan = $migrationCatalog->plan();
                    $checks[] = DiagnosticCheck::ok(
                        'migrations.catalog',
                        sprintf(
                            'Catálogo de migraciones válido: %d definición(es); estado DB no evaluado.',
                            count($migrationPlan->entries())
                        )
                    );
                } catch (\Throwable) {
                    $checks[] = DiagnosticCheck::error(
                        'migrations.catalog',
                        'El catálogo de migraciones activo no cumple el contrato.'
                    );
                }
            }

            if ($selection->isEnabled('webadmin')) {
                $databaseDiagnostic = WebAdminDatabaseDiagnostic::notChecked();
                if (
                    $inspectDatabase
                    && $migrationCatalog !== null
                    && $this->catalogContainsWebAdminMigration(
                        $migrationCatalog
                    )
                ) {
                    try {
                        $databasePlan = ($this->migrationRuntimeFactory
                            ?? new MigrationCommandRuntimeFactory())->create(
                                $projectRoot,
                                $coreRoot
                            )->preview();
                        $databaseDiagnostic =
                            WebAdminDatabaseDiagnostic::fromPlan($databasePlan);
                    } catch (\Throwable) {
                        $databaseDiagnostic =
                            WebAdminDatabaseDiagnostic::unavailable();
                    }
                }
                $webAdminReport = $this->webAdminDiagnostics->inspect(
                    $projectRoot,
                    $environment,
                    array_map(
                        static fn (array $file): string => $file['target'],
                        $catalog->get('webadmin')->projectFiles()
                    ),
                    $databaseDiagnostic
                );
                $webAdminPayload = $webAdminReport->toArray();
                $languageIssues = [];
                try {
                    $languages = (new ModuleRuntimeContext(
                        $projectRoot,
                        $environment
                    ))->languages();
                } catch (\RuntimeException) {
                    $languages = [];
                    $languageIssues[] = [
                        'code' => 'languages.catalog_invalid',
                        'key' => 'App/config/langs.php',
                    ];
                    $checks[] = DiagnosticCheck::error(
                        'project.languages',
                        'App/config/langs.php no se pudo inspeccionar de forma segura.'
                    );
                }

                $configuredPath =
                    $webAdminPayload['configuration']['effective']['path']
                    ?? WebAdminConfig::DEFAULT_BASE_PATH;
                $routeResolution = $this->webAdminRoutePolicy->resolve(
                    $projectRoot,
                    is_string($configuredPath)
                        ? $configuredPath
                        : WebAdminConfig::DEFAULT_BASE_PATH,
                    $languages
                );
                $webAdminRegisteredPath = $routeResolution->registeredPath();
                $this->applyWebAdminRoutingDiagnostics(
                    $webAdminPayload,
                    $routeResolution->toArray(),
                    $languageIssues
                );
                $moduleDiagnostics['webadmin'] = $webAdminPayload;
                $webAdminRuntimeReady =
                    ($webAdminPayload['readiness']['runtime_ready'] ?? false)
                        === true
                    ? true
                    : (($webAdminPayload['readiness']['database_connection']
                        ?? 'not_checked') === 'not_checked'
                        ? null
                        : false);
                $this->appendWebAdminChecks(
                    $webAdminPayload,
                    $checks,
                    $inspectDatabase
                );
            }

            if ($selection->isEnabled('blog')) {
                $blogReport = $this->blogDiagnostics->inspect(
                    $projectRoot,
                    $languages,
                    $environment,
                    $webAdminRegisteredPath,
                    $webAdminRuntimeReady,
                    $databasePlan instanceof MigrationDatabasePlan
                        ? $databasePlan
                        : null,
                    $inspectDatabase
                );
                $blogPayload = $blogReport->toArray();
                $moduleDiagnostics['blog'] = $blogPayload;
                $this->appendBlogChecks(
                    $blogPayload,
                    $checks,
                    $inspectDatabase
                );
            }
        } catch (\Throwable) {
            $checks[] = DiagnosticCheck::error(
                'modules.bootstrap',
                'No se pudo cargar el catálogo modular de forma segura.'
            );
        }

        return new DoctorReport(
            $projectRoot,
            $checks,
            $requested,
            $enabled,
            $providerCounts,
            $migrationPlan,
            $moduleDiagnostics
        );
    }

    /**
     * Inspects only the module and migration metadata required by
     * `liquidstack:migrate --plan`.
     *
     * This boundary deliberately ignores runtime configuration, environment
     * values and database readiness. Those belong to `doctor` and
     * `migrate --dry-run`; the catalog plan must remain offline and must not
     * fail because an unrelated operational capability is not configured.
     */
    public function inspectMigrationCatalog(
        string $projectRoot,
        string $coreRoot
    ): DoctorReport {
        $projectRoot = rtrim($projectRoot, '/\\');
        $coreRoot = rtrim($coreRoot, '/\\');
        $checks = [];
        $requested = [];
        $enabled = [];
        $providerCounts = [];
        $migrationPlan = MigrationPlan::empty();

        $composerPath = $projectRoot . '/composer.json';
        $composerReady = $this->isRegularReadableFile($composerPath);
        $checks[] = $composerReady
            ? DiagnosticCheck::ok(
                'project.composer',
                'composer.json del proyecto está disponible.'
            )
            : DiagnosticCheck::error(
                'project.composer',
                'composer.json no existe, no es regular o no se puede leer.'
            );

        try {
            $catalog = ModuleCatalog::fromCoreRoot($coreRoot);
            $checks[] = DiagnosticCheck::ok(
                'modules.catalog',
                sprintf(
                    'Catálogo modular válido: %d módulo(s).',
                    count($catalog->all())
                )
            );
        } catch (\Throwable) {
            $checks[] = DiagnosticCheck::error(
                'modules.catalog',
                'No se pudo cargar el catálogo modular de forma segura.'
            );

            return new DoctorReport(
                $projectRoot,
                $checks,
                $requested,
                $enabled,
                $providerCounts,
                $migrationPlan
            );
        }

        if (!$composerReady) {
            return new DoctorReport(
                $projectRoot,
                $checks,
                $requested,
                $enabled,
                $providerCounts,
                $migrationPlan
            );
        }

        try {
            $selection = ModuleSelection::fromComposerJson(
                $catalog,
                $composerPath
            );
            $requested = $selection->requestedIds();
            $enabled = $selection->enabledIds();
            $checks[] = DiagnosticCheck::ok(
                'modules.selection',
                $enabled === []
                    ? 'No hay módulos opcionales activos.'
                    : sprintf(
                        'Módulos activos en orden de dependencias: %s.',
                        implode(', ', $enabled)
                    )
            );
        } catch (\Throwable) {
            $checks[] = DiagnosticCheck::error(
                'modules.selection',
                'No se pudo resolver la selección modular del proyecto.'
            );

            return new DoctorReport(
                $projectRoot,
                $checks,
                $requested,
                $enabled,
                $providerCounts,
                $migrationPlan
            );
        }

        try {
            $registry = ModuleRegistry::fromSelection($selection);
            $providerCounts['migrations'] = count(
                $registry->providers('migrations')
            );
            $checks[] = DiagnosticCheck::ok(
                'modules.providers.migrations',
                sprintf(
                    'Providers de migraciones activos validados: %d.',
                    $providerCounts['migrations']
                )
            );
        } catch (\Throwable) {
            $providerCounts['migrations'] = 0;
            $checks[] = DiagnosticCheck::error(
                'modules.providers.migrations',
                'Un provider activo de migraciones no cumple el contrato.'
            );

            return new DoctorReport(
                $projectRoot,
                $checks,
                $requested,
                $enabled,
                $providerCounts,
                $migrationPlan
            );
        }

        try {
            $migrationPlan = MigrationCatalog::fromRegistry($registry)->plan();
            $checks[] = DiagnosticCheck::ok(
                'migrations.catalog',
                sprintf(
                    'Catálogo de migraciones válido: %d definición(es); estado DB no evaluado.',
                    count($migrationPlan->entries())
                )
            );
        } catch (\Throwable) {
            $checks[] = DiagnosticCheck::error(
                'migrations.catalog',
                'El catálogo de migraciones activo no cumple el contrato.'
            );
        }

        return new DoctorReport(
            $projectRoot,
            $checks,
            $requested,
            $enabled,
            $providerCounts,
            $migrationPlan
        );
    }

    /**
     * @param list<DiagnosticCheck> $checks
     * @return array<string, mixed>
     */
    private function inspectProjectFiles(
        string $projectRoot,
        array &$checks
    ): array {
        $environmentResult = $this->environmentLoader->load($projectRoot);
        $environment = $environmentResult->values();

        $composerPath = $projectRoot . '/composer.json';
        if (!$this->isRegularReadableFile($composerPath)) {
            $checks[] = DiagnosticCheck::error(
                'project.composer',
                'composer.json no existe, no es regular o no se puede leer.'
            );
        } else {
            $checks[] = DiagnosticCheck::ok(
                'project.composer',
                'composer.json del proyecto está disponible.'
            );
        }

        $configPath = $projectRoot . '/App/config/config.php';
        if (!$this->isRegularReadableFile($configPath)) {
            $checks[] = DiagnosticCheck::error(
                'project.app_config',
                'Falta App/config/config.php o no es un fichero regular legible.'
            );
        } else {
            $checks[] = DiagnosticCheck::ok(
                'project.app_config',
                'La configuración base de la aplicación está disponible.'
            );
        }

        $scssPath = $projectRoot . '/src/scss/_config.scss';
        if (!$this->isRegularReadableFile($scssPath)) {
            $checks[] = DiagnosticCheck::warning(
                'project.scss_config',
                'No se pudo inspeccionar src/scss/_config.scss; no se modifica.'
            );
        } else {
            $checks[] = DiagnosticCheck::ok(
                'project.scss_config',
                'El config SCSS es un fichero regular legible.'
            );
        }

        $checks[] = match ($environmentResult->status()) {
            ProjectEnvironmentLoadResult::VALID => DiagnosticCheck::ok(
                'project.env',
                '.env tiene sintaxis válida; sus valores no se muestran.'
            ),
            ProjectEnvironmentLoadResult::MISSING => DiagnosticCheck::warning(
                'project.env',
                'No existe .env; pueden usarse variables del entorno del servidor.'
            ),
            ProjectEnvironmentLoadResult::FILE_INVALID => DiagnosticCheck::error(
                'project.env',
                '.env no es un fichero regular legible.'
            ),
            default => DiagnosticCheck::error(
                'project.env',
                '.env no se pudo analizar; no se muestran detalles para evitar exponer secretos.'
            ),
        };

        return $environment;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $routing
     * @param list<array{code: string, key: string}> $contextIssues
     */
    private function applyWebAdminRoutingDiagnostics(
        array &$payload,
        array $routing,
        array $contextIssues = []
    ): void {
        $issues = $routing['issues'] ?? [];
        if (!is_array($issues)) {
            $issues = [];
        }
        $issues = array_merge($issues, $contextIssues);
        $routing['issues'] = $issues;
        if ($issues !== []) {
            $routing['ready'] = false;
        }
        $payload['routing'] = $routing;

        if (($routing['ready'] ?? false) === true) {
            return;
        }

        $payload['configuration']['ready'] = false;
        $configurationIssues =
            $payload['configuration']['issues'] ?? [];
        if (!is_array($configurationIssues)) {
            $configurationIssues = [];
        }
        $payload['configuration']['issues'] = array_merge(
            $configurationIssues,
            $issues
        );
        $payload['readiness']['ready'] = false;
        $payload['readiness']['runtime_ready'] = false;
        $payload['readiness']['bootstrap_ready'] = false;
        $blockers = $payload['readiness']['blockers'] ?? [];
        if (!is_array($blockers)) {
            $blockers = [];
        }
        $blockers[] = 'routing.invalid';
        $payload['readiness']['blockers'] = array_values(array_unique(
            $blockers
        ));
        $bootstrapBlockers =
            $payload['readiness']['bootstrap_blockers'] ?? [];
        if (!is_array($bootstrapBlockers)) {
            $bootstrapBlockers = [];
        }
        $bootstrapBlockers[] = 'routing.invalid';
        $payload['readiness']['bootstrap_blockers'] = array_values(
            array_unique($bootstrapBlockers)
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<DiagnosticCheck> $checks
     */
    private function appendWebAdminChecks(
        array $payload,
        array &$checks,
        bool $operationalChecks
    ): void {
        $configurationReady =
            ($payload['configuration']['ready'] ?? false) === true;
        $checks[] = $configurationReady
            ? DiagnosticCheck::ok(
                'webadmin.configuration',
                'Configuración WebAdmin válida (valores sensibles omitidos).'
            )
            : DiagnosticCheck::error(
                'webadmin.configuration',
                'Configuración WebAdmin inválida; consulta module_diagnostics.webadmin.'
            );

        $database = $payload['environment']['database'] ?? [];
        $databaseReady = is_array($database)
            && ($database['ready'] ?? false) === true;
        $missingDatabase = is_array($database)
            && is_array($database['missing'] ?? null)
                ? $database['missing']
                : [];
        $invalidDatabase = is_array($database)
            && is_array($database['invalid'] ?? null)
                ? $database['invalid']
                : [];
        $checks[] = $databaseReady
            ? DiagnosticCheck::ok(
                'webadmin.environment.database',
                'El entorno de la DB configurada tiene formato válido; no se prueba la conexión.'
            )
            : DiagnosticCheck::error(
                'webadmin.environment.database',
                $this->databaseEnvironmentFailureMessage(
                    $missingDatabase,
                    $invalidDatabase
                )
            );

        $databaseProbe = $payload['database']['connection'] ?? [];
        $connectionReady = is_array($databaseProbe)
            && ($databaseProbe['ready'] ?? false) === true;
        $connectionStatus = is_array($databaseProbe)
            && is_string($databaseProbe['status'] ?? null)
                ? $databaseProbe['status']
                : 'not_checked';
        $checks[] = $connectionReady
            ? DiagnosticCheck::ok(
                'webadmin.database.connection',
                'La conexión configurada responde con el contrato PDO esperado; no se muestran DSN ni credenciales.'
            )
            : ($connectionStatus === 'not_checked'
                ? DiagnosticCheck::warning(
                    'webadmin.database.connection',
                    'La conexión configurada no se evaluó en este preflight offline.'
                )
                : DiagnosticCheck::error(
                    'webadmin.database.connection',
                    'La conexión configurada no está disponible o no cumple el contrato; no se muestran detalles internos.'
                ));

        $migrations = $payload['database']['migrations'] ?? [];
        $migrationsReady = is_array($migrations)
            && ($migrations['ready'] ?? false) === true;
        $migrationStatus = is_array($migrations)
            && is_string($migrations['status'] ?? null)
                ? $migrations['status']
                : 'not_checked';
        $checks[] = $migrationsReady
            ? DiagnosticCheck::ok(
                'webadmin.database.schema',
                'El esquema WebAdmin aplicado coincide con el catálogo y sus postcondiciones.'
            )
            : ($migrationStatus === 'not_checked'
                ? DiagnosticCheck::warning(
                    'webadmin.database.schema',
                    $operationalChecks
                        ? 'El esquema WebAdmin no pudo evaluarse porque la conexión operativa no estuvo disponible.'
                        : 'El esquema WebAdmin no se evaluó en este preflight offline.'
                )
                : DiagnosticCheck::error(
                    'webadmin.database.schema',
                    'El esquema WebAdmin está pendiente, bloqueado o presenta deriva; consulta module_diagnostics.webadmin.database.migrations.'
                ));

        $securityKey = $payload['environment']['security_key'] ?? [];
        $securityKeyReady = is_array($securityKey)
            && ($securityKey['ready'] ?? false) === true;
        $checks[] = $securityKeyReady
            ? DiagnosticCheck::ok(
                'webadmin.environment.security_key',
                'La clave operativa WebAdmin tiene el formato requerido; su valor se omite.'
            )
            : ($operationalChecks
                ? DiagnosticCheck::error(
                    'webadmin.environment.security_key',
                    'Falta o no es válida LIQUIDSTACK_WEBADMIN_SECURITY_KEY; su valor se omite.'
                )
                : DiagnosticCheck::warning(
                    'webadmin.environment.security_key',
                    'La clave WebAdmin no está preparada; no bloquea este preflight offline.'
                ));

        $mail = $payload['environment']['mail'] ?? [];
        $mailReady = is_array($mail)
            && ($mail['ready'] ?? false) === true;
        $checks[] = $mailReady
            ? DiagnosticCheck::ok(
                'webadmin.environment.mail',
                'El transporte SMTP de WebAdmin está configurado; sus valores se omiten.'
            )
            : DiagnosticCheck::warning(
                'webadmin.environment.mail',
                'El correo WebAdmin no está preparado; login sigue disponible, pero invitaciones y recuperaciones no podrán despacharse.'
            );

        $phpSecurity = $payload['environment']['php_security'] ?? [];
        $exceptionTraceReady = is_array($phpSecurity)
            && ($phpSecurity['ready'] ?? false) === true;
        $checks[] = $exceptionTraceReady
            ? DiagnosticCheck::ok(
                'webadmin.runtime.exception_trace',
                'zend.exception_ignore_args está activo para no retener argumentos sensibles.'
            )
            : ($operationalChecks
                ? DiagnosticCheck::error(
                    'webadmin.runtime.exception_trace',
                    'WebAdmin requiere zend.exception_ignore_args=On antes de autenticar.'
                )
                : DiagnosticCheck::warning(
                    'webadmin.runtime.exception_trace',
                    'zend.exception_ignore_args no está activo; no bloquea este preflight offline.'
                ));

        $passwordPolicy = $payload['environment']['password_policy'] ?? [];
        $passwordPolicyReady = is_array($passwordPolicy)
            && ($passwordPolicy['ready'] ?? false) === true;
        $checks[] = $passwordPolicyReady
            ? DiagnosticCheck::ok(
                'webadmin.runtime.password_policy',
                'La política productiva argon2id-v1 está disponible.'
            )
            : DiagnosticCheck::error(
                'webadmin.runtime.password_policy',
                'WebAdmin requiere soporte Argon2id para argon2id-v1; no existe fallback automático.'
            );

        $bootstrap = $payload['environment']['bootstrap'] ?? [];
        $bootstrapReady = is_array($bootstrap)
            && ($bootstrap['ready'] ?? false) === true;
        $checks[] = $bootstrapReady
            ? DiagnosticCheck::ok(
                'webadmin.environment.bootstrap',
                'El entorno permite el bootstrap explícito.'
            )
            : DiagnosticCheck::warning(
                'webadmin.environment.bootstrap',
                'El bootstrap inicial aún no está preparado; no bloquea WebAdmin ya inicializado.'
            );

        $assetsReady = ($payload['assets']['ready'] ?? false) === true;
        $checks[] = $assetsReady
            ? DiagnosticCheck::ok(
                'webadmin.assets',
                'Assets WebAdmin declarados presentes y dentro del proyecto.'
            )
            : DiagnosticCheck::error(
                'webadmin.assets',
                'Faltan assets WebAdmin o sus rutas no son seguras.'
            );

        $routing = $payload['routing'] ?? [];
        $routingReady = is_array($routing)
            && ($routing['ready'] ?? false) === true;
        $checks[] = $routingReady
            ? DiagnosticCheck::ok(
                'webadmin.routing',
                'El prefijo WebAdmin es neutral y no colisiona con rutas literales del proyecto.'
            )
            : DiagnosticCheck::error(
                'webadmin.routing',
                'El prefijo WebAdmin no puede registrarse tal como está configurado; consulta module_diagnostics.webadmin.routing.'
            );
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<DiagnosticCheck> $checks
     */
    private function appendBlogChecks(
        array $payload,
        array &$checks,
        bool $operationalChecks
    ): void {
        $configurationReady =
            ($payload['configuration']['ready'] ?? false) === true;
        $checks[] = $configurationReady
            ? DiagnosticCheck::ok(
                'blog.configuration',
                'Configuracion Blog valida; sus rutas publicas son project-owned.'
            )
            : DiagnosticCheck::error(
                'blog.configuration',
                'La configuracion Blog no cumple el contrato.'
            );

        $routingReady = ($payload['routing']['ready'] ?? false) === true;
        $checks[] = $routingReady
            ? DiagnosticCheck::ok(
                'blog.routing',
                'Rutas Blog y sitemap disponibles sin colisiones.'
            )
            : DiagnosticCheck::error(
                'blog.routing',
                'Las rutas Blog o su sitemap no estan disponibles.'
            );

        $originReady = (
            $payload['environment']['public_origin']['ready'] ?? false
        ) === true;
        $originUsesLegacyCompatibilityOverride = (
            $payload['environment']['public_origin'][
                'legacy_compatibility_override'
            ] ?? false
        ) === true;
        $originSource = $payload['environment']['public_origin']['source']
            ?? null;
        $checks[] = $originReady
            ? ($originUsesLegacyCompatibilityOverride
                ? DiagnosticCheck::warning(
                    'blog.environment.public_origin',
                    'Blog conserva temporalmente el origen legacy para no cambiar URLs durante el update; alinea RAIZ y LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN.'
                )
                : ($originSource === BlogPublicOrigin::SOURCE_LEGACY
                    ? DiagnosticCheck::warning(
                        'blog.environment.public_origin',
                        'Blog conserva el origen legacy; declara una RAIZ canonica antes de retirar LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN.'
                    )
                    : DiagnosticCheck::ok(
                        'blog.environment.public_origin',
                        'Origen canonico de proyecto disponible; su valor permanece oculto.'
                    )))
            : DiagnosticCheck::error(
                'blog.environment.public_origin',
                'Falta una RAIZ HTTPS valida o un loopback de desarrollo tipado para Blog.'
            );

        $dependencyStatus = is_string(
            $payload['dependency']['status'] ?? null
        ) ? $payload['dependency']['status'] : 'not_ready';
        $checks[] = $dependencyStatus === 'ready'
            ? DiagnosticCheck::ok(
                'blog.dependency.webadmin',
                'WebAdmin esta operativo para la gestion del Blog.'
            )
            : ($dependencyStatus === 'not_checked'
                ? DiagnosticCheck::warning(
                    'blog.dependency.webadmin',
                    'WebAdmin no se comprobo de forma operativa en este diagnostico.'
                )
                : DiagnosticCheck::error(
                'blog.dependency.webadmin',
                'WebAdmin debe estar operativo para gestionar el Blog.'
                ));

        $databaseReady = ($payload['database']['ready'] ?? false) === true;
        $databaseStatus = is_string($payload['database']['status'] ?? null)
            ? $payload['database']['status']
            : 'unavailable';
        if (!$operationalChecks || $databaseStatus === 'not_checked') {
            $checks[] = DiagnosticCheck::warning(
                'blog.database.migrations',
                'Migraciones Blog no comprobadas en este diagnostico.'
            );
        } else {
            $checks[] = $databaseReady
                ? DiagnosticCheck::ok(
                    'blog.database.migrations',
                    'Esquema y capacidades Blog aplicados.'
                )
                : DiagnosticCheck::error(
                    'blog.database.migrations',
                    'Esquema o capacidades Blog pendientes o bloqueados.'
                );
        }
    }

    /**
     * @param list<mixed> $missing
     * @param list<mixed> $invalid
     */
    private function databaseEnvironmentFailureMessage(
        array $missing,
        array $invalid
    ): string {
        $parts = [];
        $missing = array_values(array_filter(
            $missing,
            static fn (mixed $name): bool => is_string($name)
        ));
        $invalid = array_values(array_filter(
            $invalid,
            static fn (mixed $name): bool => is_string($name)
        ));

        if ($missing !== []) {
            $parts[] = 'faltan: ' . implode(', ', $missing);
        }
        if ($invalid !== []) {
            $parts[] = 'formato inválido: ' . implode(', ', $invalid);
        }

        return $parts === []
            ? 'El entorno DB no es válido; consulta el diagnóstico estructurado.'
            : 'Entorno DB no preparado (' . implode('; ', $parts) . ').';
    }

    private function isRegularReadableFile(string $path): bool
    {
        return is_file($path) && !is_link($path) && is_readable($path);
    }

    private function catalogContainsWebAdminMigration(
        MigrationCatalog $catalog
    ): bool {
        foreach ($catalog->entries() as $entry) {
            if (($entry['module'] ?? null) === 'webadmin') {
                return true;
            }
        }

        return false;
    }
}
