<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PDOStatement;
use Throwable;

/** Live-category override; storage uncertainty can only tighten robots. */
final class PdoBlogDummyCategoryRobotsOverride implements
    BlogPublicRobotsOverrideInterface
{
    private readonly string $posts;
    private readonly string $relations;
    private readonly string $categories;

    public function __construct(private readonly PDO $pdo, MigrationScope $scope)
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($driver)
            || !in_array($driver, ['mysql', 'sqlite'], true)
            || $scope->moduleId() !== 'blog') {
            throw new \InvalidArgumentException('Invalid Blog robots scope.');
        }
        $this->posts = $scope->quotedTable('posts', $driver);
        $this->relations = $scope->quotedTable('post_categories', $driver);
        $this->categories = $scope->quotedTable('categories', $driver);
    }

    public function forcesNoIndexNoFollow(BlogPostVariant $variant): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM ' . $this->posts . ' p JOIN '
                    . $this->relations . ' pc ON pc.post_id = p.id JOIN '
                    . $this->categories
                    . ' c ON c.id = pc.category_id '
                    . 'WHERE p.public_id = :post_public_id '
                    . 'AND c.public_id = :dummy_category_public_id LIMIT 1'
            );
            if (!$statement instanceof PDOStatement
                || !$statement->execute([
                    'post_public_id' => $variant->postPublicId(),
                    'dummy_category_public_id' =>
                        BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
                ])) {
                return true;
            }

            return $statement->fetchColumn() !== false;
        } catch (Throwable) {
            return true;
        }
    }
}
