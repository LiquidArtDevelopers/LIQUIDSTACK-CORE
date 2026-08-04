<?php

declare(strict_types=1);

namespace App\Core\Blog\Audit;

use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Appends successful Blog mutations to the existing WebAdmin audit log.
 *
 * The adapter accepts only the exact shared PDO used to compose it and never
 * owns the transaction. Consequently an audit failure rolls the Blog write
 * back through the repository that invoked the port.
 */
final class WebAdminBlogMutationAuditAdapter implements
    BlogMutationAuditPortInterface
{
    private const EVENT_CODES = [
        BlogMutationAuditEvent::CREATE => 'blog.article.created',
        BlogMutationAuditEvent::ADD_LOCALE =>
            'blog.article.locale_added',
        BlogMutationAuditEvent::SAVE => 'blog.article.saved',
        BlogMutationAuditEvent::RESTORE => 'blog.article.restored',
        BlogMutationAuditEvent::PUBLISH => 'blog.article.published',
        BlogMutationAuditEvent::UNPUBLISH =>
            'blog.article.unpublished',
        BlogMutationAuditEvent::DUPLICATE => 'blog.article.duplicated',
        BlogMutationAuditEvent::TRASH => 'blog.article.trashed',
        BlogMutationAuditEvent::RESTORE_FROM_TRASH =>
            'blog.article.restored_from_trash',
    ];

    public function __construct(
        private readonly PDO $expectedPdo,
        private readonly WebAdminTableNames $tables,
        private readonly UuidGeneratorInterface $uuidGenerator =
            new RandomUuidV4Generator()
    ) {
        $this->assertSafeConnection();
    }

    public function record(PDO $pdo, BlogMutationAuditEvent $event): void
    {
        try {
            if ($pdo !== $this->expectedPdo || !$pdo->inTransaction()) {
                throw new BlogMutationAuditStorageException();
            }

            $actorUserId = $this->actorUserId(
                $event->actorPublicId()
            );
            $requestId = $this->uuid($this->uuidGenerator->generateV4());
            $eventCode = self::EVENT_CODES[$event->operation()] ?? null;
            if (!is_string($eventCode)) {
                throw new BlogMutationAuditStorageException();
            }

            // The module-neutral audit port carries the verified actor UUID,
            // not a browser-session identifier. Leaving the nullable session
            // column empty is preferable to guessing among active sessions.
            $statement = $this->prepare(
                'INSERT INTO ' . $this->tables->table('audit_log') . ' '
                . '(request_id, actor_user_id, actor_session_public_id, '
                . 'event_code, outcome, reason_code, target_type, '
                . 'target_public_id, metadata_json, ip_hash, '
                . 'user_agent_hash, occurred_at) VALUES '
                . '(:request_id, :actor_user_id, NULL, :event_code, '
                . "'success', NULL, 'blog_article', :target_public_id, "
                . 'NULL, NULL, NULL, :occurred_at)'
            );
            $statement->bindValue('request_id', $requestId);
            $statement->bindValue(
                'actor_user_id',
                $actorUserId,
                PDO::PARAM_INT
            );
            $statement->bindValue('event_code', $eventCode);
            $statement->bindValue(
                'target_public_id',
                $event->postPublicId()
            );
            $statement->bindValue(
                'occurred_at',
                self::format($event->occurredAt())
            );
            if (!$statement->execute() || $statement->rowCount() !== 1) {
                throw new BlogMutationAuditStorageException();
            }
        } catch (BlogMutationAuditStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogMutationAuditStorageException();
        }
    }

    private function actorUserId(string $publicId): int
    {
        $statement = $this->prepare(
            'SELECT id FROM ' . $this->tables->table('users')
            . ' WHERE public_id = :public_id'
        );
        if (!$statement->execute(['public_id' => $publicId])) {
            throw new BlogMutationAuditStorageException();
        }
        $value = $statement->fetchColumn();
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (string) (int) $value === $value
        ) {
            return (int) $value;
        }

        throw new BlogMutationAuditStorageException();
    }

    private function assertSafeConnection(): void
    {
        try {
            $driver = $this->expectedPdo->getAttribute(
                PDO::ATTR_DRIVER_NAME
            );
            if (
                $driver !== $this->tables->driver()
                || $this->expectedPdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || (
                    $driver === 'mysql'
                    && !in_array(
                        $this->expectedPdo->getAttribute(
                            PDO::ATTR_EMULATE_PREPARES
                        ),
                        [false, 0, '0'],
                        true
                    )
                )
            ) {
                throw new BlogMutationAuditStorageException();
            }
            if ($driver === 'sqlite') {
                $statement = $this->expectedPdo->query(
                    'PRAGMA foreign_keys'
                );
                if (
                    !$statement instanceof PDOStatement
                    || !in_array(
                        $statement->fetchColumn(),
                        [1, '1'],
                        true
                    )
                ) {
                    throw new BlogMutationAuditStorageException();
                }
            }
        } catch (BlogMutationAuditStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogMutationAuditStorageException();
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->expectedPdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new BlogMutationAuditStorageException();
        }

        return $statement;
    }

    private function uuid(mixed $value): string
    {
        if (
            !is_string($value)
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $value
            ) !== 1
        ) {
            throw new BlogMutationAuditStorageException();
        }

        return $value;
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }
}
