<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

interface BlogSeoCandidateRepositoryInterface
{
    public function publishedCandidates(
        string $locale,
        string $exceptPostPublicId,
        int $limit
    ): BlogSeoCandidateScan;
}
