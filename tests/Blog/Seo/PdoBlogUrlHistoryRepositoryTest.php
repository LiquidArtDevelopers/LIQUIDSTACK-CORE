<?php

declare(strict_types=1);

namespace Tests\Blog\Seo;

use App\Core\Blog\Seo\PdoBlogUrlHistoryRepository;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlogUrlHistoryNativeStatementFixture extends PDOStatement
{
    public function __construct(
        private readonly BlogUrlHistoryNativePdoFixture $pdo,
        private readonly string $sql
    ) {
    }

    public function execute(?array $params = null): bool
    {
        preg_match_all(
            '/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/',
            $this->sql,
            $matches
        );
        $names = $matches[1] ?? [];
        if (count($names) !== count(array_unique($names))) {
            throw new RuntimeException('Native prepares reject reused names.');
        }
        $this->pdo->executions[] = [
            'sql' => $this->sql,
            'params' => $params ?? [],
        ];

        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return false;
    }

    public function rowCount(): int
    {
        return str_starts_with($this->sql, 'INSERT INTO ') ? 1 : 0;
    }
}

final class BlogUrlHistoryNativePdoFixture extends PDO
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $executions = [];

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME
            ? 'mysql'
            : parent::getAttribute($attribute);
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        return new BlogUrlHistoryNativeStatementFixture($this, $query);
    }
}

final class PdoBlogUrlHistoryRepositoryTest extends TestCase
{
    public function testActivationUsesUniqueNamesWithNativeMySqlPrepares(): void
    {
        $pdo = new BlogUrlHistoryNativePdoFixture();
        $repository = new PdoBlogUrlHistoryRepository(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_')
        );

        $repository->activate(
            '11111111-1111-4111-8111-111111111111',
            'es',
            'matrix-native-prepare',
            new DateTimeImmutable('2030-01-02 03:04:05.123456',
                new DateTimeZone('UTC'))
        );

        $inserts = array_values(array_filter(
            $pdo->executions,
            static fn (array $execution): bool => str_starts_with(
                $execution['sql'],
                'INSERT INTO '
            )
        ));
        self::assertCount(1, $inserts);
        self::assertStringContainsString(':created_at', $inserts[0]['sql']);
        self::assertStringContainsString(':updated_at', $inserts[0]['sql']);
        self::assertArrayNotHasKey('now', $inserts[0]['params']);
        self::assertSame(
            '2030-01-02 03:04:05.123456',
            $inserts[0]['params']['created_at'] ?? null
        );
        self::assertSame(
            $inserts[0]['params']['created_at'] ?? null,
            $inserts[0]['params']['updated_at'] ?? null
        );
    }
}
