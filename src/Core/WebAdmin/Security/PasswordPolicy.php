<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

final class PasswordPolicy
{
    public const MIN_CHARACTERS = 8;

    /**
     * @deprecated The minimum is measured in Unicode characters. This alias
     *             remains only to avoid a fatal error in older integrations.
     */
    public const MIN_BYTES = self::MIN_CHARACTERS;

    public const MAX_BYTES = 1024;

    /**
     * Validates a newly created password and returns its exact byte sequence.
     * Passwords are never trimmed, normalized or truncated.
     */
    public function validate(string $password): string
    {
        $this->validateForVerification($password);

        $characters = preg_match_all('/./us', $password);
        if (
            !is_int($characters)
            || $characters < self::MIN_CHARACTERS
            || preg_match('/\p{Ll}/u', $password) !== 1
            || preg_match('/\p{Lu}/u', $password) !== 1
            || preg_match('/\p{N}/u', $password) !== 1
            || preg_match('/[\p{P}\p{S}]/u', $password) !== 1
        ) {
            throw new InvalidPassword();
        }

        return $password;
    }

    /**
     * Applies only the bounded input contract required before checking an
     * existing hash. Composition rules are deliberately omitted so a policy
     * upgrade cannot lock out credentials created under an earlier policy.
     */
    public function validateForVerification(string $password): string
    {
        $length = strlen($password);
        if (
            $length === 0
            || $length > self::MAX_BYTES
            || preg_match('//u', $password) !== 1
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

    public function isValidForVerification(string $password): bool
    {
        try {
            $this->validateForVerification($password);
            return true;
        } catch (InvalidPassword) {
            return false;
        }
    }
}
