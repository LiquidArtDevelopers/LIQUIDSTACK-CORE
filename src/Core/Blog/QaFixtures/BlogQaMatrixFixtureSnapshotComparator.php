<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV2Projector;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use Throwable;

/** Logical equality for deterministic fixtures across canonical upgrades. */
final class BlogQaMatrixFixtureSnapshotComparator
{
    public function __construct(
        private readonly BlogDocumentV2Projector $layoutProjector =
            new BlogDocumentV2Projector(),
        private readonly BlogDocumentCodec $codec = new BlogDocumentCodec()
    ) {
    }

    public function matches(
        BlogStructuredDraft $stored,
        BlogStructuredDraft $expected
    ): bool {
        if (
            !$this->sameDraft(
                $stored->compatibilityDraft(),
                $expected->compatibilityDraft()
            )
            || $stored->mediaAssetPublicIds()
                !== $expected->mediaAssetPublicIds()
        ) {
            return false;
        }

        try {
            $storedDocument = $this->layoutProjector->tryProject(
                $stored->document()
            );
            $expectedDocument = $this->layoutProjector->tryProject(
                $expected->document()
            );
            if ($storedDocument === null || $expectedDocument === null) {
                return false;
            }

            return hash_equals(
                $this->codec->encode($storedDocument),
                $this->codec->encode($expectedDocument)
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function sameDraft(BlogDraft $first, BlogDraft $second): bool
    {
        return $first->h1() === $second->h1()
            && $first->bodyText() === $second->bodyText()
            && $first->slug() === $second->slug()
            && $first->seoTitle() === $second->seoTitle()
            && $first->metaDescription() === $second->metaDescription()
            && $first->excerpt() === $second->excerpt()
            && $first->robotsPreferences()->equals(
                $second->robotsPreferences()
            );
    }
}
