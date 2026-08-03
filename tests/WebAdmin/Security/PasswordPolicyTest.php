<?php

declare(strict_types=1);

use App\Core\WebAdmin\Security\InvalidPassword;
use App\Core\WebAdmin\Security\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    private PasswordPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PasswordPolicy();
    }

    public function testCreationBoundaryUsesUnicodeCharactersAndUtf8Bytes(): void
    {
        $minimum = 'Áa1!βγδε';
        $maximum = 'Aa1!' . str_repeat('b', PasswordPolicy::MAX_BYTES - 4);

        self::assertSame(
            PasswordPolicy::MIN_CHARACTERS,
            preg_match_all('/./us', $minimum)
        );
        self::assertGreaterThan(
            PasswordPolicy::MIN_CHARACTERS,
            strlen($minimum),
            'The minimum is measured in Unicode characters, not bytes.'
        );
        self::assertSame(PasswordPolicy::MAX_BYTES, strlen($maximum));
        self::assertSame($minimum, $this->policy->validate($minimum));
        self::assertSame($maximum, $this->policy->validate($maximum));
    }

    public function testUnicodeCompositionCategoriesAreAccepted(): void
    {
        $password = 'Ωβ٣★abcd';

        self::assertSame($password, $this->policy->validate($password));
        self::assertTrue($this->policy->isValid($password));
    }

    public function testPasswordIsReturnedByteForByteWithoutNormalization(): void
    {
        $password = '  Aa1! value  ';

        self::assertSame($password, $this->policy->validate($password));
    }

    /**
     * @dataProvider invalidCreationPasswordProvider
     */
    public function testInvalidCreationPasswordFailureIsGeneric(
        string $password
    ): void {
        try {
            $this->policy->validate($password);
            self::fail('Invalid password should have been rejected.');
        } catch (InvalidPassword $exception) {
            self::assertSame(
                'Password does not satisfy policy.',
                $exception->getMessage()
            );
            if ($password !== '') {
                self::assertStringNotContainsString(
                    $password,
                    $exception->getMessage()
                );
            }
            self::assertFalse($this->policy->isValid($password));
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidCreationPasswordProvider(): iterable
    {
        yield 'below eight Unicode characters' => ['Aa1!bbb'];
        yield 'above maximum UTF-8 bytes' => [
            'Aa1!' . str_repeat('b', PasswordPolicy::MAX_BYTES - 3),
        ];
        yield 'missing lowercase' => ['AA1!BBBB'];
        yield 'missing uppercase' => ['aa1!bbbb'];
        yield 'missing number' => ['Aa!!bbbb'];
        yield 'missing punctuation or symbol' => ['Aa11bbbb'];
        yield 'invalid utf8' => ["Aa1!valid\xC3\x28"];
    }

    public function testVerificationAcceptsLegacyCompositionAndShortValues(): void
    {
        $legacy = 'legacy password without composition';

        self::assertSame(
            $legacy,
            $this->policy->validateForVerification($legacy)
        );
        self::assertSame('a', $this->policy->validateForVerification('a'));
        self::assertTrue($this->policy->isValidForVerification($legacy));
        self::assertFalse($this->policy->isValid($legacy));
    }

    /**
     * @dataProvider invalidVerificationPasswordProvider
     */
    public function testVerificationStillRejectsUnsafeInput(string $password): void
    {
        $this->expectException(InvalidPassword::class);

        $this->policy->validateForVerification($password);
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidVerificationPasswordProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'above maximum bytes' => [
            str_repeat('a', PasswordPolicy::MAX_BYTES + 1),
        ];
        yield 'invalid utf8' => ["legacy\xC3\x28"];
    }
}
