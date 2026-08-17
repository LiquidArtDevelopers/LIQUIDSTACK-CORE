<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences;

use App\Core\Blog\BlogInput;
use App\Core\Blog\EditorPreferences\Audit\BlogEditorPreferencesAuditEvent;
use App\Core\Blog\EditorPreferences\Audit\BlogEditorPreferencesAuditPortInterface;
use App\Core\Blog\EditorPreferences\Persistence\BlogEditorPreferencesPersistenceConflict;
use App\Core\Blog\EditorPreferences\Persistence\BlogEditorPreferencesRepositoryInterface;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\SystemClock;
use DateTimeImmutable;
use PDO;
use Throwable;

/** Transactional application boundary for the global editor defaults. */
final class BlogEditorPreferencesService
{
    public function __construct(
        private readonly BlogEditorPreferencesRepositoryInterface $repository,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly ?BlogEditorPreferencesAuditPortInterface $auditPort =
            null
    ) {
    }

    /** Absence is intentional and projects to code-owned defaults. */
    public function current(): BlogEditorPreferencesState
    {
        try {
            return $this->repository->global()
                ?? BlogEditorPreferencesState::fallback(
                    BlogEditorPreferences::defaults()
                );
        } catch (Throwable) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::STORAGE_UNAVAILABLE
            );
        }
    }

    /**
     * The actor gate must revalidate BlogSettingsCapabilities::MANAGE on the
     * supplied PDO and return the authorized WebAdmin user public UUID.
     *
     * @param callable(PDO): string $actorGate
     */
    public function save(
        #[\SensitiveParameter] callable $actorGate,
        int $expectedLockVersion,
        BlogEditorPreferences $preferences
    ): BlogEditorPreferencesState {
        if ($expectedLockVersion < 0) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::INVALID_INPUT
            );
        }

        try {
            return $this->repository->transactional(
                function (PDO $pdo) use (
                    $actorGate,
                    $expectedLockVersion,
                    $preferences
                ): BlogEditorPreferencesState {
                    $actor = $this->authorizedActor($actorGate, $pdo);
                    $now = $this->now();
                    $current = $this->repository->lockGlobal();
                    if ($current === null) {
                        if ($expectedLockVersion !== 0) {
                            throw new BlogEditorPreferencesException(
                                BlogEditorPreferencesException::LOCK_CONFLICT
                            );
                        }
                        $this->repository->insertGlobal(
                            $preferences,
                            $actor,
                            $now
                        );
                        $stored = new BlogEditorPreferencesState(
                            $preferences,
                            1,
                            true,
                            $now
                        );
                    } else {
                        if (
                            $current->lockVersion()
                                !== $expectedLockVersion
                        ) {
                            throw new BlogEditorPreferencesException(
                                BlogEditorPreferencesException::LOCK_CONFLICT
                            );
                        }
                        if ($current->preferences()->equals($preferences)) {
                            return $current;
                        }
                        if (!$this->repository->updateGlobal(
                            $expectedLockVersion,
                            $preferences,
                            $actor,
                            $now
                        )) {
                            throw new BlogEditorPreferencesException(
                                BlogEditorPreferencesException::LOCK_CONFLICT
                            );
                        }
                        $stored = new BlogEditorPreferencesState(
                            $preferences,
                            $expectedLockVersion + 1,
                            true,
                            $now
                        );
                    }

                    $this->audit($pdo, $actor, $now);

                    return $stored;
                }
            );
        } catch (BlogEditorPreferencesException $exception) {
            throw $exception;
        } catch (BlogEditorPreferencesPersistenceConflict) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::LOCK_CONFLICT
            );
        } catch (Throwable) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::STORAGE_UNAVAILABLE
            );
        }
    }

    /** @param callable(PDO): string $actorGate */
    private function authorizedActor(callable $actorGate, PDO $pdo): string
    {
        try {
            $actor = $actorGate($pdo);
            if (!is_string($actor)) {
                throw new \RuntimeException('Invalid actor gate result.');
            }

            return BlogInput::publicId($actor);
        } catch (Throwable) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::ACTOR_GATE_FAILED
            );
        }
    }

    private function now(): DateTimeImmutable
    {
        try {
            return BlogInput::utc($this->clock->now());
        } catch (Throwable) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::STORAGE_UNAVAILABLE
            );
        }
    }

    private function audit(
        PDO $pdo,
        string $actorPublicId,
        DateTimeImmutable $occurredAt
    ): void {
        if ($this->auditPort === null) {
            return;
        }
        try {
            $this->auditPort->record(
                $pdo,
                new BlogEditorPreferencesAuditEvent(
                    $actorPublicId,
                    $occurredAt
                )
            );
        } catch (Throwable) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::STORAGE_UNAVAILABLE
            );
        }
    }
}
