<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Verifies the exact canonical Dummy seed without claiming legacy slugs. */
final class BlogDummyCategorySeedPostcondition implements
    MigrationPostconditionVerifierInterface
{
    public function contractVersion(): string
    {
        return 'blog-dummy-category-seed-v2';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'blog') {
            return false;
        }
        try {
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            $categories = $scope->quotedTable('categories', $driver);
            $localizations = $scope->quotedTable(
                'category_locales',
                $driver
            );
            $statement = $pdo->prepare(
                'SELECT c.public_id AS category_public_id, '
                    . 'c.created_by_user_public_id AS category_actor, '
                    . 'cl.public_id AS localization_public_id, cl.locale, '
                    . 'cl.slug, cl.name, cl.lock_version, '
                    . 'cl.created_by_user_public_id AS localization_creator, '
                    . 'cl.updated_by_user_public_id AS localization_updater '
                    . 'FROM ' . $categories . ' c JOIN ' . $localizations
                    . ' cl ON cl.category_id = c.id '
                    . 'WHERE c.public_id = :category_public_id '
                    . 'AND cl.public_id = :localization_public_id LIMIT 2'
            );
            $statement->execute([
                'category_public_id' =>
                    BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
                'localization_public_id' =>
                    BlogReservedCategoryPolicy::DUMMY_LOCALIZATION_PUBLIC_ID,
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) !== 1) {
                return false;
            }
            $row = $rows[0];

            return is_array($row)
                && ($row['category_public_id'] ?? null)
                    === BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID
                && ($row['category_actor'] ?? null)
                    === BlogReservedCategoryPolicy::SYSTEM_ACTOR_PUBLIC_ID
                && ($row['localization_public_id'] ?? null)
                    === BlogReservedCategoryPolicy::DUMMY_LOCALIZATION_PUBLIC_ID
                && ($row['locale'] ?? null)
                    === BlogReservedCategoryPolicy::DUMMY_LOCALE
                && ($row['slug'] ?? null)
                    === BlogReservedCategoryPolicy::DUMMY_SLUG
                && ($row['name'] ?? null)
                    === BlogReservedCategoryPolicy::DUMMY_NAME
                && (int) ($row['lock_version'] ?? 0) === 1
                && ($row['localization_creator'] ?? null)
                    === BlogReservedCategoryPolicy::SYSTEM_ACTOR_PUBLIC_ID
                && ($row['localization_updater'] ?? null)
                    === BlogReservedCategoryPolicy::SYSTEM_ACTOR_PUBLIC_ID;
        } catch (Throwable) {
            return false;
        }
    }

}
