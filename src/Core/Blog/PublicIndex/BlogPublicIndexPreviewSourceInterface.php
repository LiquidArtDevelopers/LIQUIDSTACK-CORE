<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicIndex;

/** Explicit, injectable development-only source for index preview cards. */
interface BlogPublicIndexPreviewSourceInterface
{
    public function queryParameter(): string;

    public function queryValue(): string;

    /** @return list<array<string, mixed>> */
    public function cards(string $locale): array;
}
