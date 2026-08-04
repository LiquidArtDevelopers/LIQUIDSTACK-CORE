<?php

declare(strict_types=1);

namespace App\Core\Blog\Analytics;

use App\Core\Blog\BlogInput;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

final class PdoBlogAnalyticsRepository implements
    BlogAnalyticsIngestionInterface,
    BlogAnalyticsReportInterface,
    BlogAnalyticsRetentionInterface
{
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';
    private const MAX_BATCH = 50;
    private const MAX_ENGAGEMENT_MILLISECONDS = 86_400_000;
    private const ENGAGEMENT_CLOCK_TOLERANCE_MILLISECONDS = 5000;

    private readonly string $driver;
    private readonly string $localizations;
    private readonly string $sessions;
    private readonly string $views;

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
            ) {
                throw new BlogAnalyticsPersistenceException();
            }
            if (
                $driver === 'mysql'
                && !in_array(
                    $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
                    [false, 0, '0'],
                    true
                )
            ) {
                throw new BlogAnalyticsPersistenceException();
            }
            if ($driver === 'sqlite') {
                $foreignKeys = $pdo->query('PRAGMA foreign_keys');
                if (
                    !$foreignKeys instanceof PDOStatement
                    || !in_array($foreignKeys->fetchColumn(), [1, '1'], true)
                ) {
                    throw new BlogAnalyticsPersistenceException();
                }
            }

            $this->driver = $driver;
            $this->localizations = $scope->quotedTable(
                'post_localizations',
                $driver
            );
            $this->sessions = $scope->quotedTable(
                'analytics_sessions',
                $driver
            );
            $this->views = $scope->quotedTable('analytics_views', $driver);
        } catch (BlogAnalyticsPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogAnalyticsPersistenceException();
        }
    }

    public function recordView(
        string $localizationPublicId,
        string $viewPublicId,
        string $visitorHash,
        string $sessionHash,
        DateTimeImmutable $occurredAt
    ): bool {
        $localizationPublicId = BlogInput::publicId($localizationPublicId);
        $viewPublicId = BlogInput::publicId($viewPublicId);
        $this->assertHash($visitorHash);
        $this->assertHash($sessionHash);
        $occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));

        return $this->transaction(function () use (
            $localizationPublicId,
            $viewPublicId,
            $visitorHash,
            $sessionHash,
            $occurredAt
        ): bool {
            $localization = $this->one(
                'SELECT id FROM ' . $this->localizations
                    . ' WHERE public_id = :public_id '
                    . "AND status = 'published' AND published_at IS NOT NULL",
                ['public_id' => $localizationPublicId]
            );
            if ($localization === null) {
                return false;
            }
            $localizationId = (int) ($localization['id'] ?? 0);
            if ($localizationId < 1) {
                throw new BlogAnalyticsPersistenceException();
            }

            $returning = $this->one(
                'SELECT id FROM ' . $this->sessions
                    . ' WHERE visitor_hash = :visitor_hash LIMIT 1',
                ['visitor_hash' => $visitorHash]
            ) !== null;
            $timestamp = self::format($occurredAt);
            $insertSession = $this->driver === 'mysql'
                ? 'INSERT IGNORE INTO ' . $this->sessions
                : 'INSERT INTO ' . $this->sessions;
            $insertSession .= ' (session_hash, visitor_hash, '
                . 'landing_localization_id, is_returning, pageview_count, '
                . 'engagement_msec, started_at, last_activity_at) VALUES '
                . '(:session_hash, :visitor_hash, :landing, :returning, 0, 0, '
                . ':started_at, :last_activity_at)';
            if ($this->driver === 'sqlite') {
                $insertSession .= ' ON CONFLICT(session_hash) DO NOTHING';
            }
            $statement = $this->prepare($insertSession);
            $this->execute($statement, [
                'session_hash' => $sessionHash,
                'visitor_hash' => $visitorHash,
                'landing' => $localizationId,
                'returning' => $returning ? 1 : 0,
                'started_at' => $timestamp,
                'last_activity_at' => $timestamp,
            ]);

            $session = $this->one(
                'SELECT id, visitor_hash FROM ' . $this->sessions
                    . ' WHERE session_hash = :session_hash'
                    . $this->forUpdate(),
                ['session_hash' => $sessionHash]
            );
            if (
                $session === null
                || !is_string($session['visitor_hash'] ?? null)
                || !hash_equals($visitorHash, $session['visitor_hash'])
            ) {
                throw new BlogAnalyticsPersistenceException();
            }
            $sessionId = (int) ($session['id'] ?? 0);
            if ($sessionId < 1) {
                throw new BlogAnalyticsPersistenceException();
            }

            $insertView = $this->driver === 'mysql'
                ? 'INSERT IGNORE INTO ' . $this->views
                : 'INSERT INTO ' . $this->views;
            $insertView .= ' (public_id, session_id, localization_id, '
                . 'engagement_msec, last_sequence, started_at, '
                . 'last_activity_at) VALUES (:public_id, :session_id, '
                . ':localization_id, 0, 0, :started_at, :last_activity_at)';
            if ($this->driver === 'sqlite') {
                $insertView .= ' ON CONFLICT(public_id) DO NOTHING';
            }
            $statement = $this->prepare($insertView);
            $this->execute($statement, [
                'public_id' => $viewPublicId,
                'session_id' => $sessionId,
                'localization_id' => $localizationId,
                'started_at' => $timestamp,
                'last_activity_at' => $timestamp,
            ]);
            $created = $statement->rowCount() === 1;

            $view = $this->one(
                'SELECT session_id, localization_id FROM ' . $this->views
                    . ' WHERE public_id = :public_id' . $this->forUpdate(),
                ['public_id' => $viewPublicId]
            );
            if (
                $view === null
                || (int) ($view['session_id'] ?? 0) !== $sessionId
                || (int) ($view['localization_id'] ?? 0) !== $localizationId
            ) {
                throw new BlogAnalyticsPersistenceException();
            }
            if ($created) {
                $update = $this->prepare(
                    'UPDATE ' . $this->sessions
                        . ' SET pageview_count = pageview_count + 1, '
                        . 'last_activity_at = :last_activity_at WHERE id = :id'
                );
                $this->execute($update, [
                    'last_activity_at' => $timestamp,
                    'id' => $sessionId,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new BlogAnalyticsPersistenceException();
                }
            }

            return $created;
        });
    }

    public function recordEngagement(
        string $viewPublicId,
        string $sessionHash,
        int $sequence,
        int $engagementMilliseconds,
        DateTimeImmutable $occurredAt
    ): bool {
        $viewPublicId = BlogInput::publicId($viewPublicId);
        $this->assertHash($sessionHash);
        if (
            $sequence < 1
            || $sequence > 1_000_000
            || $engagementMilliseconds < 0
            || $engagementMilliseconds > self::MAX_ENGAGEMENT_MILLISECONDS
        ) {
            throw new BlogAnalyticsPersistenceException();
        }
        $occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));

        return $this->transaction(function () use (
            $viewPublicId,
            $sessionHash,
            $sequence,
            $engagementMilliseconds,
            $occurredAt
        ): bool {
            $locator = $this->one(
                'SELECT v.id, v.session_id FROM ' . $this->views
                    . ' v JOIN ' . $this->sessions
                    . ' s ON s.id = v.session_id WHERE v.public_id = '
                    . ':public_id AND s.session_hash = :session_hash',
                [
                    'public_id' => $viewPublicId,
                    'session_hash' => $sessionHash,
                ]
            );
            if ($locator === null) {
                return false;
            }
            $sessionId = (int) ($locator['session_id'] ?? 0);
            $viewId = (int) ($locator['id'] ?? 0);
            if ($sessionId < 1 || $viewId < 1) {
                throw new BlogAnalyticsPersistenceException();
            }

            // Keep the same parent-to-child lock order as recordView():
            // analytics session first, then the individual page view.
            $sessionRow = $this->one(
                'SELECT id FROM ' . $this->sessions
                    . ' WHERE id = :session_id AND session_hash = '
                    . ':session_hash' . $this->forUpdate(),
                [
                    'session_id' => $sessionId,
                    'session_hash' => $sessionHash,
                ]
            );
            if ($sessionRow === null) {
                return false;
            }
            $row = $this->one(
                'SELECT id, session_id, engagement_msec, last_sequence, '
                    . 'started_at FROM ' . $this->views
                    . ' WHERE id = :view_id AND public_id = :public_id '
                    . 'AND session_id = :session_id' . $this->forUpdate(),
                [
                    'view_id' => $viewId,
                    'public_id' => $viewPublicId,
                    'session_id' => $sessionId,
                ]
            );
            if ($row === null) {
                return false;
            }
            $lastSequence = (int) ($row['last_sequence'] ?? -1);
            $current = (int) ($row['engagement_msec'] ?? -1);
            if (
                $lastSequence < 0
                || $current < 0
                || $sequence <= $lastSequence
                || $engagementMilliseconds < $current
            ) {
                return false;
            }
            $startedAt = self::parseTimestamp($row['started_at'] ?? null);
            $wallMilliseconds = max(
                0,
                ((int) $occurredAt->format('Uu')
                    - (int) $startedAt->format('Uu')) / 1000
            );
            $allowed = min(
                self::MAX_ENGAGEMENT_MILLISECONDS,
                (int) floor($wallMilliseconds)
                    + self::ENGAGEMENT_CLOCK_TOLERANCE_MILLISECONDS
            );
            $next = min($engagementMilliseconds, $allowed);
            if ($next < $current) {
                return false;
            }
            $delta = $next - $current;
            $timestamp = self::format($occurredAt);
            $view = $this->prepare(
                'UPDATE ' . $this->views . ' SET engagement_msec = :engaged, '
                    . 'last_sequence = :sequence, last_activity_at = '
                    . ':last_activity WHERE id = :id'
            );
            $this->execute($view, [
                'engaged' => $next,
                'sequence' => $sequence,
                'last_activity' => $timestamp,
                'id' => $viewId,
            ]);
            if ($view->rowCount() !== 1) {
                throw new BlogAnalyticsPersistenceException();
            }
            $session = $this->prepare(
                'UPDATE ' . $this->sessions . ' SET engagement_msec = '
                    . 'engagement_msec + :delta, last_activity_at = '
                    . ':last_activity WHERE id = :id'
            );
            $this->execute($session, [
                'delta' => $delta,
                'last_activity' => $timestamp,
                'id' => $sessionId,
            ]);
            if ($session->rowCount() !== 1) {
                throw new BlogAnalyticsPersistenceException();
            }

            return true;
        });
    }

    public function summariesForLocalizations(
        array $localizationPublicIds,
        DateTimeImmutable $fromInclusive,
        DateTimeImmutable $toExclusive
    ): array {
        if (
            !array_is_list($localizationPublicIds)
            || count($localizationPublicIds) > self::MAX_BATCH
        ) {
            throw new BlogAnalyticsPersistenceException();
        }
        $ids = [];
        foreach ($localizationPublicIds as $publicId) {
            if (!is_string($publicId)) {
                throw new BlogAnalyticsPersistenceException();
            }
            $publicId = BlogInput::publicId($publicId);
            $ids[$publicId] = true;
        }
        if ($ids === []) {
            return [];
        }
        $fromInclusive = $fromInclusive->setTimezone(new DateTimeZone('UTC'));
        $toExclusive = $toExclusive->setTimezone(new DateTimeZone('UTC'));
        if ($fromInclusive >= $toExclusive) {
            throw new BlogAnalyticsPersistenceException();
        }

        $parameters = [
            'from_time' => self::format($fromInclusive),
            'to_time' => self::format($toExclusive),
            'landing_from_time' => self::format($fromInclusive),
            'landing_to_time' => self::format($toExclusive),
            'engaged_from_time' => self::format($fromInclusive),
            'engaged_to_time' => self::format($toExclusive),
        ];
        $placeholders = [];
        foreach (array_keys($ids) as $position => $publicId) {
            $key = 'localization_' . $position;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $publicId;
        }
        $sql = 'SELECT l.public_id AS localization_public_id, '
            . 'COUNT(v.id) AS page_views, '
            . 'COUNT(DISTINCT s.visitor_hash) AS unique_visitors, '
            . 'COUNT(DISTINCT CASE WHEN s.is_returning = 1 THEN '
            . 's.visitor_hash END) AS returning_visitors, '
            . 'COALESCE(SUM(v.engagement_msec), 0) AS engagement_total, '
            . 'COUNT(DISTINCT CASE WHEN s.landing_localization_id = '
            . 'v.localization_id AND s.started_at >= :landing_from_time AND '
            . 's.started_at < :landing_to_time THEN s.id END) AS '
            . 'landing_sessions, '
            . 'COUNT(DISTINCT CASE WHEN s.landing_localization_id = '
            . 'v.localization_id AND s.started_at >= :engaged_from_time AND '
            . 's.started_at < :engaged_to_time AND (s.engagement_msec > '
            . '10000 OR '
            . 's.pageview_count >= 2) THEN s.id END) AS engaged_landings '
            . 'FROM ' . $this->localizations . ' l LEFT JOIN ' . $this->views
            . ' v ON v.localization_id = l.id AND v.started_at >= '
            . ':from_time AND v.started_at < :to_time LEFT JOIN '
            . $this->sessions . ' s ON s.id = v.session_id WHERE '
            . 'l.public_id IN (' . implode(', ', $placeholders) . ') '
            . 'GROUP BY l.id, l.public_id';
        try {
            $statement = $this->prepare($sql);
            $this->execute($statement, $parameters);
            $result = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $publicId = (string) ($row['localization_public_id'] ?? '');
                if (!isset($ids[$publicId])) {
                    throw new BlogAnalyticsPersistenceException();
                }
                $pageViews = (int) ($row['page_views'] ?? 0);
                $engagementTotal = (int) ($row['engagement_total'] ?? 0);
                $result[$publicId] = new BlogArticleAnalyticsSummary(
                    $publicId,
                    $pageViews,
                    (int) ($row['unique_visitors'] ?? 0),
                    (int) ($row['returning_visitors'] ?? 0),
                    $pageViews === 0
                        ? 0
                        : (int) floor($engagementTotal / $pageViews),
                    (int) ($row['landing_sessions'] ?? 0),
                    (int) ($row['engaged_landings'] ?? 0)
                );
            }
            foreach (array_keys($ids) as $publicId) {
                $result[$publicId] ??= new BlogArticleAnalyticsSummary(
                    $publicId,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0
                );
            }

            return $result;
        } catch (BlogAnalyticsPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogAnalyticsPersistenceException();
        }
    }

    public function purgeBefore(
        DateTimeImmutable $cutoffExclusive
    ): BlogAnalyticsPurgeResult {
        $cutoffExclusive = $cutoffExclusive->setTimezone(
            new DateTimeZone('UTC')
        );

        return $this->transaction(function () use (
            $cutoffExclusive
        ): BlogAnalyticsPurgeResult {
            $cutoff = self::format($cutoffExclusive);
            $sessionCount = $this->one(
                'SELECT COUNT(*) AS total FROM ' . $this->sessions . ' s '
                    . 'WHERE s.last_activity_at < :session_cutoff AND NOT '
                    . 'EXISTS (SELECT 1 FROM ' . $this->views . ' recent '
                    . 'WHERE recent.session_id = s.id AND '
                    . 'recent.last_activity_at >= :recent_view_cutoff)',
                [
                    'session_cutoff' => $cutoff,
                    'recent_view_cutoff' => $cutoff,
                ]
            );
            $deletedSessions = (int) ($sessionCount['total'] ?? -1);
            if ($deletedSessions < 0) {
                throw new BlogAnalyticsPersistenceException();
            }

            $cascadeViewCount = $this->one(
                'SELECT COUNT(*) AS total FROM ' . $this->views . ' v JOIN '
                    . $this->sessions . ' s ON s.id = v.session_id WHERE '
                    . 's.last_activity_at < :session_cutoff AND NOT EXISTS '
                    . '(SELECT 1 FROM ' . $this->views . ' recent WHERE '
                    . 'recent.session_id = s.id AND '
                    . 'recent.last_activity_at >= :recent_view_cutoff)',
                [
                    'session_cutoff' => $cutoff,
                    'recent_view_cutoff' => $cutoff,
                ]
            );
            $cascadedViews = (int) ($cascadeViewCount['total'] ?? -1);
            if ($cascadedViews < 0) {
                throw new BlogAnalyticsPersistenceException();
            }

            $deleteSessions = $this->prepare(
                'DELETE FROM ' . $this->sessions
                    . ' WHERE last_activity_at < :session_cutoff AND NOT '
                    . 'EXISTS (SELECT 1 FROM ' . $this->views . ' recent '
                    . 'WHERE recent.session_id = ' . $this->sessions
                    . '.id AND recent.last_activity_at >= '
                    . ':recent_view_cutoff)'
            );
            $this->execute($deleteSessions, [
                'session_cutoff' => $cutoff,
                'recent_view_cutoff' => $cutoff,
            ]);
            if ($deleteSessions->rowCount() !== $deletedSessions) {
                throw new BlogAnalyticsPersistenceException();
            }

            $viewCount = $this->one(
                'SELECT COUNT(*) AS total FROM ' . $this->views
                    . ' WHERE last_activity_at < :view_cutoff',
                ['view_cutoff' => $cutoff]
            );
            $directViews = (int) ($viewCount['total'] ?? -1);
            if ($directViews < 0) {
                throw new BlogAnalyticsPersistenceException();
            }
            $deleteViews = $this->prepare(
                'DELETE FROM ' . $this->views
                    . ' WHERE last_activity_at < :view_cutoff'
            );
            $this->execute($deleteViews, ['view_cutoff' => $cutoff]);
            if ($deleteViews->rowCount() !== $directViews) {
                throw new BlogAnalyticsPersistenceException();
            }
            $deletedViews = $cascadedViews + $directViews;

            return new BlogAnalyticsPurgeResult(
                $cutoffExclusive,
                $deletedSessions,
                $deletedViews
            );
        });
    }

    /** @template T @param callable(): T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new BlogAnalyticsPersistenceException();
        }
        $started = false;
        try {
            if (!$this->pdo->beginTransaction()) {
                throw new BlogAnalyticsPersistenceException();
            }
            $started = true;
            $result = $operation();
            if (!$this->pdo->commit()) {
                throw new BlogAnalyticsPersistenceException();
            }
            $started = false;

            return $result;
        } catch (Throwable $exception) {
            try {
                if ($started && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                    $started = false;
                }
            } catch (Throwable) {
                // Failure stays generic and the repository is not reused.
            }
            if ($exception instanceof BlogAnalyticsPersistenceException) {
                throw $exception;
            }
            throw new BlogAnalyticsPersistenceException();
        }
    }

    /** @param array<string, string|int> $parameters */
    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->prepare($sql);
        $this->execute($statement, $parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new BlogAnalyticsPersistenceException();
        }

        return $statement;
    }

    /** @param array<string, string|int> $parameters */
    private function execute(PDOStatement $statement, array $parameters = []): void
    {
        if (!$statement->execute($parameters)) {
            throw new BlogAnalyticsPersistenceException();
        }
    }

    private function forUpdate(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function assertHash(string $value): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new BlogAnalyticsPersistenceException();
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format(
            self::UTC_FORMAT
        );
    }

    private static function parseTimestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new BlogAnalyticsPersistenceException();
        }
        $date = DateTimeImmutable::createFromFormat(
            '!' . self::UTC_FORMAT,
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$date instanceof DateTimeImmutable
            || (is_array($errors)
                && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format(self::UTC_FORMAT) !== $value
        ) {
            throw new BlogAnalyticsPersistenceException();
        }

        return $date;
    }
}
