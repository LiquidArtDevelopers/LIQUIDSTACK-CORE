<?php

declare(strict_types=1);

namespace App\Core\Blog\Tags;

final class BlogTagAssignmentResult
{
    /** @param list<BlogTag> $tags */
    public function __construct(
        private readonly int $lockVersion,
        private readonly int $workspaceVersion,
        private readonly array $tags
    ) {
        BlogTagInput::expectedLockVersion($lockVersion);
        BlogTagInput::workspaceVersion($workspaceVersion);
        if (!array_is_list($tags) || count($tags) > 30) {
            throw new BlogTagException(BlogTagException::INVALID_INPUT);
        }
        $previous = null;
        foreach ($tags as $tag) {
            if (!$tag instanceof BlogTag) {
                throw new BlogTagException(BlogTagException::INVALID_INPUT);
            }
            if ($previous !== null && strcmp($previous, $tag->slug()) >= 0) {
                throw new BlogTagException(BlogTagException::INVALID_INPUT);
            }
            $previous = $tag->slug();
        }
    }

    public function lockVersion(): int { return $this->lockVersion; }
    public function workspaceVersion(): int { return $this->workspaceVersion; }

    /** @return list<BlogTag> */
    public function tags(): array { return $this->tags; }
}
