<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

interface BlogAdminRuntimeIssueReporterInterface
{
    public function report(string $issueCode): void;
}
