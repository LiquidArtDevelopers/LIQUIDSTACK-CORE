<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use Throwable;

/** Joins pure analysis with optional database and project inventories. */
final class BlogSeoAnalysisService
{
    public const MAX_DATABASE_SCAN = 200;

    public function __construct(
        private readonly BlogSeoAnalyzer $analyzer,
        private readonly ?BlogSeoCandidateRepositoryInterface $repository,
        private readonly BlogSeoStaticPageInventory $staticInventory
    ) {
    }

    public function analyze(
        BlogStructuredDraft $draft,
        string $postPublicId,
        string $locale,
        string $publicPath
    ): BlogSeoAnalysis {
        $candidates = [];
        $complete = $this->repository !== null;
        try {
            $database = $this->repository?->publishedCandidates(
                $locale,
                $postPublicId,
                self::MAX_DATABASE_SCAN
            );
            if ($database !== null) {
                $candidates = $database->candidates();
                $complete = $database->complete();
            }
        } catch (Throwable) {
            $complete = false;
        }
        try {
            $candidates = array_merge(
                $candidates,
                $this->staticInventory->candidates($locale)
            );
        } catch (Throwable) {
            $complete = false;
        }

        return $this->analyzer->analyze(
            $draft,
            $locale,
            $publicPath,
            $candidates,
            $complete
        );
    }
}
