<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

interface BlogQaMatrixFixturePersistencePortInterface
{
    /**
     * @param list<BlogQaMatrixFixtureArticle> $articles
     * @param list<string> $requestedLocales
     */
    public function run(
        array $articles,
        array $requestedLocales,
        string $actorPublicId,
        bool $apply
    ): BlogQaMatrixFixtureResult;
}
