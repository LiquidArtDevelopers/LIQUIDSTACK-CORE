<?php

declare(strict_types=1);

use App\Core\Database\MySqlColumnDefaultNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MySqlColumnDefaultNormalizerTest extends TestCase
{
    public function testMariaDb104MetadataDistinguishesNumericLiteralAndExpression(): void
    {
        $normalizer = new MySqlColumnDefaultNormalizer();

        self::assertSame(
            "'1'",
            $normalizer->normalizeMetadata('1', 'bigint', '', true)
        );
        self::assertNull(
            $normalizer->normalizeMetadata('NULL', 'bigint', '', true)
        );
        self::assertSame(
            'current_timestamp(6)',
            $normalizer->normalizeMetadata(
                'current_timestamp(6)',
                'datetime',
                '',
                true
            )
        );
        self::assertSame(
            "'manual'",
            $normalizer->normalizeMetadata(
                "'manual'",
                'varchar',
                '',
                true
            )
        );
    }

    public function testMySqlMetadataUsesDefaultGeneratedToKeepExpressionsTyped(): void
    {
        $normalizer = new MySqlColumnDefaultNormalizer();

        self::assertSame(
            "'manual'",
            $normalizer->normalizeMetadata('manual', 'varchar', '', false)
        );
        self::assertSame(
            'current_timestamp(6)',
            $normalizer->normalizeMetadata(
                'current_timestamp(6)',
                'datetime',
                'DEFAULT_GENERATED',
                false
            )
        );
    }

    #[DataProvider('defaults')]
    public function testDefaultsAreNormalizedSemanticallyAndIdempotently(
        ?string $reported,
        ?string $expected,
        bool $unquotedValueIsLiteral = false
    ): void {
        $normalizer = new MySqlColumnDefaultNormalizer();
        $normalized = $normalizer->normalize(
            $reported,
            $unquotedValueIsLiteral
        );

        self::assertSame($expected, $normalized);
        self::assertSame(
            $expected,
            $normalizer->normalize($normalized, $unquotedValueIsLiteral)
        );
    }

    /** @return iterable<string, array{?string, ?string, 2?: bool}> */
    public static function defaults(): iterable
    {
        yield 'missing default' => [null, null];
        yield 'MariaDB SQL NULL' => ['NULL', null];
        yield 'lowercase SQL null' => ['null', null];
        yield 'quoted NULL is a string' => ["'NULL'", "'NULL'"];
        yield 'charset quoted NULL is a string' => [
            "_utf8mb4'NULL'",
            "'NULL'",
        ];
        yield 'quoted literal' => ["'manual'", "'manual'"];
        yield 'charset quoted literal' => [
            "_utf8mb4'manual'",
            "'manual'",
        ];
        yield 'MySQL unquoted literal metadata' => [
            'manual',
            "'manual'",
            true,
        ];
        yield 'MySQL unquoted numeric literal metadata' => [
            '1',
            "'1'",
            true,
        ];
        yield 'MySQL unquoted NULL string literal metadata' => [
            'NULL',
            "'NULL'",
            true,
        ];
        yield 'literal cannot impersonate expression' => [
            "'current_timestamp(6)'",
            "'current_timestamp(6)'",
        ];
        yield 'expression remains an expression' => [
            'current_timestamp(6)',
            'current_timestamp(6)',
        ];
    }
}
