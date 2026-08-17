<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\Categories\BlogReservedCategoryPolicy;
use App\Core\Blog\Persistence\BlogPersistenceConflict;
use App\Core\Blog\Persistence\BlogPersistenceException;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use PDO;
use PDOException;
use Throwable;

final class PdoBlogUrlHistoryRepository implements
    BlogUrlHistoryRepositoryInterface
{
    private readonly string $driver;
    private readonly string $history;
    private readonly string $localizations;
    private readonly string $postCategories;
    private readonly string $categories;

    public function __construct(private readonly PDO $pdo, MigrationScope $scope)
    {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!is_string($driver) || !in_array($driver, ['mysql', 'sqlite'], true)) {
                throw new BlogPersistenceException();
            }
            $this->driver = $driver;
            $this->history = $scope->quotedTable('url_history', $driver);
            $this->localizations = $scope->quotedTable('post_localizations', $driver);
            $this->postCategories = $scope->quotedTable(
                'post_categories',
                $driver
            );
            $this->categories = $scope->quotedTable('categories', $driver);
        } catch (BlogPersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function resolve(string $locale, string $slug): ?BlogUrlResolution
    {
        try {
            $query = $this->pdo->prepare(
                'SELECT h.state, owner.locale AS owner_locale, '
                    . 'target.locale AS target_locale, '
                    . 'target.slug AS target_slug, target.status AS target_status, '
                    . 'EXISTS (SELECT 1 FROM ' . $this->postCategories
                    . ' target_pc JOIN ' . $this->categories
                    . ' target_category ON target_category.id = '
                    . 'target_pc.category_id WHERE target_pc.post_id = '
                    . 'target.post_id AND target_category.public_id = '
                    . ':dummy_category_public_id) AS target_internal '
                    . 'FROM ' . $this->history . ' h JOIN '
                    . $this->localizations . ' owner ON '
                    . 'owner.id = h.localization_id LEFT JOIN '
                    . $this->localizations . ' target ON '
                    . 'target.id = h.replacement_localization_id '
                    . 'WHERE h.locale = :locale AND h.slug = :slug LIMIT 1'
            );
            $query->execute([
                'locale' => $locale,
                'slug' => $slug,
                'dummy_category_public_id' =>
                    BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
            ]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            $state = (string) ($row['state'] ?? '');
            if (($row['owner_locale'] ?? null) !== $locale) {
                return new BlogUrlResolution(
                    BlogUrlResolution::TEMPORARY_NOT_FOUND
                );
            }
            if ($state === BlogUrlResolution::REDIRECT) {
                if (
                    ($row['target_status'] ?? null) !== 'published'
                    || ($row['target_locale'] ?? null) !== $locale
                    || !is_string($row['target_locale'] ?? null)
                    || !is_string($row['target_slug'] ?? null)
                    || (int) ($row['target_internal'] ?? 1) !== 0
                ) {
                    return new BlogUrlResolution(
                        BlogUrlResolution::TEMPORARY_NOT_FOUND
                    );
                }

                return new BlogUrlResolution(
                    BlogUrlResolution::REDIRECT,
                    $row['target_locale'],
                    $row['target_slug']
                );
            }

            return new BlogUrlResolution($state);
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function activate(
        string $localizationPublicId,
        string $locale,
        string $slug,
        DateTimeImmutable $now
    ): void {
        $this->writeState(
            $localizationPublicId,
            $locale,
            $slug,
            BlogUrlResolution::ACTIVE,
            null,
            $now,
            true
        );
    }

    public function markTemporary(
        string $localizationPublicId,
        string $locale,
        string $slug,
        DateTimeImmutable $now
    ): void {
        $this->writeState(
            $localizationPublicId,
            $locale,
            $slug,
            BlogUrlResolution::TEMPORARY_NOT_FOUND,
            null,
            $now
        );
    }

    public function owns(
        string $localizationPublicId,
        string $locale,
        string $slug
    ): bool {
        try {
            $query = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $this->history . ' h JOIN '
                    . $this->localizations . ' owner ON owner.id = h.localization_id '
                    . 'WHERE owner.public_id = :owner AND h.locale = :locale '
                    . 'AND h.slug = :slug AND h.state <> :active'
            );
            $query->execute([
                'owner' => $localizationPublicId,
                'locale' => $locale,
                'slug' => $slug,
                'active' => BlogUrlResolution::ACTIVE,
            ]);

            return (int) $query->fetchColumn() === 1;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function isRedirectTargetEligible(
        string $localizationPublicId
    ): bool {
        try {
            $query = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $this->localizations . ' l JOIN '
                    . $this->postCategories . ' pc ON pc.post_id = l.post_id '
                    . 'JOIN ' . $this->categories
                    . ' c ON c.id = pc.category_id '
                    . 'WHERE l.public_id = :localization '
                    . 'AND c.public_id = :dummy_category_public_id'
            );
            $query->execute([
                'localization' => $localizationPublicId,
                'dummy_category_public_id' =>
                    BlogReservedCategoryPolicy::DUMMY_CATEGORY_PUBLIC_ID,
            ]);

            return (int) $query->fetchColumn() === 0;
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    public function markGone(
        string $localizationPublicId,
        string $locale,
        string $slug,
        DateTimeImmutable $now
    ): void {
        $this->writeState($localizationPublicId, $locale, $slug, BlogUrlResolution::GONE, null, $now);
    }

    public function markRedirect(
        string $localizationPublicId,
        string $locale,
        string $slug,
        string $replacementLocalizationPublicId,
        DateTimeImmutable $now
    ): void {
        $this->writeState(
            $localizationPublicId,
            $locale,
            $slug,
            BlogUrlResolution::REDIRECT,
            $replacementLocalizationPublicId,
            $now
        );
    }

    private function writeState(
        string $ownerPublicId,
        string $locale,
        string $slug,
        string $state,
        ?string $replacementPublicId,
        DateTimeImmutable $now,
        bool $retirePreviousActive = false
    ): void {
        try {
            $ownerCheck = $this->pdo->prepare(
                'SELECT owner.public_id FROM ' . $this->history . ' h JOIN '
                    . $this->localizations . ' owner ON owner.id = h.localization_id '
                    . 'WHERE h.locale = :locale AND h.slug = :slug'
            );
            $ownerCheck->execute(['locale' => $locale, 'slug' => $slug]);
            $existingOwner = $ownerCheck->fetchColumn();
            if (
                is_string($existingOwner)
                && !hash_equals($ownerPublicId, $existingOwner)
            ) {
                throw new BlogPersistenceConflict(BlogPersistenceConflict::SLUG);
            }
            if ($retirePreviousActive) {
                $retire = $this->pdo->prepare(
                    'UPDATE ' . $this->history . ' SET state = :temporary, '
                        . 'replacement_localization_id = NULL, updated_at = :now '
                        . 'WHERE localization_id = (SELECT id FROM '
                        . $this->localizations . ' WHERE public_id = :owner) '
                        . 'AND state = :active AND (locale <> :locale OR slug <> :slug)'
                );
                $retire->execute([
                    'temporary' => BlogUrlResolution::TEMPORARY_NOT_FOUND,
                    'now' => $this->format($now),
                    'owner' => $ownerPublicId,
                    'active' => BlogUrlResolution::ACTIVE,
                    'locale' => $locale,
                    'slug' => $slug,
                ]);
            }
            $replacementExpression = $replacementPublicId === null
                ? 'NULL'
                : '(SELECT id FROM ' . $this->localizations
                    . ' WHERE public_id = :replacement)';
            if ($this->driver === 'mysql') {
                $sql = 'INSERT INTO ' . $this->history
                    . ' (localization_id, locale, slug, state, replacement_localization_id, created_at, updated_at) '
                    . 'SELECT id, :locale, :slug, :state, ' . $replacementExpression
                    . ', :created_at, :updated_at FROM ' . $this->localizations . ' WHERE public_id = :owner '
                    . 'ON DUPLICATE KEY UPDATE state = VALUES(state), '
                    . 'replacement_localization_id = VALUES(replacement_localization_id), '
                    . 'updated_at = VALUES(updated_at)';
            } else {
                $sql = 'INSERT INTO ' . $this->history
                    . ' (localization_id, locale, slug, state, replacement_localization_id, created_at, updated_at) '
                    . 'SELECT id, :locale, :slug, :state, ' . $replacementExpression
                    . ', :created_at, :updated_at FROM ' . $this->localizations . ' WHERE public_id = :owner '
                    . 'ON CONFLICT(locale, slug) DO UPDATE SET state = excluded.state, '
                    . 'replacement_localization_id = excluded.replacement_localization_id, '
                    . 'updated_at = excluded.updated_at '
                    . 'WHERE localization_id = excluded.localization_id';
            }
            $timestamp = $this->format($now);
            $params = [
                'locale' => $locale,
                'slug' => $slug,
                'state' => $state,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'owner' => $ownerPublicId,
            ];
            if ($replacementPublicId !== null) {
                $params['replacement'] = $replacementPublicId;
            }
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            if (
                $statement->rowCount() < 1
                && !$this->matchesState(
                    $ownerPublicId,
                    $locale,
                    $slug,
                    $state,
                    $replacementPublicId
                )
            ) {
                throw new BlogPersistenceConflict(BlogPersistenceConflict::SLUG);
            }
        } catch (BlogPersistenceConflict $exception) {
            throw $exception;
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                throw new BlogPersistenceConflict(BlogPersistenceConflict::SLUG);
            }
            throw new BlogPersistenceException();
        } catch (Throwable) {
            throw new BlogPersistenceException();
        }
    }

    private function matchesState(
        string $ownerPublicId,
        string $locale,
        string $slug,
        string $state,
        ?string $replacementPublicId
    ): bool {
        $query = $this->pdo->prepare(
            'SELECT owner.public_id, target.public_id AS replacement FROM '
                . $this->history . ' h JOIN ' . $this->localizations
                . ' owner ON owner.id = h.localization_id LEFT JOIN '
                . $this->localizations . ' target ON '
                . 'target.id = h.replacement_localization_id WHERE '
                . 'h.locale = :locale AND h.slug = :slug AND h.state = :state'
        );
        $query->execute(['locale' => $locale, 'slug' => $slug, 'state' => $state]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row)
            && is_string($row['public_id'] ?? null)
            && hash_equals($ownerPublicId, $row['public_id'])
            && ($replacementPublicId === null
                ? ($row['replacement'] ?? null) === null
                : is_string($row['replacement'] ?? null)
                    && hash_equals($replacementPublicId, $row['replacement']));
    }

    private function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }
}
