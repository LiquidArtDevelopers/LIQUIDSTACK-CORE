<?php

declare(strict_types=1);

namespace App\Core\Blog\Categories\Persistence;

use App\Core\Blog\Categories\BlogCategoryDraft;
use App\Core\Blog\Categories\BlogCategoryLocalization;
use App\Core\Blog\Categories\PublishedCategoryFilter;
use App\Core\Blog\PublishedPostCard;
use DateTimeImmutable;
use PDO;

interface BlogCategoryRepositoryInterface
{
    public const MAX_PUBLIC_FILTERS = 100;
    public const PUBLIC_FILTER_OVERFLOW_QUERY_LIMIT =
        self::MAX_PUBLIC_FILTERS + 1;

    /** @template T @param callable(PDO): T $operation @return T */
    public function transactional(callable $operation): mixed;

    public function insertCategory(
        string $categoryPublicId,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;

    public function lockCategory(string $categoryPublicId): bool;

    public function insertLocalization(
        string $localizationPublicId,
        string $categoryPublicId,
        string $locale,
        BlogCategoryDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;

    public function lockLocalization(
        string $categoryPublicId,
        string $locale
    ): ?BlogCategoryLocalization;

    public function slugExists(
        string $locale,
        string $slug,
        ?string $exceptLocalizationPublicId = null
    ): bool;

    public function updateLocalization(
        string $localizationPublicId,
        int $expectedLockVersion,
        BlogCategoryDraft $draft,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool;

    public function touchCategory(
        string $categoryPublicId,
        DateTimeImmutable $now
    ): void;

    public function category(
        string $categoryPublicId,
        string $locale
    ): ?BlogCategoryLocalization;

    /** @return list<BlogCategoryLocalization> */
    public function listLocalizations(
        int $limit,
        int $offset,
        ?string $locale = null
    ): array;

    public function lockPost(string $postPublicId): bool;

    /** @param list<string> $categoryPublicIds */
    public function categoriesExist(array $categoryPublicIds): bool;

    /** @return list<string> */
    public function assignedCategoryPublicIds(string $postPublicId): array;

    /** @param array<string, string> $assignmentPublicIdsByCategory */
    public function replaceAssignments(
        string $postPublicId,
        array $assignmentPublicIdsByCategory,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void;

    /** @return list<PublishedCategoryFilter> */
    public function publicFilters(string $locale): array;

    /** @return list<PublishedPostCard> */
    public function publicPostCards(
        string $locale,
        string $categorySlug,
        int $limit,
        int $offset
    ): array;
}
