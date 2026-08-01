<?php

declare(strict_types=1);

namespace App\Core\Modules\Migrations;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class MigrationRunner
{
    public function __construct(
        private readonly MigrationRegistry $registry = new MigrationRegistry(),
        private readonly MigrationDatabasePlanner $planner = new MigrationDatabasePlanner(),
        private readonly MigrationLockFactory $lockFactory = new MigrationLockFactory()
    ) {
    }

    public function apply(
        PDO $pdo,
        MigrationCatalog $catalog,
        MigrationScopeCollection $scopes,
        ?MigrationApplyOptions $options = null
    ): MigrationRunResult {
        $options ??= new MigrationApplyOptions();
        $preflight = $this->planner->plan($pdo, $catalog, $scopes);
        $this->assertApplicable($preflight, $options);

        if (
            $options->expectedPlanHash() !== null
            && $options->expectedPlanHash() !== $preflight->hash()
        ) {
            throw new MigrationException('migrations.plan_changed');
        }
        if ($preflight->pendingEntries() === []) {
            return new MigrationRunResult($preflight->hash(), null, []);
        }

        $lock = $this->lockFactory->create($pdo);
        $lockAcquired = false;
        $successful = false;
        $operationException = null;

        try {
            $lock->acquire($pdo, $options->lockTimeoutSeconds());
            $lockAcquired = true;
            // Re-plan under the migration lock. This deliberately re-runs
            // every pending precondition before ensureExists() or any
            // migration DDL/DML can write to the database.
            $lockedPlan = $this->planner->plan($pdo, $catalog, $scopes);
            $this->assertApplicable($lockedPlan, $options);
            if ($lockedPlan->hash() !== $preflight->hash()) {
                throw new MigrationException('migrations.plan_changed');
            }

            $this->registry->ensureExists($pdo);
            $batch = $this->registry->nextBatch($pdo);
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            $definitions = $this->definitionsByKey($catalog);
            $applied = [];

            foreach ($lockedPlan->pendingEntries() as $entry) {
                $module = (string) $entry['module'];
                $id = (string) $entry['id'];
                $definition = $definitions[$this->key($module, $id)] ?? null;
                $scope = $definition instanceof MigrationDefinition
                    ? $definition->targetScope($module, $scopes)
                    : null;
                if (!$definition instanceof MigrationDefinition || $scope === null) {
                    throw new MigrationException(
                        'migration.runtime_contract_invalid',
                        $module,
                        $id
                    );
                }

                $ownsTransaction = $lock->ownsBatchTransaction();
                $migrationTransaction = !$ownsTransaction
                    && $definition->isTransactionalFor($driver);
                try {
                    if ($migrationTransaction) {
                        if (!$pdo->beginTransaction()) {
                            throw new MigrationException(
                                'migration.transaction_start_failed',
                                $module,
                                $id
                            );
                        }
                    }
                    foreach ($definition->statementsFor($driver, $scope) as $sql) {
                        if ($pdo->exec($sql) === false) {
                            throw new MigrationException(
                                'migration.execution_failed',
                                $module,
                                $id
                            );
                        }
                    }
                    $verifier = $definition->postconditionVerifier();
                    if ($verifier !== null) {
                        try {
                            $verified = $verifier->verify($pdo, $scope);
                        } catch (Throwable) {
                            $verified = false;
                        }
                        if (!$verified) {
                            throw new MigrationException(
                                'migration.postcondition_failed',
                                $module,
                                $id
                            );
                        }
                    }
                    if ($migrationTransaction && !$pdo->inTransaction()) {
                        throw new MigrationException(
                            'migration.transaction_lost',
                            $module,
                            $id
                        );
                    }
                    $this->registry->record(
                        $pdo,
                        $module,
                        $definition,
                        $scope,
                        $batch,
                        new DateTimeImmutable('now', new DateTimeZone('UTC'))
                    );
                    if ($migrationTransaction) {
                        if (!$pdo->commit()) {
                            throw new MigrationException(
                                'migration.commit_failed',
                                $module,
                                $id
                            );
                        }
                    }
                } catch (Throwable $exception) {
                    if ($migrationTransaction && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($exception instanceof MigrationException) {
                        throw $exception;
                    }
                    throw new MigrationException(
                        'migration.execution_failed',
                        $module,
                        $id
                    );
                }

                $applied[] = [
                    'module' => $module,
                    'id' => $id,
                    'checksum' => $definition->checksum(),
                ];
            }

            $postflight = $this->planner->plan($pdo, $catalog, $scopes);
            if (
                !$postflight->isApplicable()
                || $postflight->pendingEntries() !== []
            ) {
                throw new MigrationException(
                    'migrations.postflight_failed'
                );
            }

            $successful = true;
            $result = new MigrationRunResult(
                $lockedPlan->hash(),
                $batch,
                $applied
            );
        } catch (Throwable $exception) {
            $operationException = $exception instanceof MigrationException
                ? $exception
                : new MigrationException('migrations.apply_failed');
        } finally {
            if ($lockAcquired) {
                try {
                    $lock->release($pdo, $successful);
                } catch (MigrationException $releaseException) {
                    $operationException ??= $releaseException;
                }
            }
        }

        if ($operationException instanceof MigrationException) {
            throw $operationException;
        }

        return $result;
    }

    private function assertApplicable(
        MigrationDatabasePlan $plan,
        MigrationApplyOptions $options
    ): void {
        if (!$plan->isApplicable()) {
            $blocker = $plan->blockers()[0];
            throw new MigrationException(
                $blocker['code'],
                $blocker['module'],
                $blocker['migration']
            );
        }
        if ($plan->hasPendingDestructive()) {
            if (!$options->allowDestructive()) {
                throw new MigrationException(
                    'migrations.destructive_not_allowed'
                );
            }
            if (!$options->backupConfirmed()) {
                throw new MigrationException(
                    'migrations.backup_not_confirmed'
                );
            }
        }
    }

    /** @return array<string, MigrationDefinition> */
    private function definitionsByKey(MigrationCatalog $catalog): array
    {
        $definitions = [];
        foreach ($catalog->entries() as $entry) {
            $definitions[$this->key(
                $entry['module'],
                $entry['migration']->id()
            )] = $entry['migration'];
        }

        return $definitions;
    }

    private function key(string $module, string $migration): string
    {
        return $module . "\0" . $migration;
    }
}
