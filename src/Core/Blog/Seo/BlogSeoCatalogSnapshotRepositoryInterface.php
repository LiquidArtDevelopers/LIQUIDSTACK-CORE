<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;

interface BlogSeoCatalogSnapshotRepositoryInterface
{
    /**
     * Returns valid saved working snapshots keyed by localization public ID.
     * Missing or corrupt individual snapshots are deliberately omitted.
     *
     * @param list<string> $localizationPublicIds
     * @return array<string, BlogStructuredDraft>
     */
    public function snapshots(array $localizationPublicIds): array;
}
