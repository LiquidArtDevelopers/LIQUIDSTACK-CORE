<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

final class PhpErrorLogWebAdminRuntimeIssueReporter implements
    WebAdminRuntimeIssueReporterInterface
{
    public function report(string $issueCode): void
    {
        if (
            preg_match('/\Awebadmin\.[a-z0-9_]+\z/', $issueCode) !== 1
        ) {
            $issueCode = 'webadmin.runtime_unavailable';
        }

        error_log('[LiquidStack WebAdmin] ' . $issueCode);
    }
}
