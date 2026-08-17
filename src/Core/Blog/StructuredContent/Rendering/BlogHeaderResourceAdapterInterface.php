<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

/** Explicit boundary between Blog SSR and project-owned LiquidStack resources. */
interface BlogHeaderResourceAdapterInterface
{
    /** @param list<string> $resources */
    public function supports(array $resources): bool;

    /** @param array<string, string> $parameters */
    public function render(string $resource, array $parameters): string;
}
