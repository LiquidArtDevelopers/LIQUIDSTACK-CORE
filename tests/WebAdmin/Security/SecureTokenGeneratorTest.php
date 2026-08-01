<?php

declare(strict_types=1);

use App\Core\WebAdmin\Security\ConstantTime;
use App\Core\WebAdmin\Security\InvalidSecurityToken;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use PHPUnit\Framework\TestCase;

final class SecureTokenGeneratorTest extends TestCase
{
    public function testTokenContainsThirtyTwoRandomBytesAsCanonicalBase64Url(): void
    {
        $generator = new SecureTokenGenerator();
        $token = $generator->generate();

        self::assertSame(SecureTokenGenerator::ENCODED_LENGTH, strlen($token));
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
        self::assertStringNotContainsString('=', $token);
        self::assertTrue($generator->hasValidFormat($token));
        self::assertNotSame($token, $generator->generate());
    }

    public function testStorageHashIsSha256AndVerificationUsesIt(): void
    {
        $generator = new SecureTokenGenerator();
        $token = $generator->generate();
        $hash = $generator->hashForStorage($token);

        self::assertSame(SecureTokenGenerator::HASH_LENGTH, strlen($hash));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
        self::assertTrue($generator->verify($token, $hash));

        $replacement = $token[0] === 'A' ? 'B' : 'A';
        $changedToken = $replacement . substr($token, 1);
        self::assertFalse($generator->verify($changedToken, $hash));
        self::assertFalse($generator->verify($token, strtoupper($hash)));
        self::assertFalse($generator->verify($token, 'not-a-hash'));
    }

    public function testInvalidTokenFailureIsGenericAndDoesNotLeakValue(): void
    {
        $invalid = 'private-invalid-token-value';

        try {
            (new SecureTokenGenerator())->hashForStorage($invalid);
            self::fail('Invalid token should have been rejected.');
        } catch (InvalidSecurityToken $exception) {
            self::assertSame(
                'Invalid security token.',
                $exception->getMessage()
            );
            self::assertStringNotContainsString(
                $invalid,
                $exception->getMessage()
            );
        }
    }

    public function testConstantTimeFacadeComparesExactByteSequences(): void
    {
        self::assertTrue(ConstantTime::equals('known-value', 'known-value'));
        self::assertFalse(ConstantTime::equals('known-value', 'other-value'));
        self::assertFalse(ConstantTime::equals('known-value', 'known-value '));
    }
}
