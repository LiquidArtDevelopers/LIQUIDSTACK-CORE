<?php

declare(strict_types=1);

namespace Tests\Blog\Migrations;

use App\Core\Modules\Blog\BlogInitialNamespacePrecondition;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlogNamespaceScalarStatement extends PDOStatement
{
    public function __construct(private readonly mixed $value)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->value;
    }
}

final class BlogNamespaceMySqlPdo extends PDO
{
    public string $version = '10.4.32-MariaDB';
    public int $tableCollisions = 0;
    public int $constraintCollisions = 0;

    /** @var list<string> */
    public array $preparedSql = [];

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : null;
    }

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        return new BlogNamespaceScalarStatement($this->version);
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        $this->preparedSql[] = $query;

        return new BlogNamespaceScalarStatement(
            str_contains($query, 'TABLE_CONSTRAINTS')
                ? $this->constraintCollisions
                : $this->tableCollisions
        );
    }
}

final class BlogMigrationPreconditionTest extends TestCase
{
    public function testEmptyMainAndTemporaryNamespacesAreAccepted(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE unrelated (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TEMP TABLE unrelated_temp (id INTEGER PRIMARY KEY)');

        $condition = new BlogInitialNamespacePrecondition();
        self::assertSame(
            'blog-initial-namespace-empty-v1',
            $condition->contractVersion()
        );
        self::assertTrue($condition->verify(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'client_blog_')
        ));
    }

    #[DataProvider('collidingObjectProvider')]
    public function testAnyNamespacedObjectBlocksAdoption(string $sql): void
    {
        $pdo = $this->sqlite();
        $pdo->exec($sql);

        self::assertFalse((new BlogInitialNamespacePrecondition())->verify(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'client_blog_')
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function collidingObjectProvider(): iterable
    {
        yield 'table' => [
            'CREATE TABLE client_blog_partial (id INTEGER PRIMARY KEY)',
        ];
        yield 'view' => [
            'CREATE TABLE source_row (id INTEGER PRIMARY KEY); '
            . 'CREATE VIEW client_blog_partial AS SELECT id FROM source_row',
        ];
        yield 'index' => [
            'CREATE TABLE source_row (id INTEGER PRIMARY KEY); '
            . 'CREATE INDEX client_blog_partial ON source_row (id)',
        ];
        yield 'trigger' => [
            'CREATE TABLE source_row (id INTEGER PRIMARY KEY); '
            . 'CREATE TRIGGER client_blog_partial AFTER INSERT ON source_row '
            . 'BEGIN UPDATE source_row SET id = NEW.id WHERE id = NEW.id; END',
        ];
        yield 'temporary table' => [
            'CREATE TEMP TABLE client_blog_partial (id INTEGER PRIMARY KEY)',
        ];
    }

    public function testConstraintNameCollisionBlocksButTriviaAndLiteralsDoNot(): void
    {
        $scope = MigrationScope::forTablePrefix('blog', 'client_blog_');
        $condition = new BlogInitialNamespacePrecondition();

        $safe = $this->sqlite();
        $safe->exec(<<<'SQL'
CREATE TABLE unrelated (
    id INTEGER PRIMARY KEY,
    note TEXT DEFAULT 'CONSTRAINT client_blog_fake',
    -- CONSTRAINT client_blog_comment CHECK (id > 0)
    CHECK (length(note) >= 0)
)
SQL);
        self::assertTrue($condition->verify($safe, $scope));

        $collision = $this->sqlite();
        $collision->exec(<<<'SQL'
CREATE TABLE unrelated (
    id INTEGER PRIMARY KEY,
    CONSTRAINT client_blog_hidden CHECK (id > 0)
)
SQL);
        self::assertFalse($condition->verify($collision, $scope));
    }

    public function testPrefixComparisonIsCaseInsensitive(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE CLIENT_BLOG_PARTIAL (id INTEGER PRIMARY KEY)');

        self::assertFalse((new BlogInitialNamespacePrecondition())->verify(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'client_blog_')
        ));
    }

    public function testMysqlRequiresReliableMetadataAndAnEmptyNamespace(): void
    {
        $pdo = new BlogNamespaceMySqlPdo();
        $scope = MigrationScope::forTablePrefix('blog', 'client_blog_');
        $condition = new BlogInitialNamespacePrecondition();

        self::assertTrue($condition->verify($pdo, $scope));
        self::assertCount(2, $pdo->preparedSql);
        self::assertStringContainsString(
            'information_schema.TABLES',
            $pdo->preparedSql[0]
        );
        self::assertStringContainsString(
            'information_schema.TABLE_CONSTRAINTS',
            $pdo->preparedSql[1]
        );

        $pdo->tableCollisions = 1;
        self::assertFalse($condition->verify($pdo, $scope));
        $pdo->tableCollisions = 0;
        $pdo->constraintCollisions = 1;
        self::assertFalse($condition->verify($pdo, $scope));
        $pdo->constraintCollisions = 0;
        $pdo->version = '5.7.44';
        self::assertFalse($condition->verify($pdo, $scope));
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }
}
