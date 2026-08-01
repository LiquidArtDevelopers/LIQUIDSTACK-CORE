<?php

declare(strict_types=1);

use App\Core\WebAdmin\Security\EmailAddress;
use App\Core\WebAdmin\Security\InvalidEmailAddress;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function testAddressIsTrimmedAndCanonicalizedForIdentity(): void
    {
        $address = EmailAddress::fromString(
            '  Person+WebAdmin@Example.COM  '
        );

        self::assertSame(
            'person+webadmin@example.com',
            $address->value()
        );
        self::assertSame($address->value(), (string) $address);
        self::assertTrue($address->equals(
            EmailAddress::fromString('PERSON+WEBADMIN@EXAMPLE.COM')
        ));
        self::assertFalse($address->equals(
            EmailAddress::fromString('other@example.com')
        ));
    }

    /**
     * @dataProvider invalidAddressProvider
     */
    public function testInvalidAddressesRaiseAGenericException(
        string $invalid
    ): void {
        try {
            EmailAddress::fromString($invalid);
            self::fail('Invalid address should have been rejected.');
        } catch (InvalidEmailAddress $exception) {
            self::assertSame('Invalid email address.', $exception->getMessage());
            if ($invalid !== '') {
                self::assertStringNotContainsString(
                    $invalid,
                    $exception->getMessage()
                );
            }
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidAddressProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'missing domain' => ['user@'];
        yield 'control character' => ["user@example.com\n"];
        yield 'local part too long' => [
            str_repeat('a', EmailAddress::MAX_LOCAL_PART_BYTES + 1)
                . '@example.com',
        ];
        yield 'address too long' => [
            str_repeat('a', 63)
                . '@'
                . str_repeat('b', 187)
                . '.com',
        ];
    }
}
