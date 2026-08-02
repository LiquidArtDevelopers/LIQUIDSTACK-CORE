<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories\Audit;

use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

final class WebAdminBlogCategoryAuditAdapter implements
    BlogCategoryAuditPortInterface
{
    /** @var array<string, array{code: string, type: string}> */
    private const EVENTS = [
        BlogCategoryAuditEvent::CREATE => [
            'code' => 'blog.category.created',
            'type' => 'blog_category',
        ],
        BlogCategoryAuditEvent::ADD_LOCALE => [
            'code' => 'blog.category.locale_added',
            'type' => 'blog_category',
        ],
        BlogCategoryAuditEvent::SAVE => [
            'code' => 'blog.category.saved',
            'type' => 'blog_category',
        ],
        BlogCategoryAuditEvent::ASSIGN => [
            'code' => 'blog.category.assignments_saved',
            'type' => 'blog_article',
        ],
    ];

    public function __construct(
        private readonly PDO $expectedPdo,
        private readonly WebAdminTableNames $tables,
        private readonly UuidGeneratorInterface $uuidGenerator =
            new RandomUuidV4Generator()
    ) {
    }

    public function record(PDO $pdo, BlogCategoryAuditEvent $event): void
    {
        try {
            if ($pdo !== $this->expectedPdo || !$pdo->inTransaction()) {
                throw new BlogCategoryAuditStorageException();
            }
            $definition = self::EVENTS[$event->operation()] ?? null;
            if ($definition === null) {
                throw new BlogCategoryAuditStorageException();
            }
            $actor = $this->prepare(
                'SELECT id FROM ' . $this->tables->table('users')
                . ' WHERE public_id = :public_id'
            );
            $actor->execute(['public_id' => $event->actorPublicId()]);
            $actorId = $actor->fetchColumn();
            if (!is_numeric($actorId) || (int) $actorId < 1) {
                throw new BlogCategoryAuditStorageException();
            }
            $requestId = $this->uuidGenerator->generateV4();
            if (!is_string($requestId)) {
                throw new BlogCategoryAuditStorageException();
            }

            $statement = $this->prepare(
                'INSERT INTO ' . $this->tables->table('audit_log') . ' '
                . '(request_id, actor_user_id, actor_session_public_id, '
                . 'event_code, outcome, reason_code, target_type, '
                . 'target_public_id, metadata_json, ip_hash, '
                . 'user_agent_hash, occurred_at) VALUES '
                . '(:request_id, :actor_user_id, NULL, :event_code, '
                . "'success', NULL, :target_type, :target_public_id, "
                . 'NULL, NULL, NULL, :occurred_at)'
            );
            $statement->execute([
                'request_id' => $requestId,
                'actor_user_id' => (int) $actorId,
                'event_code' => $definition['code'],
                'target_type' => $definition['type'],
                'target_public_id' => $event->targetPublicId(),
                'occurred_at' => $event->occurredAt()
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s.u'),
            ]);
            if ($statement->rowCount() !== 1) {
                throw new BlogCategoryAuditStorageException();
            }
        } catch (BlogCategoryAuditStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogCategoryAuditStorageException();
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->expectedPdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new BlogCategoryAuditStorageException();
        }

        return $statement;
    }
}
