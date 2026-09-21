<?php

declare(strict_types=1);

namespace App\Core\Commerce\Persistence;

use DateTimeImmutable;
use DateTimeZone;

final class PdoCommerceInquiryRateLimitRepository extends AbstractPdoCommerceRepository
{
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';

    private readonly string $rateLimits;

    public function __construct(\PDO $pdo, CommerceTableNames $tables)
    {
        parent::__construct($pdo, $tables);
        $this->rateLimits = $tables->table('inquiry_rate_limits');
    }

    public function consume(
        string $action,
        string $subjectHash,
        DateTimeImmutable $now,
        int $windowSeconds,
        int $maximumAttempts
    ): bool {
        if (
            preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $action) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $subjectHash) !== 1
            || $windowSeconds < 60
            || $windowSeconds > 86_400
            || $maximumAttempts < 1
            || $maximumAttempts > 1_000
        ) {
            throw new CommercePersistenceException();
        }

        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $timestamp = $now->format(self::UTC_FORMAT);
        $cutoff = $now->modify('-' . $windowSeconds . ' seconds')
            ->format(self::UTC_FORMAT);

        if ($this->driver === 'mysql') {
            $this->write(
                'INSERT INTO ' . $this->rateLimits . ' ('
                    . 'action, subject_hash, attempts, window_started_at, updated_at'
                    . ') VALUES (:action, :subject_hash, 1, :window_started_at, :updated_at) '
                    . 'ON DUPLICATE KEY UPDATE attempts = IF('
                    . 'window_started_at <= :cutoff_attempts, 1, IF('
                    . 'attempts >= 4294967294, 4294967295, attempts + 1)), '
                    . 'window_started_at = IF(window_started_at <= :cutoff_window, '
                    . 'VALUES(window_started_at), window_started_at), '
                    . 'updated_at = VALUES(updated_at)',
                [
                    'action' => $action,
                    'subject_hash' => $subjectHash,
                    'window_started_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'cutoff_attempts' => $cutoff,
                    'cutoff_window' => $cutoff,
                ]
            );
        } else {
            $this->write(
                'INSERT INTO ' . $this->rateLimits . ' ('
                    . 'action, subject_hash, attempts, window_started_at, updated_at'
                    . ') VALUES (:action, :subject_hash, 1, :window_started_at, :updated_at) '
                    . 'ON CONFLICT(action, subject_hash) DO UPDATE SET attempts = CASE '
                    . 'WHEN window_started_at <= :cutoff_attempts THEN 1 '
                    . 'WHEN attempts >= 2147483646 THEN 2147483647 '
                    . 'ELSE attempts + 1 END, window_started_at = CASE '
                    . 'WHEN window_started_at <= :cutoff_window '
                    . 'THEN excluded.window_started_at ELSE window_started_at END, '
                    . 'updated_at = excluded.updated_at',
                [
                    'action' => $action,
                    'subject_hash' => $subjectHash,
                    'window_started_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'cutoff_attempts' => $cutoff,
                    'cutoff_window' => $cutoff,
                ]
            );
        }

        $row = $this->one(
            'SELECT attempts FROM ' . $this->rateLimits
                . ' WHERE action = :action AND subject_hash = :subject_hash',
            ['action' => $action, 'subject_hash' => $subjectHash]
        );
        if ($row === null) {
            throw new CommercePersistenceException();
        }

        return $this->nonNegativeInt($row['attempts'] ?? null)
            <= $maximumAttempts;
    }
}
