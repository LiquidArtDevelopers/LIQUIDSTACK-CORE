<?php

declare(strict_types=1);

use App\Core\Database\DatabaseConnectionException;
use App\Core\Database\LiquidStackDatabaseEnvironmentValidator;
use PHPUnit\Framework\TestCase;

final class LiquidStackDatabaseEnvironmentValidatorTest extends TestCase
{
    public function testAcceptsTheDedicatedMysqlEnvironmentContract(): void
    {
        $validator = new LiquidStackDatabaseEnvironmentValidator();

        foreach ([
            'localhost',
            'db.internal',
            'db_internal',
            '127.0.0.1',
            '2001:db8::1',
            '[2001:db8::1]',
        ] as $host) {
            self::assertSame([
                'missing' => [],
                'invalid' => [],
                'ready' => true,
            ], $validator->inspect($this->environment($host)));
        }
    }

    public function testSeparatesMissingValuesFromMalformedValuesWithoutLeaks(): void
    {
        $inspection = (new LiquidStackDatabaseEnvironmentValidator())
            ->inspect([
                'LIQUIDSTACK_DB_HOST' => '   ',
                'LIQUIDSTACK_DB_PORT' => null,
                'LIQUIDSTACK_DB_NAME' => ['must-not-leak'],
                'LIQUIDSTACK_DB_USER' => "user\0must-not-leak",
                'LIQUIDSTACK_DB_PASSWORD' => '',
                'LIQUIDSTACK_DB_CHARSET' => 'utf8;must-not-leak',
            ]);

        self::assertSame([
            'LIQUIDSTACK_DB_HOST',
            'LIQUIDSTACK_DB_PORT',
            'LIQUIDSTACK_DB_PASSWORD',
        ], $inspection['missing']);
        self::assertSame([
            'LIQUIDSTACK_DB_NAME',
            'LIQUIDSTACK_DB_USER',
            'LIQUIDSTACK_DB_CHARSET',
        ], $inspection['invalid']);
        self::assertFalse($inspection['ready']);
        self::assertStringNotContainsString(
            'must-not-leak',
            json_encode($inspection, JSON_THROW_ON_ERROR)
        );
    }

    /** @dataProvider unsafeValueProvider */
    public function testRejectsUnsafeOrAmbiguousDedicatedValues(
        string $name,
        mixed $value
    ): void {
        $environment = $this->environment();
        $environment[$name] = $value;

        $inspection = (new LiquidStackDatabaseEnvironmentValidator())
            ->inspect($environment);

        self::assertSame([], $inspection['missing']);
        self::assertSame([$name], $inspection['invalid']);
        self::assertFalse($inspection['ready']);
    }

