<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

final class MigrationDatabasePlan
{
    private const HASH_SCHEMA = 2;

    /**
     * @param list<array{
     *     module: string,
     *     target_scope_module: ?string,
     *     id: string,
     *     description: string,
     *     checksum: string,
     *     scope_hash: ?string,
     *     destructive: bool,
     *     status: string
     * }> $entries
     * @param list<array{code: string, module: ?string, migration: ?string}> $blockers
     */
    public function __construct(
        private readonly string $driver,
        private readonly bool $registryExists,
        private readonly array $entries,
        private readonly array $blockers
    ) {
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function registryExists(): bool
    {
        return $this->registryExists;
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<array{code: string, module: ?string, migration: ?string}> */
    public function blockers(): array
    {
        return $this->blockers;
    }

    public function isApplicable(): bool
    {
        return $this->blockers === [];
    }

    /** @return list<array<string, mixed>> */
    public function pendingEntries(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['status'] === 'pending'
        ));
    }

    public function hasPendingDestructive(): bool
    {
        foreach ($this->pendingEntries() as $entry) {
            if ($entry['destructive'] === true) {
                return true;
            }
        }

        return false;
    }

    public function hash(): string
    {
        return hash('sha256', (string) json_encode([
            'schema' => self::HASH_SCHEMA,
            'driver' => $this->driver,
            'entries' => $this->entries,
            'blockers' => $this->blockers,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $pending = $this->pendingEntries();

        return [
            'mode' => 'database',
            'read_only' => true,
            'driver' => $this->driver,
            'database_state' => $this->blockers !== []
                ? 'blocked'
                : ($this->registryExists ? 'ready' : 'registry_missing'),
            'registry_exists' => $this->registryExists,
            'plan_hash' => $this->hash(),
            'counts' => [
                'catalog' => count(array_filter(
                    $this->entries,
                    static fn (array $entry): bool =>
                        $entry['status'] !== 'unknown_applied'
                )),
                'pending' => count($pending),
                'applied' => count(array_filter(
                    $this->entries,
                    static fn (array $entry): bool =>
                        $entry['status'] === 'applied'
                )),
                'destructive_pending' => count(array_filter(
                    $pending,
                    static fn (array $entry): bool =>
                        $entry['destructive'] === true
                )),
                'blockers' => count($this->blockers),
            ],
            'entries' => $this->entries,
            'blockers' => $this->blockers,
        ];
    }
}
