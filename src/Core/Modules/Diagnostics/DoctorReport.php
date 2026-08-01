<?php

declare(strict_types=1);

namespace App\Core\Modules\Diagnostics;

use App\Core\Modules\Migrations\MigrationPlan;

final class DoctorReport
{
    /**
     * @param list<DiagnosticCheck> $checks
     * @param list<string> $requestedModules
     * @param list<string> $enabledModules
     * @param array<string, int> $providerCounts
     * @param array<string, array<string, mixed>> $moduleDiagnostics
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $checks,
        private readonly array $requestedModules,
        private readonly array $enabledModules,
        private readonly array $providerCounts,
        private readonly MigrationPlan $migrationPlan,
        private readonly array $moduleDiagnostics = []
    ) {
    }

    /**
     * @return list<DiagnosticCheck>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return list<string>
     */
    public function requestedModules(): array
    {
        return $this->requestedModules;
    }

    /**
     * @return list<string>
     */
    public function enabledModules(): array
    {
        return $this->enabledModules;
    }

    public function migrationPlan(): MigrationPlan
    {
        return $this->migrationPlan;
    }

    public function isHealthy(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->status() === 'error') {
                return false;
            }
        }

        return true;
    }

    public function warningCount(): int
    {
        return count(array_filter(
            $this->checks,
            static fn (DiagnosticCheck $check): bool =>
                $check->status() === 'warning'
        ));
    }

    /**
     * @return array{
     *     schema: 1,
     *     ok: bool,
     *     project_root: string,
     *     modules: array{
     *         requested: list<string>,
     *         enabled: list<string>,
     *         providers: array<string, int>
     *     },
     *     migrations: array<string, mixed>,
     *     module_diagnostics: array<string, array<string, mixed>>,
     *     checks: list<array{id: string, status: string, message: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema' => 1,
            'ok' => $this->isHealthy(),
            'project_root' => $this->projectRoot,
            'modules' => [
                'requested' => $this->requestedModules,
                'enabled' => $this->enabledModules,
                'providers' => $this->providerCounts,
            ],
            'migrations' => $this->migrationPlan->toArray(),
            'module_diagnostics' => $this->moduleDiagnostics,
            'checks' => array_map(
                static fn (DiagnosticCheck $check): array => $check->toArray(),
                $this->checks
            ),
        ];
    }
}