    public static function unsafeValueProvider(): iterable
    {
        yield 'host DSN injection' => [
            'LIQUIDSTACK_DB_HOST',
            'localhost;dbname=must-not-leak',
        ];
        yield 'host control byte' => [
            'LIQUIDSTACK_DB_HOST',
            "localhost\nmust-not-leak",
        ];
        yield 'host must not embed a port' => [
            'LIQUIDSTACK_DB_HOST',
            'localhost:3306',
        ];
        yield 'invalid bracketed IPv6' => [
            'LIQUIDSTACK_DB_HOST',
            '[not-an-ipv6-address]',
        ];
        yield 'oversized host' => [
            'LIQUIDSTACK_DB_HOST',
            str_repeat('h', 256),
        ];
        yield 'integer port' => ['LIQUIDSTACK_DB_PORT', 3306];
        yield 'zero port' => ['LIQUIDSTACK_DB_PORT', '0'];
        yield 'oversized port' => ['LIQUIDSTACK_DB_PORT', '65536'];
        yield 'port DSN injection' => [
            'LIQUIDSTACK_DB_PORT',
            '3306;must-not-leak',
        ];
        yield 'database DSN injection' => [
            'LIQUIDSTACK_DB_NAME',
            'database;charset=must-not-leak',
        ];
        yield 'oversized database name' => [
            'LIQUIDSTACK_DB_NAME',
            str_repeat('d', 65),
        ];
        yield 'non-string database name' => [
            'LIQUIDSTACK_DB_NAME',
            new stdClass(),
        ];
        yield 'non-string username' => ['LIQUIDSTACK_DB_USER', 1234];
        yield 'username control byte' => [
            'LIQUIDSTACK_DB_USER',
            "user\0must-not-leak",
        ];
        yield 'oversized username' => [
            'LIQUIDSTACK_DB_USER',
            str_repeat('u', 129),
        ];
        yield 'non-string password' => [
            'LIQUIDSTACK_DB_PASSWORD',
            ['must-not-leak'],
        ];
        yield 'password null byte' => [
            'LIQUIDSTACK_DB_PASSWORD',
            "secret\0must-not-leak",
        ];
        yield 'oversized password' => [
            'LIQUIDSTACK_DB_PASSWORD',
            str_repeat('p', 4097),
        ];
        yield 'legacy utf8 charset' => [
            'LIQUIDSTACK_DB_CHARSET',
            'utf8',
        ];
        yield 'charset DSN injection' => [
            'LIQUIDSTACK_DB_CHARSET',
            'utf8mb4;must-not-leak',
        ];
        yield 'non-string charset' => ['LIQUIDSTACK_DB_CHARSET', 1234];
    }

    public function testConnectionParametersUseValidatedPortAndUtf8mb4Only(): void
    {
        $parameters = (new LiquidStackDatabaseEnvironmentValidator())
            ->connectionParameters($this->environment(
                ' [2001:db8::1] ',
                '3308'
            ));

        self::assertSame([
            'mysql:host=2001:db8::1;port=3308;dbname=liquidstack_modules;charset=utf8mb4',
            'liquidstack_user',
            'dedicated-secret',
        ], $parameters);
    }

    /** @dataProvider invalidInspectionProvider */
    public function testConnectionParametersExposeOnlyAStableIssueCode(
        array $environment,
        string $expectedCode
    ): void {
        try {
            (new LiquidStackDatabaseEnvironmentValidator())
                ->connectionParameters($environment);
            self::fail('El perfil dedicado no preparado debía rechazarse.');
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
        yield 'missing' => [[
            'LIQUIDSTACK_DB_HOST' => 'localhost',
            'LIQUIDSTACK_DB_PORT' => '3306',
            'LIQUIDSTACK_DB_NAME' => 'database',
            'LIQUIDSTACK_DB_USER' => 'user',
            'LIQUIDSTACK_DB_PASSWORD' => '',
            'LIQUIDSTACK_DB_CHARSET' => 'utf8mb4',
        ], 'database.environment_missing'];

        yield 'invalid' => [[
            'LIQUIDSTACK_DB_HOST' => 'localhost;must-not-leak',
            'LIQUIDSTACK_DB_PORT' => '3306',
            'LIQUIDSTACK_DB_NAME' => 'database',
            'LIQUIDSTACK_DB_USER' => 'user',
            'LIQUIDSTACK_DB_PASSWORD' => 'must-not-leak',
            'LIQUIDSTACK_DB_CHARSET' => 'utf8mb4',
        ], 'database.environment_invalid'];
    }

    /** @return array<string, string> */
    private function environment(
        string $host = 'localhost',
        string $port = '3306'
    ): array {
        return [
            'LIQUIDSTACK_DB_HOST' => $host,
            'LIQUIDSTACK_DB_PORT' => $port,
            'LIQUIDSTACK_DB_NAME' => 'liquidstack_modules',
            'LIQUIDSTACK_DB_USER' => 'liquidstack_user',
            'LIQUIDSTACK_DB_PASSWORD' => 'dedicated-secret',
            'LIQUIDSTACK_DB_CHARSET' => 'utf8mb4',
        ];
    }
}
