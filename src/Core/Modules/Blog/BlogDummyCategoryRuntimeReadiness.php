<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Modules\Migrations\MigrationDatabaseDriver;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Request-time Dummy invariant without recursively auditing prior migrations.
 *
 * The exact 0018 postcondition remains unchanged for migrate/doctor. HTTP only
 * verifies its operational seed and that no legacy exact `dummy` assignment
 * lacks the canonical assignment consumed by private/public repositories.
 */
final class BlogDummyCategoryRuntimeReadiness
{
    public function __construct(
        private readonly BlogDummyCategorySeedPostcondition $seedVerifier =
            new BlogDummyCategorySeedPostcondition()
    ) {
    }

    public function isReady(PDO $pdo, MigrationScope $scope): bool
    {
        if ($scope->moduleId() !== 'blog') {
            return false;
        }

        try {
            if (!$this->seedVerifier->verify($pdo, $scope)) {
                return false;
            }

            $driver = MigrationDatabaseDriver::fromPdo($pdo)->value;
            $categories = $scope->quotedTable('categories', $driver);
            $locales = $scope->quotedTable('category_locales', $driver);

            return !$this->hasUnnormalizedAssignment(
                $pdo,
                $categories,
                $locales,
                $scope->quotedTable('post_categories', $driver)
            ) && !$this->hasUnnormalizedAssignment(
                $pdo,
                $categories,
                $locales,
                $scope->quotedTable(
                    'category_assignment_workspace_items',
                    $driver
                )
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function hasUnnormalizedAssignment(
        PDO $pdo,
        string $categories,
        string $locales,
        string $assignments
    ): bool {
        $statement = $pdo->prepare(
            'SELECT 1 FROM ' . $assignments . ' legacy_assignment JOIN '
                . $locales . ' legacy_locale ON legacy_locale.category_id = '
                . 'legacy_assignment.category_id WHERE legacy_locale.slug = '
                . ':dummy_slug AND NOT EXISTS (SELECT 1 FROM '
                . $assignments . ' canonical_assignment JOIN '
                . $categories . ' canonical_category ON '
                . 'canonical_category.id = canonical_assignment.category_id '
                . 'WHERE canonical_assignment.post_id = '
                . 'legacy_assignment.post_id AND '
                . 'canonical_category.public_id = :canonical_public_id) '
                . 'LIMIT 1'
        );
        if (!$statement instanceof PDOStatement || !$statement->execute([
            'dummy_slug' => BlogReservedCategoryPolicy::DUMMY_SLUG,
            'canonical_public_id' =>
                BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
        ])) {
            throw new \RuntimeException('Dummy readiness query failed.');
        }

        return $statement->fetchColumn() !== false;
    }
}
