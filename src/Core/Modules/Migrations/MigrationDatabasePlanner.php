<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use PDO;

final class MigrationDatabasePlanner
{
    public function __construct(
        private readonly MigrationRegistry $registry = new MigrationRegistry(),
        private readonly MigrationDatabaseConnectionContract $connectionContract =
            new MigrationDatabaseConnectionContract()
    ) {
    }

    public function plan(
        PDO $pdo,
        MigrationCatalog $catalog,
        MigrationScopeCollection $scopes
    ): MigrationDatabasePlan {
        $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
        $databaseContractBlockers = array_map(
            static fn (string $code): array => [
                'code' => $code,
                'module' => null,
                'migration' => null,
            ],
            $this->connectionContract->issueCodes($pdo, $driver)
        );
        if ($databaseContractBlockers !== []) {
            return $this->blockedConnectionPlan(
                $driver,
                $catalog,
                $scopes,
                $databaseContractBlockers
            );
        }
        $registryExists = $this->registry->exists($pdo);
        $applied = $registryExists ? $this->registry->all($pdo) : [];
        $appliedByKey = [];
        foreach ($applied as $record) {
            if (in_array(
                $record->moduleId(),
                $catalog->activeModuleIds(),
                true
            )) {
                $appliedByKey[$this->key(
                    $record->moduleId(),
                    $record->migrationId()
                )] = $record;
            }
        }

        $entries = [];
        $blockers = $databaseContractBlockers;
        $catalogKeys = [];
        $supersededPostconditions = $this->supersededPostconditions(
            $pdo,
            $catalog,
            $appliedByKey,
            $scopes
        );

        foreach ($catalog->entries() as $catalogEntry) {
            $module = $catalogEntry['module'];
            $migration = $catalogEntry['migration'];
            $key = $this->key($module, $migration->id());
            $catalogKeys[$key] = true;
            $targetScopeModule = $migration->targetScopeModuleId() ?? $module;
            $scope = $migration->targetScope($module, $scopes);
            $status = 'pending';

            if ($scope === null) {
                $status = 'scope_missing';
                $blockers[] = $this->blocker(
                    'migration.scope_missing',
                    $module,
                    $migration->id()
                );
            } elseif (!$migration->isExecutableFor($driver)) {
                $status = 'not_executable';
                $blockers[] = $this->blocker(
                    'migration.not_executable',
                    $module,
                    $migration->id()
                );
            } elseif (!$this->hasValidRuntimeSql(
                $migration,
                $scope,
                $driver
            )) {
                $status = 'sql_contract_invalid';
                $blockers[] = $this->blocker(
                    'migration.sql_contract_invalid',
                    $module,
                    $migration->id()
                );
            } elseif (
                !$migration->isTransactionalFor($driver)
                && !$migration->isRetrySafe()
            ) {
                $status = 'unsafe_non_transactional';
                $blockers[] = $this->blocker(
                    'migration.non_transactional_not_retry_safe',
                    $module,
                    $migration->id()
                );
            } elseif (isset($appliedByKey[$key])) {
                $record = $appliedByKey[$key];
                if ($record->checksum() !== $migration->checksum()) {
                    $status = 'checksum_mismatch';
                    $blockers[] = $this->blocker(
                        'migration.checksum_mismatch',
                        $module,
                        $migration->id()
                    );
                } elseif ($record->scopeHash() !== $scope->hash()) {
                    $status = 'scope_mismatch';
                    $blockers[] = $this->blocker(
                        'migration.scope_mismatch',
                        $module,
                        $migration->id()
                    );
                } elseif (
                    !isset($supersededPostconditions[$key])
                    && !$this->postconditionIsSatisfied(
                        $pdo,
                        $migration,
                        $scope
                    )
                ) {
                    $status = 'postcondition_drift';
                    $blockers[] = $this->blocker(
                        'migration.postcondition_drift',
                        $module,
                        $migration->id()
                    );
                } else {
                    $status = 'applied';
                }
            } elseif (!$this->preconditionIsSatisfied(
                $pdo,
                $migration,
                $scope
            )) {
                $status = 'precondition_failed';
                $blockers[] = $this->blocker(
                    'migration.precondition_failed',
                    $module,
                    $migration->id()
                );
            }

            $entries[] = [
                'module' => $module,
                'target_scope_module' => $targetScopeModule,
                'id' => $migration->id(),
                'description' => $migration->description(),
                'checksum' => $migration->checksum(),
                'scope_hash' => $scope?->hash(),
                'destructive' => $migration->isDestructive(),
                'status' => $status,
            ];
        }

        $this->blockOutOfOrderPending($entries, $blockers);

        foreach ($appliedByKey as $key => $record) {
            if (isset($catalogKeys[$key])) {
                continue;
            }
            $entries[] = [
                'module' => $record->moduleId(),
                'target_scope_module' => null,
                'id' => $record->migrationId(),
                'description' => '',
                'checksum' => $record->checksum(),
                'scope_hash' => $record->scopeHash(),
                'destructive' => false,
                'status' => 'unknown_applied',
            ];
            $blockers[] = $this->blocker(
                'migration.unknown_applied',
                $record->moduleId(),
                $record->migrationId()
            );
        }

        return new MigrationDatabasePlan(
            $driver,
            $registryExists,
            $entries,
            $blockers
        );
    }

