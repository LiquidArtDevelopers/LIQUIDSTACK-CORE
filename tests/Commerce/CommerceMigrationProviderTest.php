<?php

declare(strict_types=1);

namespace Tests\Commerce;

use App\Core\Commerce\Configuration\CommerceConfig;
use App\Core\Modules\Commerce\CommerceCapabilitySeedPostcondition;
use App\Core\Modules\Commerce\CommerceInitialNamespacePrecondition;
use App\Core\Modules\Commerce\CommerceMigrationPostconditionVerifier;
use App\Core\Modules\Commerce\CommerceMigrationProvider;
use App\Core\Modules\Commerce\CommerceSchemaContract;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use PDO;
use PHPUnit\Framework\TestCase;

final class CommerceMigrationProviderTest extends TestCase
{
    public function testCatalogIsAppendOnlyAndCrossScopeIsExplicit(): void
    {
        $migrations = iterator_to_array(
            CommerceMigrationProvider::migrations(),
            false
        );

        self::assertSame('commerce', CommerceMigrationProvider::moduleId());
        self::assertSame([
            '0001_commerce_catalog',
            '0002_commerce_inquiries',
            '0003_commerce_capabilities',
        ], array_map(
            static fn (MigrationDefinition $migration): string =>
                $migration->id(),
            $migrations
        ));
        foreach ($migrations as $migration) {
            self::assertFalse($migration->isDestructive());
            self::assertTrue($migration->isRetrySafe());
            self::assertTrue($migration->isExecutableFor('mysql'));
            self::assertTrue($migration->isExecutableFor('sqlite'));
            self::assertTrue($migration->isTransactionalFor('sqlite'));
            self::assertFalse($migration->isTransactionalFor('mysql'));
        }
        self::assertInstanceOf(
            CommerceInitialNamespacePrecondition::class,
            $migrations[0]->preconditionVerifier()
        );
        self::assertInstanceOf(
            CommerceMigrationPostconditionVerifier::class,
            $migrations[0]->postconditionVerifier()
        );
        self::assertSame(
            ['0001_commerce_catalog'],
            $migrations[1]->supersededPostconditionIds()
        );
        self::assertSame('webadmin', $migrations[2]->targetScopeModuleId());
        self::assertInstanceOf(
            CommerceCapabilitySeedPostcondition::class,
            $migrations[2]->postconditionVerifier()
        );
    }

    public function testMysqlCatalogAvoidsAutoIncrementCheckExpressions(): void
    {
        $migration = iterator_to_array(
            CommerceMigrationProvider::migrations(),
            false
        )[0];
        $scope = MigrationScope::forTablePrefix(
            'commerce',
            'ls_commerce_'
        );
        $sql = implode("\n", $migration->statementsFor('mysql', $scope));

        self::assertStringNotContainsString(
            '`parent_id` <> `id`',
            $sql
        );
    }

    public function testConfiguredPrefixBudgetCoversEveryIdentifier(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2)
                . '/src/Core/Modules/Commerce/CommerceMigrationProvider.php'
        );
        self::assertIsString($source);
        preg_match_all('/\{\{table:([a-z0-9_]+)\}\}/', $source, $matches);
        $identifiers = array_values(array_unique($matches[1]));
        usort(
            $identifiers,
            static fn (string $left, string $right): int =>
                strlen($right) <=> strlen($left)
                    ?: strcmp($left, $right)
        );

        self::assertSame(
            CommerceConfig::LONGEST_TABLE_SUFFIX,
            $identifiers[0] ?? null
        );
    }

    public function testSqliteSchemaAndCapabilitiesApplyIdempotently(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $commerceScope = MigrationScope::forTablePrefix(
            'commerce',
            'ls_commerce_'
        );
        $webAdminScope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        $webAdmin = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        $this->apply($pdo, $webAdmin, $webAdminScope);

        $migrations = iterator_to_array(
            CommerceMigrationProvider::migrations(),
            false
        );
        $this->apply($pdo, $migrations[0], $commerceScope);
        $this->apply($pdo, $migrations[1], $commerceScope);
        $this->apply($pdo, $migrations[2], $webAdminScope);
        $this->apply($pdo, $migrations[0], $commerceScope);
        $this->apply($pdo, $migrations[1], $commerceScope);
        $this->apply($pdo, $migrations[2], $webAdminScope);

        self::assertTrue($migrations[1]->postconditionVerifier()?->verify(
            $pdo,
            $commerceScope
        ));
        self::assertTrue($migrations[2]->postconditionVerifier()?->verify(
            $pdo,
            $webAdminScope
        ));
        foreach (array_keys(CommerceSchemaContract::allTables()) as $suffix) {
            $statement = $pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master "
                . "WHERE type = 'table' AND name = :name"
            );
            $statement->execute(['name' => 'ls_commerce_' . $suffix]);
            self::assertSame(1, (int) $statement->fetchColumn(), $suffix);
        }
        self::assertSame(9, (int) $pdo->query(
            "SELECT COUNT(*) FROM ls_webadmin_capabilities "
            . "WHERE module_id = 'commerce'"
        )->fetchColumn());
        self::assertSame(18, (int) $pdo->query(
            'SELECT COUNT(*) FROM ls_webadmin_role_capabilities rc '
            . 'JOIN ls_webadmin_capabilities c ON c.id = rc.capability_id '
            . "WHERE c.module_id = 'commerce'"
        )->fetchColumn());

        $mediaLocalizationSql = (string) $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' "
            . "AND name = 'ls_commerce_product_media_localizations'"
        )->fetchColumn();
        self::assertStringContainsString(
            '"alt_text" IS NOT NULL',
            $mediaLocalizationSql
        );
        $pdo->exec("INSERT INTO ls_commerce_products (public_id) "
            . "VALUES ('10000000-0000-4000-8000-000000000001')");
        $productId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO ls_commerce_product_media "
            . "(product_id, media_asset_public_id, role) VALUES ("
            . $productId . ", '20000000-0000-4000-8000-000000000002', 'cover')");
        $mediaId = (int) $pdo->lastInsertId();
        $pdo->exec('INSERT INTO ls_commerce_product_media_localizations '
            . '(media_id, locale, alt_text, caption, translation_status) VALUES ('
            . $mediaId . ", 'eu', NULL, NULL, 'fallback')");
        try {
            $pdo->exec('INSERT INTO ls_commerce_product_media_localizations '
                . '(media_id, locale, alt_text, caption, translation_status) VALUES ('
                . $mediaId . ", 'es', NULL, NULL, 'source')");
            self::fail('Published source media must require non-empty ALT text.');
        } catch (\PDOException) {
            self::assertSame(1, (int) $pdo->query(
                'SELECT COUNT(*) FROM ls_commerce_product_media_localizations'
            )->fetchColumn());
        }
    }

    private function apply(
        PDO $pdo,
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            $pdo->exec($sql);
        }
    }
}
