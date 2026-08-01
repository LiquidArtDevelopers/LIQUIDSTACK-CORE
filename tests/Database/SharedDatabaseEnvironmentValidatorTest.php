<?php

declare(strict_types=1);

use App\Core\Database\DatabaseConnectionException;
use App\Core\Database\SharedDatabaseEnvironmentValidator;
use PHPUnit\Framework\TestCase;

final class SharedDatabaseEnvironmentValidatorTest extends TestCase
{
    public function testAcceptsTheSupportedMysqlEnvironmentShapes(): void
    {
        $validator = new SharedDatabaseEnvironmentValidator();

        foreach ([
            'localhost',
            'db.internal',
            'db_internal:3307',
            '127.0.0.1:3306',
            '[2001:db8::1]:3308',
        ] as $server) {
            self::assertSame([
                'missing' => [],
                'invalid' => [],
                'ready' => true,
            ], $validator->inspect($this->environment($server)));
        }

        self::assertTrue($validator->inspect([
            'BBDD_SERVER' => 'localhost',
            'BBDD_USER' => 'user',
            'BBDD_PASS' => '',
            'BBDD_NAME' => 'project-db_$1',
        ])['ready'], 'An intentionally empty database password remains valid.');
    }

    public function testSeparatesMissingValuesFromMalformedValues(): void
    {
        $inspection = (new SharedDatabaseEnvironmentValidator())->inspect([
            'BBDD_SERVER' => '   ',
            'BBDD_USER' => ['must-not-leak'],
            'BBDD_PASS' => null,
            'BBDD_NAME' => 'database;must-not-leak',
        ]);

        self::assertSame(
            ['BBDD_SERVER', 'BBDD_PASS'],
            $inspection['missing']
        );
        self::assertSame(
            ['BBDD_USER', 'BBDD_NAME'],
            $inspection['invalid']
        );
        self::assertFalse($inspection['ready']);
        self::assertStringNotContainsString(
            'must-not-leak',
            json_encode($inspection, JSON_THROW_ON_ERROR)
        );
    }

    /** @dataProvider unsafeValueProvider */
    public function testRejectsUnsafeOrAmbiguousValues(
        string $name,
        mixed $value
    ): void {
        $environment = $this->environment('localhost');
        $environment[$name] = $value;

        $inspection = (new SharedDatabaseEnvironmentValidator())->inspect(
            $environment
        );

        self::assertSame([], $inspection['missing']);
        self::assertSame([$name], $inspection['invalid']);
        self::assertFalse($inspection['ready']);
    }

    public static function unsafeValueProvider(): iterable
    {
        yield 'server DSN injection' => [
            'BBDD_SERVER',
            'localhost;dbname=must-not-leak',
        ];
        yield 'server control byte' => ['BBDD_SERVER', "localhost\nsecret"];
        yield 'unbracketed IPv6' => ['BBDD_SERVER', '2001:db8::1'];
        yield 'empty port' => ['BBDD_SERVER', 'localhost:'];
        yield 'zero port' => ['BBDD_SERVER', 'localhost:0'];
        yield 'oversized port' => ['BBDD_SERVER', 'localhost:65536'];
        yield 'non-string username' => ['BBDD_USER', 1234];
        yield 'username control byte' => ['BBDD_USER', "user\0secret"];
        yield 'oversized username' => ['BBDD_USER', str_repeat('u', 129)];
        yield 'non-string password' => ['BBDD_PASS', ['secret']];
        yield 'password null byte' => ['BBDD_PASS', "secret\0suffix"];
        yield 'oversized password' => ['BBDD_PASS', str_repeat('p', 4097)];
        yield 'database DSN injection' => [
            'BBDD_NAME',
            'database;charset=must-not-leak',
        ];
        yield 'oversized database name' => [
            'BBDD_NAME',
            str_repeat('d', 65),
        ];
        yield 'non-string database name' => ['BBDD_NAME', new stdClass()];
    }

    public function testConnectionParametersUseOnlyValidatedValues(): void
    {
        $parameters = (new SharedDatabaseEnvironmentValidator())
            ->connectionParameters([
                'BBDD_SERVER' => ' [2001:db8::1]:3308 ',
                'BBDD_USER' => 'liquidstack',
                'BBDD_PASS' => 'secret',
                'BBDD_NAME' => 'liquidstack_test',
            ]);

        self::assertSame([
            'mysql:host=2001:db8::1;port=3308;dbname=liquidstack_test;charset=utf8mb4',
            'liquidstack',
            'secret',
        ], $parameters);
    }

    /** @dataProvider invalidInspectionProvider */
    public function testConnectionParametersReturnOnlyASanitizedIssueCode(
        array $environment,
        string $expectedCode
    ): void {
        try {
            (new SharedDatabaseEnvironmentValidator())
                ->connectionParameters($environment);
            self::fail('El entorno no preparado debía rechazarse.');
        } catch (DatabaseConnectionException $exception) {
            self::assertSame($expectedCode, $exception->issueCode());
            self::assertStringNotContainsString(
                'must-not-leak',
                $exception->getMessage()
            );
            self::assertNull($exception->getPrevious());
        }
    }

    public static function invalidInspectionProvider(): iterable
    {
        yield 'missing' => [
            [
                'BBDD_SERVER' => 'localhost',
                'BBDD_USER' => '',
                'BBDD_PASS' => 'must-not-leak',
                'BBDD_NAME' => 'database',
            ],
            'database.environment_missing',
        ];
        yield 'invalid' => [
            [
                'BBDD_SERVER' => 'localhost;must-not-leak',
                'BBDD_USER' => 'user',
                'BBDD_PASS' => 'must-not-leak',
                'BBDD_NAME' => 'database',
            ],
            'database.environment_invalid',
        ];
    }

    /** @return array<string, string> */
    private function environment(string $server): array
    {
        return [
            'BBDD_SERVER' => $server,
            'BBDD_USER' => 'liquidstack',
            'BBDD_PASS' => 'secret',
            'BBDD_NAME' => 'liquidstack_test',
        ];
    }
}
