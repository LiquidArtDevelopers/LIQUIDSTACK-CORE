<?php

declare(strict_types=1);

namespace App\Core\Commerce;

use App\Core\Commerce\Persistence\CommerceCatalogRepositoryInterface;
use DateTimeImmutable;

final class CommerceCatalogService
{
    /** @var list<string> */
    private readonly array $activeLocales;
    private readonly string $primaryLocale;

    /** @param list<string> $activeLocales */
    public function __construct(
        private readonly CommerceCatalogRepositoryInterface $repository,
        string $primaryLocale,
        array $activeLocales
    ) {
        $this->activeLocales = CommerceInput::locales($activeLocales);
        $this->primaryLocale = CommerceInput::locale($primaryLocale);
        if (!in_array($this->primaryLocale, $this->activeLocales, true)) {
            throw new CommerceValidationException('Primary locale must be active.');
        }
    }

    public function createProduct(
        ProductLocalizationDraft $source,
        ?string $sku,
        ?Money $price,
        DateTimeImmutable $now,
        ProductAvailabilityStatus $availability = ProductAvailabilityStatus::AVAILABLE
    ): LocalizedProduct {
        $this->assertDraftLocale($source->locale(), $source->translationStatus());
        if ($source->translationStatus() !== CommerceTranslationStatus::SOURCE) {
            throw new CommerceValidationException('A product must start from its source locale.');
        }
        $publicId = CommerceInput::newUuid();
        $this->repository->createProduct(
            $publicId,
            $sku,
            $price,
            $availability,
            $this->primaryLocale,
            $this->activeLocales,
            $source,
            $now
        );
        $product = $this->repository->localizedProduct(
            $publicId,
            $this->primaryLocale,
            $this->primaryLocale
        );
        if (!$product instanceof LocalizedProduct) {
            throw new CommerceException('Created product is unavailable.');
        }

        return $product;
    }

    public function saveProductLocalization(
        string $productPublicId,
        ProductLocalizationDraft $draft,
        int $expectedLockVersion,
        DateTimeImmutable $now
    ): LocalizedProduct {
        $this->assertDraftLocale($draft->locale(), $draft->translationStatus());
        $this->repository->saveProductLocalization(
            $productPublicId,
            $draft,
            $expectedLockVersion,
            $now
        );
        $product = $this->repository->localizedProduct(
            $productPublicId,
            $draft->locale(),
            $this->primaryLocale
        );
        if (!$product instanceof LocalizedProduct) {
            throw new CommerceException('Updated product is unavailable.');
        }

        return $product;
    }

    public function saveProductDetails(
        string $productPublicId,
        ProductLocalizationDraft $draft,
        int $expectedLockVersion,
        ?ProductEditorialStatus $editorialStatus,
        ProductAvailabilityStatus $availabilityStatus,
        ?Money $price,
        DateTimeImmutable $now
    ): LocalizedProduct {
        $this->assertDraftLocale($draft->locale(), $draft->translationStatus());
        if ($editorialStatus === ProductEditorialStatus::ACTIVE) {
            throw new CommerceValidationException(
                'Use activateProduct to publish paths atomically.'
            );
        }
        $this->repository->saveProductDetails(
            $productPublicId,
            $draft,
            $expectedLockVersion,
            $editorialStatus,
            $availabilityStatus,
            $price,
            $now
        );
        $product = $this->repository->localizedProduct(
            $productPublicId,
            $draft->locale(),
            $this->primaryLocale
        );
        if (!$product instanceof LocalizedProduct) {
            throw new CommerceException('Updated product is unavailable.');
        }

        return $product;
    }

    public function product(string $publicId, string $locale): ?LocalizedProduct
    {
        $locale = $this->activeLocale($locale);

        return $this->repository->localizedProduct(
            CommerceInput::uuid($publicId),
            $locale,
            $this->primaryLocale
        );
    }

    public function setProductState(
        string $publicId,
        int $expectedLockVersion,
        ProductEditorialStatus $editorial,
        ProductAvailabilityStatus $availability,
        ?Money $price,
        DateTimeImmutable $now
    ): void {
        if ($editorial === ProductEditorialStatus::ACTIVE) {
            throw new CommerceValidationException('Use activateProduct to publish paths atomically.');
        }
        $this->repository->setProductState(
            $publicId,
            $expectedLockVersion,
            $editorial,
            $availability,
            $price,
            $now
        );
    }

    public function createCategory(
        CategoryLocalizationDraft $source,
        ?string $parentPublicId,
        DateTimeImmutable $now
    ): string {
        $this->assertDraftLocale($source->locale(), $source->translationStatus());
        if ($source->translationStatus() !== CommerceTranslationStatus::SOURCE) {
            throw new CommerceValidationException('A category must start from its source locale.');
        }
        $publicId = CommerceInput::newUuid();
        $this->repository->createCategory(
            $publicId,
            $parentPublicId,
            $this->primaryLocale,
            $this->activeLocales,
            $source,
            $now
        );

        return $publicId;
    }

    public function saveCategoryLocalization(
        string $categoryPublicId,
        CategoryLocalizationDraft $draft,
        int $expectedLockVersion,
        DateTimeImmutable $now
    ): void {
        $this->assertDraftLocale($draft->locale(), $draft->translationStatus());
        $this->repository->saveCategoryLocalization(
            $categoryPublicId,
            $draft,
            $expectedLockVersion,
            $now
        );
    }

    public function setCategoryParent(
        string $categoryPublicId,
        ?string $parentPublicId,
        int $expectedLockVersion,
        DateTimeImmutable $now
    ): void {
        $this->repository->setCategoryParent(
            $categoryPublicId,
            $parentPublicId,
            $expectedLockVersion,
            $now
        );
    }

