<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences\Persistence;

use App\Core\Blog\EditorPreferences\BlogEditorPreferences;
use App\Core\Blog\EditorPreferences\BlogEditorPreferencesCodec;
use App\Core\Blog\EditorPreferences\BlogEditorPreferencesState;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final class PdoBlogEditorPreferencesRepository implements
    BlogEditorPreferencesRepositoryInterface
{
    private const SCOPE = 'global';
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';

    private readonly string $driver;
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $scope,
        private readonly BlogEditorPreferencesCodec $codec =
            new BlogEditorPreferencesCodec()
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (
                !is_string($driver)
                || !in_array($driver, ['mysql', 'sqlite'], true)
                || $scope->moduleId() !== 'blog'
                || $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || (
                    $driver === 'mysql'
                    && !in_array(
                        $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                        [false, 0, '0'],
                        true
                    )
                )
                || (
                    $driver === 'sqlite'
                    && !in_array(
                        $pdo->query('PRAGMA foreign_keys')->fetchColumn(),
                        [1, '1'],
                        true
                    )
                )
            ) {
                throw new BlogEditorPreferencesPersistenceException();
            }
            $this->driver = $driver;
            $this->table = $scope->quotedTable(
                'editor_preferences',
                $driver
            );
        } catch (BlogEditorPreferencesPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogEditorPreferencesPersistenceException();
        }
    }

    public function transactional(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new BlogEditorPreferencesPersistenceException();
        }
        $started = false;
        try {
            if (!$this->pdo->beginTransaction()) {
                throw new BlogEditorPreferencesPersistenceException();
            }
            $started = true;
            $result = $operation($this->pdo);
            if (!$this->pdo->inTransaction() || !$this->pdo->commit()) {
                throw new BlogEditorPreferencesPersistenceException();
            }
            $started = false;

            return $result;
        } catch (Throwable $exception) {
            $rollbackProven = !$started;
            try {
                if ($started && $this->pdo->inTransaction()) {
                    $rollbackProven = $this->pdo->rollBack();
                }
            } catch (Throwable) {
                // A rollback that cannot be proven always fails closed.
            }
            if (!$rollbackProven) {
                throw new BlogEditorPreferencesPersistenceException();
            }
            // The repository owns transaction mechanics, not application
            // semantics. Once rollback is proven, preserve callback domain
            // failures so the service can keep lock/auth errors distinct.
            throw $exception;
        }
    }

    public function global(): ?BlogEditorPreferencesState
    {
        return $this->read(false);
    }

    public function lockGlobal(): ?BlogEditorPreferencesState
    {
        $this->assertTransaction();
        if ($this->driver === 'sqlite') {
            $statement = $this->prepare(
                'UPDATE ' . $this->table
                    . ' SET lock_version = lock_version '
                    . 'WHERE scope_key = :scope'
            );
            $this->execute($statement, ['scope' => self::SCOPE]);
        }

        return $this->read(true);
    }

    public function insertGlobal(
        BlogEditorPreferences $preferences,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->assertTransaction();
        if (!self::isUuid($actorPublicId)) {
            throw new BlogEditorPreferencesPersistenceException();
        }
        $json = $this->codec->encode($preferences);
        $statement = $this->prepare(
            'INSERT INTO ' . $this->table . ' '
                . '(scope_key, schema_version, preferences_json, '
                . 'preferences_bytes, preferences_sha256, lock_version, '
                . 'updated_by_user_public_id, created_at, updated_at) VALUES '
                . '(:scope, 1, :json, :bytes, :sha256, 1, :actor, :created, '
                . ':updated)'
        );
        $this->executeConflictAware($statement, [
            'scope' => self::SCOPE,
            'json' => $json,
            'bytes' => strlen($json),
            'sha256' => hash('sha256', $json),
            'actor' => $actorPublicId,
            'created' => self::format($now),
            'updated' => self::format($now),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new BlogEditorPreferencesPersistenceException();
        }
    }

    public function updateGlobal(
        int $expectedLockVersion,
        BlogEditorPreferences $preferences,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool {
        $this->assertTransaction();
        if ($expectedLockVersion < 1 || $expectedLockVersion === PHP_INT_MAX) {
            throw new BlogEditorPreferencesPersistenceException();
        }
        if (!self::isUuid($actorPublicId)) {
            throw new BlogEditorPreferencesPersistenceException();
        }
        $json = $this->codec->encode($preferences);
        $statement = $this->prepare(
            'UPDATE ' . $this->table . ' SET preferences_json = :json, '
                . 'preferences_bytes = :bytes, preferences_sha256 = :sha256, '
                . 'lock_version = lock_version + 1, '
                . 'updated_by_user_public_id = :actor, updated_at = :updated '
                . 'WHERE scope_key = :scope AND lock_version = :lock_version'
        );
        $this->execute($statement, [
            'json' => $json,
            'bytes' => strlen($json),
            'sha256' => hash('sha256', $json),
            'actor' => $actorPublicId,
            'updated' => self::format($now),
            'scope' => self::SCOPE,
            'lock_version' => $expectedLockVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    private function read(bool $locked): ?BlogEditorPreferencesState
    {
        try {
            $statement = $this->prepare(
                'SELECT schema_version, preferences_json, preferences_bytes, '
                    . 'preferences_sha256, lock_version, '
                    . 'updated_by_user_public_id, updated_at FROM '
                    . $this->table . ' WHERE scope_key = :scope'
                    . ($locked && $this->driver === 'mysql'
                        ? ' FOR UPDATE' : '')
            );
            $this->execute($statement, ['scope' => self::SCOPE]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                return null;
            }
            if (count($rows) !== 1) {
                throw new BlogEditorPreferencesPersistenceException();
            }
            $row = $rows[0];
            $json = $row['preferences_json'] ?? null;
            $bytes = self::positiveInt($row['preferences_bytes'] ?? null);
            $lockVersion = self::positiveInt($row['lock_version'] ?? null);
            $sha256 = $row['preferences_sha256'] ?? null;
            $actor = $row['updated_by_user_public_id'] ?? null;
            if (
                self::positiveInt($row['schema_version'] ?? null) !== 1
                || !is_string($json)
                || strlen($json) !== $bytes
                || !is_string($sha256)
                || preg_match('/\A[0-9a-f]{64}\z/D', $sha256) !== 1
                || !hash_equals(hash('sha256', $json), $sha256)
                || !is_string($actor)
                || !self::isUuid($actor)
            ) {
                throw new BlogEditorPreferencesPersistenceException();
            }

            return new BlogEditorPreferencesState(
                $this->codec->decode($json),
                $lockVersion,
                true,
                self::timestamp($row['updated_at'] ?? null)
            );
        } catch (BlogEditorPreferencesPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogEditorPreferencesPersistenceException();
        }
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new BlogEditorPreferencesPersistenceException();
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new BlogEditorPreferencesPersistenceException();
        }

        return $statement;
    }

    /** @param array<string, mixed> $parameters */
    private function execute(
        PDOStatement $statement,
        array $parameters = []
    ): void {
        try {
            if (!$statement->execute($parameters)) {
                throw new BlogEditorPreferencesPersistenceException();
            }
        } catch (BlogEditorPreferencesPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogEditorPreferencesPersistenceException();
        }
    }

    /** @param array<string, mixed> $parameters */
    private function executeConflictAware(
        PDOStatement $statement,
        array $parameters
    ): void {
        try {
            if (!$statement->execute($parameters)) {
                throw new BlogEditorPreferencesPersistenceException();
            }
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                throw new BlogEditorPreferencesPersistenceConflict();
            }
            throw new BlogEditorPreferencesPersistenceException();
        } catch (BlogEditorPreferencesPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogEditorPreferencesPersistenceException();
        }
    }

    private static function positiveInt(mixed $value): int
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

        throw new BlogEditorPreferencesPersistenceException();
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $value
        ) === 1;
    }

    private static function timestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new BlogEditorPreferencesPersistenceException();
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new BlogEditorPreferencesPersistenceException();
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))
            ->format(self::UTC_FORMAT);
    }
}
