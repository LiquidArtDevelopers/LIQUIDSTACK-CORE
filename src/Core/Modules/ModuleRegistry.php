<?php

declare(strict_types=1);

namespace App\Core\Modules;

use ReflectionClass;
use RuntimeException;

final class ModuleRegistry
{
    private function __construct(
        private readonly ModuleSelection $selection
    ) {
    }

    public static function fromSelection(ModuleSelection $selection): self
    {
        return new self($selection);
    }

    public static function forProject(
        string $projectRoot,
        ?string $coreRoot = null
    ): self {
        $coreRoot ??= dirname(__DIR__, 3);
        $catalog = ModuleCatalog::fromCoreRoot($coreRoot);

        return new self(ModuleSelection::fromComposerJson(
            $catalog,
            rtrim($projectRoot, '/\\') . '/composer.json'
        ));
    }

    public function selection(): ModuleSelection
    {
        return $this->selection;
    }

    public function isEnabled(string $id): bool
    {
        return $this->selection->isEnabled($id);
    }

    /**
     * @return list<array{module: string, class: string}>
     */
    public function providers(string $type): array
    {
        $providers = [];

        foreach ($this->selection->enabledDefinitions() as $definition) {
            foreach ($definition->providers($type) as $className) {
                if (
                    !class_exists($className)
                    || !is_subclass_of(
                        $className,
                        ModuleProviderInterface::class
                    )
                ) {
                    throw new RuntimeException(sprintf(
                        'El provider %s del módulo %s no implementa %s.',
                        $className,
                        $definition->id(),
                        ModuleProviderInterface::class
                    ));
                }
                if ($className::moduleId() !== $definition->id()) {
                    throw new RuntimeException(sprintf(
                        'El provider %s declara el módulo %s, pero está registrado en %s.',
                        $className,
                        $className::moduleId(),
                        $definition->id()
                    ));
                }

                $providers[] = [
                    'module' => $definition->id(),
                    'class' => $className,
                ];
            }
        }

        return $providers;
    }

    /**
     * @return list<array{module: string, class: class-string<ModuleRouteProviderInterface>}>
     */
    public function routeProviders(): array
    {
        $providers = $this->partitionRouteProviders('private')['private'];

        /** @var list<array{module: string, class: class-string<ModuleRouteProviderInterface>}> $providers */
        return $providers;
    }

    /**
     * @return list<array{module: string, class: class-string<ModulePublicRouteProviderInterface>}>
     */
    public function publicRouteProviders(): array
    {
        $providers = $this->partitionRouteProviders('public')['public'];

        /** @var list<array{module: string, class: class-string<ModulePublicRouteProviderInterface>}> $providers */
        return $providers;
    }

    /**
     * @return list<array{
     *     module: string,
     *     class: class-string<ModuleWebAdminNavigationProviderInterface>
     * }>
     */
    public function webAdminNavigationProviders(): array
    {
        $providers = $this->providers('navigation');

        foreach ($providers as $provider) {
            $className = $provider['class'];
            if (!is_subclass_of(
                $className,
                ModuleWebAdminNavigationProviderInterface::class
            )) {
                throw new RuntimeException(sprintf(
                    'El provider de navegacion %s del modulo %s no implementa %s.',
                    $className,
                    $provider['module'],
                    ModuleWebAdminNavigationProviderInterface::class
                ));
            }

            $this->assertProviderConstructible(
                $className,
                $provider['module'],
                'navegacion WebAdmin'
            );
        }

        /** @var list<array{module: string, class: class-string<ModuleWebAdminNavigationProviderInterface>}> $providers */
        return $providers;
    }

    /**
     * @return array{
     *     private: list<array{module: string, class: class-string<ModuleRouteProviderInterface>}>,
     *     public: list<array{module: string, class: class-string<ModulePublicRouteProviderInterface>}>
     * }
     */
    private function partitionRouteProviders(string $validateKind): array
    {
        if (!in_array($validateKind, ['private', 'public'], true)) {
            throw new RuntimeException(
                'El tipo interno de provider de rutas no es valido.'
            );
        }

        $private = [];
        $public = [];

        foreach ($this->providers('routes') as $provider) {
            $className = $provider['class'];
            $isPrivate = is_subclass_of(
                $className,
                ModuleRouteProviderInterface::class
            );
            $isPublic = is_subclass_of(
                $className,
                ModulePublicRouteProviderInterface::class
            );

            if ($isPrivate === $isPublic) {
                throw new RuntimeException(sprintf(
                    'El provider de rutas %s del modulo %s debe implementar exactamente uno de %s o %s.',
                    $className,
                    $provider['module'],
                    ModuleRouteProviderInterface::class,
                    ModulePublicRouteProviderInterface::class
                ));
            }

            if ($isPrivate) {
                if ($validateKind === 'private') {
                    $this->assertProviderConstructible(
                        $className,
                        $provider['module'],
                        'rutas'
                    );
                }
                /** @var class-string<ModuleRouteProviderInterface> $className */
                $private[] = [
                    'module' => $provider['module'],
                    'class' => $className,
                ];
                continue;
            }

            if ($validateKind === 'public') {
                $this->assertProviderConstructible(
                    $className,
                    $provider['module'],
                    'rutas'
                );
            }
            /** @var class-string<ModulePublicRouteProviderInterface> $className */
            $public[] = [
                'module' => $provider['module'],
                'class' => $className,
            ];
        }

        return ['private' => $private, 'public' => $public];
    }

    private function assertProviderConstructible(
        string $className,
        string $module,
        string $providerType
    ): void {
        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();
        if (
            !$reflection->isInstantiable()
            || (
                $constructor !== null
                && $constructor->getNumberOfRequiredParameters() > 0
            )
        ) {
            throw new RuntimeException(sprintf(
                'El provider de %s %s del modulo %s debe poder construirse sin argumentos.',
                $providerType,
                $className,
                $module
            ));
        }
    }
}
