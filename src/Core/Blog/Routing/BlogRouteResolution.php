<?php

declare(strict_types=1);

namespace App\Core\Blog\Routing;

final class BlogRouteResolution
{
    /**
     * @param list<array{code: string, key: string}> $issues
     * @param list<array{method: string, route: string, source: string, line: int}> $collisions
     */
    public function __construct(
        private readonly array $issues,
        private readonly array $collisions
    ) {
    }

    public function isReady(): bool
    {
        return $this->issues === [] && $this->collisions === [];
    }

    /** @return list<array{code: string, key: string}> */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<array{method: string, route: string, source: string, line: int}>
     */
    public function collisions(): array
    {
        return $this->collisions;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ready' => $this->isReady(),
            'issues' => $this->issues,
            'collisions' => $this->collisions,
        ];
    }
}
