<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Diagnostics;

use JsonSerializable;

final class WebAdminDiagnosticReport implements JsonSerializable
{
    /**
     * @param array<string, mixed> $report
     */
    public function __construct(private readonly array $report)
    {
    }

    public function isReady(): bool
    {
        return $this->isRuntimeReady();
    }

    public function isRuntimeReady(): bool
    {
        return $this->report['readiness']['runtime_ready'] === true;
    }

    public function isBootstrapReady(): bool
    {
        return $this->report['readiness']['bootstrap_ready'] === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->report;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
