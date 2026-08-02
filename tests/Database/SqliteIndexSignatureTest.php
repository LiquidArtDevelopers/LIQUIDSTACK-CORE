<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Core\Database\SqliteIndexSignature;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqliteIndexSignatureTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function indexDefinitions(): iterable
    {
        yield 'exact ascending binary index' => [
            'CREATE UNIQUE INDEX sample_idx ON sample (first, second)',
            '1:first,second',
        ];
        yield 'descending index' => [
            'CREATE UNIQUE INDEX sample_idx ON sample (first DESC, second)',
            null,
        ];
        yield 'collation drift' => [
            'CREATE UNIQUE INDEX sample_idx ON sample '
                . '(first COLLATE NOCASE, second)',
            null,
        ];
        yield 'partial index' => [
            'CREATE UNIQUE INDEX sample_idx ON sample (first, second) '
                . 'WHERE first IS NOT NULL',
            null,
        ];
        yield 'expression index' => [
            'CREATE UNIQUE INDEX sample_idx ON sample (lower(first), second)',
            null,
        ];
    }

    #[DataProvider('indexDefinitions')]
    public function testOnlyExactPortableIndexesProduceASignature(
        string $definition,
        ?string $expected
    ): void {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE sample (id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'first TEXT COLLATE BINARY, second INTEGER)'
        );
        $pdo->exec($definition);
        $index = $pdo->query(
            "PRAGMA index_list('sample')"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($index);

        self::assertSame(
            $expected,
            SqliteIndexSignature::fromPragmaRow($pdo, $index, ['c'])
        );
    }
}
