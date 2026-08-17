<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;

/** Explicit QA-only projection suitable for a private showroom/dev adapter. */
final class BlogQaMatrixFixtureView
{
    public function __construct(
        private readonly int $articleNumber,
        private readonly string $locale,
        private readonly BlogPostVariant $variant,
        private readonly BlogStructuredDraft $publishedSnapshot
    ) {
        if (
            $articleNumber < 1
            || $articleNumber > BlogQaMatrixFixtureCatalog::ARTICLE_COUNT
            || $variant->locale() !== $locale
            || $variant->status() !== BlogPostVariant::PUBLISHED
            || !$variant->draft()->robotsPreferences()->equals(
                $publishedSnapshot->robotsPreferences()
            )
        ) {
            throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.read_projection_invalid'
            );
        }
    }

    public function articleNumber(): int
    {
        return $this->articleNumber;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function variant(): BlogPostVariant
    {
        return $this->variant;
    }

    public function publishedSnapshot(): BlogStructuredDraft
    {
        return $this->publishedSnapshot;
    }
}
