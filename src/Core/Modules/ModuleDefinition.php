<?php

declare(strict_types=1);

namespace App\Core\Modules;

use RuntimeException;

final class ModuleDefinition
{
    private const SCHEMA = 1;
    private const PROVIDER_TYPES = [
        'routes',
        'middleware',
        'services',
        'navigation',
        'capabilities',
        'migrations',
        'sitemap',
    ];

    /**
     * @param list<string> $dependencies
     * @param array<string, list<string>> $providers
     * @param list<array{
     *     source: string,
     *     target: string,
     *     type: 'file'|'dir',
     *     policy: 'managed_hash'|'install_if_missing'|'merge_json_additive',
     *     group: string,
     *     track_state: bool
     * }> $projectFiles
     */
    private function __construct(
        private readonly string $id,
        private readonly string $packageName,
        private readonly array $dependencies,
        private readonly array $providers,
        private readonly array $projectFiles,
        private readonly string $root
    ) {
    }

    public static function fromManifest(string $manifestPath): self
    {
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException(sprintf(
                'No existe un manifiesto de módulo regular en %s.',
                $manifestPath
            ));
        }

        $raw = file_get_contents($manifestPath);
        if (!is_string($raw)) {
            throw new RuntimeException(sprintf(
                'No se pudo leer el manifiesto de módulo %s.',
                $manifestPath
            ));
        }

