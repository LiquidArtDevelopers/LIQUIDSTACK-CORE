<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

final class MigrationPlan
{
    /**
     * @param list<array{
     *     module: string,
     *     target_scope_module: string,
     *     id: string,
     *     description: string,
     *     checksum: string,
     *     destructive: bool
     * }> $entries
     */
    private function __construct(private readonly array $entries)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromCatalog(MigrationCatalog $catalog): self
    {
        $entries = [];

        foreach ($catalog->entries() as $entry) {
            $entries[] = array_merge(
                [
                    'module' => $entry['module'],
                    'target_scope_module' =>
                        $entry['migration']->targetScopeModuleId()
                            ?? $entry['module'],
                ],
                $entry['migration']->toArray()
            );
        }

        return new self($entries);
    }

    /**
     * @return list<array{
     *     module: string,
     *     target_scope_module: string,
     *     id: string,
     *     description: string,
     *     checksum: string,
     *     destructive: bool
     * }>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return array{
     *     mode: 'catalog-only',
     *     read_only: true,
     *     database_state: 'not_evaluated',
     *     count: int,
     *     entries: list<array{
     *         module: string,
     *         target_scope_module: string,
     *         id: string,
     *         description: string,
     *         checksum: string,
     *         destructive: bool
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'mode' => 'catalog-only',
            'read_only' => true,
            'database_state' => 'not_evaluated',
            'count' => count($this->entries),
            'entries' => $this->entries,
        ];
    }
}
