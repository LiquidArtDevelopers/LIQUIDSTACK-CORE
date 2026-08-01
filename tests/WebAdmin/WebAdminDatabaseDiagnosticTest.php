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
            [[
                'module' => 'webadmin',
                'id' => '0001_webadmin_identity_and_access',
                'description' => 'sensitive-description-must-not-leak',
                'checksum' => str_repeat('a', 64),
                'scope_hash' => str_repeat('b', 64),
                'destructive' => false,
                'status' => $status,
            ]],
            $blockers
        );
    }
}
