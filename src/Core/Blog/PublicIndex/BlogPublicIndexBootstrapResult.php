<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicIndex;

use App\Core\Http\Response;

/** Fully prepared HTTP response plus the optional renderable page. */
final class BlogPublicIndexBootstrapResult
{
    public function __construct(
        private readonly Response $response,
        private readonly ?BlogPublicIndexPage $page,
        private readonly BlogPublicIndexSecurityContext $security,
        private readonly bool $terminate
    ) {
    }

    public function response(): Response
    {
        return $this->response;
    }

    public function page(): ?BlogPublicIndexPage
    {
        return $this->page;
    }

    public function security(): BlogPublicIndexSecurityContext
    {
        return $this->security;
    }

    public function shouldTerminate(): bool
    {
        return $this->terminate;
    }
}
