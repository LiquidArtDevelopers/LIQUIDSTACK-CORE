<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorialWorkflow\Persistence;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogInput;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\EditorialWorkflow\BlogEditorialVariantState;
use App\Core\Blog\EditorialWorkflow\BlogEditorialWorkspaceState;
use App\Core\Blog\EditorialWorkflow\BlogPublicationHeadState;
use App\Core\Blog\Persistence\BlogPersistenceConflict;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/** Shared-PDO adapter for the optional private draft/public head frontier. */
final class PdoBlogEditorialWorkspaceRepository implements
    BlogEditorialWorkspaceRepositoryInterface
{
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';

    private readonly string $driver;
    private readonly string $posts;
    private readonly string $localizations;
    private readonly string $revisions;
    private readonly string $workspaces;
    private readonly string $categoryWorkspaces;
    private readonly string $categoryWorkspaceItems;
    private readonly string $publicationHeads;
    private readonly string $categoryAssignmentHeads;
    private readonly string $categories;
    private readonly string $postCategories;
    private readonly string $robotsSettings;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $scope,
        private readonly bool $robotsSettingsReady = false
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, ['mysql', 'sqlite'], true)
                || $scope->moduleId() !== 'blog'
                || $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || ($driver === 'mysql' && !in_array(
                    $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                    [false, 0, '0'],
                    true
                ))
                || ($driver === 'sqlite' && !in_array(
                    $pdo->query('PRAGMA foreign_keys')->fetchColumn(),
                    [1, '1'],
                    true
                ))
            ) {
                throw new BlogEditorialWorkflowPersistenceException();
            }
            $this->driver = $driver;
            $this->posts = $scope->quotedTable('posts', $driver);
            $this->localizations = $scope->quotedTable(
                'post_localizations',
                $driver
            );
            $this->revisions = $scope->quotedTable(
                'content_revisions',
                $driver
            );
            $this->workspaces = $scope->quotedTable(
                'editorial_workspaces',
                $driver
            );
            $this->categoryWorkspaces = $scope->quotedTable(
                'category_assignment_workspaces',
                $driver
            );
            $this->categoryWorkspaceItems = $scope->quotedTable(
                'category_assignment_workspace_items',
                $driver
            );
            $this->publicationHeads = $scope->quotedTable(
                'publication_heads',
                $driver
            );
            $this->categoryAssignmentHeads = $scope->quotedTable(
                'category_assignment_heads',
                $driver
            );
            $this->categories = $scope->quotedTable('categories', $driver);
            $this->postCategories = $scope->quotedTable(
                'post_categories',
                $driver
            );
            $this->robotsSettings = $scope->quotedTable(
                'robots_settings',
                $driver
            );
        } catch (BlogEditorialWorkflowPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    public function variantState(
        string $postPublicId,
        string $locale,
        bool $lock = false
    ): ?BlogEditorialVariantState {
        $postPublicId = $this->publicId($postPublicId);
        $locale = $this->locale($locale);
        $this->assertReadableLock($lock);
        $row = $this->one(
            'SELECT p.public_id AS post_public_id, '
                . 'l.public_id AS localization_public_id, l.locale, '
                . 'l.status, l.lock_version FROM ' . $this->posts . ' p '
                . 'JOIN ' . $this->localizations . ' l ON l.post_id = p.id '
                . 'WHERE p.public_id = :post AND l.locale = :locale'
                . ($lock ? $this->forUpdate() : ''),
            ['post' => $postPublicId, 'locale' => $locale]
        );
        if ($row === null) {
            return null;
        }

        try {
            return new BlogEditorialVariantState(
                (string) ($row['post_public_id'] ?? ''),
                (string) ($row['localization_public_id'] ?? ''),
                (string) ($row['locale'] ?? ''),
                (string) ($row['status'] ?? ''),
                $this->positiveInteger($row['lock_version'] ?? null)
            );
        } catch (Throwable) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    public function workspace(
        string $localizationPublicId,
        bool $lock = false
    ): ?BlogEditorialWorkspaceState {
        $localizationPublicId = $this->publicId($localizationPublicId);
        $this->assertReadableLock($lock);
        $row = $this->one(
            'SELECT l.public_id AS localization_public_id, '
                . 'r.public_id AS draft_revision_public_id, '
                . 'w.base_publication_version FROM '
                . $this->workspaces . ' w JOIN ' . $this->localizations
                . ' l ON l.id = w.localization_id LEFT JOIN '
                . $this->revisions . ' r ON r.id = w.draft_revision_id '
                . 'WHERE l.public_id = :localization'
                . ($lock ? $this->forUpdate() : ''),
            ['localization' => $localizationPublicId]
        );
        if ($row === null) {
            return null;
        }

        try {
            return new BlogEditorialWorkspaceState(
                (string) ($row['localization_public_id'] ?? ''),
                isset($row['draft_revision_public_id'])
                    ? (string) $row['draft_revision_public_id']
                    : null,
                $this->nonNegativeInteger(
                    $row['base_publication_version'] ?? null
                )
            );
        } catch (Throwable) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    public function categoryAssignmentVersion(
        string $postPublicId,
        bool $lock = false
    ): int {
        $postPublicId = $this->publicId($postPublicId);
        $this->assertReadableLock($lock);
        $row = $this->one(
            'SELECT h.assignment_version FROM ' . $this->posts . ' p LEFT '
                . 'JOIN ' . $this->categoryAssignmentHeads
                . ' h ON h.post_id = p.id WHERE p.public_id = :post'
                . ($lock ? $this->forUpdate() : ''),
            ['post' => $postPublicId]
        );
        if ($row === null) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
        if (($row['assignment_version'] ?? null) === null) {
            return 0;
        }

        return $this->positiveInteger($row['assignment_version']);
    }

    public function publicationHead(
        string $localizationPublicId,
        bool $lock = false
    ): ?BlogPublicationHeadState {
        $localizationPublicId = $this->publicId($localizationPublicId);
        $this->assertReadableLock($lock);
        $row = $this->one(
            'SELECT l.public_id AS localization_public_id, '
                . 'r.public_id AS revision_public_id, '
                . 'h.publication_version FROM ' . $this->publicationHeads
                . ' h JOIN ' . $this->localizations
                . ' l ON l.id = h.localization_id JOIN ' . $this->revisions
                . ' r ON r.id = h.revision_id WHERE l.public_id = '
                . ':localization' . ($lock ? $this->forUpdate() : ''),
            ['localization' => $localizationPublicId]
        );
        if ($row === null) {
            return null;
        }

        try {
            return new BlogPublicationHeadState(
                (string) ($row['localization_public_id'] ?? ''),
                (string) ($row['revision_public_id'] ?? ''),
                $this->positiveInteger($row['publication_version'] ?? null)
            );
        } catch (Throwable) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    public function advancePrivateLock(
        string $localizationPublicId,
        int $expectedLockVersion,
        string $actorPublicId
    ): bool {
        $this->assertTransaction();
        $localizationPublicId = $this->publicId($localizationPublicId);
        $actorPublicId = $this->publicId($actorPublicId);
        $this->lockVersion($expectedLockVersion);
        $statement = $this->prepare(
            'UPDATE ' . $this->localizations . ' SET lock_version = '
                . 'lock_version + 1, updated_by_user_public_id = :actor '
                . 'WHERE public_id = :localization AND lock_version = :lock '
                . "AND status = 'published'"
        );
        $this->execute($statement, [
            'actor' => $actorPublicId,
            'localization' => $localizationPublicId,
            'lock' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    public function storeDraftRevision(
        string $localizationPublicId,
        string $revisionPublicId,
        int $basePublicationVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        $localizationId = $this->localizationId($localizationPublicId);
        $revisionId = $this->revisionId($revisionPublicId, $localizationId);
        $actorPublicId = $this->publicId($actorPublicId);
        $this->publicationVersion($basePublicationVersion);
        $timestamp = self::format($now);
        $current = $this->workspace($localizationPublicId, true);
        if ($current === null) {
            $statement = $this->prepare(
                'INSERT INTO ' . $this->workspaces . ' '
                    . '(localization_id, draft_revision_id, '
                    . 'base_publication_version, '
                    . 'created_by_user_public_id, updated_by_user_public_id, '
                    . 'created_at, updated_at) VALUES (:localization, '
                    . ':revision, :base, :created_actor, :updated_actor, '
                    . ':created, :updated)'
            );
            $this->execute($statement, [
                'localization' => $localizationId,
                'revision' => $revisionId,
                'base' => $basePublicationVersion,
                'created_actor' => $actorPublicId,
                'updated_actor' => $actorPublicId,
                'created' => $timestamp,
                'updated' => $timestamp,
            ]);
        } else {
            $this->assertWorkspaceBase($current, $basePublicationVersion);
            $statement = $this->prepare(
                'UPDATE ' . $this->workspaces . ' SET draft_revision_id = '
                    . ':revision, updated_by_user_public_id = :actor, '
                    . 'updated_at = :updated WHERE localization_id = '
                    . ':localization AND base_publication_version = :base'
            );
            $this->execute($statement, [
                'revision' => $revisionId,
                'actor' => $actorPublicId,
                'updated' => $timestamp,
                'localization' => $localizationId,
                'base' => $basePublicationVersion,
            ]);
        }
        if ($statement->rowCount() !== 1) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    public function categoryWorkspaceVersion(
        string $postPublicId,
        bool $lock = false
    ): int {
        $postPublicId = $this->publicId($postPublicId);
        $this->assertReadableLock($lock);
        $row = $this->one(
            'SELECT w.workspace_version FROM ' . $this->posts . ' p LEFT '
                . 'JOIN ' . $this->categoryWorkspaces
                . ' w ON w.post_id = p.id WHERE p.public_id = :post'
                . ($lock ? $this->forUpdate() : ''),
            ['post' => $postPublicId]
        );
        if ($row === null) {
            throw new BlogEditorialWorkflowPersistenceException();
        }

        return ($row['workspace_version'] ?? null) === null
            ? 0
            : $this->positiveInteger($row['workspace_version']);
    }

    public function workspaceCategoryPublicIds(
        string $postPublicId
    ): ?array {
        $postPublicId = $this->publicId($postPublicId);
        if ($this->categoryWorkspaceVersion($postPublicId) === 0) {
            return null;
        }
        $statement = $this->prepare(
            'SELECT c.public_id FROM ' . $this->categoryWorkspaceItems
                . ' wi JOIN ' . $this->categoryWorkspaces
                . ' w ON w.post_id = wi.post_id JOIN ' . $this->posts
                . ' p ON p.id = w.post_id JOIN ' . $this->categories
                . ' c ON c.id = wi.category_id WHERE p.public_id = :post '
                . 'ORDER BY c.public_id'
        );
        $this->execute($statement, ['post' => $postPublicId]);
        $values = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = $this->publicId((string) ($row['public_id'] ?? ''));
            if (isset($values[$value])) {
                throw new BlogEditorialWorkflowPersistenceException();
            }
            $values[$value] = true;
        }

        return array_keys($values);
    }

    public function categoryHasWorkspaceReference(
        string $categoryPublicId,
        bool $lock = false
    ): bool {
        $categoryPublicId = $this->publicId($categoryPublicId);
        $this->assertReadableLock($lock);

        return $this->one(
            'SELECT c.public_id FROM ' . $this->categoryWorkspaceItems
                . ' wi JOIN ' . $this->categories
                . ' c ON c.id = wi.category_id WHERE c.public_id = '
                . ':category LIMIT 1' . ($lock ? $this->forUpdate() : ''),
            ['category' => $categoryPublicId]
        ) !== null;
    }

    public function liveCategoryPublicIds(string $postPublicId): array
    {
        $postPublicId = $this->publicId($postPublicId);
        $statement = $this->prepare(
            'SELECT c.public_id FROM ' . $this->postCategories
                . ' pc JOIN ' . $this->posts
                . ' p ON p.id = pc.post_id JOIN ' . $this->categories
                . ' c ON c.id = pc.category_id WHERE p.public_id = :post '
                . 'ORDER BY c.public_id'
        );
        $this->execute($statement, ['post' => $postPublicId]);
        $values = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = $this->publicId((string) ($row['public_id'] ?? ''));
            if (isset($values[$value])) {
                throw new BlogEditorialWorkflowPersistenceException();
            }
            $values[$value] = true;
        }

        return array_keys($values);
    }

    public function replaceWorkspaceCategories(
        string $postPublicId,
        array $categoryPublicIds,
        int $expectedWorkspaceVersion,
        int $baseAssignmentVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int {
        $this->assertTransaction();
        $postPublicId = $this->publicId($postPublicId);
        $categories = $this->categoryPublicIds($categoryPublicIds);
        $actorPublicId = $this->publicId($actorPublicId);
        $this->publicationVersion($expectedWorkspaceVersion);
        $this->publicationVersion($baseAssignmentVersion);
        $postId = $this->internalId(
            'SELECT id FROM ' . $this->posts . ' WHERE public_id = :post',
            ['post' => $postPublicId]
        );
        $currentVersion = $this->categoryWorkspaceVersion(
            $postPublicId,
            true
        );
        if ($currentVersion !== $expectedWorkspaceVersion) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
        $next = $currentVersion + 1;
        $timestamp = self::format($now);
        if ($currentVersion === 0) {
            $statement = $this->prepare(
                'INSERT INTO ' . $this->categoryWorkspaces . ' '
                    . '(post_id, base_assignment_version, workspace_version, '
                    . 'created_by_user_public_id, updated_by_user_public_id, '
                    . 'created_at, updated_at) VALUES (:post, :base, :version, '
                    . ':created_actor, :updated_actor, :created, :updated)'
            );
            $this->execute($statement, [
                'post' => $postId,
                'base' => $baseAssignmentVersion,
                'version' => $next,
                'created_actor' => $actorPublicId,
                'updated_actor' => $actorPublicId,
                'created' => $timestamp,
                'updated' => $timestamp,
            ]);
        } else {
            $statement = $this->prepare(
                'UPDATE ' . $this->categoryWorkspaces . ' SET '
                    . 'workspace_version = :next, '
                    . 'updated_by_user_public_id = :actor, '
                    . 'updated_at = :updated WHERE post_id = :post '
                    . 'AND workspace_version = :expected '
                    . 'AND base_assignment_version = :base'
            );
            $this->execute($statement, [
                'next' => $next,
                'actor' => $actorPublicId,
                'updated' => $timestamp,
                'post' => $postId,
                'expected' => $expectedWorkspaceVersion,
                'base' => $baseAssignmentVersion,
            ]);
        }
        if ($statement->rowCount() !== 1) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
        $delete = $this->prepare(
            'DELETE FROM ' . $this->categoryWorkspaceItems
                . ' WHERE post_id = :post'
        );
        $this->execute($delete, ['post' => $postId]);
        foreach ($categories as $categoryPublicId) {
            $insert = $this->prepare(
                'INSERT INTO ' . $this->categoryWorkspaceItems . ' '
                    . '(post_id, category_id, assigned_by_user_public_id, '
                    . 'created_at) SELECT :post, c.id, :actor, :created FROM '
                    . $this->categories
                    . ' c WHERE c.public_id = :category'
            );
            $this->execute($insert, [
                'post' => $postId,
                'actor' => $actorPublicId,
                'created' => $timestamp,
                'category' => $categoryPublicId,
            ]);
            if ($insert->rowCount() !== 1) {
                throw new BlogEditorialWorkflowPersistenceException();
            }
        }

        return $next;
    }

    public function clearCategoryWorkspace(string $postPublicId): void
    {
        $this->assertTransaction();
        $postId = $this->internalId(
            'SELECT id FROM ' . $this->posts . ' WHERE public_id = :post',
            ['post' => $this->publicId($postPublicId)]
        );
        $statement = $this->prepare(
            'DELETE FROM ' . $this->categoryWorkspaces
                . ' WHERE post_id = :post'
        );
        $this->execute($statement, ['post' => $postId]);
        if ($statement->rowCount() > 1) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    public function promoteCategoryAssignments(
        string $postPublicId,
        int $expectedAssignmentVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int {
        $this->assertTransaction();
        $postPublicId = $this->publicId($postPublicId);
        $actorPublicId = $this->publicId($actorPublicId);
        $this->publicationVersion($expectedAssignmentVersion);
        $postId = $this->internalId(
            'SELECT id FROM ' . $this->posts . ' WHERE public_id = :post',
            ['post' => $postPublicId]
        );
        $current = $this->categoryAssignmentVersion($postPublicId, true);
        if ($current !== $expectedAssignmentVersion) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
        $next = $expectedAssignmentVersion + 1;
        $timestamp = self::format($now);
        if ($current === 0) {
            $statement = $this->prepare(
                'INSERT INTO ' . $this->categoryAssignmentHeads . ' '
                    . '(post_id, assignment_version, '
                    . 'updated_by_user_public_id, updated_at) VALUES '
                    . '(:post, :version, :actor, :updated)'
            );
            $this->execute($statement, [
                'post' => $postId,
                'version' => $next,
                'actor' => $actorPublicId,
                'updated' => $timestamp,
            ]);
        } else {
            $statement = $this->prepare(
                'UPDATE ' . $this->categoryAssignmentHeads . ' SET '
                    . 'assignment_version = :next, '
                    . 'updated_by_user_public_id = :actor, '
                    . 'updated_at = :updated WHERE post_id = :post '
                    . 'AND assignment_version = :expected'
            );
            $this->execute($statement, [
                'next' => $next,
                'actor' => $actorPublicId,
                'updated' => $timestamp,
                'post' => $postId,
                'expected' => $expectedAssignmentVersion,
            ]);
        }
        if ($statement->rowCount() !== 1) {
            throw new BlogEditorialWorkflowPersistenceException();
        }

        return $next;
    }

    public function publishSnapshot(
        string $localizationPublicId,
        int $expectedLockVersion,
        string $expectedStatus,
        BlogDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool {
        $this->assertTransaction();
        $localizationPublicId = $this->publicId($localizationPublicId);
        $actorPublicId = $this->publicId($actorPublicId);
        $this->lockVersion($expectedLockVersion);
        if (!in_array($expectedStatus, [
            BlogPostVariant::DRAFT,
            BlogPostVariant::PUBLISHED,
        ], true) || !$draft->isPublishable()) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
        $statement = $this->prepare(
            'UPDATE ' . $this->localizations . ' SET slug = :slug, '
                . 'h1 = :h1, seo_title = :seo_title, '
                . 'meta_description = :meta_description, excerpt = :excerpt, '
                . 'body_text = :body_text, '
                . 'published_at = CASE WHEN status = :draft THEN '
                . ':published_at ELSE published_at END, '
                . 'status = :published, '
                . 'updated_by_user_public_id = :actor, '
                . 'updated_at = :updated, lock_version = lock_version + 1 '
                . 'WHERE public_id = :localization AND lock_version = :lock '
                . 'AND status = :expected_status'
        );
        $this->executeConflictAware($statement, [
            'slug' => $draft->slug(),
            'h1' => $draft->h1(),
            'seo_title' => $draft->seoTitle(),
            'meta_description' => $draft->metaDescription(),
            'excerpt' => $draft->excerpt(),
            'body_text' => $draft->bodyText(),
            'published' => BlogPostVariant::PUBLISHED,
            'draft' => BlogPostVariant::DRAFT,
            'published_at' => self::format($now),
            'actor' => $actorPublicId,
            'updated' => self::format($now),
            'localization' => $localizationPublicId,
            'lock' => $expectedLockVersion,
            'expected_status' => $expectedStatus,
        ]);

        $updated = $statement->rowCount() === 1;
        if ($updated) {
            $this->upsertRobotsSettings(
                $localizationPublicId,
                $draft->robotsPreferences()
            );
        }

        return $updated;
    }

    public function unpublishSnapshot(
        string $localizationPublicId,
        int $expectedLockVersion,
        BlogDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool {
        $this->assertTransaction();
        $localizationPublicId = $this->publicId($localizationPublicId);
        $actorPublicId = $this->publicId($actorPublicId);
        $this->lockVersion($expectedLockVersion);
        $statement = $this->prepare(
            'UPDATE ' . $this->localizations . ' SET slug = :slug, '
                . 'h1 = :h1, seo_title = :seo_title, '
                . 'meta_description = :meta_description, excerpt = :excerpt, '
                . 'body_text = :body_text, status = :draft, '
                . 'published_at = NULL, updated_by_user_public_id = :actor, '
                . 'updated_at = :updated, lock_version = lock_version + 1 '
                . 'WHERE public_id = :localization AND lock_version = :lock '
                . 'AND status = :published'
        );
        $this->executeConflictAware($statement, [
            'slug' => $draft->slug(),
            'h1' => $draft->h1(),
            'seo_title' => $draft->seoTitle(),
            'meta_description' => $draft->metaDescription(),
            'excerpt' => $draft->excerpt(),
            'body_text' => $draft->bodyText(),
            'draft' => BlogPostVariant::DRAFT,
            'actor' => $actorPublicId,
            'updated' => self::format($now),
            'localization' => $localizationPublicId,
            'lock' => $expectedLockVersion,
            'published' => BlogPostVariant::PUBLISHED,
        ]);

        $updated = $statement->rowCount() === 1;
        if ($updated) {
            $this->upsertRobotsSettings(
                $localizationPublicId,
                $draft->robotsPreferences()
            );
        }

        return $updated;
    }

    public function replaceLiveCategories(
        string $postPublicId,
        array $assignmentPublicIdsByCategory,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        $postPublicId = $this->publicId($postPublicId);
        $actorPublicId = $this->publicId($actorPublicId);
        if (count($assignmentPublicIdsByCategory) > 100) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
        $postId = $this->internalId(
            'SELECT id FROM ' . $this->posts . ' WHERE public_id = :public',
            ['public' => $postPublicId]
        );
        $delete = $this->prepare(
            'DELETE FROM ' . $this->postCategories . ' WHERE post_id = :post'
        );
        $this->execute($delete, ['post' => $postId]);
        $timestamp = self::format($now);
        foreach ($assignmentPublicIdsByCategory as $category => $assignment) {
            if (!is_string($category) || !is_string($assignment)) {
                throw new BlogEditorialWorkflowPersistenceException();
            }
            $category = $this->publicId($category);
            $assignment = $this->generatedPublicId($assignment);
            $statement = $this->prepare(
                'INSERT INTO ' . $this->postCategories . ' '
                    . '(public_id, post_id, category_id, '
                    . 'assigned_by_user_public_id, created_at, updated_at) '
                    . 'SELECT :assignment, :post, c.id, :actor, :created, '
                    . ':updated FROM ' . $this->categories
                    . ' c WHERE c.public_id = :category'
            );
            $this->execute($statement, [
                'assignment' => $assignment,
                'post' => $postId,
                'actor' => $actorPublicId,
                'created' => $timestamp,
                'updated' => $timestamp,
                'category' => $category,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new BlogEditorialWorkflowPersistenceException();
            }
        }
    }

    public function promotePublicationHead(
        string $localizationPublicId,
        string $revisionPublicId,
        int $expectedPublicationVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int {
        $this->assertTransaction();
        $localizationId = $this->localizationId($localizationPublicId);
        $revisionId = $this->revisionId($revisionPublicId, $localizationId);
        $actorPublicId = $this->publicId($actorPublicId);
        $this->publicationVersion($expectedPublicationVersion);
        $current = $this->publicationHead($localizationPublicId, true);
        $timestamp = self::format($now);
        if ($current === null) {
            if ($expectedPublicationVersion !== 0) {
                throw new BlogEditorialWorkflowPersistenceException();
            }
            $next = 1;
            $statement = $this->prepare(
                'INSERT INTO ' . $this->publicationHeads . ' '
                    . '(localization_id, revision_id, publication_version, '
                    . 'published_by_user_public_id, published_at) VALUES '
                    . '(:localization, :revision, :version, :actor, :published)'
            );
            $this->execute($statement, [
                'localization' => $localizationId,
                'revision' => $revisionId,
                'version' => $next,
                'actor' => $actorPublicId,
                'published' => $timestamp,
            ]);
        } else {
            if ($current->publicationVersion() !== $expectedPublicationVersion) {
                throw new BlogEditorialWorkflowPersistenceException();
            }
            $next = $expectedPublicationVersion + 1;
            $statement = $this->prepare(
                'UPDATE ' . $this->publicationHeads . ' SET revision_id = '
                    . ':revision, publication_version = :next, '
                    . 'published_by_user_public_id = :actor, '
                    . 'published_at = :published WHERE localization_id = '
                    . ':localization AND publication_version = :expected'
            );
            $this->execute($statement, [
                'revision' => $revisionId,
                'next' => $next,
                'actor' => $actorPublicId,
                'published' => $timestamp,
                'localization' => $localizationId,
                'expected' => $expectedPublicationVersion,
            ]);
        }
        if ($statement->rowCount() !== 1) {
            throw new BlogEditorialWorkflowPersistenceException();
        }

        return $next;
    }

    public function clearWorkspace(string $localizationPublicId): void
    {
        $this->assertTransaction();
        $localizationId = $this->localizationId($localizationPublicId);
        $statement = $this->prepare(
            'DELETE FROM ' . $this->workspaces
                . ' WHERE localization_id = :localization'
        );
        $this->execute($statement, ['localization' => $localizationId]);
        if ($statement->rowCount() > 1) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    public function retirePublicationState(string $localizationPublicId): void
    {
        $this->assertTransaction();
        $localizationId = $this->localizationId($localizationPublicId);
        $this->clearWorkspace($localizationPublicId);
        $statement = $this->prepare(
            'DELETE FROM ' . $this->publicationHeads
                . ' WHERE localization_id = :localization'
        );
        $this->execute($statement, ['localization' => $localizationId]);
        if ($statement->rowCount() > 1) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    private function localizationId(string $publicId): int
    {
        return $this->internalId(
            'SELECT id FROM ' . $this->localizations
                . ' WHERE public_id = :public',
            ['public' => $this->publicId($publicId)]
        );
    }

    private function revisionId(string $publicId, int $localizationId): int
    {
        return $this->internalId(
            'SELECT id FROM ' . $this->revisions
                . ' WHERE public_id = :public AND localization_id = '
                . ':localization',
            [
                'public' => $this->publicId($publicId),
                'localization' => $localizationId,
            ]
        );
    }

    /** @param array<string, mixed> $parameters */
    private function internalId(string $sql, array $parameters): int
    {
        $row = $this->one($sql, $parameters);
        if ($row === null || count($row) !== 1) {
            throw new BlogEditorialWorkflowPersistenceException();
        }

        return $this->positiveInteger(reset($row));
    }

    /** @param list<string> $values @return list<string> */
    private function categoryPublicIds(array $values): array
    {
        if (!array_is_list($values) || count($values) > 100) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new BlogEditorialWorkflowPersistenceException();
            }
            $value = $this->publicId($value);
            if (isset($result[$value])) {
                throw new BlogEditorialWorkflowPersistenceException();
            }
            $result[$value] = true;
        }

        return array_keys($result);
    }

    private function assertWorkspaceBase(
        BlogEditorialWorkspaceState $workspace,
        int $expected
    ): void {
        if ($workspace->basePublicationVersion() !== $expected) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    private function assertReadableLock(bool $lock): void
    {
        if ($lock) {
            $this->assertTransaction();
        }
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    private function forUpdate(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function upsertRobotsSettings(
        string $localizationPublicId,
        BlogRobotsPreferences $preferences
    ): void {
        if (!$this->robotsSettingsReady) {
            return;
        }
        $sql = 'INSERT INTO ' . $this->robotsSettings
            . ' (localization_id, allow_index, allow_follow, settings_sha256) '
            . 'SELECT l.id, :allow_index, :allow_follow, :settings_sha256 '
            . 'FROM ' . $this->localizations
            . ' l WHERE l.public_id = :localization_public_id ';
        $sql .= $this->driver === 'mysql'
            ? 'ON DUPLICATE KEY UPDATE allow_index = VALUES(allow_index), '
                . 'allow_follow = VALUES(allow_follow), '
                . 'settings_sha256 = VALUES(settings_sha256)'
            : 'ON CONFLICT(localization_id) DO UPDATE SET '
                . 'allow_index = excluded.allow_index, '
                . 'allow_follow = excluded.allow_follow, '
                . 'settings_sha256 = excluded.settings_sha256';
        $statement = $this->prepare($sql);
        $this->execute($statement, [
            'allow_index' => $preferences->index() ? 1 : 0,
            'allow_follow' => $preferences->follow() ? 1 : 0,
            'settings_sha256' => $preferences->integrityHash(),
            'localization_public_id' => $localizationPublicId,
        ]);
        $stored = $this->one(
            'SELECT r.allow_index, r.allow_follow, r.settings_sha256 FROM '
                . $this->robotsSettings . ' r JOIN ' . $this->localizations
                . ' l ON l.id = r.localization_id '
                . 'WHERE l.public_id = :localization_public_id',
            ['localization_public_id' => $localizationPublicId]
        );
        if (
            $stored === null
            || (int) ($stored['allow_index'] ?? -1)
                !== ($preferences->index() ? 1 : 0)
            || (int) ($stored['allow_follow'] ?? -1)
                !== ($preferences->follow() ? 1 : 0)
            || !is_string($stored['settings_sha256'] ?? null)
            || !hash_equals(
                $preferences->integrityHash(),
                (string) $stored['settings_sha256']
            )
        ) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
        } catch (Throwable) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
        if (!$statement instanceof PDOStatement) {
            throw new BlogEditorialWorkflowPersistenceException();
        }

        return $statement;
    }

    /** @param array<string, mixed> $parameters */
    private function execute(PDOStatement $statement, array $parameters = []): void
    {
        try {
            if (!$statement->execute($parameters)) {
                throw new BlogEditorialWorkflowPersistenceException();
            }
        } catch (BlogEditorialWorkflowPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    /** @param array<string, mixed> $parameters */
    private function executeConflictAware(
        PDOStatement $statement,
        array $parameters
    ): void {
        try {
            if (!$statement->execute($parameters)) {
                throw new BlogEditorialWorkflowPersistenceException();
            }
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                throw new BlogPersistenceConflict(BlogPersistenceConflict::SLUG);
            }
            throw new BlogEditorialWorkflowPersistenceException();
        } catch (BlogEditorialWorkflowPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @return null|array<string, mixed>
     */
    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->prepare($sql);
        $this->execute($statement, $parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row) || $statement->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new BlogEditorialWorkflowPersistenceException();
        }

        return $row;
    }

    private function publicId(string $value): string
    {
        try {
            return BlogInput::publicId($value);
        } catch (Throwable) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    private function generatedPublicId(string $value): string
    {
        try {
            return BlogInput::generatedPublicId($value);
        } catch (Throwable) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    private function locale(string $value): string
    {
        try {
            return BlogInput::locale($value);
        } catch (Throwable) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    private function lockVersion(int $value): void
    {
        try {
            BlogInput::lockVersion($value);
        } catch (Throwable) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    private function publicationVersion(int $value): void
    {
        if ($value < 0 || $value === PHP_INT_MAX) {
            throw new BlogEditorialWorkflowPersistenceException();
        }
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1
            && (string) (int) $value === $value) {
            return (int) $value;
        }
        throw new BlogEditorialWorkflowPersistenceException();
    }

    private function nonNegativeInteger(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value)
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
            && (string) (int) $value === $value) {
            return (int) $value;
        }
        throw new BlogEditorialWorkflowPersistenceException();
    }

    private function boolean(mixed $value): bool
    {
        return match ($value) {
            0, '0' => false,
            1, '1' => true,
            default => throw new BlogEditorialWorkflowPersistenceException(),
        };
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))
            ->format(self::UTC_FORMAT);
    }
}