    /** @param list<string> $categoryPublicIds */
    public function assignCategories(
        string $productPublicId,
        array $categoryPublicIds,
        string $canonicalCategoryPublicId,
        DateTimeImmutable $now
    ): void {
        $this->repository->assignCategories(
            $productPublicId,
            $categoryPublicIds,
            $canonicalCategoryPublicId,
            $now
        );
    }

    public function createTag(TagLocalizationDraft $source, DateTimeImmutable $now): string
    {
        $this->assertDraftLocale($source->locale(), $source->translationStatus());
        if ($source->translationStatus() !== CommerceTranslationStatus::SOURCE) {
            throw new CommerceValidationException('A tag must start from its source locale.');
        }
        $publicId = CommerceInput::newUuid();
        $this->repository->createTag(
            $publicId,
            $this->primaryLocale,
            $this->activeLocales,
            $source,
            $now
        );

        return $publicId;
    }

    public function saveTagLocalization(
        string $tagPublicId,
        TagLocalizationDraft $draft,
        DateTimeImmutable $now
    ): void {
        $this->assertDraftLocale($draft->locale(), $draft->translationStatus());
        $this->repository->saveTagLocalization($tagPublicId, $draft, $now);
    }

    /** @param list<string> $tagPublicIds */
    public function assignTags(
        string $productPublicId,
        array $tagPublicIds,
        DateTimeImmutable $now
    ): void {
        $this->repository->assignTags($productPublicId, $tagPublicIds, $now);
    }

    public function defineAttribute(
        AttributeDefinitionDraft $draft,
        DateTimeImmutable $now
    ): string {
        if ($draft->sourceLocale() !== $this->primaryLocale) {
            throw new CommerceValidationException('Attribute source locale must be primary.');
        }
        $publicId = CommerceInput::newUuid();
        $this->repository->defineAttribute($publicId, $draft, $this->activeLocales, $now);

        return $publicId;
    }

    /** @param array<string, string> $localizedLabels */
    public function addAttributeOption(
        string $attributePublicId,
        string $code,
        int $sortOrder,
        array $localizedLabels,
        DateTimeImmutable $now
    ): string {
        foreach (array_keys($localizedLabels) as $locale) {
            if (!is_string($locale)) {
                throw new CommerceValidationException('Invalid option locale.');
            }
            $this->activeLocale($locale);
        }
        $publicId = CommerceInput::newUuid();
        $this->repository->addAttributeOption(
            $attributePublicId,
            $publicId,
            $code,
            $sortOrder,
            $this->primaryLocale,
            $this->activeLocales,
            $localizedLabels,
            $now
        );

        return $publicId;
    }

    public function setAttributeValue(
        string $productPublicId,
        string $attributePublicId,
        AttributeValueDraft $value,
        DateTimeImmutable $now
    ): void {
        if ($value->locale() !== null) {
            $this->activeLocale($value->locale());
        }
        $this->repository->setAttributeValue(
            $productPublicId,
            $attributePublicId,
            $value,
            $now
        );
    }

    /** @param array<string, string> $basePaths */
    public function activateProduct(
        string $productPublicId,
        int $expectedLockVersion,
        array $basePaths,
        DateTimeImmutable $now
    ): LocalizedProduct {
        if (array_keys($basePaths) !== $this->activeLocales) {
            $normalized = [];
            foreach ($basePaths as $locale => $path) {
                if (!is_string($locale) || !is_string($path)) {
                    throw new CommerceValidationException('Invalid localized base paths.');
                }
                $normalized[$this->activeLocale($locale)] = $path;
            }
            if (array_diff($this->activeLocales, array_keys($normalized)) !== []) {
                throw new CommerceValidationException('Every active locale needs a public path.');
            }
            $basePaths = $normalized;
        }

        return $this->repository->activateProduct(
            $productPublicId,
            $expectedLockVersion,
            $this->primaryLocale,
            $basePaths,
            $now
        );
    }

    public function refreshPublicPath(
        string $productPublicId,
        string $locale,
        string $basePath,
        DateTimeImmutable $now
    ): string {
        return $this->repository->refreshPublicPath(
            $productPublicId,
            $this->activeLocale($locale),
            $this->primaryLocale,
            $basePath,
            $now
        );
    }

    public function resolvePublicPath(string $path, string $locale): ?ProductPathResolution
    {
        return $this->repository->resolvePublicPath(
            $path,
            $this->activeLocale($locale),
            $this->primaryLocale
        );
    }

    /** @return list<LocalizedProduct> */
    public function listPublished(string $locale, int $limit = 24, int $offset = 0): array
    {
        return $this->repository->listPublished(
            $this->activeLocale($locale),
            $this->primaryLocale,
            $limit,
            $offset
        );
    }

    public function inquiryCount(string $productPublicId): int
    {
        return $this->repository->inquiryCount($productPublicId);
    }

    private function assertDraftLocale(
        string $locale,
        CommerceTranslationStatus $status
    ): void {
        $locale = $this->activeLocale($locale);
        if (
            ($locale === $this->primaryLocale && $status !== CommerceTranslationStatus::SOURCE)
            || ($locale !== $this->primaryLocale && $status === CommerceTranslationStatus::SOURCE)
        ) {
            throw new CommerceValidationException('Invalid translation role for locale.');
        }
    }

    private function activeLocale(string $locale): string
    {
        $locale = CommerceInput::locale($locale);
        if (!in_array($locale, $this->activeLocales, true)) {
            throw new CommerceValidationException('Inactive locale.');
        }

        return $locale;
    }
}
