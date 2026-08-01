<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

final class PhpErrorLogBlogAdminRuntimeIssueReporter implements
    BlogAdminRuntimeIssueReporterInterface
{
    public function report(string $issueCode): void
    {
        if (preg_match('/\Ablog\.[a-z0-9_]+\z/', $issueCode) !== 1) {
            $issueCode = 'blog.admin_runtime_unavailable';
        }

        error_log('[LiquidStack Blog] ' . $issueCode);
    }
}
