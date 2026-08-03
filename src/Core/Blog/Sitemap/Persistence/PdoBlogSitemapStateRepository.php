<?php

declare(strict_types=1);

namespace App\Core\Blog\Sitemap\Persistence;

use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

final class PdoBlogSitemapStateRepository implements
    BlogSitemapStateRepositoryInterface
{
    private readonly string $driver;
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $scope
    ) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!is_string($driver)
                || !in_array($driver, ['mysql', 'sqlite'], true)
                || $scope->moduleId() !== 'blog'
                || $pdo->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION) {
                throw new BlogSitemapStateException();
            }
            $this->driver = $driver;
            $this->table = $scope->quotedTable('sitemap_state', $driver);
        } catch (BlogSitemapStateException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogSitemapStateException();
        }
    }

    public function current(): BlogSitemapState
    {
        return $this->read(false);
    }

    public function lock(): BlogSitemapState
    {
        $this->assertTransaction();
        if ($this->driver === 'sqlite') {
            $statement = $this->pdo->prepare(
                'UPDATE ' . $this->table
                . ' SET public_revision = public_revision '
                . "WHERE state_key = 'sitemap'"
            );
            if (!$statement->execute() || $statement->rowCount() !== 1) {
                throw new BlogSitemapStateException();
            }
        }

        return $this->read(true);
    }

    public function incrementRevision(
        int $expectedRevision,
        DateTimeImmutable $now
    ): BlogSitemapState {
        $this->assertTransaction();
        if ($expectedRevision < 1 || $expectedRevision === PHP_INT_MAX) {
            throw new BlogSitemapStateException();
        }
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table
            . ' SET public_revision = public_revision + 1, updated_at = :now '
            . "WHERE state_key = 'sitemap' AND public_revision = :revision"
        );
        if (!$statement->execute([
            'now' => self::format($now),
            'revision' => $expectedRevision,
        ]) || $statement->rowCount() !== 1) {
            throw new BlogSitemapStateException();
        }

        return new BlogSitemapState($expectedRevision + 1, $this->generation());
    }

    public function activateGeneration(
        string $generation,
        DateTimeImmutable $now
    ): BlogSitemapState {
        $this->assertTransaction();
        // Validate through the value object before binding the generation.
        new BlogSitemapState(1, $generation);
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table
            . ' SET cache_generation = :generation, updated_at = :now '
            . "WHERE state_key = 'sitemap' "
            . 'AND (cache_generation IS NULL OR cache_generation = :same)'
        );
        if (!$statement->execute([
            'generation' => $generation,
            'same' => $generation,
            'now' => self::format($now),
        ]) || $statement->rowCount() > 1) {
            throw new BlogSitemapStateException();
        }

        $state = $this->read(true);
        if (!hash_equals($generation, (string) $state->cacheGeneration())) {
            throw new BlogSitemapStateException();
        }

        return $state;
    }

    private function read(bool $locked): BlogSitemapState
    {
        try {
            $statement = $this->pdo->query(
                'SELECT public_revision, cache_generation FROM '
                . $this->table . " WHERE state_key = 'sitemap'"
                . ($locked && $this->driver === 'mysql' ? ' FOR UPDATE' : '')
            );
            if (!$statement instanceof PDOStatement) {
                throw new BlogSitemapStateException();
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                throw new BlogSitemapStateException();
            }
            $revision = $rows[0]['public_revision'] ?? null;
            if (is_string($revision)
                && preg_match('/\A[1-9][0-9]*\z/', $revision) === 1) {
                $revision = (int) $revision;
            }
            $generation = $rows[0]['cache_generation'] ?? null;
            if (!is_int($revision)
                || ($generation !== null && !is_string($generation))) {
                throw new BlogSitemapStateException();
            }

            return new BlogSitemapState($revision, $generation);
        } catch (BlogSitemapStateException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogSitemapStateException();
        }
    }

    private function generation(): ?string
    {
        return $this->read(true)->cacheGeneration();
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new BlogSitemapStateException();
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }
}
