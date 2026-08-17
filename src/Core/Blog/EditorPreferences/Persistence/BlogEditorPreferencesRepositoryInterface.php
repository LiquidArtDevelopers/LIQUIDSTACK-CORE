<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences\Persistence;

use App\Core\Blog\EditorPreferences\BlogEditorPreferences;
use App\Core\Blog\EditorPreferences\BlogEditorPreferencesState;
use DateTimeImmutable;
use PDO;

interface BlogEditorPreferencesRepositoryInterface
{
    /** @template T @param callable(PDO): T $operation @return T */
    public function transactional(callable $operation): mixed;

    public function global(): ?BlogEditorPreferencesState;

    /** Caller must own the surrounding write transaction. */
    public function lockGlobal(): ?BlogEditorPreferencesState;

    /** Caller must own the surrounding write transaction. */
    public function insertGlobal(
        BlogEditorPreferences $preferences,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;

    /** Caller must own the surrounding write transaction. */
    public function updateGlobal(
        int $expectedLockVersion,
        BlogEditorPreferences $preferences,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool;
}
