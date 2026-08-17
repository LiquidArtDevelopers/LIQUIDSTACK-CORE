<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences\Audit;

use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

/** Atomically records the global settings mutation in WebAdmin audit_log. */
final class WebAdminBlogEditorPreferencesAuditAdapter implements
    BlogEditorPreferencesAuditPortInterface
{
    public function __construct(
        private readonly PDO $expectedPdo,
        private readonly WebAdminTableNames $tables,
        private readonly UuidGeneratorInterface $uuidGenerator =
            new RandomUuidV4Generator()
    ) {
        $this->assertSafeConnection();
    }

    public function record(
        PDO $pdo,
        BlogEditorPreferencesAuditEvent $event
    ): void {
        try {
            if ($pdo !== $this->expectedPdo || !$pdo->inTransaction()) {
                throw new BlogEditorPreferencesAuditStorageException();
            }
            $actorId = $this->actorId($event->actorPublicId());
            $requestId = $this->uuid($this->uuidGenerator->generateV4());
            $statement = $this->prepare(
                'INSERT INTO ' . $this->tables->table('audit_log') . ' '
                    . '(request_id, actor_user_id, actor_session_public_id, '
                    . 'event_code, outcome, reason_code, target_type, '
                    . 'target_public_id, metadata_json, ip_hash, '
                    . 'user_agent_hash, occurred_at) VALUES '
                    . '(:request_id, :actor_id, NULL, '
                    . "'blog.settings.updated', 'success', NULL, "
                    . "'blog_editor_preferences', NULL, NULL, NULL, NULL, "
                    . ':occurred_at)'
            );
            if (!$statement->execute([
                'request_id' => $requestId,
                'actor_id' => $actorId,
                'occurred_at' => self::format($event->occurredAt()),
            ]) || $statement->rowCount() !== 1) {
                throw new BlogEditorPreferencesAuditStorageException();
            }
        } catch (BlogEditorPreferencesAuditStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogEditorPreferencesAuditStorageException();
        }
    }

    private function actorId(string $publicId): int
    {
        $statement = $this->prepare(
            'SELECT id FROM ' . $this->tables->table('users')
                . ' WHERE public_id = :public_id'
        );
        if (!$statement->execute(['public_id' => $publicId])) {
            throw new BlogEditorPreferencesAuditStorageException();
        }
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) !== 1) {
            throw new BlogEditorPreferencesAuditStorageException();
        }

        return $this->positiveInt($rows[0]);
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
                throw new BlogEditorPreferencesAuditStorageException();
            }
            if (
                $driver === 'sqlite'
                && !in_array(
                    $this->expectedPdo->query(
                        'PRAGMA foreign_keys'
                    )->fetchColumn(),
                    [1, '1'],
                    true
                )
            ) {
                throw new BlogEditorPreferencesAuditStorageException();
            }
        } catch (BlogEditorPreferencesAuditStorageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogEditorPreferencesAuditStorageException();
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->expectedPdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new BlogEditorPreferencesAuditStorageException();
        }

        return $statement;
    }

    private function uuid(mixed $value): string
    {
        if (
            !is_string($value)
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $value
            ) !== 1
        ) {
            throw new BlogEditorPreferencesAuditStorageException();
        }

        return $value;
    }

    private function positiveInt(mixed $value): int
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

        throw new BlogEditorPreferencesAuditStorageException();
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }
}
