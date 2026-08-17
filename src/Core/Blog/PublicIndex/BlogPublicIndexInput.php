<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicIndex;

use App\Core\Blog\BlogInput;
use App\Core\Http\Request;

/** Request input supplied explicitly by a project adapter, never globals. */
final class BlogPublicIndexInput
{
    private readonly string $locale;
    private readonly Request $request;

    /**
     * @param array<string|int, mixed> $query
     * @param array<string|int, mixed> $routeParameters
     * @param array<string, mixed> $server
     */
    public function __construct(
        string $locale,
        array $query = [],
        private readonly array $routeParameters = [],
        array $server = []
    ) {
        $this->locale = BlogInput::locale($locale);
        $this->request = Request::fromInput($server, $query);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function request(): Request
    {
        return $this->request;
    }

    /** @return array<string|int, mixed> */
    public function query(): array
    {
        return $this->request->queryParams();
    }

    /** @return array<string|int, mixed> */
    public function routeParameters(): array
    {
        return $this->routeParameters;
    }

    public function isPartialRequest(): bool
    {
        return $this->request->method() === 'GET'
            && $this->request->header('X-LiquidStack-Partial')
                === 'blog-results';
    }

    public function isHeadRequest(): bool
    {
        return $this->request->method() === 'HEAD';
    }
}
