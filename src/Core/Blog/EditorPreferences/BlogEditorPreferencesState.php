<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences;

use DateTimeImmutable;

final class BlogEditorPreferencesState
{
    public function __construct(
        private readonly BlogEditorPreferences $preferences,
        private readonly int $lockVersion,
        private readonly bool $persisted,
        private readonly ?DateTimeImmutable $updatedAt
    ) {
        if (
            $lockVersion < 0
            || ($persisted && ($lockVersion < 1 || $updatedAt === null))
            || (!$persisted && ($lockVersion !== 0 || $updatedAt !== null))
        ) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::INVALID_INPUT
            );
        }
    }

    public static function fallback(BlogEditorPreferences $preferences): self
    {
        return new self($preferences, 0, false, null);
    }

    public function preferences(): BlogEditorPreferences
    {
        return $this->preferences;
    }

    public function lockVersion(): int
    {
        return $this->lockVersion;
    }

    public function isPersisted(): bool
    {
        return $this->persisted;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
