<?php

declare(strict_types=1);

namespace App\Core\Commerce\Persistence;

use App\Core\Commerce\AttributeDefinitionDraft;
use App\Core\Commerce\AttributeValueDraft;
use App\Core\Commerce\CategoryLocalizationDraft;
use App\Core\Commerce\CommercePublicCatalogPage;
use App\Core\Commerce\CommercePublicCatalogQuery;
use App\Core\Commerce\CommercePublicProduct;
use App\Core\Commerce\LocalizedProduct;
use App\Core\Commerce\Money;
use App\Core\Commerce\ProductAvailabilityStatus;
use App\Core\Commerce\ProductEditorialStatus;
use App\Core\Commerce\ProductLocalizationDraft;
use App\Core\Commerce\ProductPathResolution;
use App\Core\Commerce\TagLocalizationDraft;
use DateTimeImmutable;

interface CommerceCatalogRepositoryInterface
{
    /** @param list<string> $activeLocales */
    public function createProduct(
        string $publicId,
        ?string $sku,
        ?Money $price,
        ProductAvailabilityStatus $availability,
        string $primaryLocale,
        array $activeLocales,
        ProductLocalizationDraft $source,
        DateTimeImmutable $now
    ): void;

    public function saveProductLocalization(
        string $productPublicId,
        ProductLocalizationDraft $draft,
        int $expectedLockVersion,
        DateTimeImmutable $now
    ): void;

    /**
     * Persists editable product details under one optimistic product lock.
     * A null editorial status preserves the current state before an explicit
     * activateProduct() call; ACTIVE cannot be written through this method.
     */
    public function saveProductDetails(
        string $productPublicId,
        ProductLocalizationDraft $draft,
        int $expectedLockVersion,
        ?ProductEditorialStatus $editorialStatus,
        ProductAvailabilityStatus $availabilityStatus,
        ?Money $price,
        DateTimeImmutable $now
    ): void;

    public function localizedProduct(
        string $productPublicId,
        string $requestedLocale,
        string $primaryLocale
    ): ?LocalizedProduct;

    public function setProductState(
        string $productPublicId,
        int $expectedLockVersion,
        ProductEditorialStatus $editorialStatus,
        ProductAvailabilityStatus $availabilityStatus,
        ?Money $price,
        DateTimeImmutable $now
    ): void;

    /** @param list<string> $activeLocales */
    public function createCategory(
        string $publicId,
        ?string $parentPublicId,
        string $primaryLocale,
        array $activeLocales,
        CategoryLocalizationDraft $source,
        DateTimeImmutable $now
    ): void;

    public function saveCategoryLocalization(
        string $categoryPublicId,
        CategoryLocalizationDraft $draft,
        int $expectedLockVersion,
        DateTimeImmutable $now
    ): void;

    public function setCategoryParent(
        string $categoryPublicId,
        ?string $parentPublicId,
        int $expectedLockVersion,
        DateTimeImmutable $now
    ): void;

    /** @param list<string> $categoryPublicIds */
    public function assignCategories(
        string $productPublicId,
        array $categoryPublicIds,
        string $canonicalCategoryPublicId,
        DateTimeImmutable $now
    ): void;

    /** @param list<string> $activeLocales */
    public function createTag(
        string $publicId,
        string $primaryLocale,
        array $activeLocales,
        TagLocalizationDraft $source,
        DateTimeImmutable $now
    ): void;

    public function saveTagLocalization(
        string $tagPublicId,
        TagLocalizationDraft $draft,
        DateTimeImmutable $now
    ): void;

    /** @param list<string> $tagPublicIds */
    public function assignTags(
        string $productPublicId,
        array $tagPublicIds,
        DateTimeImmutable $now
    ): void;

    /** @param list<string> $activeLocales */
    public function defineAttribute(
        string $publicId,
        AttributeDefinitionDraft $draft,
        array $activeLocales,
        DateTimeImmutable $now
    ): void;

    /** @param array<string, string> $localizedLabels locale => label */
    public function addAttributeOption(
        string $attributePublicId,
        string $optionPublicId,
        string $code,
        int $sortOrder,
        string $primaryLocale,
        array $activeLocales,
        array $localizedLabels,
        DateTimeImmutable $now
    ): void;

    public function setAttributeValue(
        string $productPublicId,
        string $attributePublicId,
        AttributeValueDraft $value,
        DateTimeImmutable $now
    ): void;

    public function refreshPublicPath(
        string $productPublicId,
        string $locale,
        string $primaryLocale,
        string $basePath,
        DateTimeImmutable $now
    ): string;

    /** @param array<string, string> $basePaths */
    public function activateProduct(
        string $productPublicId,
        int $expectedLockVersion,
        string $primaryLocale,
        array $basePaths,
        DateTimeImmutable $now
    ): LocalizedProduct;

    public function resolvePublicPath(
        string $path,
        string $requestedLocale,
        string $primaryLocale
    ): ?ProductPathResolution;

    /** @return list<LocalizedProduct> */
    public function listPublished(
        string $locale,
        string $primaryLocale,
        int $limit,
        int $offset = 0
    ): array;

    public function inquiryCount(string $productPublicId): int;

    public function publicProduct(
        string $productPublicId,
        string $requestedLocale,
        string $primaryLocale
    ): ?CommercePublicProduct;

    public function searchPublished(
        string $locale,
        string $primaryLocale,
        CommercePublicCatalogQuery $query
    ): CommercePublicCatalogPage;

    /** @return list<\App\Core\Commerce\CommercePublicTaxonomyTerm> */
    public function publicTaxonomyTerms(
        string $locale,
        string $primaryLocale,
        string $kind
    ): array;
}
