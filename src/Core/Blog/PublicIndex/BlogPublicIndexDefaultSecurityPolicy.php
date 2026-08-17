<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicIndex;

use App\Core\Blog\PublicShell\BlogPublicShellDefaultSecurityPolicy;

/** @deprecated Use BlogPublicShellDefaultSecurityPolicy for new surfaces. */
final class BlogPublicIndexDefaultSecurityPolicy extends
    BlogPublicShellDefaultSecurityPolicy implements
    BlogPublicIndexSecurityPolicyInterface
{
    public function context(): BlogPublicIndexSecurityContext
    {
        $context = parent::context();

        return new BlogPublicIndexSecurityContext(
            $context->nonce(),
            $context->headers()
        );
    }
}
