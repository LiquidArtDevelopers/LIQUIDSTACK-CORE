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

    public function testBoundaryLengthsAreMeasuredInBytes(): void
    {
        $minimum = str_repeat('a', PasswordPolicy::MIN_BYTES);
        $maximum = str_repeat('b', PasswordPolicy::MAX_BYTES);

        self::assertSame($minimum, $this->policy->validate($minimum));
        self::assertSame($maximum, $this->policy->validate($maximum));
        self::assertTrue($this->policy->isValid(str_repeat('€', 5)));
        self::assertFalse($this->policy->isValid(str_repeat('€', 4)));
    }

    public function testPasswordIsReturnedByteForByteWithoutCompositionRules(): void
    {
        $password = '  exact value  ';

        self::assertSame($password, $this->policy->validate($password));
    }

    /**
     * @dataProvider invalidPasswordProvider
     */
    public function testInvalidPasswordFailureIsGeneric(string $password): void
    {
        try {
            $this->policy->validate($password);
            self::fail('Invalid password should have been rejected.');
        } catch (InvalidPassword $exception) {
            self::assertSame(
                'Password does not satisfy policy.',
                $exception->getMessage()
            );
            self::assertStringNotContainsString(
                $password,
                $exception->getMessage()
            );
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidPasswordProvider(): iterable
    {
        yield 'below minimum' => [
            str_repeat('a', PasswordPolicy::MIN_BYTES - 1),
        ];
        yield 'above maximum' => [
            str_repeat('b', PasswordPolicy::MAX_BYTES + 1),
        ];
        yield 'invalid utf8' => ["valid-prefix-123\xC3\x28"];
    }
}
