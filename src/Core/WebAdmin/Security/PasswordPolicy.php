<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

final class PasswordPolicy
{
    public const MIN_BYTES = 15;
    public const MAX_BYTES = 1024;

    /**
     * Validates the exact byte sequence and returns it unchanged. Passwords
     * are never trimmed, normalized, truncated or subjected to composition
     * rules; only valid UTF-8 and the byte-length bounds are enforced.
     */
    public function validate(string $password): string
    {
        $length = strlen($password);
        if (
            preg_match('//u', $password) !== 1
            || $length < self::MIN_BYTES
            || $length > self::MAX_BYTES
        ) {
            throw new InvalidPassword();
        }

        return $password;
    }

    public function isValid(string $password): bool
    {
        try {
            $this->validate($password);
            return true;
        } catch (InvalidPassword) {
            return false;
        }
    }
}
