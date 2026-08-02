<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use App\Core\WebAdmin\Authorization\WebAdminAuthorizedActor;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

final class PdoMediaRepository implements MediaRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly WebAdminTableNames $tables
    ) {
        try {
            if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
                || ($tables->driver() === 'mysql' && !in_array(
                    $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                    [false, 0, '0'],
                    true
                ))) {
                throw new MediaException('webadmin.media.database_contract_invalid');
            }
            if ($tables->driver() === 'sqlite'
                && !in_array($pdo->query('PRAGMA foreign_keys')->fetchColumn(), [1, '1'], true)) {
                throw new MediaException('webadmin.media.database_contract_invalid');
            }
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException('webadmin.media.database_contract_invalid');
        }
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new MediaException('webadmin.media.transaction_already_active');
        }
        try {
            if (!$this->pdo->beginTransaction()) {
                throw new MediaException('webadmin.media.transaction_failed');
            }
            $result = $operation();
            if (!$this->pdo->inTransaction() || !$this->pdo->commit()) {
                throw new MediaException('webadmin.media.transaction_failed');
            }

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof MediaException) {
                throw $exception;
            }
            throw new MediaException('webadmin.media.storage_operation_failed');
        }
    }

    public function listPage(int $page, int $pageSize): MediaAssetPage
    {
        if ($page < 1 || $pageSize < 1 || $pageSize > 48) {
            throw new MediaException('webadmin.media.pagination_invalid');
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT a.public_id, a.label, a.source_width, '
                . 'a.source_height, a.created_at, MIN(v.width) AS thumbnail_width '
                . 'FROM ' . $this->tables->table('media_assets') . ' a '
                . 'JOIN ' . $this->tables->table('media_variants') . ' v '
                . 'ON v.asset_id = a.id GROUP BY a.id, a.public_id, a.label, '
                . 'a.source_width, a.source_height, a.created_at '
                . 'ORDER BY a.created_at DESC, a.id DESC LIMIT :limit OFFSET :offset'
            );
            $statement->bindValue('limit', $pageSize + 1, PDO::PARAM_INT);
            $statement->bindValue('offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $hasNext = count($rows) > $pageSize;
            $rows = array_slice($rows, 0, $pageSize);
            $items = [];
            foreach ($rows as $row) {
                $items[] = [
                    'public_id' => $this->uuid($row['public_id'] ?? null),
                    'label' => $this->label($row['label'] ?? null),
                    'source_width' => $this->positiveInt($row['source_width'] ?? null),
                    'source_height' => $this->positiveInt($row['source_height'] ?? null),
                    'created_at' => $this->timestamp($row['created_at'] ?? null)->format(DATE_ATOM),
                    'thumbnail_width' => $this->positiveInt($row['thumbnail_width'] ?? null),
                ];
            }

            return new MediaAssetPage($items, $page, $hasNext);
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException('webadmin.media.list_failed');
        }
    }

    public function findVariant(string $publicId, int $width): ?MediaStoredVariant
    {
        if (!$this->isUuid($publicId) || $width < 1 || $width > 2560) {
            return null;
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT v.storage_key, v.width, v.height, v.bytes, v.sha256 '
                . 'FROM ' . $this->tables->table('media_variants') . ' v '
                . 'JOIN ' . $this->tables->table('media_assets') . ' a '
                . 'ON a.id = v.asset_id WHERE a.public_id = :public_id '
                . 'AND v.width = :width AND v.mime = :mime'
            );
            $statement->bindValue('public_id', $publicId);
            $statement->bindValue('width', $width, PDO::PARAM_INT);
            $statement->bindValue('mime', 'image/avif');
            $statement->execute();
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }
            if (!is_array($row) || !is_string($row['storage_key'] ?? null)) {
                throw new MediaException('webadmin.media.variant_invalid');
            }

            return new MediaStoredVariant(
                $row['storage_key'],
                $this->positiveInt($row['width'] ?? null),
                $this->positiveInt($row['height'] ?? null),
                $this->positiveInt($row['bytes'] ?? null),
                is_string($row['sha256'] ?? null) ? $row['sha256'] : ''
            );
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException('webadmin.media.file_lookup_failed');
        }
    }

    public function totalVariantBytes(): int
    {
        try {
            $value = $this->pdo->query(
                'SELECT COALESCE(SUM(bytes), 0) FROM '
                . $this->tables->table('media_variants')
            )->fetchColumn();

            return $this->nonNegativeInt($value);
        } catch (Throwable) {
            throw new MediaException('webadmin.media.quota_probe_failed');
        }
    }

    public function lockQuota(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new MediaException('webadmin.media.quota_lock_invalid');
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT value_text FROM ' . $this->tables->table('state')
                . ' WHERE state_key = :state_key'
                . ($this->tables->driver() === 'mysql' ? ' FOR UPDATE' : '')
            );
            $statement->execute(['state_key' => 'media.quota_lock']);
            if ($statement->fetchColumn() !== 'v1') {
                throw new MediaException('webadmin.media.quota_lock_invalid');
            }
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException('webadmin.media.quota_lock_invalid');
        }
    }

    public function consumeRateLimit(
        string $action,
        string $subjectHash,
        DateTimeImmutable $now,
        int $windowSeconds,
        int $maximumAttempts
    ): bool {
        if (!$this->pdo->inTransaction()
            || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/', $action) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $subjectHash) !== 1
            || $windowSeconds < 1 || $maximumAttempts < 1) {
            throw new MediaException('webadmin.media.rate_limit_contract_invalid');
        }
        $select = $this->pdo->prepare(
            'SELECT window_started_at, attempts FROM '
            . $this->tables->table('rate_limits')
            . ' WHERE action = :action AND subject_hash = :subject_hash'
            . ($this->tables->driver() === 'mysql' ? ' FOR UPDATE' : '')
        );
        $select->execute(['action' => $action, 'subject_hash' => $subjectHash]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        $windowStart = $now;
        $attempts = 0;
        if (is_array($row)) {
            $storedStart = $this->timestamp($row['window_started_at'] ?? null);
            if ($storedStart->modify('+' . $windowSeconds . ' seconds') > $now) {
                $windowStart = $storedStart;
                $attempts = $this->nonNegativeInt($row['attempts'] ?? null);
            }
        } elseif ($row !== false) {
            throw new MediaException('webadmin.media.rate_limit_invalid');
        }
        if ($attempts >= $maximumAttempts) {
            return false;
        }
        ++$attempts;
        $sql = 'INSERT INTO ' . $this->tables->table('rate_limits')
            . ' (action, subject_hash, window_started_at, attempts, '
            . 'blocked_until, updated_at) VALUES (:action, :subject_hash, '
            . ':window_started_at, :attempts, NULL, :updated_at)';
        $sql .= $this->tables->driver() === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE window_started_at = VALUES(window_started_at), '
                . 'attempts = VALUES(attempts), blocked_until = NULL, '
                . 'updated_at = VALUES(updated_at)'
            : ' ON CONFLICT(action, subject_hash) DO UPDATE SET '
                . 'window_started_at = excluded.window_started_at, '
                . 'attempts = excluded.attempts, blocked_until = NULL, '
                . 'updated_at = excluded.updated_at';
        $write = $this->pdo->prepare($sql);
        $write->execute([
            'action' => $action,
            'subject_hash' => $subjectHash,
            'window_started_at' => self::format($windowStart),
            'attempts' => $attempts,
            'updated_at' => self::format($now),
        ]);

        return true;
    }

    public function insertAsset(
        string $publicId,
        string $label,
        ProcessedMediaUpload $processed,
        int $authorUserId,
        DateTimeImmutable $createdAt
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->tables->table('media_assets')
            . ' (public_id, label, source_mime, source_width, source_height, '
            . 'source_bytes, source_sha256, created_by_user_id, created_at) '
            . 'VALUES (:public_id, :label, :source_mime, :source_width, '
            . ':source_height, :source_bytes, :source_sha256, :author, :created_at)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'label' => $label,
            'source_mime' => $processed->sourceMime(),
            'source_width' => $processed->sourceWidth(),
            'source_height' => $processed->sourceHeight(),
            'source_bytes' => $processed->sourceBytes(),
            'source_sha256' => $processed->sourceSha256(),
            'author' => $authorUserId,
            'created_at' => self::format($createdAt),
        ]);
        $id = $this->pdo->lastInsertId();

        return $this->positiveInt($id);
    }

    public function insertVariant(
        int $assetId,
        ProcessedMediaVariant $variant,
        string $storageKey,
        DateTimeImmutable $createdAt
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->tables->table('media_variants')
            . ' (asset_id, width, height, bytes, sha256, storage_key, mime, created_at) '
            . 'VALUES (:asset_id, :width, :height, :bytes, :sha256, '
            . ':storage_key, :mime, :created_at)'
        );
        $statement->execute([
            'asset_id' => $assetId,
            'width' => $variant->width(),
            'height' => $variant->height(),
            'bytes' => $variant->bytes(),
            'sha256' => $variant->sha256(),
            'storage_key' => $storageKey,
            'mime' => 'image/avif',
            'created_at' => self::format($createdAt),
        ]);
    }

    public function auditCreated(
        WebAdminAuthorizedActor $actor,
        string $requestId,
        string $publicId,
        ?string $ipHash,
        DateTimeImmutable $occurredAt
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->tables->table('audit_log')
            . ' (request_id, actor_user_id, actor_session_public_id, '
            . 'event_code, outcome, reason_code, target_type, target_public_id, '
            . 'metadata_json, ip_hash, user_agent_hash, occurred_at) VALUES '
            . '(:request_id, :actor, :session_id, :event_code, :outcome, NULL, '
            . ':target_type, :target_id, NULL, :ip_hash, NULL, :occurred_at)'
        );
        $statement->execute([
            'request_id' => $requestId,
            'actor' => $actor->userId(),
            'session_id' => $actor->sessionPublicId(),
            'event_code' => 'webadmin.media.created',
            'outcome' => 'success',
            'target_type' => 'media_asset',
            'target_id' => $publicId,
            'ip_hash' => $ipHash,
            'occurred_at' => self::format($occurredAt),
        ]);
    }

    public function publicIds(int $limit): array
    {
        if ($limit < 1 || $limit > 10_000) {
            throw new MediaException('webadmin.media.public_id_limit_invalid');
        }
        $statement = $this->pdo->prepare(
            'SELECT public_id FROM ' . $this->tables->table('media_assets')
            . ' ORDER BY id LIMIT :limit'
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            fn (mixed $value): string => $this->uuid($value),
            $statement->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    private function label(mixed $value): string
    {
        if (!is_string($value) || trim($value) === ''
            || strlen($value) > 480 || preg_match('//u', $value) !== 1) {
            throw new MediaException('webadmin.media.label_invalid');
        }
        return $value;
    }

    private function uuid(mixed $value): string
    {
        if (!is_string($value) || !$this->isUuid($value)) {
            throw new MediaException('webadmin.media.public_id_invalid');
        }
        return $value;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $value
        ) === 1;
    }

    private function positiveInt(mixed $value): int
    {
        $int = $this->nonNegativeInt($value);
        if ($int < 1) {
            throw new MediaException('webadmin.media.integer_invalid');
        }
        return $int;
    }

    private function nonNegativeInt(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1
            && (string) (int) $value === $value) {
            return (int) $value;
        }
        throw new MediaException('webadmin.media.integer_invalid');
    }

    private function timestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new MediaException('webadmin.media.timestamp_invalid');
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new MediaException('webadmin.media.timestamp_invalid');
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }
}
