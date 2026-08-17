<?php

declare(strict_types=1);

namespace App\Core\Blog\QaFixtures;

use App\Core\Blog\BlogInput;

final class BlogQaMatrixFixtureArticle
{
    /** @var array<string, BlogQaMatrixFixtureVariant> */
    private readonly array $variants;

    /** @param list<BlogQaMatrixFixtureVariant> $variants */
    public function __construct(
        private readonly int $number,
        private readonly string $postPublicId,
        private readonly string $assignmentPublicId,
        array $variants
    ) {
        if ($number < 1 || $number > BlogQaMatrixFixtureCatalog::ARTICLE_COUNT) {
            throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.catalog_article_invalid'
            );
        }
        BlogInput::generatedPublicId($postPublicId);
        BlogInput::generatedPublicId($assignmentPublicId);
        $byLocale = [];
        foreach ($variants as $variant) {
            if (!$variant instanceof BlogQaMatrixFixtureVariant) {
                throw new BlogQaMatrixFixtureException(
                    'blog.qa_fixture.catalog_article_invalid'
                );
            }
            if (isset($byLocale[$variant->locale()])) {
                throw new BlogQaMatrixFixtureException(
                    'blog.qa_fixture.catalog_locale_duplicate'
                );
            }
            $byLocale[$variant->locale()] = $variant;
        }
        if (array_keys($byLocale) !== BlogQaMatrixFixtureCatalog::LOCALES) {
            throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.catalog_locales_invalid'
            );
        }
        $this->variants = $byLocale;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function postPublicId(): string
    {
        return $this->postPublicId;
    }

    public function assignmentPublicId(): string
    {
        return $this->assignmentPublicId;
    }

    /** @return array<string, BlogQaMatrixFixtureVariant> */
    public function variants(): array
    {
        return $this->variants;
    }

    public function variant(string $locale): BlogQaMatrixFixtureVariant
    {
        return $this->variants[$locale]
            ?? throw new BlogQaMatrixFixtureException(
                'blog.qa_fixture.catalog_locale_invalid'
            );
    }
}
