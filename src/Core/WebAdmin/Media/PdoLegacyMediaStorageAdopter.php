<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use PDO;
use PDOStatement;
use Throwable;

/** Explicit, DB-locked adoption path for storage written before the v1 marker. */
final class PdoLegacyMediaStorageAdopter
{
    private bool $sqliteTransactionActive = false;

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
                ))
                || ($tables->driver() === 'sqlite' && !in_array(
                    $pdo->query('PRAGMA foreign_keys')->fetchColumn(),
                    [1, '1'],
                    true
                ))) {
                throw new MediaException(
                    'webadmin.media.storage_adoption_database_failed'
                );
            }
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException(
                'webadmin.media.storage_adoption_database_failed'
            );
        }
    }

    public function adopt(
        PrivateMediaStorage $storage
    ): MediaStorageInitializationResult {
        $sqlite = $this->tables->driver() === 'sqlite';
        $started = false;

        try {
            if ($this->pdo->inTransaction() || $this->sqliteTransactionActive) {
                throw new MediaException(
                    'webadmin.media.storage_adoption_database_failed'
                );
            }
            if ($sqlite) {
                if ($this->pdo->exec('BEGIN IMMEDIATE') === false) {
                    throw new MediaException(
                        'webadmin.media.storage_adoption_database_failed'
                    );
                }
                $this->sqliteTransactionActive = true;
            } elseif (!$this->pdo->beginTransaction()) {
                throw new MediaException(
                    'webadmin.media.storage_adoption_database_failed'
                );
            }
            $started = true;

            $this->lockQuotaRow();
            $result = $storage->adoptLegacy($this->manifest());

            if ($sqlite) {
                if ($this->pdo->exec('COMMIT') === false) {
                    throw new MediaException(
                        'webadmin.media.storage_adoption_database_failed'
                    );
                }
                $this->sqliteTransactionActive = false;
            } elseif (!$this->pdo->commit()) {
                throw new MediaException(
                    'webadmin.media.storage_adoption_database_failed'
                );
            }
            $started = false;

            return $result;
        } catch (Throwable $exception) {
            $rollbackOk = $this->rollback($started, $sqlite);
            if (!$rollbackOk) {
                throw new MediaException(
                    'webadmin.media.storage_adoption_database_failed'
                );
            }
            if ($exception instanceof MediaException) {
                throw $exception;
            }
            throw new MediaException(
                'webadmin.media.storage_adoption_database_failed'
            );
        }
    }

    private function lockQuotaRow(): void
    {
        try {
            $statement = $this->prepare(
                'SELECT value_text FROM ' . $this->tables->table('state')
                . ' WHERE state_key = :state_key'
                . ($this->tables->driver() === 'mysql' ? ' FOR UPDATE' : '')
            );
            $statement->execute(['state_key' => 'media.quota_lock']);
            if ($statement->fetchColumn() !== 'v1'
                || $statement->fetchColumn() !== false) {
                throw new MediaException(
                    'webadmin.media.storage_adoption_database_failed'
                );
            }
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException(
                'webadmin.media.storage_adoption_database_failed'
            );
        }
    }

    private function manifest(): LegacyMediaStorageManifest
    {
        try {
            $statement = $this->prepare(
                'SELECT a.id AS asset_id, a.public_id, '
                . 'v.id AS variant_id, v.storage_key, v.width, v.height, '
                . 'v.bytes, v.sha256, v.mime FROM '
                . $this->tables->table('media_assets') . ' a LEFT JOIN '
                . $this->tables->table('media_variants')
                . ' v ON v.asset_id = a.id ORDER BY a.id, v.width, v.id'
            );
            $statement->execute();

            $variants = [];
            $seenAssets = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (!is_array($row)) {
                    $this->mismatch();
                }
                $assetId = $this->positiveInt($row['asset_id'] ?? null);
                $publicId = $row['public_id'] ?? null;
                if (!is_string($publicId)
                    || (isset($seenAssets[$assetId])
                        && $seenAssets[$assetId] !== $publicId)
                    || ($row['variant_id'] ?? null) === null
                    || ($row['mime'] ?? null) !== 'image/avif') {
                    $this->mismatch();
                }
                $seenAssets[$assetId] = $publicId;

                $storageKey = $row['storage_key'] ?? null;
                $sha256 = $row['sha256'] ?? null;
                if (!is_string($storageKey) || !is_string($sha256)) {
                    $this->mismatch();
                }
                try {
                    $variants[] = new LegacyMediaStorageVariant(
                        $publicId,
                        new MediaStoredVariant(
                            $storageKey,
                            $this->positiveInt($row['width'] ?? null),
                            $this->positiveInt($row['height'] ?? null),
                            $this->positiveInt($row['bytes'] ?? null),
                            $sha256
                        )
                    );
                } catch (Throwable) {
                    $this->mismatch();
                }
            }

            return new LegacyMediaStorageManifest($variants);
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException(
                'webadmin.media.storage_adoption_database_failed'
            );
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new MediaException(
                'webadmin.media.storage_adoption_database_failed'
            );
        }

        return $statement;
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && (string) (int) $value === $value) {
            return (int) $value;
        }

        $this->mismatch();
    }

    private function mismatch(): never
    {
        throw new MediaException(
            'webadmin.media.storage_adoption_mismatch'
        );
    }

    private function rollback(bool $started, bool $sqlite): bool
    {
        if (!$started) {
            return true;
        }
        try {
            if ($sqlite) {
                $ok = $this->pdo->exec('ROLLBACK') !== false;
                if ($ok) {
                    $this->sqliteTransactionActive = false;
                }

                return $ok;
            }
            if ($this->pdo->inTransaction()) {
                return $this->pdo->rollBack();
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'database' => '[redacted]',
            'tables' => '[redacted]',
        ];
    }
}
