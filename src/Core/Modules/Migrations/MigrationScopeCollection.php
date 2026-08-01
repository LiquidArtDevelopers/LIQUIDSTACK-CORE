<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use InvalidArgumentException;

final class MigrationScopeCollection
{
    /** @param array<string, MigrationScope> $scopes */
    private function __construct(private readonly array $scopes)
    {
    }

    /** @param array<string, string> $prefixesByModule */
    public static function fromTablePrefixes(array $prefixesByModule): self
    {
        $scopes = [];
        foreach ($prefixesByModule as $module => $prefix) {
            if (!is_string($module) || !is_string($prefix)) {
                throw new InvalidArgumentException(
                    'Los scopes de migración deben mapear módulo a prefijo.'
                );
            }
            $scope = MigrationScope::forTablePrefix($module, $prefix);
            $scopes[$scope->moduleId()] = $scope;
        }

        ksort($scopes, SORT_STRING);

        return new self($scopes);
    }

    public function get(string $moduleId): ?MigrationScope
    {
        return $this->scopes[$moduleId] ?? null;
    }

    /** @return list<MigrationScope> */
    public function all(): array
    {
        return array_values($this->scopes);
    }
}
