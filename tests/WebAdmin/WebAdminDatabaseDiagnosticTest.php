<?php

declare(strict_types=1);

use App\Core\Modules\Migrations\MigrationDatabasePlan;
use App\Core\WebAdmin\Diagnostics\WebAdminDatabaseDiagnostic;
use PHPUnit\Framework\TestCase;

final class WebAdminDatabaseDiagnosticTest extends TestCase
{
    public function testAppliedPlanProducesOnlyASafeOperationalProjection(): void
    {
        $diagnostic = WebAdminDatabaseDiagnostic::fromPlan(
            $this->plan('applied')
        );
        $encoded = json_encode(
            $diagnostic->toArray(),
            JSON_THROW_ON_ERROR
        );

        self::assertTrue($diagnostic->connectionReady());
        self::assertTrue($diagnostic->migrationsReady());
        self::assertSame('connected', $diagnostic->connectionStatus());
        self::assertSame('applied', $diagnostic->migrationStatus());
        self::assertStringNotContainsString(
            'sensitive-description-must-not-leak',
            $encoded
        );
        self::assertStringNotContainsString(str_repeat('a', 64), $encoded);
        self::assertStringNotContainsString(str_repeat('b', 64), $encoded);
    }

    public function testPendingAndDriftedPlansCannotBeReady(): void
    {
        $pending = WebAdminDatabaseDiagnostic::fromPlan(
            $this->plan('pending')
        );
        $drift = WebAdminDatabaseDiagnostic::fromPlan(
            $this->plan('postcondition_drift', [[
                'code' => 'migration.postcondition_drift',
                'module' => 'webadmin',
                'migration' => '0001_webadmin_identity_and_access',
            ]])
        );

        self::assertTrue($pending->connectionReady());
        self::assertFalse($pending->migrationsReady());
        self::assertSame('pending', $pending->migrationStatus());
        self::assertFalse($drift->migrationsReady());
        self::assertSame('blocked', $drift->migrationStatus());
        self::assertSame(
            'migration.postcondition_drift',
            $drift->toArray()['migrations']['blockers'][0]['code']
        );
    }

    public function testPdoContractBlockerMarksConnectionInvalid(): void
    {
        $diagnostic = WebAdminDatabaseDiagnostic::fromPlan(
            $this->plan('database_contract_invalid', [[
                'code' => 'database.sqlite_foreign_keys_disabled',
                'module' => null,
                'migration' => null,
            ]])
        );

        self::assertFalse($diagnostic->connectionReady());
        self::assertFalse($diagnostic->migrationsReady());
        self::assertSame(
            'contract_invalid',
            $diagnostic->connectionStatus()
        );
        self::assertSame('blocked', $diagnostic->migrationStatus());
    }

    public function testFuturePendingMigrationIsReportedWithoutBlockingRuntime(): void
    {
        $diagnostic = WebAdminDatabaseDiagnostic::fromPlan(
            new MigrationDatabasePlan(
                'sqlite',
                true,
                [
                    $this->entry(
                        '0001_webadmin_identity_and_access',
                        'applied'
                    ),
                    $this->entry('0002_future_feature', 'pending'),
                ],
                []
            )
        );
        $migrations = $diagnostic->toArray()['migrations'];

        self::assertTrue($diagnostic->migrationsReady());
        self::assertSame('applied', $diagnostic->migrationStatus());
        self::assertTrue($migrations['base']['ready']);
        self::assertFalse($migrations['features']['ready']);
        self::assertSame('pending', $migrations['features']['status']);
        self::assertSame(
            ['0002_future_feature'],
            $migrations['features']['pending']
        );
    }

    public function testFutureMigrationBlockerDoesNotDisableBaseRuntime(): void
    {
        $diagnostic = WebAdminDatabaseDiagnostic::fromPlan(
            new MigrationDatabasePlan(
                'sqlite',
                true,
                [
                    $this->entry(
                        '0001_webadmin_identity_and_access',
                        'applied'
                    ),
                    $this->entry(
                        '0002_future_feature',
                        'postcondition_drift'
                    ),
                ],
                [[
                    'code' => 'migration.postcondition_drift',
                    'module' => 'webadmin',
                    'migration' => '0002_future_feature',
                ]]
            )
        );
        $migrations = $diagnostic->toArray()['migrations'];

        self::assertTrue($diagnostic->migrationsReady());
        self::assertSame('applied', $diagnostic->migrationStatus());
        self::assertSame('blocked', $migrations['features']['status']);
    }

    public function testMediaReadinessIsExplicitAndIndependentFromBaseRuntime(): void
    {
        $pending = WebAdminDatabaseDiagnostic::fromPlan(
            new MigrationDatabasePlan('sqlite', true, [
                $this->entry('0001_webadmin_identity_and_access', 'applied'),
                $this->entry('0002_webadmin_media_library', 'pending'),
            ], [])
        );
        self::assertTrue($pending->migrationsReady());
        self::assertFalse($pending->mediaMigrations()['ready']);
        self::assertSame('pending', $pending->mediaMigrations()['status']);
        self::assertSame(
            ['0002_webadmin_media_library'],
            $pending->mediaMigrations()['pending']
        );

        $applied = WebAdminDatabaseDiagnostic::fromPlan(
            new MigrationDatabasePlan('sqlite', true, [
                $this->entry('0001_webadmin_identity_and_access', 'applied'),
                $this->entry('0002_webadmin_media_library', 'applied'),
            ], [])
        );
        self::assertTrue($applied->migrationsReady());
        self::assertTrue($applied->mediaMigrations()['ready']);
        self::assertSame('applied', $applied->mediaMigrations()['status']);
    }

    public function testNonTrailingCatalogEntryStillBlocksBaseRuntime(): void
    {
        $diagnostic = WebAdminDatabaseDiagnostic::fromPlan(
            new MigrationDatabasePlan(
                'sqlite',
                true,
                [
                    $this->entry('0000_late_backfill', 'out_of_order'),
                    $this->entry(
                        '0001_webadmin_identity_and_access',
                        'applied'
                    ),
                ],
                [[
                    'code' => 'migration.out_of_order',
                    'module' => 'webadmin',
                    'migration' => '0000_late_backfill',
                ]]
            )
        );

        self::assertFalse($diagnostic->migrationsReady());
        self::assertSame('blocked', $diagnostic->migrationStatus());
    }

    /**
     * @param list<array{code: string, module: ?string, migration: ?string}> $blockers
     */
    private function plan(
        string $status,
        array $blockers = []
    ): MigrationDatabasePlan {
        return new MigrationDatabasePlan(
            'sqlite',
            $status !== 'pending',
            [$this->entry(
                '0001_webadmin_identity_and_access',
                $status
            )],
            $blockers
        );
    }

    /** @return array<string, mixed> */
    private function entry(string $id, string $status): array
    {
        return [
            'module' => 'webadmin',
            'target_scope_module' => null,
            'id' => $id,
            'description' => 'sensitive-description-must-not-leak',
            'checksum' => str_repeat('a', 64),
            'scope_hash' => str_repeat('b', 64),
            'destructive' => false,
            'status' => $status,
        ];
    }
}
