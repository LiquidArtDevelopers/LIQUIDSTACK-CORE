<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Routing;

use JsonSerializable;

final class WebAdminRouteResolution implements JsonSerializable
{
    /**
     * @param list<array<string, string>> $issues
     * @param list<array{method: string, route: string, source: string, line: int}> $collisions
     */
    public function __construct(
        private readonly string $requestedPath,
        private readonly ?string $registeredPath,
        private readonly bool $fallback,
        private readonly array $issues,
        private readonly array $collisions
    ) {
    }

    public function requestedPath(): string
    {
        return $this->requestedPath;
    }

    public function registeredPath(): ?string
    {
        return $this->registeredPath;
    }

    public function isAvailable(): bool
    {
        return $this->registeredPath !== null;
    }

    public function isReady(): bool
    {
        return $this->isAvailable() && $this->issues === [];
    }

    /** @return list<array<string, string>> */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return array{
     *     requested_path: string,
     *     registered_path: string|null,
     *     fallback: bool,
     *     available: bool,
     *     ready: bool,
     *     issues: list<array<string, string>>,
     *     collisions: list<array{method: string, route: string, source: string, line: int}>
     * }
     */
    public function toArray(): array
    {
        return [
            'requested_path' => $this->requestedPath,
            'registered_path' => $this->registeredPath,
            'fallback' => $this->fallback,
            'available' => $this->isAvailable(),
            'ready' => $this->isReady(),
            'issues' => $this->issues,
            'collisions' => $this->collisions,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
