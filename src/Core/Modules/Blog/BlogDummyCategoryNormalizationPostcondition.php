<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationConditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationPostconditionVerifierInterface;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use Throwable;

/** Verifies that every legacy exact `dummy` assignment is canonicalized. */
final class BlogDummyCategoryNormalizationPostcondition implements
    MigrationPostconditionVerifierInterface
{
    private readonly MigrationConditionVerifierInterface $urlVerifier;

    public function __construct(
        ?MigrationConditionVerifierInterface $urlVerifier = null
    ) {
        $this->urlVerifier = $urlVerifier
            ?? new BlogUrlHistoryMigrationPostconditionVerifier();
    }

    public function contractVersion(): string
    {
        return 'blog-dummy-category-normalization-v1';
    }

    public function verify(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'blog'
            || !(new BlogDummyCategorySeedPostcondition())->verify(
                $pdo,
                $scope
            )
            || !$this->urlVerifier->verify(
                    $pdo,
                    $scope
                )) {
            return false;
        }

        try {
            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            $categories = $scope->quotedTable('categories', $driver);
            $localizations = $scope->quotedTable(
                'category_locales',
                $driver
            );
            $relations = $scope->quotedTable('post_categories', $driver);
            $workspaceItems = $scope->quotedTable(
                'category_assignment_workspace_items',
                $driver
            );
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM ' . $relations . ' legacy_relation '
                    . 'JOIN ' . $localizations . ' legacy_locale '
                    . 'ON legacy_locale.category_id = '
                    . 'legacy_relation.category_id '
                    . 'WHERE legacy_locale.slug = :dummy_slug '
                    . 'AND NOT EXISTS (SELECT 1 FROM ' . $relations
                    . ' canonical_relation JOIN ' . $categories
                    . ' canonical_category ON canonical_category.id = '
                    . 'canonical_relation.category_id WHERE '
                    . 'canonical_relation.post_id = legacy_relation.post_id '
                    . 'AND canonical_category.public_id = '
                    . ':canonical_public_id)'
            );
            if (!$statement->execute([
                'dummy_slug' => BlogReservedCategoryPolicy::DUMMY_SLUG,
                'canonical_public_id' =>
                    BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
            ])) {
                return false;
            }
            if ((int) $statement->fetchColumn() !== 0) {
                return false;
            }

            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM ' . $workspaceItems
                    . ' legacy_item JOIN ' . $localizations
                    . ' legacy_locale ON legacy_locale.category_id = '
                    . 'legacy_item.category_id WHERE legacy_locale.slug = '
                    . ':dummy_slug AND NOT EXISTS (SELECT 1 FROM '
                    . $workspaceItems . ' canonical_item JOIN '
                    . $categories . ' canonical_category ON '
                    . 'canonical_category.id = canonical_item.category_id '
                    . 'WHERE canonical_item.post_id = legacy_item.post_id '
                    . 'AND canonical_category.public_id = '
                    . ':canonical_public_id)'
            );
            if (!$statement->execute([
                'dummy_slug' => BlogReservedCategoryPolicy::DUMMY_SLUG,
                'canonical_public_id' =>
                    BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
            ])) {
                return false;
            }

            return (int) $statement->fetchColumn() === 0;
        } catch (Throwable) {
            return false;
        }
    }
}
