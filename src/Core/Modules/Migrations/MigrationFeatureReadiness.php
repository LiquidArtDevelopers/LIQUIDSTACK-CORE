<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

/** Secret-free doctor projection for one migration feature boundary. */
final class MigrationFeatureReadiness
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(private readonly array $payload)
    {
    }

    public static function fromPlan(
        MigrationDatabasePlan $plan,
        MigrationFeatureRequirement $requirement
    ): self {
        $moduleId = $requirement->moduleId();
        $required = array_fill_keys($requirement->migrationIds(), true);
        $requiredIds = $requirement->migrationIds();
        $lastRequiredId = $requiredIds[count($requiredIds) - 1];
        $states = [];
        $featureIds = [];
        $invalidCatalog = false;

        foreach ($plan->entries() as $entry) {
            if (($entry['module'] ?? null) !== $moduleId) {
                continue;
            }
            $migrationId = $entry['id'] ?? null;
            $status = $entry['status'] ?? null;
            if (
                !is_string($migrationId)
                || !is_string($status)
                || isset($states[$migrationId])
            ) {
                $invalidCatalog = true;
                continue;
            }
            $states[$migrationId] = $status;
            if (
                !isset($required[$migrationId])
                && $status !== 'unknown_applied'
            ) {
                if (strcmp($migrationId, $lastRequiredId) > 0) {
                    $featureIds[$migrationId] = true;
                } else {
                    $invalidCatalog = true;
                }
            }
            if ($status === 'unknown_applied') {
                $invalidCatalog = true;
            }
        }
        ksort($states, SORT_STRING);
        ksort($featureIds, SORT_STRING);

        $baseBlockers = [];
        $featureBlockers = [];
        foreach ($plan->blockers() as $blocker) {
            $safe = self::safeBlocker($blocker);
            $blockerModule = $safe['module'];
            $migrationId = $safe['migration'];

            if ($blockerModule !== null && $blockerModule !== $moduleId) {
                continue;
            }
            if (
                $blockerModule === $moduleId
                && $migrationId !== null
                && isset($featureIds[$migrationId])
            ) {
                $featureBlockers[] = $safe;
                continue;
            }
            $baseBlockers[] = $safe;
        }
        if ($invalidCatalog) {
            $baseBlockers[] = [
                'code' => 'migration.catalog_invalid',
                'module' => $moduleId,
                'migration' => null,
            ];
        }
        $baseBlockers = self::uniqueBlockers($baseBlockers);
        $featureBlockers = self::uniqueBlockers($featureBlockers);

        $pendingBase = [];
        $missingBase = [];
        foreach ($requirement->migrationIds() as $migrationId) {
            if (($states[$migrationId] ?? null) !== 'applied') {
                $pendingBase[] = $migrationId;
            }
            if (!array_key_exists($migrationId, $states)) {
                $missingBase[] = $migrationId;
            }
        }

        $pendingFeatures = [];
        foreach (array_keys($featureIds) as $migrationId) {
            if (($states[$migrationId] ?? null) !== 'applied') {
                $pendingFeatures[] = $migrationId;
            }
        }

        $baseReady = $pendingBase === [] && $baseBlockers === [];
        $featuresReady = $featureIds === []
            || ($pendingFeatures === [] && $featureBlockers === []);

        return new self([
            'base' => [
                'ready' => $baseReady,
                'status' => $baseBlockers !== []
                    ? 'blocked'
                    : ($missingBase !== []
                        ? 'catalog_missing'
                        : ($pendingBase === [] ? 'applied' : 'pending')),
                'required' => $requirement->migrationIds(),
                'pending' => $pendingBase,
                'missing' => $missingBase,
                'blockers' => $baseBlockers,
            ],
            'features' => [
                'ready' => $featuresReady,
                'status' => $featureIds === []
                    ? 'not_applicable'
                    : ($featureBlockers !== []
                        ? 'blocked'
                        : ($pendingFeatures === [] ? 'applied' : 'pending')),
                'known' => array_keys($featureIds),
                'pending' => $pendingFeatures,
                'blockers' => $featureBlockers,
            ],
        ]);
    }

    public function baseReady(): bool
    {
        return $this->payload['base']['ready'] === true;
    }

    public function baseStatus(): string
    {
        return (string) $this->payload['base']['status'];
    }

    /** @return array<string, mixed> */
    public function base(): array
    {
        return $this->payload['base'];
    }

    /** @return array<string, mixed> */
    public function features(): array
    {
        return $this->payload['features'];
    }

    /**
     * @param array<string, mixed> $blocker
     * @return array{code: string, module: ?string, migration: ?string}
     */
    private static function safeBlocker(array $blocker): array
    {
        return [
            'code' => is_string($blocker['code'] ?? null)
                ? $blocker['code']
                : 'migration.unknown_blocker',
            'module' => is_string($blocker['module'] ?? null)
                ? $blocker['module']
                : null,
            'migration' => is_string($blocker['migration'] ?? null)
                ? $blocker['migration']
                : null,
        ];
    }

    /**
     * @param list<array{code: string, module: ?string, migration: ?string}> $blockers
     * @return list<array{code: string, module: ?string, migration: ?string}>
     */
    private static function uniqueBlockers(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $key = implode("\0", [
                $blocker['code'],
                $blocker['module'] ?? '',
                $blocker['migration'] ?? '',
            ]);
            $unique[$key] = $blocker;
        }

        return array_values($unique);
    }
}
