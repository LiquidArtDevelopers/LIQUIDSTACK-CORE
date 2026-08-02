<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Core\Database\SqlCheckExpressionCanonicalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlCheckExpressionCanonicalizerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function equivalentExpressions(): iterable
    {
        yield 'MariaDB lcase and charset introducers' => [
            "(`slug` = LCASE(`slug`) AND `role` = _utf8mb4'image')",
            "and(slug=lower(slug),role='image')",
        ];
        yield 'MySQL regexp_like metadata' => [
            "REGEXP_LIKE(`document_sha256`, _utf8mb4'^[0-9a-f]{64}$')",
            "document_sha256regexp'^[0-9a-f]{64}$'",
        ];
        yield 'MariaDB rlike synonym' => [
            "`document_sha256` RLIKE '^[0-9a-f]{64}$'",
            "document_sha256regexp'^[0-9a-f]{64}$'",
        ];
        yield 'only truly wrapping parentheses are removed' => [
            "((`a` = 1) AND (`b` = 2))",
            'and(a=1,b=2)',
        ];
        yield 'parentheses inside a literal remain balanced' => [
            "((`value` = 'matrix ) ( value'))",
            "value='matrix ) ( value'",
        ];
    }

    #[DataProvider('equivalentExpressions')]
    public function testEquivalentMetadataHasStableCanonicalForm(
        string $expression,
        string $expected
    ): void {
        self::assertSame(
            $expected,
            SqlCheckExpressionCanonicalizer::canonicalize($expression)
        );
    }

    public function testRegexpLikeWithMatchFlagsFailsClosed(): void
    {
        self::assertNotSame(
            "valueregexp'^[a-z]+$'",
            SqlCheckExpressionCanonicalizer::canonicalize(
                "REGEXP_LIKE(value, '^[a-z]+$', 'i')"
            )
        );
    }

    public function testUnapprovedCharsetIntroducersFailClosed(): void
    {
        self::assertNotSame(
            SqlCheckExpressionCanonicalizer::canonicalize(
                "role = _utf8mb4'image'"
            ),
            SqlCheckExpressionCanonicalizer::canonicalize(
                "role = _binary'image'"
            )
        );
    }

    public function testMySqlBooleanSerializationMatchesTheContract(): void
    {
        $contract = "CHAR_LENGTH(locale) BETWEEN 2 AND 16 "
            . "AND locale = LOWER(locale) AND locale = TRIM(locale)";
        $mySql = "((char_length(`locale`) between 2 and 16) "
            . "and (`locale` = lcase(`locale`)) "
            . "and (`locale` = trim(`locale`)))";

        self::assertSame(
            SqlCheckExpressionCanonicalizer::canonicalize($contract),
            SqlCheckExpressionCanonicalizer::canonicalize($mySql)
        );
    }

    public function testMySqlIsNullAndNestedBooleanGroupsMatchTheContract(): void
    {
        $contract = "slug IS NULL OR (CHAR_LENGTH(TRIM(slug)) > 0 "
            . "AND slug = LOWER(slug) AND slug = TRIM(slug))";
        $mySql = "(isnull(`slug`) or (((char_length(trim(`slug`)) > 0) "
            . "and (`slug` = lcase(`slug`))) and (`slug` = trim(`slug`))))";

        self::assertSame(
            SqlCheckExpressionCanonicalizer::canonicalize($contract),
            SqlCheckExpressionCanonicalizer::canonicalize($mySql)
        );
    }

    public function testMySqlIsNotNullFunctionMatchesTheInfixContract(): void
    {
        self::assertSame(
            SqlCheckExpressionCanonicalizer::canonicalize(
                'password_hash IS NOT NULL AND password_set_at IS NOT NULL'
            ),
            SqlCheckExpressionCanonicalizer::canonicalize(
                'isnotnull(`password_hash`) AND isnotnull(`password_set_at`)'
            )
        );
        self::assertSame(
            SqlCheckExpressionCanonicalizer::canonicalize(
                'password_hash IS NOT NULL'
            ),
            SqlCheckExpressionCanonicalizer::canonicalize(
                'NOT(ISNULL(`password_hash`))'
            )
        );
    }
}