        try {
            $manifest = json_decode(
                self::stripUtf8Bom($raw),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException(sprintf(
                'JSON inválido en %s: %s',
                $manifestPath,
                $exception->getMessage()
            ), 0, $exception);
        }

        if (!is_array($manifest) || ($manifest['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException(sprintf(
                'El manifiesto %s no usa el schema de módulo %d.',
                $manifestPath,
                self::SCHEMA
            ));
        }

        $id = self::requiredIdentifier($manifest, 'id', $manifestPath);
        $directoryId = basename(dirname($manifestPath));
        if ($directoryId !== $id) {
            throw new RuntimeException(sprintf(
                'El módulo %s debe vivir en un directorio con el mismo nombre.',
                $id
            ));
        }

        $packageName = self::requiredPackageName($manifest, $manifestPath);
        $dependencies = self::parseDependencies($manifest, $manifestPath);
        $providers = self::parseProviders($manifest, $manifestPath);
        $projectFiles = self::parseProjectFiles($manifest, $manifestPath, $id);

        return new self(
            $id,
            $packageName,
            $dependencies,
            $providers,
            $projectFiles,
            dirname($manifestPath)
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function packageName(): string
    {
        return $this->packageName;
    }

    /**
     * @return list<string>
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * @return list<string>
     */
    public function providers(string $type): array
    {
        return $this->providers[$type] ?? [];
    }

    /**
     * @return list<array{
     *     source: string,
     *     target: string,
     *     type: 'file'|'dir',
     *     policy: 'managed_hash'|'install_if_missing'|'merge_json_additive',
     *     group: string,
     *     track_state: bool
     * }>
     */
    public function projectFiles(): array
    {
        return $this->projectFiles;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @return list<string>
     */
    public static function providerTypes(): array
    {
        return self::PROVIDER_TYPES;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function requiredIdentifier(
        array $manifest,
        string $key,
        string $manifestPath
    ): string {
        $value = $manifest[$key] ?? null;
        if (
            !is_string($value)
            || preg_match('/\A[a-z][a-z0-9-]*\z/', $value) !== 1
        ) {
            throw new RuntimeException(sprintf(
                'El campo %s de %s no es un identificador de módulo válido.',
                $key,
                $manifestPath
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function requiredPackageName(
        array $manifest,
        string $manifestPath
    ): string {
        $value = $manifest['package'] ?? null;
        if (
            !is_string($value)
            || preg_match(
                '/\A[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*\z/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(sprintf(
                'El campo package de %s no es un nombre Composer válido.',
                $manifestPath
            ));
        }

        return strtolower($value);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private static function parseDependencies(
        array $manifest,
        string $manifestPath
    ): array {
        $values = $manifest['requires'] ?? [];
        if (!is_array($values) || !array_is_list($values)) {
            throw new RuntimeException(sprintf(
                'El campo requires de %s debe ser una lista.',
                $manifestPath
            ));
        }

        $dependencies = [];
        foreach ($values as $value) {
            if (
                !is_string($value)
                || preg_match('/\A[a-z][a-z0-9-]*\z/', $value) !== 1
            ) {
                throw new RuntimeException(sprintf(
                    'Dependencia de módulo inválida en %s.',
                    $manifestPath
                ));
            }

            $dependencies[] = $value;
        }

        return array_values(array_unique($dependencies));
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, list<string>>
     */
    private static function parseProviders(
        array $manifest,
        string $manifestPath
    ): array {
        $values = $manifest['providers'] ?? [];
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new RuntimeException(sprintf(
                'El campo providers de %s debe ser un objeto.',
                $manifestPath
            ));
        }

        $providers = [];
        foreach ($values as $type => $classes) {
            if (
                !is_string($type)
                || preg_match('/\A[a-z][a-z0-9_-]*\z/', $type) !== 1
                || !in_array($type, self::PROVIDER_TYPES, true)
                || !is_array($classes)
                || !array_is_list($classes)
            ) {
                throw new RuntimeException(sprintf(
                    'Proveedor de módulo inválido en %s.',
                    $manifestPath
                ));
            }

            $providers[$type] = [];
            foreach ($classes as $className) {
                if (
                    !is_string($className)
                    || preg_match(
                        '/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/',
                        $className
                    ) !== 1
                ) {
                    throw new RuntimeException(sprintf(
                        'Clase de provider inválida en %s.',
                        $manifestPath
                    ));
                }

                $providers[$type][] = $className;
            }
        }

        return $providers;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array{
     *     source: string,
     *     target: string,
     *     type: 'file'|'dir',
     *     policy: 'managed_hash'|'install_if_missing'|'merge_json_additive',
     *     group: string,
     *     track_state: bool
     * }>
     */
    private static function parseProjectFiles(
        array $manifest,
        string $manifestPath,
        string $id
    ): array {
        $values = $manifest['project_files'] ?? [];
        if (!is_array($values) || !array_is_list($values)) {
            throw new RuntimeException(sprintf(
                'El campo project_files de %s debe ser una lista.',
                $manifestPath
            ));
        }

        $supportedPolicies = [
            'managed_hash',
            'install_if_missing',
            'merge_json_additive',
        ];
        $files = [];
        $targets = [];

        foreach ($values as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException(sprintf(
                    'Entrada project_files inválida en %s.',
                    $manifestPath
                ));
            }

            $source = self::relativePath($entry['source'] ?? null, 'source', $manifestPath);
            $target = self::relativePath($entry['target'] ?? null, 'target', $manifestPath);
            $type = $entry['type'] ?? 'file';
            $policy = $entry['policy'] ?? 'managed_hash';
            $trackState = $entry['track_state'] ?? true;
            $groupName = $entry['group'] ?? $target;

            if (!in_array($type, ['file', 'dir'], true)) {
                throw new RuntimeException(sprintf(
                    'Tipo project_files inválido en %s.',
                    $manifestPath
                ));
            }
            if (!is_string($policy) || !in_array($policy, $supportedPolicies, true)) {
                throw new RuntimeException(sprintf(
                    'Política project_files inválida en %s.',
                    $manifestPath
                ));
            }
            if (
                $policy === 'merge_json_additive'
                && (
                    $type !== 'file'
                    || strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== 'json'
                    || strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'json'
                )
            ) {
                throw new RuntimeException(sprintf(
                    'merge_json_additive solo admite ficheros JSON explícitos en %s.',
                    $manifestPath
                ));
            }
            if (!is_bool($trackState)) {
                throw new RuntimeException(sprintf(
                    'track_state debe ser booleano en %s.',
                    $manifestPath
                ));
            }
            if (
                !is_string($groupName)
                || $groupName === ''
                || preg_match('/[\x00-\x1F\x7F:]/', $groupName) === 1
            ) {
                throw new RuntimeException(sprintf(
                    'group debe ser un identificador no vacío y sin dos puntos en %s.',
                    $manifestPath
                ));
            }

            self::assertNamespacedProjectTarget(
                $target,
                $id,
                $manifestPath
            );
            self::assertUniqueProjectTarget(
                $target,
                $type,
                $targets,
                $manifestPath
            );
            $targets[] = ['target' => $target, 'type' => $type];

            $files[] = [
                'source' => $source,
                'target' => $target,
                'type' => $type,
                'policy' => $policy,
                'group' => 'module:' . $id . ':' . $groupName,
                'track_state' => $trackState,
            ];
        }

        return $files;
    }

    private static function relativePath(
        mixed $value,
        string $key,
        string $manifestPath
    ): string {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf(
                'La ruta %s de %s no es válida.',
                $key,
                $manifestPath
            ));
        }

        $normalized = str_replace('\\', '/', $value);
        $segments = explode('/', $normalized);
        if (
            str_starts_with($normalized, '/')
            || preg_match('/\A[A-Za-z]:\//', $normalized) === 1
            || preg_match('/[\x00-\x1F\x7F:]/', $normalized) === 1
            || in_array('..', $segments, true)
            || in_array('.', $segments, true)
            || in_array('', $segments, true)
        ) {
            throw new RuntimeException(sprintf(
                'La ruta %s de %s debe ser relativa y no puede escapar del módulo.',
                $key,
                $manifestPath
            ));
        }

        return $normalized;
    }

    /**
     * @param 'file'|'dir' $type
     * @param list<array{target: string, type: 'file'|'dir'}> $targets
     */
    private static function assertUniqueProjectTarget(
        string $target,
        string $type,
        array $targets,
        string $manifestPath
    ): void {
        foreach ($targets as $existing) {
            $same = $target === $existing['target'];
            $insideExistingDirectory = $existing['type'] === 'dir'
                && str_starts_with($target, $existing['target'] . '/');
            $containsExistingTarget = $type === 'dir'
                && str_starts_with(
                    $existing['target'],
                    $target . '/'
                );

            if ($same || $insideExistingDirectory || $containsExistingTarget) {
                throw new RuntimeException(sprintf(
                    'El destino %s colisiona con otra entrada project_files de %s.',
                    $target,
                    $manifestPath
                ));
            }
        }
    }

    private static function assertNamespacedProjectTarget(
        string $target,
        string $id,
        string $manifestPath
    ): void {
        $roots = [
            'public/assets/modules/' . $id,
            'src/js/modules/' . $id,
            'src/scss/modules/' . $id,
        ];

        foreach ($roots as $root) {
            if ($target === $root || str_starts_with($target, $root . '/')) {
                return;
            }
        }

        throw new RuntimeException(sprintf(
            'El destino %s de %s no pertenece al espacio público del módulo %s.',
            $target,
            $manifestPath,
            $id
        ));
    }

    private static function stripUtf8Bom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF")
            ? substr($contents, 3)
            : $contents;
    }
}