    /**
     * A migration catalog is append-only inside each module. Introducing an
     * identifier that sorts before an already applied migration would execute
     * the new definition after a schema state that was designed to follow it.
     *
     * @param list<array<string, mixed>> $entries
     * @param list<array{code: string, module: string, migration: string}> $blockers
     */
    private function blockOutOfOrderPending(
        array &$entries,
        array &$blockers
    ): void {
        $latestAppliedByModule = [];
        foreach ($entries as $entry) {
            if ($entry['status'] !== 'applied') {
                continue;
            }

            $module = (string) $entry['module'];
            $id = (string) $entry['id'];
            if (
                !isset($latestAppliedByModule[$module])
                || strcmp($id, $latestAppliedByModule[$module]) > 0
            ) {
                $latestAppliedByModule[$module] = $id;
            }
        }

        foreach ($entries as &$entry) {
            $module = (string) $entry['module'];
            if (
                $entry['status'] !== 'pending'
                || !isset($latestAppliedByModule[$module])
                || strcmp(
                    (string) $entry['id'],
                    $latestAppliedByModule[$module]
                ) >= 0
            ) {
                continue;
            }

            $entry['status'] = 'out_of_order';
            $blockers[] = $this->blocker(
                'migration.out_of_order',
                $module,
                (string) $entry['id']
            );
        }
        unset($entry);
    }

    private function key(string $module, string $migration): string
    {
        return $module . "\0" . $migration;
    }

    /**
     * @param array<string, AppliedMigration> $appliedByKey
     * @return array<string, true>
     */
    private function supersededPostconditions(
        PDO $pdo,
        MigrationCatalog $catalog,
        array $appliedByKey,
        MigrationScopeCollection $scopes
    ): array {
        $superseded = [];
        foreach ($catalog->entries() as $entry) {
            $migration = $entry['migration'];
            $module = $entry['module'];
            $record = $appliedByKey[$this->key(
                $module,
                $migration->id()
            )] ?? null;
            $scope = $migration->targetScope($module, $scopes);
            if (
                !$record instanceof AppliedMigration
                || $scope === null
                || $record->checksum() !== $migration->checksum()
                || $record->scopeHash() !== $scope->hash()
                || !$this->postconditionIsSatisfied(
                    $pdo,
                    $migration,
                    $scope
                )
            ) {
                continue;
            }
            foreach (
                $migration->supersededPostconditionIds()
                as $targetId
            ) {
                // Only a recorded and currently valid superseder can retire
                // the old verifier. Pending or drifted composite migrations
                // must still prove the state they extend.
                $superseded[$this->key($module, $targetId)] = true;
            }
        }

        return $superseded;
    }

    private function hasValidRuntimeSql(
        MigrationDefinition $migration,
        MigrationScope $scope,
        string $driver
    ): bool {
        try {
            return $migration->statementsFor($driver, $scope) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function postconditionIsSatisfied(
        PDO $pdo,
        MigrationDefinition $migration,
        MigrationScope $scope
    ): bool {
        $verifier = $migration->postconditionVerifier();
        if ($verifier === null) {
            return true;
        }

        try {
            return $verifier->verify($pdo, $scope);
        } catch (\Throwable) {
            return false;
        }
    }

    private function preconditionIsSatisfied(
        PDO $pdo,
        MigrationDefinition $migration,
        MigrationScope $scope
    ): bool {
        $verifier = $migration->preconditionVerifier();
        if ($verifier === null) {
            return true;
        }

        try {
            return $verifier->verify($pdo, $scope);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<array{code: string, module: null, migration: null}> $blockers
     */
    private function blockedConnectionPlan(
        string $driver,
        MigrationCatalog $catalog,
        MigrationScopeCollection $scopes,
        array $blockers
    ): MigrationDatabasePlan {
        $entries = [];
        foreach ($catalog->entries() as $catalogEntry) {
            $module = $catalogEntry['module'];
            $migration = $catalogEntry['migration'];
            $entries[] = [
                'module' => $module,
                'target_scope_module' =>
                    $migration->targetScopeModuleId() ?? $module,
                'id' => $migration->id(),
                'description' => $migration->description(),
                'checksum' => $migration->checksum(),
                'scope_hash' => $migration
                    ->targetScope($module, $scopes)?->hash(),
                'destructive' => $migration->isDestructive(),
                'status' => 'database_contract_invalid',
            ];
        }

        return new MigrationDatabasePlan(
            $driver,
            false,
            $entries,
            $blockers
        );
    }

    /** @return array{code: string, module: string, migration: string} */
    private function blocker(
        string $code,
        string $module,
        string $migration
    ): array {
        return [
            'code' => $code,
            'module' => $module,
            'migration' => $migration,
        ];
    }
}
