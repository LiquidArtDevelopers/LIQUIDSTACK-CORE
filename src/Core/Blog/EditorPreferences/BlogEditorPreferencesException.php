<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences;

use RuntimeException;

/** Stable, non-sensitive failures for global Blog editor preferences. */
final class BlogEditorPreferencesException extends RuntimeException
{
    public const INVALID_INPUT = 'blog.editor_preferences.invalid_input';
    public const ACTOR_GATE_FAILED =
        'blog.editor_preferences.actor_gate_failed';
    public const LOCK_CONFLICT = 'blog.editor_preferences.lock_conflict';
    public const STORAGE_UNAVAILABLE =
        'blog.editor_preferences.storage_unavailable';

    private const MESSAGES = [
        self::INVALID_INPUT => 'Invalid Blog editor preferences.',
        self::ACTOR_GATE_FAILED =>
            'Blog editor preferences authorization failed.',
        self::LOCK_CONFLICT =>
            'Blog editor preferences have changed.',
        self::STORAGE_UNAVAILABLE =>
            'Blog editor preferences storage is unavailable.',
    ];

    public function __construct(private readonly string $issueCode)
    {
        if (!isset(self::MESSAGES[$issueCode])) {
            throw new \LogicException(
                'Unknown Blog editor preferences issue code.'
            );
        }

        parent::__construct(self::MESSAGES[$issueCode]);
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }
}
