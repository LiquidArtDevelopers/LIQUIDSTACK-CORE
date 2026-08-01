<?php

declare(strict_types=1);

namespace App\Core\Blog\Audit;

use PDO;

/**
 * Atomically appends a Blog mutation to the host audit system.
 *
 * The supplied PDO is the exact connection used by Blog and already has an
 * active write transaction. Implementations must use it as-is and must not
 * start, commit or roll back a transaction. Throwing fails the Blog mutation
 * closed and causes the repository to roll the whole transaction back.
 */
interface BlogMutationAuditPortInterface
{
    public function record(
        PDO $pdo,
        BlogMutationAuditEvent $event
    ): void;
}
