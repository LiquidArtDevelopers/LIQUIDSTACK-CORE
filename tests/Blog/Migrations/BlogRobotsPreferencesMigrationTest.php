<?php

declare(strict_types=1);

namespace Tests\Blog\Migrations;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Blog\BlogMigrationRequirements;
use App\Core\Modules\Blog\BlogRobotsPreferencesMigrationPostconditionVerifier;
use App\Core\Modules\Migrations\MigrationConditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class BlogRobotsAppliedBaseVerifierFixture implements
    MigrationConditionVerifierInterface
{
    public int $calls = 0;

    public function contractVersion(): string
    {
        return 'blog-applied-0001-0014-fixture-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        ++$this->calls;

        return $scope->moduleId() === 'blog'
            && $scope->tableName('posts') === 'ls_blog_posts';
    }
}

final class BlogRobotsMariaDbStatementFixture extends PDOStatement
{
    /** @var array<string, mixed> */
    private array $parameters = [];

    /**
     * @param \Closure(array<string, mixed>): list<array<string, mixed>> $rows
     */
    public function __construct(
        private readonly \Closure $rows,
        private readonly mixed $scalar = null
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->parameters = $params ?? [];

        return true;
    }

    public function fetchAll(
        int $mode = PDO::FETCH_DEFAULT,
        mixed ...$args
    ): array {
        return ($this->rows)($this->parameters);
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        $rows = ($this->rows)($this->parameters);

        return $rows[0] ?? false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->scalar;
    }
}

final class BlogRobotsMariaDbPdoFixture extends PDO
{
    /** @var list<string> */
    public array $preparedSql = [];

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : null;
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        $this->preparedSql[] = $query;

        if (str_contains($query, 'information_schema.COLUMNS')) {
            return new BlogRobotsMariaDbStatementFixture(
                fn (array $params): array => $this->columns(
                    (string) ($params['name'] ?? '')
                )
            );
        }
        if (str_contains($query, 'information_schema.STATISTICS')) {
            return new BlogRobotsMariaDbStatementFixture(
                fn (array $params): array => [[
                    'INDEX_NAME' => 'PRIMARY',
                    'NON_UNIQUE' => 0,
                    'SEQ_IN_INDEX' => 1,
                    'COLUMN_NAME' => $this->owner(
                        (string) ($params['name'] ?? '')
                    ),
                    'SUB_PART' => null,
                ]]
            );
        }
        if (str_contains($query, 'information_schema.KEY_COLUMN_USAGE')) {
            return new BlogRobotsMariaDbStatementFixture(
                fn (array $params): array => [[
                    'COLUMN_NAME' => $this->owner(
                        (string) ($params['name'] ?? '')
                    ),
                    'REFERENCED_TABLE_NAME' => str_ends_with(
                        (string) ($params['name'] ?? ''),
                        'revision_robots'
                    ) ? 'ls_blog_content_revisions'
                        : 'ls_blog_post_localizations',
                    'REFERENCED_COLUMN_NAME' => 'id',
                    'DELETE_RULE' => 'CASCADE',
                ]]
            );
        }
        if (str_contains($query, 'information_schema.TRIGGERS')) {
            return new BlogRobotsMariaDbStatementFixture(
                static fn (array $params): array => [],
                0
            );
        }

