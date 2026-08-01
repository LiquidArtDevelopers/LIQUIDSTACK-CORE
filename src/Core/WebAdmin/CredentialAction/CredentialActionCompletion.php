<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\CredentialAction;

final class CredentialActionCompletion
{
    public const GENERIC_FAILURE = 'webadmin.credential_action.unavailable';

    private function __construct(private readonly bool $completed)
    {
    }

    public static function succeeded(): self
    {
        return new self(true);
    }

    public static function failed(): self
    {
        return new self(false);
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function publicErrorCode(): ?string
    {
        return $this->completed ? null : self::GENERIC_FAILURE;
    }
}
