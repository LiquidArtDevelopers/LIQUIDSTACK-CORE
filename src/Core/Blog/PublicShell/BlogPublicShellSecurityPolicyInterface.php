<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicShell;

/** Project-replaceable security policy shared by public Blog shells. */
interface BlogPublicShellSecurityPolicyInterface
{
    public function context(): BlogPublicShellSecurityContext;
}
