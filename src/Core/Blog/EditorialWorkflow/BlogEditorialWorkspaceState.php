<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorialWorkflow;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;

/** Private workspace pointer; content itself remains an immutable revision. */
final class BlogEditorialWorkspaceState
{
    private readonly string $localizationPublicId;
    private readonly ?string $draftRevisionPublicId;

    public function __construct(
        string $localizationPublicId,
        ?string $draftRevisionPublicId,
        private readonly int $basePublicationVersion
    ) {
        $this->localizationPublicId = BlogInput::publicId(
            $localizationPublicId
        );
        $this->draftRevisionPublicId = $draftRevisionPublicId === null
            ? null
            : BlogInput::publicId($draftRevisionPublicId);
        if ($basePublicationVersion < 0) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }
    }

    public function localizationPublicId(): string
    {
        return $this->localizationPublicId;
    }
    public function draftRevisionPublicId(): ?string
    {
        return $this->draftRevisionPublicId;
    }
    public function basePublicationVersion(): int
    {
        return $this->basePublicationVersion;
    }
}
