<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicIndex;

use App\Core\Http\Response;
use LogicException;

/** One terminal redirect or one renderable public index page. */
final class BlogPublicIndexResolution
{
    private function __construct(
        private readonly ?Response $redirect,
        private readonly ?BlogPublicIndexPage $page
    ) {
        if (($redirect === null) === ($page === null)) {
            throw new LogicException('Invalid Blog index resolution.');
        }
    }

    public static function fromRedirect(Response $response): self
    {
        return new self($response, null);
    }

    public static function fromPage(BlogPublicIndexPage $page): self
    {
        return new self(null, $page);
    }

    public function redirectResponse(): ?Response { return $this->redirect; }
    public function pageModel(): ?BlogPublicIndexPage { return $this->page; }

    /** Alias that reads naturally in a project adapter. */
    public function page(): ?BlogPublicIndexPage { return $this->page; }

    public function statusCode(): int
    {
        return $this->redirect?->status() ?? $this->page?->statusCode() ?? 500;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->redirect?->headers() ?? $this->page?->headers() ?? [];
    }
}
