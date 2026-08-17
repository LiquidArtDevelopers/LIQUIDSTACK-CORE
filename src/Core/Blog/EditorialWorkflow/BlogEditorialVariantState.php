<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorialWorkflow;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;
use App\Core\Blog\BlogPostVariant;

/** Content-free row lock projection used by the private workspace. */
final class BlogEditorialVariantState
{
    private readonly string $postPublicId;
    private readonly string $localizationPublicId;
    private readonly string $locale;

    public function __construct(
        string $postPublicId,
        string $localizationPublicId,
        string $locale,
        private readonly string $status,
        private readonly int $lockVersion
    ) {
        $this->postPublicId = BlogInput::publicId($postPublicId);
        $this->localizationPublicId = BlogInput::publicId(
            $localizationPublicId
        );
        $this->locale = BlogInput::locale($locale);
        BlogInput::lockVersion($lockVersion);
        if (!in_array($status, [
            BlogPostVariant::DRAFT,
            BlogPostVariant::PUBLISHED,
        ], true)) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
    }

    public function postPublicId(): string { return $this->postPublicId; }
    public function localizationPublicId(): string
    {
        return $this->localizationPublicId;
    }
    public function locale(): string { return $this->locale; }
    public function status(): string { return $this->status; }
    public function lockVersion(): int { return $this->lockVersion; }
}
