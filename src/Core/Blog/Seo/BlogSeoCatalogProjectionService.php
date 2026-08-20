<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\BlogPostSummary;
use Throwable;

/** Produces the visible page's SEO scores without per-row persistence reads. */
final class BlogSeoCatalogProjectionService
{
    public const MAX_SUMMARIES = 50;

    public function __construct(
        private readonly BlogSeoCatalogSnapshotRepositoryInterface $repository,
        private readonly BlogSeoAnalyzer $analyzer = new BlogSeoAnalyzer()
    ) {
    }

    /**
     * @param list<BlogPostSummary> $summaries
     * @param array<string, string> $publicPaths
     * @return array<string, BlogSeoScore>
     */
    public function scoresFor(array $summaries, array $publicPaths): array
    {
        if (
            !array_is_list($summaries)
            || count($summaries) > self::MAX_SUMMARIES
        ) {
            throw new \InvalidArgumentException(
                'Invalid Blog SEO catalog projection.'
            );
        }

        $ids = [];
        foreach ($summaries as $summary) {
            if (!$summary instanceof BlogPostSummary) {
                throw new \InvalidArgumentException(
                    'Invalid Blog SEO catalog projection.'
                );
            }
            $ids[] = $summary->localizationPublicId();
        }
        $snapshots = $this->repository->snapshots($ids);

        $scores = [];
        foreach ($summaries as $summary) {
            $localization = $summary->localizationPublicId();
            $snapshot = $snapshots[$localization] ?? null;
            $publicPath = $publicPaths[$summary->locale()] ?? null;
            if ($snapshot === null || !is_string($publicPath)) {
                continue;
            }
            try {
                $analysis = $this->analyzer->analyze(
                    $snapshot,
                    $summary->locale(),
                    $publicPath,
                    null,
                    false
                );
                $scores[$localization] = BlogSeoScore::fromAnalysis(
                    $analysis
                );
            } catch (Throwable) {
                // An unavailable score never removes the editorial row.
                continue;
            }
        }

        return $scores;
    }
}
