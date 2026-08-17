<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

interface BlogQaMatrixFixtureReadPortInterface
{
    /**
     * Reads only the deterministic Matrix fixtures; this is not a generic
     * escape hatch for reserved categories.
     *
     * @param list<BlogQaMatrixFixtureArticle> $articles
     * @param list<string> $locales
     * @return list<BlogQaMatrixFixtureView>
     */
    public function publishedViews(array $articles, array $locales): array;
}
