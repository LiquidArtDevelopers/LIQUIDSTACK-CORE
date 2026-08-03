<?php

declare(strict_types=1);

use App\Core\WebAdmin\Security\InvalidPassword;
use App\Core\WebAdmin\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testAlgorithmSelectionRequiresAnExplicitPolicyFactory(): void
    {
        $constructor = (new ReflectionClass(PasswordHasher::class))
            ->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        self::assertSame(
            PasswordHasher::PRODUCTIVE_POLICY_ID,
            PasswordHasher::productive()->policyId()
        );
        self::assertSame(
            PasswordHasher::LEGACY_BCRYPT_POLICY_ID,
            PasswordHasher::bcryptFallback()->policyId()
        );
    }

    public function testProductivePolicyIsFixedToArgon2id(): void
    {
        $password = 'Correct horse battery staple 1!';
        $hasher = PasswordHasher::productive();
        $hash = $hasher->hash($password);

        self::assertTrue($hasher->verify($password, $hash));
        self::assertFalse($hasher->verify(
            'Different horse battery staple 2!',
            $hash
        ));
        self::assertFalse($hasher->needsRehash($hash));
        self::assertSame('argon2id', $hasher->algorithmName());
        self::assertSame(
            PasswordHasher::PRODUCTIVE_POLICY_ID,
            $hasher->policyId()
        );
        self::assertTrue($hasher->isCurrentHash(
            $hasher->verificationDummyHash()
        ));
        self::assertTrue($hasher->verify(
            'LiquidStack dummy credential only!',
            $hasher->verificationDummyHash()
        ));
    }

    public function testArgon2idUsesRequestedOwaspParametersWhenAvailable(): void
    {
        if (!PasswordHasher::runtimeSupportsArgon2id()) {
            self::markTestSkipped('Argon2id is unavailable in this PHP build.');
        }

        $hasher = PasswordHasher::productive();
        $hash = $hasher->hash('Argon parameters test password 1!');
        $info = password_get_info($hash);

        self::assertSame('argon2id', $info['algoName']);
        self::assertSame(
            PasswordHasher::ARGON2_MEMORY_COST,
            $info['options']['memory_cost']
        );
        self::assertSame(
            PasswordHasher::ARGON2_TIME_COST,
            $info['options']['time_cost']
        );
        self::assertSame(
            PasswordHasher::ARGON2_THREADS,
            $info['options']['threads']
        );
    }

    public function testBcryptFallbackUsesCostTwelveAndNeverTruncates(): void
    {
        $hasher = PasswordHasher::bcryptFallback();
        $maximum = 'Aa1!'
            . str_repeat('m', PasswordHasher::BCRYPT_MAX_BYTES - 4);
        $hash = $hasher->hash($maximum);
        $info = password_get_info($hash);

        self::assertSame('bcrypt', $hasher->algorithmName());
        self::assertSame(
            PasswordHasher::LEGACY_BCRYPT_POLICY_ID,
            $hasher->policyId()
        );
        self::assertTrue($hasher->isCurrentHash(
            $hasher->verificationDummyHash()
        ));
        self::assertSame('bcrypt', $info['algoName']);
        self::assertSame(PasswordHasher::BCRYPT_COST, $info['options']['cost']);
        self::assertTrue($hasher->verify($maximum, $hash));
        self::assertFalse($hasher->needsRehash($hash));

        $overLimit = $maximum . 'x';
        try {
            $hasher->hash($overLimit);
            self::fail('Bcrypt input over 72 bytes must not be truncated.');
        } catch (InvalidPassword $exception) {
            self::assertStringNotContainsString(
                $overLimit,
                $exception->getMessage()
            );
        }
        self::assertFalse($hasher->verify($overLimit, $hash));
        self::assertFalse(
            PasswordHasher::productive()->verify($maximum, $hash),
            'The productive verifier must never fall back to bcrypt.'
        );
    }

    public function testBcryptLimitIsInBytesAndOnlyAffectsBcryptHashes(): void
    {
        $password = 'Aa1!' . str_repeat('€', 23);
        self::assertSame(73, strlen($password));

        $fallback = PasswordHasher::bcryptFallback();
        $this->expectException(InvalidPassword::class);
        $fallback->hash($password);
    }

    public function testNeedsRehashDetectsWeakerBcryptCost(): void
    {
        $password = 'Rehash decision password 1!';
        $weakHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        self::assertIsString($weakHash);

        self::assertTrue(
            PasswordHasher::bcryptFallback()->needsRehash($weakHash)
        );
    }

    public function testWhitespaceAndUnicodeNormalizationRemainSignificant(): void
    {
        $hasher = PasswordHasher::productive();
        $withWhitespace = '  Exact password value 1!  ';
        $whitespaceHash = $hasher->hash($withWhitespace);

        self::assertTrue($hasher->verify($withWhitespace, $whitespaceHash));
        self::assertFalse($hasher->verify(trim($withWhitespace), $whitespaceHash));

        $composed = 'Aa1!' . str_repeat('é', 4);
        $decomposed = 'Aa1!' . str_repeat("e\u{0301}", 4);
        $unicodeHash = $hasher->hash($composed);

        self::assertTrue($hasher->verify($composed, $unicodeHash));
        self::assertFalse($hasher->verify($decomposed, $unicodeHash));
    }

    public function testHashRejectsPasswordsThatOnlyMeetLegacyPolicy(): void
    {
        $this->expectException(InvalidPassword::class);

        PasswordHasher::productive()->hash(
            'legacy lowercase password without composition'
        );
    }

    public function testVerificationAcceptsCurrentHashWithLegacyComposition(): void
    {
        $password = 'legacy lowercase password without composition';
        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => PasswordHasher::ARGON2_MEMORY_COST,
            'time_cost' => PasswordHasher::ARGON2_TIME_COST,
            'threads' => PasswordHasher::ARGON2_THREADS,
        ]);

        self::assertIsString($hash);
        self::assertTrue(PasswordHasher::productive()->verify($password, $hash));
    }

    public function testVerificationRejectsUnsafeInputWithoutThrowing(): void
    {
        $hasher = PasswordHasher::productive();
        $hash = $hasher->hash('Current password value 1!');

        self::assertFalse($hasher->verify('', $hash));
        self::assertFalse($hasher->verify(str_repeat('a', 1025), $hash));
        self::assertFalse($hasher->verify("Aa1!valid\xC3\x28", $hash));
    }

    public function testMalformedHashAndInvalidPasswordFailClosed(): void
    {
        $hasher = PasswordHasher::productive();

        self::assertFalse($hasher->verify('too-short', 'not-a-password-hash'));
        self::assertTrue($hasher->needsRehash('not-a-password-hash'));
    }
}
