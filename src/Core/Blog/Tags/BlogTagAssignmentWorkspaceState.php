<?php

declare(strict_types=1);

namespace App\Core\Blog\Tags;

final class BlogTagAssignmentWorkspaceState
{
    public function __construct(
        private readonly int $baseAssignmentVersion,
        private readonly int $workspaceVersion
    ) {
        BlogTagInput::workspaceVersion($baseAssignmentVersion);
        if ($workspaceVersion < 1) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }
        BlogTagInput::workspaceVersion($workspaceVersion);
    }

    public function baseAssignmentVersion(): int
    {
        return $this->baseAssignmentVersion;
    }

    public function workspaceVersion(): int
    {
        return $this->workspaceVersion;
    }
}
