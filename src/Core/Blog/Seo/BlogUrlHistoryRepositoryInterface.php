<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use DateTimeImmutable;

interface BlogUrlHistoryRepositoryInterface
{
    public function resolve(string $locale, string $slug): ?BlogUrlResolution;

    public function activate(
        string $localizationPublicId,
        string $locale,
        string $slug,
        DateTimeImmutable $now
    ): void;

    public function markTemporary(
        string $localizationPublicId,
        string $locale,
        string $slug,
        DateTimeImmutable $now
    ): void;

    public function owns(
        string $localizationPublicId,
        string $locale,
        string $slug
    ): bool;

    public function isRedirectTargetEligible(
        string $localizationPublicId
    ): bool;

    public function markGone(
        string $localizationPublicId,
        string $locale,
        string $slug,
        DateTimeImmutable $now
    ): void;

    public function markRedirect(
        string $localizationPublicId,
        string $locale,
        string $slug,
        string $replacementLocalizationPublicId,
        DateTimeImmutable $now
    ): void;
}
