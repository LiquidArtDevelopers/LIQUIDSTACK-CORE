<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use InvalidArgumentException;

/**
 * Immutable migration boundary for one runtime feature.
 *
 * A feature declares the migrations it actually consumes. Later catalog
 * entries may remain pending without disabling an older, independent path.
 */
final class MigrationFeatureRequirement
{
    /** @var list<string> */
    private readonly array $migrationIds;

    /**
     * @param list<string> $migrationIds
     */
    public function __construct(
        private readonly string $moduleId,
        private readonly string $featureId,
        array $migrationIds
    ) {
        if (
            preg_match('/\A[a-z][a-z0-9-]{0,62}\z/', $moduleId) !== 1
            || preg_match('/\A[a-z][a-z0-9._-]{0,127}\z/', $featureId) !== 1
            || $migrationIds === []
            || !array_is_list($migrationIds)
        ) {
            throw new InvalidArgumentException(
                'El requisito de migraciones de la funcionalidad no es valido.'
            );
        }

        $normalized = [];
        foreach ($migrationIds as $migrationId) {
            if (
                !is_string($migrationId)
                || strlen($migrationId) > 190
                || preg_match(
                    '/\A[a-z0-9][a-z0-9._-]*\z/',
                    $migrationId
                ) !== 1
                || isset($normalized[$migrationId])
            ) {
                throw new InvalidArgumentException(
                    'El requisito contiene migraciones invalidas o duplicadas.'
                );
            }
            $normalized[$migrationId] = true;
        }

        $ids = array_keys($normalized);
        sort($ids, SORT_STRING);
        $this->migrationIds = $ids;
    }

    public function moduleId(): string
    {
        return $this->moduleId;
    }

    public function featureId(): string
    {
        return $this->featureId;
    }

    /** @return list<string> */
    public function migrationIds(): array
    {
        return $this->migrationIds;
    }

    public function requires(string $migrationId): bool
    {
        return in_array($migrationId, $this->migrationIds, true);
    }
}
