<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

use Throwable;

final class PasswordHasher
{
    public const PRODUCTIVE_POLICY_ID = 'argon2id-v1';
    public const LEGACY_BCRYPT_POLICY_ID = 'bcrypt-v1';
    public const ARGON2_MEMORY_COST = 19456;
    public const ARGON2_TIME_COST = 2;
    public const ARGON2_THREADS = 1;
    public const BCRYPT_COST = 12;
    public const BCRYPT_MAX_BYTES = 72;

    private const PRODUCTIVE_DUMMY_HASH =
        '$argon2id$v=19$m=19456,t=2,p=1$MENFcENpNFlBbHpJUmJaZA$'
        . 'pEnKgrQtnpNck5nSoJkuX4N++oDMbS0N1B0ogBsVpkE';
    private const BCRYPT_DUMMY_HASH =
        '$2y$12$U5iWtKzla8.wZ1xTeCLdk.IW.VieM7YJFdcWB6s5hi7SoC96awcU.';

    private function __construct(
        private readonly PasswordPolicy $policy,
        private readonly string $policyId
    ) {
    }

    public static function productive(?PasswordPolicy $policy = null): self
    {
        if (!self::runtimeSupportsArgon2id()) {
            throw new UnsupportedPasswordPolicy();
        }

        return new self(
            $policy ?? new PasswordPolicy(),
            self::PRODUCTIVE_POLICY_ID
        );
    }

    public static function bcryptFallback(
        ?PasswordPolicy $policy = null
    ): self {
        return new self(
            $policy ?? new PasswordPolicy(),
            self::LEGACY_BCRYPT_POLICY_ID
        );
    }

    public static function runtimeSupportsArgon2id(): bool
    {
        return defined('PASSWORD_ARGON2ID')
            && in_array('argon2id', password_algos(), true);
    }

    public function algorithmName(): string
    {
        return $this->policyId === self::PRODUCTIVE_POLICY_ID
            ? 'argon2id'
            : 'bcrypt';
    }

    public function policyId(): string
    {
        return $this->policyId;
    }

    public function verificationDummyHash(): string
    {
        return $this->policyId === self::PRODUCTIVE_POLICY_ID
            ? self::PRODUCTIVE_DUMMY_HASH
            : self::BCRYPT_DUMMY_HASH;
    }

    public function hash(string $password): string
    {
        $password = $this->policy->validate($password);
        if (
            $this->policyId === self::LEGACY_BCRYPT_POLICY_ID
            && strlen($password) > self::BCRYPT_MAX_BYTES
        ) {
            throw new InvalidPassword();
        }

        [$algorithm, $options] = $this->algorithmAndOptions();

        try {
            $hash = password_hash($password, $algorithm, $options);
        } catch (Throwable) {
            throw new PasswordHashingException();
        }

        if (!is_string($hash) || $hash === '') {
            throw new PasswordHashingException();
        }

        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        try {
            $password = $this->policy->validate($password);
        } catch (InvalidPassword) {
            return false;
        }

        $info = password_get_info($hash);
        $hashAlgorithm = $info['algoName'] ?? 'unknown';
        if (
            $this->policyId === self::PRODUCTIVE_POLICY_ID
                ? !$this->isCurrentHash($hash)
                : $hashAlgorithm !== 'bcrypt'
        ) {
            return false;
        }
        if (
            $hashAlgorithm === 'bcrypt'
            && strlen($password) > self::BCRYPT_MAX_BYTES
        ) {
            return false;
        }

        try {
            return password_verify($password, $hash);
        } catch (Throwable) {
            return false;
        }
    }

    public function needsRehash(string $hash): bool
    {
        [$algorithm, $options] = $this->algorithmAndOptions();

        try {
            return password_needs_rehash($hash, $algorithm, $options);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Authentication accepts only hashes produced by the active policy.
     * Algorithm/work-factor upgrades are handled through a password-reset
     * flow instead of verifying a distinguishable legacy cost on login.
     */
    public function isCurrentHash(string $hash): bool
    {
        $information = password_get_info($hash);
        if (($information['algoName'] ?? 'unknown') !== $this->algorithmName()) {
            return false;
        }

        return !$this->needsRehash($hash);
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    private function algorithmAndOptions(): array
    {
        if ($this->policyId === self::PRODUCTIVE_POLICY_ID) {
            /** @var string $algorithm */
            $algorithm = constant('PASSWORD_ARGON2ID');

            return [$algorithm, [
                'memory_cost' => self::ARGON2_MEMORY_COST,
                'time_cost' => self::ARGON2_TIME_COST,
                'threads' => self::ARGON2_THREADS,
            ]];
        }

        return [PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]];
    }
}