        return false;
    }

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        if (str_starts_with($query, 'SHOW CREATE TABLE')) {
            return new BlogRobotsMariaDbStatementFixture(
                static fn (array $params): array => [[
                    'table',
                    'CREATE TABLE `fixture` ('
                        . 'CHECK (`allow_index` IN (0, 1)), '
                        . 'CHECK (`allow_follow` IN (0, 1)), '
                        . 'CHECK (`settings_sha256` REGEXP "^[0-9a-f]{64}$")'
                        . ')',
                ]]
            );
        }
        if (str_starts_with($query, 'SELECT allow_index')) {
            return new BlogRobotsMariaDbStatementFixture(
                static fn (array $params): array => []
            );
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function columns(string $table): array
    {
        $owner = $this->owner($table);

        return [
            $this->column($owner, 'bigint', 'bigint(20) unsigned'),
            $this->column('allow_index', 'tinyint', 'tinyint(3) unsigned'),
            $this->column('allow_follow', 'tinyint', 'tinyint(3) unsigned'),
            $this->column(
                'settings_sha256',
                'char',
                'char(64)',
                'ascii',
                'ascii_bin'
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function column(
        string $name,
        string $dataType,
        string $columnType,
        ?string $charset = null,
        ?string $collation = null
    ): array {
        return [
            'COLUMN_NAME' => $name,
            'DATA_TYPE' => $dataType,
            'COLUMN_TYPE' => $columnType,
            'IS_NULLABLE' => 'NO',
            'COLUMN_DEFAULT' => null,
            'CHARACTER_MAXIMUM_LENGTH' => $dataType === 'char' ? 64 : null,
            'CHARACTER_SET_NAME' => $charset,
            'COLLATION_NAME' => $collation,
            'EXTRA' => '',
        ];
    }

    private function owner(string $table): string
    {
        return str_ends_with($table, 'revision_robots')
            ? 'revision_id' : 'localization_id';
    }
}

final class BlogRobotsPreferencesMigrationTest extends TestCase
{
    private const POST = '51000000-0000-4000-8000-000000000001';
    private const LOCALIZATION = '51000000-0000-4000-8000-000000000002';
    private const ACTOR = '51000000-0000-4000-8000-000000000003';

    public function testMigrationIsRetrySafeAndRepositoryDefaultsLegacyRows(): void
    {
        $pdo = $this->sqlite();
        $scope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $migrations = $this->migrations();
        foreach ([
            '0001_blog_posts',
            '0003_blog_categories',
            '0005_blog_structured_content',
            '0006_blog_sitemap_publication_state',
            '0007_blog_post_tombstones',
            '0009_blog_analytics',
            '0011_blog_layout_editor_v2',
            '0012_blog_editor_preferences',
            '0014_blog_private_draft_publication',
            '0015_blog_robots_preferences',
        ] as $id) {
            $this->apply($pdo, $migrations[$id], $scope);
        }
        $migration = $migrations['0015_blog_robots_preferences'];
        $this->apply($pdo, $migration, $scope);

        self::assertInstanceOf(
            BlogRobotsPreferencesMigrationPostconditionVerifier::class,
            $migration->postconditionVerifier()
        );
        self::assertTrue($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));

        $legacy = new PdoBlogRepository($pdo, $scope);
        $legacy->transactional(function () use ($legacy): void {
            $now = $this->now();
            $legacy->insertPost(self::POST, self::ACTOR, $now);
            $legacy->insertLocalization(
                self::LOCALIZATION,
                self::POST,
                'es',
                new BlogDraft(
                    'Título heredado',
                    'Contenido heredado.',
                    'titulo-heredado',
                    'Título SEO heredado',
                    'Descripción heredada.',
                    'Extracto heredado.'
                ),
                self::ACTOR,
                $now
            );
        });

        $managed = new PdoBlogRepository($pdo, $scope, false, true);
        $legacyVariant = $managed->variant(self::POST, 'es');
        self::assertNotNull($legacyVariant);
        self::assertSame(
            'index,follow',
            $legacyVariant->draft()->robotsPreferences()->directive()
        );

        $updatedDraft = new BlogDraft(
            'Título heredado',
            'Contenido heredado.',
            'titulo-heredado',
            'Título SEO heredado',
            'Descripción heredada.',
            'Extracto heredado.',
            new BlogRobotsPreferences(false, true)
        );
        self::assertTrue($managed->transactional(
            fn (): bool => $managed->updateDraft(
                self::LOCALIZATION,
                1,
                $updatedDraft,
                self::ACTOR,
                $this->now()
            )
        ));

        $stored = $managed->variant(self::POST, 'es');
        self::assertNotNull($stored);
        self::assertSame(
            'noindex,follow',
            $stored->draft()->robotsPreferences()->directive()
        );
        self::assertTrue($migration->postconditionVerifier()?->verify(
            $pdo,
            $scope
        ));
        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_blog_robots_settings'
        )->fetchColumn());
    }

    public function testMariaDbMetadataFromConsumerAppliedSchemaIsAccepted(): void
    {
        $pdo = new BlogRobotsMariaDbPdoFixture();
        $base = new BlogRobotsAppliedBaseVerifierFixture();
        $verifier = new BlogRobotsPreferencesMigrationPostconditionVerifier(
            $base
        );

        self::assertTrue($verifier->verify(
            $pdo,
            MigrationScope::forTablePrefix('blog', 'ls_blog_')
        ));
        self::assertSame(1, $base->calls);

        $foreignKeyQueries = array_values(array_filter(
            $pdo->preparedSql,
            static fn (string $sql): bool => str_contains(
                $sql,
                'information_schema.KEY_COLUMN_USAGE'
            )
        ));
        self::assertCount(2, $foreignKeyQueries);
        foreach ($foreignKeyQueries as $sql) {
            self::assertStringContainsString(
                'k.REFERENCED_TABLE_NAME',
                $sql
            );
            self::assertStringContainsString('r.DELETE_RULE', $sql);
        }
    }

    public function testRequirementIncludesRobotsAtThe0018Frontier(): void
    {
        $requirement = BlogMigrationRequirements::robotsPreferences();

        self::assertSame('blog.robots_preferences', $requirement->featureId());
        self::assertTrue($requirement->requires(
            '0015_blog_robots_preferences'
        ));
        self::assertTrue(
            BlogMigrationRequirements::administration()->requires(
                '0015_blog_robots_preferences'
            )
        );
    }

    /** @return array<string, MigrationDefinition> */
    private function migrations(): array
    {
        $migrations = [];
        foreach (BlogMigrationProvider::migrations() as $migration) {
            $migrations[$migration->id()] = $migration;
        }

        return $migrations;
    }

    private function apply(
        PDO $pdo,
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $statement) {
            self::assertNotFalse($pdo->exec($statement));
        }
    }

    private function sqlite(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2031-01-01 00:00:00.000000',
            new DateTimeZone('UTC')
        );
    }
}
