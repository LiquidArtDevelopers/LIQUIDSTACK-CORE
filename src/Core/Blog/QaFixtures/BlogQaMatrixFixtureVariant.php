<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

use App\Core\Blog\BlogInput;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;

final class BlogQaMatrixFixtureVariant
{
    public function __construct(
        private readonly string $locale,
        private readonly string $localizationPublicId,
        private readonly string $documentPublicId,
        private readonly string $revisionPublicId,
        private readonly BlogStructuredDraft $draft
    ) {
        BlogInput::locale($locale);
        BlogInput::generatedPublicId($localizationPublicId);
        BlogInput::generatedPublicId($documentPublicId);
        BlogInput::generatedPublicId($revisionPublicId);
        if (!$draft->compatibilityDraft()->isPublishable()) {
            throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.catalog_variant_not_publishable'
            );
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function localizationPublicId(): string
    {
        return $this->localizationPublicId;
    }

    public function documentPublicId(): string
    {
        return $this->documentPublicId;
    }

    public function revisionPublicId(): string
    {
        return $this->revisionPublicId;
    }

    public function draft(): BlogStructuredDraft
    {
        return $this->draft;
    }
}
