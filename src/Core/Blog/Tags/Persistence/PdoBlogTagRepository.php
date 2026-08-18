<?php

declare(strict_types=1);

namespace App\Core\Blog\Tags\Persistence;

use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\EditorialWorkflow\BlogEditorialVariantState;
use App\Core\Blog\Tags\BlogTag;
use App\Core\Blog\Tags\BlogTagAssignmentWorkspaceState;
use App\Core\Blog\Tags\BlogTagInput;
use App\Core\Blog\Tags\BlogTagService;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/** Portable PDO store for localized tags and their private assignment CAS. */
final class PdoBlogTagRepository implements BlogTagRepositoryInterface
{
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';
    private readonly string $driver;
    private readonly string $tags;
    private readonly string $live;
    private readonly string $heads;
    private readonly string $workspaces;
    private readonly string $workspaceItems;
    private readonly string $posts;
    private readonly string $localizations;
    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $scope
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
            ) {
                throw new BlogTagPersistenceException();
            }
            if ($driver === 'sqlite') {
                $foreignKeys = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$foreignKeys instanceof PDOStatement
                    || !in_array($foreignKeys->fetchColumn(), [1, '1'], true)
                ) {
                    throw new BlogTagPersistenceException();
                }
            }
            $this->driver = $driver;
            $this->tags = $scope->quotedTable('tags', $driver);
            $this->live = $scope->quotedTable('localization_tags', $driver);
            $this->heads = $scope->quotedTable(
                'tag_assignment_heads',
                $driver
            );
            $this->workspaces = $scope->quotedTable(
                'tag_assignment_workspaces',
                $driver
            );
            $this->workspaceItems = $scope->quotedTable(
                'tag_assignment_workspace_items',
                $driver
            );
            $this->posts = $scope->quotedTable('posts', $driver);
            $this->localizations = $scope->quotedTable(
                'post_localizations',
                $driver
            );
        } catch (BlogTagPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogTagPersistenceException();
        }
    }

    public function transactional(callable $operation): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            throw new BlogTagPersistenceException();
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->transactionOnce($operation);
            } catch (Throwable $exception) {
                if (
                    $attempt === 0
                    && !$this->transactionActive
                    && $this->isRetryableMySqlConflict($exception)
                ) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new BlogTagPersistenceException();
    }

    /** @template T @param callable(PDO): T $operation @return T */
    private function transactionOnce(callable $operation): mixed
    {
        $started = false;
        $this->transactionActive = true;
        try {
            if (!$this->pdo->beginTransaction()) {
                throw new BlogTagPersistenceException();
            }
            $started = true;
            if ($this->driver === 'sqlite') {
                $this->execute($this->prepare(
                    'UPDATE ' . $this->tags . ' SET id = id WHERE 1 = 0'
                ));
            }
            $result = $operation($this->pdo);
            if (!$this->pdo->commit()) {
                throw new BlogTagPersistenceException();
            }
            $started = false;
            $this->transactionActive = false;

            return $result;
        } catch (Throwable $exception) {
            try {
                if ($started) {
                    if (
                        $this->pdo->inTransaction()
                        && $this->pdo->rollBack()
                    ) {
                        $started = false;
                    } elseif (
                        $this->driver === 'mysql'
                        && !$this->pdo->inTransaction()
                    ) {
                        // InnoDB can already have rolled back a deadlock
                        // victim. The connection is reusable for one retry.
                        $started = false;
                    }
                }
            } catch (Throwable) {
                // Fail closed if rollback cannot be proven.
            }
            if (!$started) {
                $this->transactionActive = false;
            }
            if ($started) {
                throw new BlogTagPersistenceException();
            }
            throw $exception;
        }
    }

    public function variantState(
        string $postPublicId,
        string $locale,
        bool $lock = false
    ): ?BlogEditorialVariantState {
        $postPublicId = BlogTagInput::publicId($postPublicId);
        $locale = BlogTagInput::locale($locale);
        $this->assertReadableLock($lock);
        $row = $this->one(
            'SELECT p.public_id AS post_public_id, '
                . 'l.public_id AS localization_public_id, l.locale, '
                . 'l.status, l.lock_version FROM ' . $this->posts . ' p '
                . 'JOIN ' . $this->localizations
                . ' l ON l.post_id = p.id WHERE p.public_id = :post '
                . 'AND l.locale = :locale' . ($lock ? $this->forUpdate() : ''),
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
            throw new BlogTagPersistenceException();
        }
    }

    public function tagByIdentity(
        string $locale,
        string $normalizedSha256,
        bool $lock = false
    ): ?BlogTag {
        $locale = BlogTagInput::locale($locale);
        $normalizedSha256 = BlogTagInput::normalizedSha256(
            $normalizedSha256
        );
        $this->assertReadableLock($lock);
        $row = $this->one(
            'SELECT public_id, locale, slug, name, normalized_sha256 FROM '
                . $this->tags . ' WHERE locale = :locale '
                . 'AND normalized_sha256 = :normalized'
                . ($lock ? $this->forUpdate() : ''),
            ['locale' => $locale, 'normalized' => $normalizedSha256]
        );

        return $row === null ? null : $this->hydrateTag($row);
    }

    public function tagBySlug(
        string $locale,
        string $slug,
        bool $lock = false
    ): ?BlogTag {
        $locale = BlogTagInput::locale($locale);
        $slug = BlogTagInput::slug($slug);
        $this->assertReadableLock($lock);
        $row = $this->one(
            'SELECT public_id, locale, slug, name, normalized_sha256 FROM '
                . $this->tags . ' WHERE locale = :locale AND slug = :slug'
                . ($lock ? $this->forUpdate() : ''),
            ['locale' => $locale, 'slug' => $slug]
        );

        return $row === null ? null : $this->hydrateTag($row);
    }

    public function insertTag(
        string $publicId,
        string $locale,
        string $slug,
        string $name,
        string $normalizedSha256,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        $publicId = BlogTagInput::generatedPublicId($publicId);
        $locale = BlogTagInput::locale($locale);
        $slug = BlogTagInput::slug($slug);
        $name = BlogTagInput::name($name);
        $normalizedSha256 = BlogTagInput::normalizedSha256(
            $normalizedSha256
        );
        $actorPublicId = BlogTagInput::publicId($actorPublicId);
        $timestamp = self::format($now);
        $statement = $this->prepare(
            'INSERT INTO ' . $this->tags . ' (public_id, locale, slug, name, '
                . 'normalized_sha256, lock_version, '
                . 'created_by_user_public_id, updated_by_user_public_id, '
                . 'created_at, updated_at) VALUES (:public, :locale, :slug, '
                . ':name, :normalized, 1, :created_actor, :updated_actor, '
                . ':created, :updated)'
        );
        $this->executeConflictAware($statement, [
            'public' => $publicId,
            'locale' => $locale,
            'slug' => $slug,
            'name' => $name,
            'normalized' => $normalizedSha256,
            'created_actor' => $actorPublicId,
            'updated_actor' => $actorPublicId,
            'created' => $timestamp,
            'updated' => $timestamp,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new BlogTagPersistenceException();
        }
    }

    public function liveTags(string $localizationPublicId): array
    {
        return $this->assignedTags(
            $localizationPublicId,
            $this->live
        );
    }

    public function workspaceTags(string $localizationPublicId): ?array
    {
        $state = $this->workspaceState($localizationPublicId);
        if ($state === null) {
            return null;
        }

        return $this->assignedTags(
            $localizationPublicId,
            $this->workspaceItems
        );
    }

    public function assignmentVersion(
        string $localizationPublicId,
        bool $lock = false
    ): int {
        $localizationPublicId = BlogTagInput::publicId(
            $localizationPublicId
        );
        $this->assertReadableLock($lock);
        $row = $this->one(
            'SELECT h.assignment_version FROM ' . $this->localizations
                . ' l LEFT JOIN ' . $this->heads
                . ' h ON h.localization_id = l.id '
                . 'WHERE l.public_id = :localization'
                . ($lock ? $this->forUpdate() : ''),
            ['localization' => $localizationPublicId]
        );
        if ($row === null) {
            throw new BlogTagPersistenceException();
        }

        return ($row['assignment_version'] ?? null) === null
            ? 0
            : $this->positiveInteger($row['assignment_version']);
    }

    public function workspaceState(
        string $localizationPublicId,
        bool $lock = false
    ): ?BlogTagAssignmentWorkspaceState {
        $localizationPublicId = BlogTagInput::publicId(
            $localizationPublicId
        );
        $this->assertReadableLock($lock);
        $row = $this->one(
            'SELECT w.base_assignment_version, w.workspace_version FROM '
                . $this->localizations . ' l LEFT JOIN ' . $this->workspaces
                . ' w ON w.localization_id = l.id '
                . 'WHERE l.public_id = :localization'
                . ($lock ? $this->forUpdate() : ''),
            ['localization' => $localizationPublicId]
        );
        if ($row === null) {
            throw new BlogTagPersistenceException();
        }
        if (($row['workspace_version'] ?? null) === null) {
            return null;
        }

        try {
            return new BlogTagAssignmentWorkspaceState(
                $this->nonNegativeInteger(
                    $row['base_assignment_version'] ?? null
                ),
                $this->positiveInteger($row['workspace_version'])
            );
        } catch (Throwable) {
            throw new BlogTagPersistenceException();
        }
    }

    public function replaceWorkspace(
        string $localizationPublicId,
        array $tagPublicIds,
        int $expectedWorkspaceVersion,
        int $baseAssignmentVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int {
        $this->assertTransaction();
        $localizationPublicId = BlogTagInput::publicId(
            $localizationPublicId
        );
        $tags = $this->tagPublicIds($tagPublicIds);
        BlogTagInput::workspaceVersion($expectedWorkspaceVersion);
        BlogTagInput::workspaceVersion($baseAssignmentVersion);
        $actorPublicId = BlogTagInput::publicId($actorPublicId);
        [$localizationId, $locale] = $this->localizationIdentity(
            $localizationPublicId
        );
        $current = $this->workspaceState($localizationPublicId, true);
        $actual = $current?->workspaceVersion() ?? 0;
        if (
            $actual !== $expectedWorkspaceVersion
            || ($current !== null
                && $current->baseAssignmentVersion()
                    !== $baseAssignmentVersion)
        ) {
            throw new BlogTagPersistenceConflict();
        }
        $next = $actual + 1;
        $timestamp = self::format($now);
        if ($current === null) {
            $statement = $this->prepare(
                'INSERT INTO ' . $this->workspaces
                    . ' (localization_id, base_assignment_version, '
                    . 'workspace_version, created_by_user_public_id, '
                    . 'updated_by_user_public_id, created_at, updated_at) '
                    . 'VALUES (:localization, :base, :version, :created_actor, '
                    . ':updated_actor, :created, :updated)'
            );
            $this->execute($statement, [
                'localization' => $localizationId,
                'base' => $baseAssignmentVersion,
                'version' => $next,
                'created_actor' => $actorPublicId,
                'updated_actor' => $actorPublicId,
                'created' => $timestamp,
                'updated' => $timestamp,
            ]);
        } else {
            $statement = $this->prepare(
                'UPDATE ' . $this->workspaces . ' SET '
                    . 'workspace_version = :next, '
                    . 'updated_by_user_public_id = :actor, '
                    . 'updated_at = :updated WHERE localization_id = '
                    . ':localization AND workspace_version = :expected '
                    . 'AND base_assignment_version = :base'
            );
            $this->execute($statement, [
                'next' => $next,
                'actor' => $actorPublicId,
                'updated' => $timestamp,
                'localization' => $localizationId,
                'expected' => $expectedWorkspaceVersion,
                'base' => $baseAssignmentVersion,
            ]);
        }
        if ($statement->rowCount() !== 1) {
            throw new BlogTagPersistenceConflict();
        }
        $delete = $this->prepare(
            'DELETE FROM ' . $this->workspaceItems
                . ' WHERE localization_id = :localization'
        );
        $this->execute($delete, ['localization' => $localizationId]);
        foreach ($tags as $tag) {
            $insert = $this->prepare(
                'INSERT INTO ' . $this->workspaceItems
                    . ' (localization_id, tag_id, assigned_by_user_public_id, '
                    . 'created_at) SELECT :localization, t.id, :actor, '
                    . ':created FROM ' . $this->tags . ' t WHERE '
                    . 't.public_id = :tag AND t.locale = :locale'
            );
            $this->execute($insert, [
                'localization' => $localizationId,
                'actor' => $actorPublicId,
                'created' => $timestamp,
                'tag' => $tag,
                'locale' => $locale,
            ]);
            if ($insert->rowCount() !== 1) {
                throw new BlogTagPersistenceException();
            }
        }

        return $next;
    }

    public function clearWorkspace(string $localizationPublicId): void
    {
        $this->assertTransaction();
        [$localizationId] = $this->localizationIdentity(
            BlogTagInput::publicId($localizationPublicId)
        );
        $statement = $this->prepare(
            'DELETE FROM ' . $this->workspaces
                . ' WHERE localization_id = :localization'
        );
        $this->execute($statement, ['localization' => $localizationId]);
        if ($statement->rowCount() > 1) {
            throw new BlogTagPersistenceException();
        }
    }

    public function replaceLiveTags(
        string $localizationPublicId,
        array $tagPublicIds,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        $localizationPublicId = BlogTagInput::publicId(
            $localizationPublicId
        );
        $tags = $this->tagPublicIds($tagPublicIds);
        $actorPublicId = BlogTagInput::publicId($actorPublicId);
        [$localizationId, $locale] = $this->localizationIdentity(
            $localizationPublicId
        );
        $delete = $this->prepare(
            'DELETE FROM ' . $this->live
                . ' WHERE localization_id = :localization'
        );
        $this->execute($delete, ['localization' => $localizationId]);
        $timestamp = self::format($now);
        foreach ($tags as $tag) {
            $insert = $this->prepare(
                'INSERT INTO ' . $this->live
                    . ' (localization_id, tag_id, assigned_by_user_public_id, '
                    . 'created_at) SELECT :localization, t.id, :actor, '
                    . ':created FROM ' . $this->tags . ' t WHERE '
                    . 't.public_id = :tag AND t.locale = :locale'
            );
            $this->execute($insert, [
                'localization' => $localizationId,
                'actor' => $actorPublicId,
                'created' => $timestamp,
                'tag' => $tag,
                'locale' => $locale,
            ]);
            if ($insert->rowCount() !== 1) {
                throw new BlogTagPersistenceException();
            }
        }
    }

    public function promoteAssignments(
        string $localizationPublicId,
        int $expectedAssignmentVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int {
        $this->assertTransaction();
        $localizationPublicId = BlogTagInput::publicId(
            $localizationPublicId
        );
        BlogTagInput::workspaceVersion($expectedAssignmentVersion);
        $actorPublicId = BlogTagInput::publicId($actorPublicId);
        [$localizationId] = $this->localizationIdentity(
            $localizationPublicId
        );
        $current = $this->assignmentVersion($localizationPublicId, true);
        if ($current !== $expectedAssignmentVersion) {
            throw new BlogTagPersistenceConflict();
        }
        $next = $current + 1;
        $timestamp = self::format($now);
        if ($current === 0) {
            $statement = $this->prepare(
                'INSERT INTO ' . $this->heads . ' (localization_id, '
                    . 'assignment_version, updated_by_user_public_id, '
                    . 'updated_at) VALUES (:localization, :version, :actor, '
                    . ':updated)'
            );
            $this->execute($statement, [
                'localization' => $localizationId,
                'version' => $next,
                'actor' => $actorPublicId,
                'updated' => $timestamp,
            ]);
        } else {
            $statement = $this->prepare(
                'UPDATE ' . $this->heads . ' SET assignment_version = :next, '
                    . 'updated_by_user_public_id = :actor, '
                    . 'updated_at = :updated WHERE localization_id = '
                    . ':localization AND assignment_version = :expected'
            );
            $this->execute($statement, [
                'next' => $next,
                'actor' => $actorPublicId,
                'updated' => $timestamp,
                'localization' => $localizationId,
                'expected' => $expectedAssignmentVersion,
            ]);
        }
        if ($statement->rowCount() !== 1) {
            throw new BlogTagPersistenceConflict();
        }

        return $next;
    }

    /** @return list<BlogTag> */
    private function assignedTags(
        string $localizationPublicId,
        string $relation
    ): array {
        $localizationPublicId = BlogTagInput::publicId(
            $localizationPublicId
        );
        $statement = $this->prepare(
            'SELECT t.public_id, t.locale, t.slug, t.name, '
                . 't.normalized_sha256 FROM ' . $relation . ' r JOIN '
                . $this->localizations
                . ' l ON l.id = r.localization_id JOIN ' . $this->tags
                . ' t ON t.id = r.tag_id WHERE l.public_id = :localization '
                . 'AND t.locale = l.locale'
        );
        $this->execute($statement, ['localization' => $localizationPublicId]);
        $result = [];
        $seen = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tag = $this->hydrateTag($row);
            if (isset($seen[$tag->publicId()])) {
                throw new BlogTagPersistenceException();
            }
            $seen[$tag->publicId()] = true;
            $result[] = $tag;
        }
        if (count($result) > BlogTagService::MAX_TAGS_PER_VARIANT) {
            throw new BlogTagPersistenceException();
        }
        usort(
            $result,
            static fn (BlogTag $left, BlogTag $right): int =>
                strcmp($left->slug(), $right->slug())
        );

        return $result;
    }

    /** @return array{int, string} */
    private function localizationIdentity(string $publicId): array
    {
        $row = $this->one(
            'SELECT id, locale FROM ' . $this->localizations
                . ' WHERE public_id = :public',
            ['public' => $publicId]
        );
        if ($row === null) {
            throw new BlogTagPersistenceException();
        }

        return [
            $this->positiveInteger($row['id'] ?? null),
            BlogTagInput::locale((string) ($row['locale'] ?? '')),
        ];
    }

    /** @param list<string> $values @return list<string> */
    private function tagPublicIds(array $values): array
    {
        if (
            !array_is_list($values)
            || count($values) > BlogTagService::MAX_TAGS_PER_VARIANT
        ) {
            throw new BlogTagPersistenceException();
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new BlogTagPersistenceException();
            }
            try {
                $value = BlogTagInput::publicId($value);
            } catch (Throwable) {
                throw new BlogTagPersistenceException();
            }
            if (isset($result[$value])) {
                throw new BlogTagPersistenceException();
            }
            $result[$value] = true;
        }

        return array_keys($result);
    }

    /** @param array<string, mixed> $row */
    private function hydrateTag(array $row): BlogTag
    {
        try {
            return new BlogTag(
                (string) ($row['public_id'] ?? ''),
                (string) ($row['locale'] ?? ''),
                (string) ($row['slug'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['normalized_sha256'] ?? '')
            );
        } catch (Throwable) {
            throw new BlogTagPersistenceException();
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
            throw new BlogTagPersistenceException();
        }
    }

    private function forUpdate(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function positiveInteger(mixed $value): int
    {
        $integer = $this->nonNegativeInteger($value);
        if ($integer < 1) {
            throw new BlogTagPersistenceException();
        }
        return $integer;
    }

    private function nonNegativeInteger(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }
        throw new BlogTagPersistenceException();
    }

    /** @param array<string, mixed> $params */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->prepare($sql);
        $this->execute($statement, $params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new BlogTagPersistenceException();
        }
        return $row;
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new BlogTagPersistenceException();
        }
        return $statement;
    }

    /** @param array<string, mixed> $params */
    private function execute(PDOStatement $statement, array $params = []): void
    {
        try {
            if (!$statement->execute($params)) {
                throw new BlogTagPersistenceException();
            }
        } catch (BlogTagPersistenceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BlogTagPersistenceException('', 0, $exception);
        }
    }

    /** @param array<string, mixed> $params */
    private function executeConflictAware(
        PDOStatement $statement,
        array $params
    ): void {
        try {
            if (!$statement->execute($params)) {
                throw new BlogTagPersistenceException();
            }
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                throw new BlogTagPersistenceConflict();
            }
            throw new BlogTagPersistenceException('', 0, $exception);
        } catch (BlogTagPersistenceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BlogTagPersistenceException('', 0, $exception);
        }
    }

    private function isRetryableMySqlConflict(Throwable $exception): bool
    {
        if ($this->driver !== 'mysql') {
            return false;
        }
        for (
            $current = $exception;
            $current !== null;
            $current = $current->getPrevious()
        ) {
            if (!$current instanceof PDOException) {
                continue;
            }
            $driverCode = is_array($current->errorInfo ?? null)
                ? (int) ($current->errorInfo[1] ?? 0)
                : 0;
            if (
                in_array($driverCode, [1205, 1213], true)
                || in_array(
                    (string) $current->getCode(),
                    ['40001', '41000'],
                    true
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))
            ->format(self::UTC_FORMAT);
    }
}
