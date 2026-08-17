<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Adoption;

use App\Core\Blog\BlogInput;
use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use PDO;
use PDOStatement;
use Throwable;

/** CLI actor gate for an explicit, active WebAdmin content editor. */
final class PdoBlogUnifiedTextAdoptionActorGate
{
    private const REQUIRED_CAPABILITIES = [
        'blog.articles.edit',
        'webadmin.media.view',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly WebAdminTableNames $tables,
        private readonly MigrationScope $blogScope
    ) {
        try {
            if (
                $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $tables->driver()
                || $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || $blogScope->moduleId() !== 'blog'
            ) {
                throw $this->failure('storage_unavailable');
            }
        } catch (BlogUnifiedTextAdoptionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure('storage_unavailable');
        }
    }

    public function assertEligible(string $actorPublicId): void
    {
        $this->eligibleUserId($actorPublicId, false);
    }

    public function authorize(
        PDO $transaction,
        string $actorPublicId,
        string $postPublicId,
        string $locale
    ): string {
        try {
            if ($transaction !== $this->pdo || !$transaction->inTransaction()) {
                throw $this->failure('actor_gate_invalid');
            }
            $this->eligibleUserId($actorPublicId, true);
            $this->assertActiveNonQaVariant($postPublicId, $locale);

            return BlogInput::publicId($actorPublicId);
        } catch (BlogUnifiedTextAdoptionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure('actor_gate_invalid');
        }
    }

    private function assertActiveNonQaVariant(
        string $postPublicId,
        string $locale
    ): void {
        $postPublicId = BlogInput::publicId($postPublicId);
        $locale = BlogInput::locale($locale);
        $post = $this->one(
            'SELECT id FROM ' . $this->blogTable('posts')
                . ' WHERE public_id = :post_public_id'
                . $this->forUpdate(),
            ['post_public_id' => $postPublicId]
        );
        if ($post === null) {
            throw $this->failure('variant_not_eligible');
        }
        $postId = $this->positiveInteger($post['id'] ?? null);
        $variant = $this->one(
            'SELECT localization.id FROM '
                . $this->blogTable('post_localizations') . ' localization '
                . 'WHERE localization.post_id = :post_id '
                . 'AND localization.locale = :locale '
                . 'AND NOT EXISTS (SELECT 1 FROM '
                . $this->blogTable('post_tombstones') . ' tombstone '
                . 'WHERE tombstone.post_localization_id = localization.id) '
                . 'AND NOT EXISTS (SELECT 1 FROM '
                . $this->blogTable('post_categories') . ' assignment JOIN '
                . $this->blogTable('categories') . ' category '
                . 'ON category.id = assignment.category_id '
                . 'WHERE assignment.post_id = localization.post_id '
                . 'AND category.public_id = :dummy_public_id)'
                . $this->forUpdate(),
            [
                'post_id' => $postId,
                'locale' => $locale,
                'dummy_public_id' =>
                    BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
            ]
        );
        if ($variant === null) {
            throw $this->failure('variant_not_eligible');
        }
        $this->positiveInteger($variant['id'] ?? null);
    }

    private function eligibleUserId(string $actorPublicId, bool $lock): int
    {
        try {
            $actorPublicId = BlogInput::publicId($actorPublicId);
            $row = $this->one(
                'SELECT id, status, activated_at, suspended_at FROM '
                    . $this->tables->table('users')
                    . ' WHERE public_id = :public_id'
                    . ($lock && $this->tables->driver() === 'mysql'
                        ? ' FOR UPDATE' : ''),
                ['public_id' => $actorPublicId]
            );
            if (
                $row === null
                || ($row['status'] ?? null) !== 'active'
                || !is_string($row['activated_at'] ?? null)
                || $row['activated_at'] === ''
                || ($row['suspended_at'] ?? null) !== null
            ) {
                throw $this->failure('actor_not_eligible');
            }
            $userId = $this->positiveInteger($row['id'] ?? null);
            foreach (self::REQUIRED_CAPABILITIES as $capability) {
                if (!$this->hasEffectiveCapability($userId, $capability)) {
                    throw $this->failure('actor_not_eligible');
                }
            }

            return $userId;
        } catch (BlogUnifiedTextAdoptionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure('actor_gate_invalid');
        }
    }

    private function hasEffectiveCapability(
        int $userId,
        string $capability
    ): bool {
        $statement = $this->prepare(
            'SELECT CASE WHEN EXISTS (SELECT 1 FROM '
                . $this->tables->table('user_roles') . ' ur JOIN '
                . $this->tables->table('role_capabilities') . ' rc '
                . 'ON rc.role_id = ur.role_id JOIN '
                . $this->tables->table('capabilities') . ' c '
                . 'ON c.id = rc.capability_id WHERE ur.user_id = :role_user '
                . 'AND c.code = :role_code) OR EXISTS (SELECT 1 FROM '
                . $this->tables->table('user_capabilities') . ' uc JOIN '
                . $this->tables->table('capabilities') . ' dc '
                . 'ON dc.id = uc.capability_id WHERE uc.user_id = '
                . ':direct_user AND dc.code = :direct_code) '
                . 'THEN 1 ELSE 0 END'
        );
        if (!$statement->execute([
            'role_user' => $userId,
            'role_code' => $capability,
            'direct_user' => $userId,
            'direct_code' => $capability,
        ])) {
            throw $this->failure('storage_unavailable');
        }

        return in_array($statement->fetchColumn(), [1, '1'], true);
    }

    /** @param array<string, int|string> $parameters */
    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->prepare($sql);
        if (!$statement->execute($parameters)) {
            throw $this->failure('storage_unavailable');
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row) || $statement->fetch(PDO::FETCH_ASSOC) !== false) {
            throw $this->failure('storage_unavailable');
        }

        return $row;
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw $this->failure('storage_unavailable');
        }

        return $statement;
    }

    private function blogTable(string $suffix): string
    {
        return $this->blogScope->quotedTable($suffix, $this->tables->driver());
    }

    private function forUpdate(): string
    {
        return $this->tables->driver() === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        throw $this->failure('storage_unavailable');
    }

    private function failure(string $suffix): BlogUnifiedTextAdoptionException
    {
        return new BlogUnifiedTextAdoptionException(
            'blog.unified_text_adoption.' . $suffix
        );
    }
}
