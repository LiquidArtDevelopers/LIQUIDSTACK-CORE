<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorialWorkflow;

use App\Core\Blog\BlogInput;

/** Pointer to the immutable revision currently approved for publication. */
final class BlogPublicationHeadState
{
    private readonly string $localizationPublicId;
    private readonly string $revisionPublicId;

    public function __construct(
        string $localizationPublicId,
        string $revisionPublicId,
        private readonly int $publicationVersion
    ) {
        $this->localizationPublicId = BlogInput::publicId(
            $localizationPublicId
        );
        $this->revisionPublicId = BlogInput::publicId($revisionPublicId);
        BlogInput::lockVersion($publicationVersion);
    }

    public function localizationPublicId(): string
    {
        return $this->localizationPublicId;
    }
    public function revisionPublicId(): string
    {
        return $this->revisionPublicId;
    }
    public function publicationVersion(): int
    {
        return $this->publicationVersion;
    }
}
