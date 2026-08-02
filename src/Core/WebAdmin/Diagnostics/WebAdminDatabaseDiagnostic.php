<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Diagnostics;

use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\Modules\Migrations\MigrationFeatureReadiness;
use App\Core\Modules\WebAdmin\WebAdminMigrationRequirements;

/**
 * Secret-free projection of the read-only migration probe used by doctor.
 */
final class WebAdminDatabaseDiagnostic
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(private readonly array $payload)
    {
    }

    public static function notChecked(): self
    {
        return new self([
            'connection' => [
                'ready' => false,
                'status' => 'not_checked',
                'driver' => null,
            ],
            'migrations' => [
                'ready' => false,
                'status' => 'not_checked',
                'base' => self::uncheckedBase(),
                'features' => self::uncheckedFeatures(),
                'media' => self::uncheckedMedia(),
                'registry_exists' => null,
                'counts' => [
                    'catalog' => 0,
                    'pending' => 0,
                    'applied' => 0,
                    'not_ready' => 0,
                    'blockers' => 0,
                ],
                'entries' => [],
                'blockers' => [],
            ],
        ]);
    }

    public static function unavailable(): self
    {
        return new self([
            'connection' => [
                'ready' => false,
                'status' => 'unavailable',
                'driver' => null,
            ],
            'migrations' => [
                'ready' => false,
                'status' => 'not_checked',
                'base' => self::uncheckedBase(),
                'features' => self::uncheckedFeatures(),
                'media' => self::uncheckedMedia(),
                'registry_exists' => null,
                'counts' => [
                    'catalog' => 0,
                    'pending' => 0,
                    'applied' => 0,
                    'not_ready' => 0,
                    'blockers' => 0,
                ],
                'entries' => [],
                'blockers' => [],
            ],
        ]);
    }

    public static function fromPlan(
        MigrationDatabasePlan $plan,
        string $moduleId = 'webadmin'
    ): self {
        $entries = [];
        foreach ($plan->entries() as $entry) {
            if (($entry['module'] ?? null) !== $moduleId) {
                continue;
            }

            $entries[] = [
                'module' => $moduleId,
                'id' => is_string($entry['id'] ?? null)
                    ? $entry['id']
                    : '[invalid]',
                'status' => is_string($entry['status'] ?? null)
                    ? $entry['status']
                    : 'invalid',
                'destructive' => ($entry['destructive'] ?? false) === true,
            ];
        }

        $blockers = array_map(
            static fn (array $blocker): array => [
                'code' => is_string($blocker['code'] ?? null)
                    ? $blocker['code']
                    : 'migration.unknown_blocker',
                'module' => is_string($blocker['module'] ?? null)
                    ? $blocker['module']
                    : null,
                'migration' => is_string($blocker['migration'] ?? null)
                    ? $blocker['migration']
                    : null,
            ],
            $plan->blockers()
        );
        $connectionContractReady = array_filter(
            $blockers,
            static fn (array $blocker): bool => str_starts_with(
                (string) $blocker['code'],
                'database.'
            )
        ) === [];
        $applied = count(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['status'] === 'applied'
        ));
        $pending = count(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['status'] === 'pending'
        ));
        $notReady = count($entries) - $applied;
        $featureReadiness = MigrationFeatureReadiness::fromPlan(
            $plan,
            WebAdminMigrationRequirements::runtime()
        );
        $mediaReadiness = MigrationFeatureReadiness::fromPlan(
            $plan,
            WebAdminMigrationRequirements::media()
        );
        $base = $featureReadiness->base();
        $features = $featureReadiness->features();
        $ready = $connectionContractReady
            && $featureReadiness->baseReady();
        $status = $connectionContractReady
            ? $featureReadiness->baseStatus()
            : 'blocked';

        return new self([
            'connection' => [
                'ready' => $connectionContractReady,
                'status' => $connectionContractReady
                    ? 'connected'
                    : 'contract_invalid',
                'driver' => $plan->driver(),
            ],
            'migrations' => [
                'ready' => $ready,
                'status' => $status,
                'base' => $base,
                'features' => $features,
                'media' => $mediaReadiness->base(),
                'registry_exists' => $plan->registryExists(),
                'counts' => [
                    'catalog' => count($entries),
                    'pending' => $pending,
                    'applied' => $applied,
                    'not_ready' => $notReady,
                    'blockers' => count($blockers),
                ],
                'entries' => $entries,
                'blockers' => $blockers,
            ],
        ]);
    }

    public function connectionReady(): bool
    {
        return $this->payload['connection']['ready'] === true;
    }

    public function migrationsReady(): bool
    {
        return $this->payload['migrations']['ready'] === true;
    }

    public function connectionStatus(): string
    {
        return (string) $this->payload['connection']['status'];
    }

    public function migrationStatus(): string
    {
        return (string) $this->payload['migrations']['status'];
    }

    /** @return array<string, mixed> */
    public function mediaMigrations(): array
    {
        $media = $this->payload['migrations']['media'] ?? null;

        return is_array($media) ? $media : self::uncheckedMedia();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }

    /** @return array<string, mixed> */
    private static function uncheckedBase(): array
    {
        return [
            'ready' => false,
            'status' => 'not_checked',
            'required' => WebAdminMigrationRequirements::runtime()
                ->migrationIds(),
            'pending' => [],
            'missing' => [],
            'blockers' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function uncheckedFeatures(): array
    {
        return [
            'ready' => false,
            'status' => 'not_checked',
            'known' => [],
            'pending' => [],
            'blockers' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function uncheckedMedia(): array
    {
        return [
            'ready' => false,
            'status' => 'not_checked',
            'required' => WebAdminMigrationRequirements::media()
                ->migrationIds(),
            'pending' => [],
            'missing' => [],
            'blockers' => [],
        ];
    }
}
