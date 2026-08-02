<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories\Audit;

use PDO;

interface BlogCategoryAuditPortInterface
{
    public function record(PDO $pdo, BlogCategoryAuditEvent $event): void;
}
