<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

interface WebAdminRuntimeIssueReporterInterface
{
    public function report(string $issueCode): void;
}
