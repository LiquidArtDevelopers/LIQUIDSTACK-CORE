<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

final class MigrationRunResult
{
    /**
     * @param list<array{module: string, id: string, checksum: string}> $applied
     * @param list<array{module: string, id: string, checksum: string, destructive: bool, reason: string}> $deferred
     */
    public function __construct(
        private readonly string $planHash,
        private readonly ?int $batch,
        private readonly array $applied,
        private readonly array $deferred = []
    ) {
    }

    public function planHash(): string
    {
        return $this->planHash;
    }

    public function batch(): ?int
    {
        return $this->batch;
    }

    /** @return list<array{module: string, id: string, checksum: string}> */
    public function applied(): array
    {
        return $this->applied;
    }

    public function changed(): bool
    {
        return $this->applied !== [];
    }

    /**
     * @return list<array{
     *     module: string,
     *     id: string,
     *     checksum: string,
     *     destructive: bool,
     *     reason: string
     * }>
     */
    public function deferred(): array
    {
        return $this->deferred;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => true,
            'operation' => 'migrate-apply',
            'plan_hash' => $this->planHash,
            'batch' => $this->batch,
            'changed' => $this->changed(),
            'applied' => $this->applied,
            'deferred' => $this->deferred,
        ];
    }
}
