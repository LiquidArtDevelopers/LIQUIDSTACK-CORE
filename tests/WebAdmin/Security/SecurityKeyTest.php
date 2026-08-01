<?php

declare(strict_types=1);

use App\Core\WebAdmin\Security\InvalidSecurityKey;
use App\Core\WebAdmin\Security\SecurityKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityKeyTest extends TestCase
{
    public function testCanonicalBase64UrlKeyDecodesExactlyThirtyTwoBytes(): void
    {
        $raw = random_bytes(32);
        $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $fromEncoded = SecurityKey::fromBase64Url($encoded);
        $fromRaw = SecurityKey::fromRawBytes($raw);

        self::assertSame(
            $fromRaw->subjectHash('login.identifier', 'subject'),
            $fromEncoded->subjectHash('login.identifier', 'subject')
        );
        self::assertNotSame(
            $fromEncoded->subjectHash('login.identifier', 'subject'),
            $fromEncoded->subjectHash('login.ip', 'subject')
        );
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{64}\z/',
            $fromEncoded->subjectHash('login.identifier', 'subject')
        );
        self::assertSame(
            $fromRaw->deriveToken('csrf.session', 'session'),
            $fromEncoded->deriveToken('csrf.session', 'session')
        );
        self::assertMatchesRegularExpression(
            '/\A[A-Za-z0-9_-]{43}\z/',
            $fromEncoded->deriveToken('csrf.session', 'session')
        );
        self::assertNotSame(
            $fromEncoded->deriveToken('csrf.session', 'session'),
            $fromEncoded->deriveToken('csrf.session', 'different-session')
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEncodedKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('A', 42)];
        yield 'padding is noncanonical' => [str_repeat('A', 43) . '='];
        yield 'standard base64 alphabet' => [str_repeat('A', 42) . '+'];
        yield 'noncanonical trailing bits' => [str_repeat('A', 42) . 'B'];
    }

    #[DataProvider('invalidEncodedKeys')]
    public function testRejectsWeakOrNoncanonicalEncodedKeys(
        string $encoded
    ): void {
        $this->expectException(InvalidSecurityKey::class);
        $this->expectExceptionMessage('Invalid WebAdmin security key.');

        SecurityKey::fromBase64Url($encoded);
    }

    public function testRawKeyMustContainAtLeastThirtyTwoBytes(): void
    {
        $this->expectException(InvalidSecurityKey::class);

        SecurityKey::fromRawBytes(str_repeat('x', 31));
    }

    public function testKeyCannotBeCastOrSerializedAccidentally(): void
    {
        $key = SecurityKey::fromRawBytes(str_repeat('k', 32));

        self::assertFalse(method_exists($key, '__toString'));
        self::assertStringNotContainsString(str_repeat('k', 32), print_r($key, true));
        self::assertStringNotContainsString(
            str_repeat('k', 32),
            var_export($key, true)
        );

        try {
            serialize($key);
            self::fail('Security keys must not serialize.');
        } catch (LogicException $exception) {
            self::assertStringNotContainsString(
                str_repeat('k', 32),
                $exception->getMessage()
            );
        }
    }
}
