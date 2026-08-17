<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicIndex;

use App\Core\Blog\PublicShell\BlogPublicShellSecurityPolicyInterface;

/** @deprecated Use the shared public-shell policy interface. */
interface BlogPublicIndexSecurityPolicyInterface extends
    BlogPublicShellSecurityPolicyInterface
{
    public function context(): BlogPublicIndexSecurityContext;
}
