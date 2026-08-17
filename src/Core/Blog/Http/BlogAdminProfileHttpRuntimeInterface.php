<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\WebAdmin\Profile\WebAdminPublicProfile;

/** Optional ADMIN-003 port; older runtimes remain source compatible. */
interface BlogAdminProfileHttpRuntimeInterface
{
    public function profileForSession(string $sessionToken): ?WebAdminPublicProfile;
}
