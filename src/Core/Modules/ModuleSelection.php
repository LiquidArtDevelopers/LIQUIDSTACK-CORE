<?php

declare(strict_types=1);

namespace App\Core\Modules;

use RuntimeException;

final class ModuleSelection
{
    /**
     * @param list<string> $requestedIds
     * @param list<string> $enabledIds
     */
    private function __construct(
        private readonly ModuleCatalog $catalog,
        private readonly array $requestedIds,
        private readonly array $enabledIds
    ) {
    }

    /**
     * @param iterable<string> $requirementNames
     */
    public static function fromRequirementNames(
        ModuleCatalog $catalog,
        iterable $requirementNames
    ): self {
        $requested = [];

        foreach ($requirementNames as $packageName) {
            if (!is_string($packageName)) {
                continue;
            }

            $definition = $catalog->forPackage(strtolower($packageName));
            if ($definition !== null) {
                $requested[$definition->id()] = true;
            }
        }

        $requestedIds = array_keys($requested);
        sort($requestedIds, SORT_STRING);

        $enabled = [];
        $visiting = [];
        foreach ($requestedIds as $id) {
            self::enable($id, $catalog, $enabled, $visiting, []);
        }

        return new self($catalog, $requestedIds, array_keys($enabled));
    }

    public static function fromComposerJson(
        ModuleCatalog $catalog,
        string $composerPath
    ): self {
        if (!is_file($composerPath) || is_link($composerPath)) {
            return self::fromRequirementNames($catalog, []);
        }

        $raw = file_get_contents($composerPath);
        if (!is_string($raw)) {
            throw new RuntimeException(sprintf(
                'No se pudo leer %s para resolver los módulos.',
                $composerPath
            ));
        }

        try {
            $composer = json_decode(
                str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException(sprintf(
                'No se pueden resolver módulos: JSON inválido en %s.',
                $composerPath
            ), 0, $exception);
        }

        $requirements = is_array($composer) ? ($composer['require'] ?? []) : [];
        if (
            !is_array($requirements)
            || ($requirements !== [] && array_is_list($requirements))
        ) {
            throw new RuntimeException(sprintf(
                'No se pueden resolver módulos: require no es un objeto en %s.',
                $composerPath
            ));
        }

        return self::fromRequirementNames($catalog, array_keys($requirements));
    }

    /**
     * @return list<string>
     */
    public function requestedIds(): array
    {
        return $this->requestedIds;
    }

    /**
     * @return list<string>
     */
    public function enabledIds(): array
    {
        return $this->enabledIds;
    }

    /**
     * @return list<ModuleDefinition>
     */
    public function enabledDefinitions(): array
    {
        return array_map(
            fn (string $id): ModuleDefinition => $this->catalog->get($id),
            $this->enabledIds
        );
    }

    public function isEnabled(string $id): bool
    {
        return in_array($id, $this->enabledIds, true);
    }

    /**
     * @param array<string, true> $enabled
     * @param array<string, true> $visiting
     * @param list<string> $trail
     */
    private static function enable(
        string $id,
        ModuleCatalog $catalog,
        array &$enabled,
        array &$visiting,
        array $trail
    ): void {
        if (isset($enabled[$id])) {
            return;
        }
        if (isset($visiting[$id])) {
            $trail[] = $id;
            throw new RuntimeException(sprintf(
                'Dependencia circular entre módulos: %s.',
                implode(' -> ', $trail)
            ));
        }

        $visiting[$id] = true;
        $trail[] = $id;
        $definition = $catalog->get($id);

        foreach ($definition->dependencies() as $dependency) {
            self::enable(
                $dependency,
                $catalog,
                $enabled,
                $visiting,
                $trail
            );
        }

        unset($visiting[$id]);
        $enabled[$id] = true;
    }
}
