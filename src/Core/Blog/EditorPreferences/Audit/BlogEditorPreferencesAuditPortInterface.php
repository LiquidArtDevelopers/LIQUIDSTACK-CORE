<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences\Audit;

use PDO;

interface BlogEditorPreferencesAuditPortInterface
{
    /** Uses the exact active transaction supplied by the service. */
    public function record(
        PDO $pdo,
        BlogEditorPreferencesAuditEvent $event
    ): void;
}
