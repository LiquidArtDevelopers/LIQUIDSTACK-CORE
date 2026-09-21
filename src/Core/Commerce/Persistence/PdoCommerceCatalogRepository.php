<?php

declare(strict_types=1);

namespace App\Core\Commerce\Persistence;

use App\Core\Commerce\AttributeDefinitionDraft;
use App\Core\Commerce\AttributeValueDraft;
use App\Core\Commerce\CategoryLocalizationDraft;
use App\Core\Commerce\CommerceAttributeType;
use App\Core\Commerce\CommerceConflictException;
use App\Core\Commerce\CommerceInput;
use App\Core\Commerce\CommercePublicAttribute;
use App\Core\Commerce\CommercePublicAttributeOption;
use App\Core\Commerce\CommercePublicCatalogPage;
use App\Core\Commerce\CommercePublicCatalogQuery;
use App\Core\Commerce\CommercePublicMediaReference;
use App\Core\Commerce\CommercePublicProduct;
use App\Core\Commerce\CommercePublicTaxonomyTerm;
use App\Core\Commerce\CommerceTranslationStatus;
use App\Core\Commerce\CommerceValidationException;
use App\Core\Commerce\Http\CommercePublicMediaRoute;
use App\Core\Commerce\LocalizedProduct;
use App\Core\Commerce\Money;
use App\Core\Commerce\ProductAvailabilityStatus;
use App\Core\Commerce\ProductEditorialStatus;
use App\Core\Commerce\ProductLocalizationDraft;
use App\Core\Commerce\ProductPathResolution;
use App\Core\Commerce\TagLocalizationDraft;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use DateTimeImmutable;
use Throwable;

final class PdoCommerceCatalogRepository extends AbstractPdoCommerceRepository implements CommerceCatalogRepositoryInterface
{
    private const MAX_CATEGORY_DEPTH = 32;
    private const MAX_PUBLIC_CATEGORY_FILTER_IDS = 500;

    private readonly string $products;
    private readonly string $productLocalizations;
    private readonly string $categories;
    private readonly string $categoryLocalizations;
    private readonly string $productCategories;
    private readonly string $tags;
    private readonly string $tagLocalizations;
    private readonly string $productTags;
    private readonly string $attributes;
    private readonly string $attributeLocalizations;
    private readonly string $attributeOptions;
    private readonly string $attributeOptionLocalizations;
    private readonly string $productAttributeValues;
    private readonly string $productAttributeValueOptions;
    private readonly string $productMedia;
    private readonly string $productMediaLocalizations;
    private readonly string $urlHistory;
    private readonly string $productInquiryStats;
    private readonly ?string $mediaAssets;
    private readonly ?string $mediaVariants;

    public function __construct(
        \PDO $pdo,
        CommerceTableNames $tables,
        ?WebAdminTableNames $webAdminTables = null
    ) {
        parent::__construct($pdo, $tables);
        if (
            $webAdminTables !== null
            && $webAdminTables->driver() !== $tables->driver()
        ) {
            throw new CommercePersistenceException();
        }
        $this->products = $tables->table('products');
        $this->productLocalizations = $tables->table('product_localizations');
        $this->categories = $tables->table('categories');
        $this->categoryLocalizations = $tables->table('category_localizations');
        $this->productCategories = $tables->table('product_categories');
        $this->tags = $tables->table('tags');
        $this->tagLocalizations = $tables->table('tag_localizations');
        $this->productTags = $tables->table('product_tags');
        $this->attributes = $tables->table('attributes');
        $this->attributeLocalizations = $tables->table('attribute_localizations');
        $this->attributeOptions = $tables->table('attribute_options');
        $this->attributeOptionLocalizations = $tables->table('attribute_option_localizations');
        $this->productAttributeValues = $tables->table('product_attribute_values');
        $this->productAttributeValueOptions = $tables->table('product_attribute_value_options');
        $this->productMedia = $tables->table('product_media');
        $this->productMediaLocalizations = $tables->table(
            'product_media_localizations'
        );
        $this->urlHistory = $tables->table('url_history');
        $this->productInquiryStats = $tables->table('product_inquiry_stats');
        $this->mediaAssets = $webAdminTables?->table('media_assets');
        $this->mediaVariants = $webAdminTables?->table('media_variants');
    }

    public function createProduct(
        string $publicId,
        ?string $sku,
        ?Money $price,
        ProductAvailabilityStatus $availability,
        string $primaryLocale,
        array $activeLocales,
        ProductLocalizationDraft $source,
        DateTimeImmutable $now
    ): void {
        $publicId = CommerceInput::uuid($publicId);
        $sku = CommerceInput::nullableText($sku, 100);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        $activeLocales = CommerceInput::locales($activeLocales);
        if (
            !in_array($primaryLocale, $activeLocales, true)
            || $source->locale() !== $primaryLocale
            || $source->translationStatus() !== CommerceTranslationStatus::SOURCE
        ) {
            throw new CommerceValidationException('Invalid primary localization.');
        }
        $timestamp = CommerceInput::formatUtc($now);

        $this->transactional(function () use (
            $publicId,
            $sku,
            $price,
            $availability,
            $primaryLocale,
            $activeLocales,
            $source,
            $timestamp
        ): void {
            if ($this->one(
                'SELECT id FROM ' . $this->products . ' WHERE public_id = :public_id'
                    . $this->forUpdate(),
                ['public_id' => $publicId]
            ) !== null) {
                throw new CommerceConflictException(CommerceConflictException::DUPLICATE);
            }
            if ($sku !== null && $this->one(
                'SELECT id FROM ' . $this->products . ' WHERE sku = :sku'
                    . $this->forUpdate(),
                ['sku' => $sku]
            ) !== null) {
                throw new CommerceConflictException(CommerceConflictException::DUPLICATE);
            }
            $this->write(
                'INSERT INTO ' . $this->products . ' ('
                    . 'public_id, sku, editorial_status, availability_status, '
                    . 'price_minor, currency, canonical_category_id, lock_version, '
                    . 'created_at, updated_at) VALUES ('
                    . ':public_id, :sku, :editorial_status, :availability_status, '
                    . ':price_minor, :currency, NULL, 1, :created_at, :updated_at)',
                [
                    'public_id' => $publicId,
                    'sku' => $sku,
                    'editorial_status' => ProductEditorialStatus::DRAFT->value,
                    'availability_status' => $availability->value,
                    'price_minor' => $price?->minorUnits(),
                    'currency' => $price?->currency(),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
            $productId = $this->lastInsertId();
            foreach ($activeLocales as $locale) {
                $draft = $locale === $primaryLocale
                    ? $source
                    : ProductLocalizationDraft::fallback($locale);
                $this->insertProductLocalization($productId, $draft, $timestamp);
            }
        });
    }

    public function saveProductLocalization(
        string $productPublicId,
        ProductLocalizationDraft $draft,
        int $expectedLockVersion,
        DateTimeImmutable $now
    ): void {
        $productPublicId = CommerceInput::uuid($productPublicId);
        if ($expectedLockVersion < 1) {
            throw new CommerceValidationException('Invalid product version.');
        }
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use (
            $productPublicId,
            $draft,
            $expectedLockVersion,
            $timestamp
        ): void {
            $product = $this->productRow($productPublicId, true);
            if ($product === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            if ($this->positiveInt($product['lock_version'] ?? null) !== $expectedLockVersion) {
                throw new CommerceConflictException(CommerceConflictException::STALE);
            }
            $affected = $this->write(
                'UPDATE ' . $this->productLocalizations . ' SET '
                    . 'title = :title, slug = :slug, summary = :summary, '
                    . 'description = :description, seo_title = :seo_title, '
                    . 'seo_description = :seo_description, '
                    . 'translation_status = :translation_status, '
                    . 'lock_version = lock_version + 1, updated_at = :updated_at '
                    . 'WHERE product_id = :product_id AND locale = :locale',
                [
                    'title' => $draft->title(),
                    'slug' => $draft->slug(),
                    'summary' => $draft->summary(),
                    'description' => $draft->description(),
                    'seo_title' => $draft->seoTitle(),
                    'seo_description' => $draft->seoDescription(),
                    'translation_status' => $draft->translationStatus()->value,
                    'updated_at' => $timestamp,
                    'product_id' => $this->positiveInt($product['id'] ?? null),
                    'locale' => $draft->locale(),
                ]
            );
            if ($affected !== 1) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            $this->touchProduct(
                $this->positiveInt($product['id'] ?? null),
                $expectedLockVersion,
                $timestamp
            );
        });
    }

    public function saveProductDetails(
        string $productPublicId,
        ProductLocalizationDraft $draft,
        int $expectedLockVersion,
        ?ProductEditorialStatus $editorialStatus,
        ProductAvailabilityStatus $availabilityStatus,
        ?Money $price,
        DateTimeImmutable $now
    ): void {
        $productPublicId = CommerceInput::uuid($productPublicId);
        if ($expectedLockVersion < 1
            || $editorialStatus === ProductEditorialStatus::ACTIVE
        ) {
            throw new CommerceValidationException('Invalid product detail update.');
        }
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use (
            $productPublicId,
            $draft,
            $expectedLockVersion,
            $editorialStatus,
            $availabilityStatus,
            $price,
            $timestamp
        ): void {
            $product = $this->productRow($productPublicId, true);
            if ($product === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            if ($this->positiveInt($product['lock_version'] ?? null) !== $expectedLockVersion) {
                throw new CommerceConflictException(CommerceConflictException::STALE);
            }
            $productId = $this->positiveInt($product['id'] ?? null);
            if ($this->write(
                'UPDATE ' . $this->productLocalizations . ' SET '
                    . 'title = :title, slug = :slug, summary = :summary, '
                    . 'description = :description, seo_title = :seo_title, '
                    . 'seo_description = :seo_description, '
                    . 'translation_status = :translation_status, '
                    . 'lock_version = lock_version + 1, updated_at = :updated_at '
                    . 'WHERE product_id = :product_id AND locale = :locale',
                [
                    'title' => $draft->title(),
                    'slug' => $draft->slug(),
                    'summary' => $draft->summary(),
                    'description' => $draft->description(),
                    'seo_title' => $draft->seoTitle(),
                    'seo_description' => $draft->seoDescription(),
                    'translation_status' => $draft->translationStatus()->value,
                    'updated_at' => $timestamp,
                    'product_id' => $productId,
                    'locale' => $draft->locale(),
                ]
            ) !== 1) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            $currentEditorial = ProductEditorialStatus::from(
                (string) ($product['editorial_status'] ?? '')
            );
            if ($this->write(
                'UPDATE ' . $this->products . ' SET editorial_status = :editorial, '
                    . 'availability_status = :availability, price_minor = :price_minor, '
                    . 'currency = :currency, lock_version = lock_version + 1, '
                    . 'updated_at = :updated_at WHERE id = :id AND lock_version = :expected',
                [
                    'editorial' => ($editorialStatus ?? $currentEditorial)->value,
                    'availability' => $availabilityStatus->value,
                    'price_minor' => $price?->minorUnits(),
                    'currency' => $price?->currency(),
                    'updated_at' => $timestamp,
                    'id' => $productId,
                    'expected' => $expectedLockVersion,
                ]
            ) !== 1) {
                throw new CommerceConflictException(CommerceConflictException::STALE);
            }
        });
    }

    public function localizedProduct(
        string $productPublicId,
        string $requestedLocale,
        string $primaryLocale
    ): ?LocalizedProduct {
        $productPublicId = CommerceInput::uuid($productPublicId);
        $requestedLocale = CommerceInput::locale($requestedLocale);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        $product = $this->productRow($productPublicId, false);
        if ($product === null) {
            return null;
        }

        return $this->hydrateLocalizedProduct($product, $requestedLocale, $primaryLocale);
    }

    public function setProductState(
        string $productPublicId,
        int $expectedLockVersion,
        ProductEditorialStatus $editorialStatus,
        ProductAvailabilityStatus $availabilityStatus,
        ?Money $price,
        DateTimeImmutable $now
    ): void {
        $productPublicId = CommerceInput::uuid($productPublicId);
        if ($expectedLockVersion < 1) {
            throw new CommerceValidationException('Invalid product version.');
        }
        $affected = $this->write(
            'UPDATE ' . $this->products . ' SET editorial_status = :editorial, '
                . 'availability_status = :availability, price_minor = :price_minor, '
                . 'currency = :currency, lock_version = lock_version + 1, '
                . 'updated_at = :updated_at WHERE public_id = :public_id '
                . 'AND lock_version = :expected',
            [
                'editorial' => $editorialStatus->value,
                'availability' => $availabilityStatus->value,
                'price_minor' => $price?->minorUnits(),
                'currency' => $price?->currency(),
                'updated_at' => CommerceInput::formatUtc($now),
                'public_id' => $productPublicId,
                'expected' => $expectedLockVersion,
            ]
        );
        if ($affected !== 1) {
            $this->throwMissingOrStaleProduct($productPublicId);
        }
    }

    public function createCategory(
        string $publicId,
        ?string $parentPublicId,
        string $primaryLocale,
        array $activeLocales,
        CategoryLocalizationDraft $source,
        DateTimeImmutable $now
    ): void {
        $publicId = CommerceInput::uuid($publicId);
        $parentPublicId = $parentPublicId === null ? null : CommerceInput::uuid($parentPublicId);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        $activeLocales = CommerceInput::locales($activeLocales);
        if (
            !in_array($primaryLocale, $activeLocales, true)
            || $source->locale() !== $primaryLocale
            || $source->translationStatus() !== CommerceTranslationStatus::SOURCE
        ) {
            throw new CommerceValidationException('Invalid primary localization.');
        }
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use (
            $publicId,
            $parentPublicId,
            $primaryLocale,
            $activeLocales,
            $source,
            $timestamp
        ): void {
            $parentId = null;
            if ($parentPublicId !== null) {
                $parent = $this->categoryRow($parentPublicId, true);
                if ($parent === null) {
                    throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
                }
                $parentId = $this->positiveInt($parent['id'] ?? null);
            }
            $this->write(
                'INSERT INTO ' . $this->categories . ' ('
                    . 'public_id, parent_id, lock_version, created_at, updated_at) '
                    . 'VALUES (:public_id, :parent_id, 1, :created_at, :updated_at)',
                [
                    'public_id' => $publicId,
                    'parent_id' => $parentId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
            $categoryId = $this->lastInsertId();
            foreach ($activeLocales as $locale) {
                $draft = $locale === $primaryLocale
                    ? $source
                    : CategoryLocalizationDraft::fallback($locale);
                $this->write(
                    'INSERT INTO ' . $this->categoryLocalizations . ' ('
                        . 'category_id, locale, name, slug, translation_status, '
                        . 'lock_version, created_at, updated_at) VALUES ('
                        . ':category_id, :locale, :name, :slug, :status, 1, '
                        . ':created_at, :updated_at)',
                    [
                        'category_id' => $categoryId,
                        'locale' => $draft->locale(),
                        'name' => $draft->name(),
                        'slug' => $draft->slug(),
                        'status' => $draft->translationStatus()->value,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]
                );
            }
        });
    }

    public function saveCategoryLocalization(
        string $categoryPublicId,
        CategoryLocalizationDraft $draft,
        int $expectedLockVersion,
        DateTimeImmutable $now
    ): void {
        $categoryPublicId = CommerceInput::uuid($categoryPublicId);
        if ($expectedLockVersion < 1) {
            throw new CommerceValidationException('Invalid category version.');
        }
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use (
            $categoryPublicId,
            $draft,
            $expectedLockVersion,
            $timestamp
        ): void {
            $category = $this->categoryRow($categoryPublicId, true);
            if ($category === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            if ($this->positiveInt($category['lock_version'] ?? null) !== $expectedLockVersion) {
                throw new CommerceConflictException(CommerceConflictException::STALE);
            }
            if ($this->write(
                'UPDATE ' . $this->categoryLocalizations . ' SET name = :name, '
                    . 'slug = :slug, translation_status = :status, '
                    . 'lock_version = lock_version + 1, updated_at = :updated_at '
                    . 'WHERE category_id = :category_id AND locale = :locale',
                [
                    'name' => $draft->name(),
                    'slug' => $draft->slug(),
                    'status' => $draft->translationStatus()->value,
                    'updated_at' => $timestamp,
                    'category_id' => $this->positiveInt($category['id'] ?? null),
                    'locale' => $draft->locale(),
                ]
            ) !== 1) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            $this->touchCategory(
                $this->positiveInt($category['id'] ?? null),
                $expectedLockVersion,
                $timestamp
            );
        });
    }

    public function setCategoryParent(
        string $categoryPublicId,
        ?string $parentPublicId,
        int $expectedLockVersion,
        DateTimeImmutable $now
    ): void {
        $categoryPublicId = CommerceInput::uuid($categoryPublicId);
        $parentPublicId = $parentPublicId === null ? null : CommerceInput::uuid($parentPublicId);
        if ($expectedLockVersion < 1 || $categoryPublicId === $parentPublicId) {
            throw new CommerceConflictException(CommerceConflictException::CATEGORY_CYCLE);
        }
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use (
            $categoryPublicId,
            $parentPublicId,
            $expectedLockVersion,
            $timestamp
        ): void {
            $category = $this->categoryRow($categoryPublicId, true);
            if ($category === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            if ($this->positiveInt($category['lock_version'] ?? null) !== $expectedLockVersion) {
                throw new CommerceConflictException(CommerceConflictException::STALE);
            }
            $categoryId = $this->positiveInt($category['id'] ?? null);
            $parentId = null;
            if ($parentPublicId !== null) {
                $parent = $this->categoryRow($parentPublicId, true);
                if ($parent === null) {
                    throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
                }
                $parentId = $this->positiveInt($parent['id'] ?? null);
                $cursor = $parentId;
                $seen = [];
                for ($depth = 0; $depth < self::MAX_CATEGORY_DEPTH; ++$depth) {
                    if ($cursor === $categoryId || isset($seen[$cursor])) {
                        throw new CommerceConflictException(CommerceConflictException::CATEGORY_CYCLE);
                    }
                    $seen[$cursor] = true;
                    $row = $this->one(
                        'SELECT parent_id FROM ' . $this->categories . ' WHERE id = :id'
                            . $this->forUpdate(),
                        ['id' => $cursor]
                    );
                    if ($row === null) {
                        throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
                    }
                    if (($row['parent_id'] ?? null) === null) {
                        $cursor = 0;
                        break;
                    }
                    $cursor = $this->positiveInt($row['parent_id']);
                }
                if ($cursor !== 0) {
                    throw new CommerceConflictException(CommerceConflictException::CATEGORY_CYCLE);
                }
            }
            if ($this->write(
                'UPDATE ' . $this->categories . ' SET parent_id = :parent_id, '
                    . 'lock_version = lock_version + 1, updated_at = :updated_at '
                    . 'WHERE id = :id AND lock_version = :expected',
                [
                    'parent_id' => $parentId,
                    'updated_at' => $timestamp,
                    'id' => $categoryId,
                    'expected' => $expectedLockVersion,
                ]
            ) !== 1) {
                throw new CommerceConflictException(CommerceConflictException::STALE);
            }
        });
    }

    public function assignCategories(
        string $productPublicId,
        array $categoryPublicIds,
        string $canonicalCategoryPublicId,
        DateTimeImmutable $now
    ): void {
        $productPublicId = CommerceInput::uuid($productPublicId);
        $canonicalCategoryPublicId = CommerceInput::uuid($canonicalCategoryPublicId);
        $ids = $this->uniquePublicIds($categoryPublicIds, 100);
        if (!in_array($canonicalCategoryPublicId, $ids, true)) {
            throw new CommerceValidationException('Canonical category must be assigned.');
        }
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use (
            $productPublicId,
            $ids,
            $canonicalCategoryPublicId,
            $timestamp
        ): void {
            $product = $this->productRow($productPublicId, true);
            if ($product === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            $internalIds = [];
            foreach ($ids as $publicId) {
                $category = $this->categoryRow($publicId, true);
                if ($category === null) {
                    throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
                }
                $internalIds[$publicId] = $this->positiveInt($category['id'] ?? null);
            }
            $productId = $this->positiveInt($product['id'] ?? null);
            $this->write(
                'DELETE FROM ' . $this->productCategories . ' WHERE product_id = :product_id',
                ['product_id' => $productId]
            );
            foreach ($internalIds as $categoryId) {
                $this->write(
                    'INSERT INTO ' . $this->productCategories
                        . ' (product_id, category_id) VALUES (:product_id, :category_id)',
                    ['product_id' => $productId, 'category_id' => $categoryId]
                );
            }
            $this->write(
                'UPDATE ' . $this->products . ' SET canonical_category_id = :category_id, '
                    . 'lock_version = lock_version + 1, updated_at = :updated_at '
                    . 'WHERE id = :product_id',
                [
                    'category_id' => $internalIds[$canonicalCategoryPublicId],
                    'updated_at' => $timestamp,
                    'product_id' => $productId,
                ]
            );
        });
    }

    public function createTag(
        string $publicId,
        string $primaryLocale,
        array $activeLocales,
        TagLocalizationDraft $source,
        DateTimeImmutable $now
    ): void {
        $publicId = CommerceInput::uuid($publicId);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        $activeLocales = CommerceInput::locales($activeLocales);
        if (
            !in_array($primaryLocale, $activeLocales, true)
            || $source->locale() !== $primaryLocale
            || $source->translationStatus() !== CommerceTranslationStatus::SOURCE
        ) {
            throw new CommerceValidationException('Invalid primary localization.');
        }
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use (
            $publicId,
            $primaryLocale,
            $activeLocales,
            $source,
            $timestamp
        ): void {
            $this->write(
                'INSERT INTO ' . $this->tags . ' (public_id, created_at, updated_at) '
                    . 'VALUES (:public_id, :created_at, :updated_at)',
                [
                    'public_id' => $publicId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
            $tagId = $this->lastInsertId();
            foreach ($activeLocales as $locale) {
                $draft = $locale === $primaryLocale
                    ? $source
                    : TagLocalizationDraft::fallback($locale);
                $this->insertTagLocalization($tagId, $draft, $timestamp);
            }
        });
    }

    public function saveTagLocalization(
        string $tagPublicId,
        TagLocalizationDraft $draft,
        DateTimeImmutable $now
    ): void {
        $tagPublicId = CommerceInput::uuid($tagPublicId);
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use ($tagPublicId, $draft, $timestamp): void {
            $tag = $this->one(
                'SELECT id FROM ' . $this->tags . ' WHERE public_id = :public_id'
                    . $this->forUpdate(),
                ['public_id' => $tagPublicId]
            );
            if ($tag === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            if ($this->write(
                'UPDATE ' . $this->tagLocalizations . ' SET name = :name, '
                    . 'slug = :slug, translation_status = :status, '
                    . 'updated_at = :updated_at WHERE tag_id = :tag_id AND locale = :locale',
                [
                    'name' => $draft->name(),
                    'slug' => $draft->slug(),
                    'status' => $draft->translationStatus()->value,
                    'updated_at' => $timestamp,
                    'tag_id' => $this->positiveInt($tag['id'] ?? null),
                    'locale' => $draft->locale(),
                ]
            ) !== 1) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            $this->write(
                'UPDATE ' . $this->tags . ' SET updated_at = :updated_at WHERE id = :id',
                [
                    'updated_at' => $timestamp,
                    'id' => $this->positiveInt($tag['id'] ?? null),
                ]
            );
        });
    }

    public function assignTags(
        string $productPublicId,
        array $tagPublicIds,
        DateTimeImmutable $now
    ): void {
        $productPublicId = CommerceInput::uuid($productPublicId);
        $ids = $this->uniquePublicIds($tagPublicIds, 50, true);
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use ($productPublicId, $ids, $timestamp): void {
            $product = $this->productRow($productPublicId, true);
            if ($product === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            $productId = $this->positiveInt($product['id'] ?? null);
            $tagIds = [];
            foreach ($ids as $publicId) {
                $tag = $this->one(
                    'SELECT id FROM ' . $this->tags . ' WHERE public_id = :public_id'
                        . $this->forUpdate(),
                    ['public_id' => $publicId]
                );
                if ($tag === null) {
                    throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
                }
                $tagIds[] = $this->positiveInt($tag['id'] ?? null);
            }
            $this->write(
                'DELETE FROM ' . $this->productTags . ' WHERE product_id = :product_id',
                ['product_id' => $productId]
            );
            foreach ($tagIds as $tagId) {
                $this->write(
                    'INSERT INTO ' . $this->productTags
                        . ' (product_id, tag_id) VALUES (:product_id, :tag_id)',
                    ['product_id' => $productId, 'tag_id' => $tagId]
                );
            }
            $this->write(
                'UPDATE ' . $this->products . ' SET lock_version = lock_version + 1, '
                    . 'updated_at = :updated_at WHERE id = :id',
                ['updated_at' => $timestamp, 'id' => $productId]
            );
        });
    }

    public function defineAttribute(
        string $publicId,
        AttributeDefinitionDraft $draft,
        array $activeLocales,
        DateTimeImmutable $now
    ): void {
        $publicId = CommerceInput::uuid($publicId);
        $activeLocales = CommerceInput::locales($activeLocales);
        if (!in_array($draft->sourceLocale(), $activeLocales, true)) {
            throw new CommerceValidationException('Attribute source locale is inactive.');
        }
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use (
            $publicId,
            $draft,
            $activeLocales,
            $timestamp
        ): void {
            $categoryId = null;
            if ($draft->categoryPublicId() !== null) {
                $category = $this->categoryRow($draft->categoryPublicId(), true);
                if ($category === null) {
                    throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
                }
                $categoryId = $this->positiveInt($category['id'] ?? null);
            }
            $this->write(
                'INSERT INTO ' . $this->attributes . ' ('
                    . 'public_id, code, type, category_id, unit, is_filterable, '
                    . 'sort_order, created_at, updated_at) VALUES ('
                    . ':public_id, :code, :type, :category_id, :unit, :filterable, '
                    . ':sort_order, :created_at, :updated_at)',
                [
                    'public_id' => $publicId,
                    'code' => $draft->code(),
                    'type' => $draft->type()->value,
                    'category_id' => $categoryId,
                    'unit' => $draft->unit(),
                    'filterable' => $draft->filterable() ? 1 : 0,
                    'sort_order' => $draft->sortOrder(),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
            $attributeId = $this->lastInsertId();
            foreach ($activeLocales as $locale) {
                $source = $locale === $draft->sourceLocale();
                $this->write(
                    'INSERT INTO ' . $this->attributeLocalizations . ' ('
                        . 'attribute_id, locale, name, translation_status, created_at, updated_at) '
                        . 'VALUES (:attribute_id, :locale, :name, :status, :created_at, :updated_at)',
                    [
                        'attribute_id' => $attributeId,
                        'locale' => $locale,
                        'name' => $source ? $draft->sourceName() : null,
                        'status' => $source
                            ? CommerceTranslationStatus::SOURCE->value
                            : CommerceTranslationStatus::FALLBACK->value,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]
                );
            }
        });
    }

    public function addAttributeOption(
        string $attributePublicId,
        string $optionPublicId,
        string $code,
        int $sortOrder,
        string $primaryLocale,
        array $activeLocales,
        array $localizedLabels,
        DateTimeImmutable $now
    ): void {
        $attributePublicId = CommerceInput::uuid($attributePublicId);
        $optionPublicId = CommerceInput::uuid($optionPublicId);
        $code = CommerceInput::code($code);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        $activeLocales = CommerceInput::locales($activeLocales);
        if ($sortOrder < 0 || $sortOrder > 10_000 || !isset($localizedLabels[$primaryLocale])) {
            throw new CommerceValidationException('Invalid attribute option.');
        }
        $labels = [];
        foreach ($localizedLabels as $locale => $label) {
            if (!is_string($locale) || !is_string($label)) {
                throw new CommerceValidationException('Invalid option localization.');
            }
            $labels[CommerceInput::locale($locale)] = CommerceInput::text($label, 180);
        }
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use (
            $attributePublicId,
            $optionPublicId,
            $code,
            $sortOrder,
            $primaryLocale,
            $activeLocales,
            $labels,
            $timestamp
        ): void {
            $attribute = $this->attributeRow($attributePublicId, true);
            if ($attribute === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            $type = CommerceAttributeType::from((string) ($attribute['type'] ?? ''));
            if (!in_array($type, [CommerceAttributeType::SELECT, CommerceAttributeType::MULTISELECT], true)) {
                throw new CommerceValidationException('Only selectable attributes have options.');
            }
            $this->write(
                'INSERT INTO ' . $this->attributeOptions . ' ('
                    . 'public_id, attribute_id, code, sort_order, created_at, updated_at) '
                    . 'VALUES (:public_id, :attribute_id, :code, :sort_order, :created_at, :updated_at)',
                [
                    'public_id' => $optionPublicId,
                    'attribute_id' => $this->positiveInt($attribute['id'] ?? null),
                    'code' => $code,
                    'sort_order' => $sortOrder,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
            $optionId = $this->lastInsertId();
            foreach ($activeLocales as $locale) {
                $label = $labels[$locale] ?? null;
                $this->write(
                    'INSERT INTO ' . $this->attributeOptionLocalizations . ' ('
                        . 'option_id, locale, label, translation_status, created_at, updated_at) '
                        . 'VALUES (:option_id, :locale, :label, :status, :created_at, :updated_at)',
                    [
                        'option_id' => $optionId,
                        'locale' => $locale,
                        'label' => $label,
                        'status' => $locale === $primaryLocale
                            ? CommerceTranslationStatus::SOURCE->value
                            : ($label === null
                                ? CommerceTranslationStatus::FALLBACK->value
                                : CommerceTranslationStatus::TRANSLATED->value),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]
                );
            }
        });
    }

    public function setAttributeValue(
        string $productPublicId,
        string $attributePublicId,
        AttributeValueDraft $value,
        DateTimeImmutable $now
    ): void {
        $productPublicId = CommerceInput::uuid($productPublicId);
        $attributePublicId = CommerceInput::uuid($attributePublicId);
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use (
            $productPublicId,
            $attributePublicId,
            $value,
            $timestamp
        ): void {
            $product = $this->productRow($productPublicId, true);
            $attribute = $this->attributeRow($attributePublicId, true);
            if ($product === null || $attribute === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            $type = CommerceAttributeType::from((string) ($attribute['type'] ?? ''));
            if ($type !== $value->type()) {
                throw new CommerceValidationException('Attribute value type mismatch.');
            }
            $productId = $this->positiveInt($product['id'] ?? null);
            $attributeId = $this->positiveInt($attribute['id'] ?? null);
            if (($attribute['category_id'] ?? null) !== null && $this->one(
                'SELECT 1 AS found FROM ' . $this->productCategories
                    . ' WHERE product_id = :product_id AND category_id = :category_id',
                [
                    'product_id' => $productId,
                    'category_id' => $this->positiveInt($attribute['category_id']),
                ]
            ) === null) {
                throw new CommerceValidationException('Attribute does not belong to the product family.');
            }
            if ($value->locale() !== null && $this->one(
                'SELECT id FROM ' . $this->productLocalizations
                    . ' WHERE product_id = :product_id AND locale = :locale',
                ['product_id' => $productId, 'locale' => $value->locale()]
            ) === null) {
                throw new CommerceValidationException('Attribute locale is inactive.');
            }
            $optionIds = [];
            foreach ($value->optionPublicIds() as $optionPublicId) {
                $option = $this->one(
                    'SELECT id FROM ' . $this->attributeOptions
                        . ' WHERE public_id = :public_id AND attribute_id = :attribute_id'
                        . $this->forUpdate(),
                    ['public_id' => $optionPublicId, 'attribute_id' => $attributeId]
                );
                if ($option === null) {
                    throw new CommerceValidationException('Attribute option mismatch.');
                }
                $optionIds[] = $this->positiveInt($option['id'] ?? null);
            }
            $existing = $this->one(
                'SELECT id FROM ' . $this->productAttributeValues
                    . ' WHERE product_id = :product_id AND attribute_id = :attribute_id '
                    . 'AND locale = :locale' . $this->forUpdate(),
                [
                    'product_id' => $productId,
                    'attribute_id' => $attributeId,
                    'locale' => $value->storageLocale(),
                ]
            );
            if ($existing === null) {
                $this->write(
                    'INSERT INTO ' . $this->productAttributeValues . ' ('
                        . 'product_id, attribute_id, locale, text_value, number_value, '
                        . 'boolean_value, date_value, created_at, updated_at) VALUES ('
                        . ':product_id, :attribute_id, :locale, :text_value, :number_value, '
                        . ':boolean_value, :date_value, :created_at, :updated_at)',
                    $this->attributeValueParameters(
                        $productId,
                        $attributeId,
                        $value,
                        $timestamp,
                        true
                    )
                );
                $valueId = $this->lastInsertId();
            } else {
                $valueId = $this->positiveInt($existing['id'] ?? null);
                $parameters = $this->attributeValueParameters(
                    $productId,
                    $attributeId,
                    $value,
                    $timestamp,
                    false
                );
                $parameters['id'] = $valueId;
                $this->write(
                    'UPDATE ' . $this->productAttributeValues . ' SET '
                        . 'text_value = :text_value, number_value = :number_value, '
                        . 'boolean_value = :boolean_value, date_value = :date_value, '
                        . 'updated_at = :updated_at WHERE id = :id',
                    [
                        'text_value' => $parameters['text_value'],
                        'number_value' => $parameters['number_value'],
                        'boolean_value' => $parameters['boolean_value'],
                        'date_value' => $parameters['date_value'],
                        'updated_at' => $parameters['updated_at'],
                        'id' => $valueId,
                    ]
                );
                $this->write(
                    'DELETE FROM ' . $this->productAttributeValueOptions
                        . ' WHERE value_id = :value_id',
                    ['value_id' => $valueId]
                );
            }
            foreach ($optionIds as $optionId) {
                $this->write(
                    'INSERT INTO ' . $this->productAttributeValueOptions
                        . ' (value_id, option_id) VALUES (:value_id, :option_id)',
                    ['value_id' => $valueId, 'option_id' => $optionId]
                );
            }
            $this->write(
                'UPDATE ' . $this->products . ' SET lock_version = lock_version + 1, '
                    . 'updated_at = :updated_at WHERE id = :id',
                ['updated_at' => $timestamp, 'id' => $productId]
            );
        });
    }

    public function refreshPublicPath(
        string $productPublicId,
        string $locale,
        string $primaryLocale,
        string $basePath,
        DateTimeImmutable $now
    ): string {
        $productPublicId = CommerceInput::uuid($productPublicId);
        $locale = CommerceInput::locale($locale);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        $basePath = CommerceInput::basePath($basePath);
        $timestamp = CommerceInput::formatUtc($now);

        return $this->transactional(fn (): string => $this->refreshPublicPathLocked(
            $productPublicId,
            $locale,
            $primaryLocale,
            $basePath,
            $timestamp
        ));
    }

    public function activateProduct(
        string $productPublicId,
        int $expectedLockVersion,
        string $primaryLocale,
        array $basePaths,
        DateTimeImmutable $now
    ): LocalizedProduct {
        $productPublicId = CommerceInput::uuid($productPublicId);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        if ($expectedLockVersion < 1 || $basePaths === []) {
            throw new CommerceValidationException('Invalid activation request.');
        }
        $paths = [];
        foreach ($basePaths as $locale => $basePath) {
            if (!is_string($locale) || !is_string($basePath)) {
                throw new CommerceValidationException('Invalid localized base path.');
            }
            $paths[CommerceInput::locale($locale)] = CommerceInput::basePath($basePath);
        }
        if (!isset($paths[$primaryLocale])) {
            throw new CommerceValidationException('Primary public base path is missing.');
        }
        $timestamp = CommerceInput::formatUtc($now);

        $this->transactional(function () use (
            $productPublicId,
            $expectedLockVersion,
            $primaryLocale,
            $paths,
            $timestamp
        ): void {
            $product = $this->productRow($productPublicId, true);
            if ($product === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            if ($this->positiveInt($product['lock_version'] ?? null) !== $expectedLockVersion) {
                throw new CommerceConflictException(CommerceConflictException::STALE);
            }
            if (!ProductAvailabilityStatus::from(
                (string) ($product['availability_status'] ?? '')
            )->acceptsInquiries()) {
                throw new CommerceConflictException(CommerceConflictException::INACTIVE_PRODUCT);
            }
            $locales = $this->all(
                'SELECT locale FROM ' . $this->productLocalizations
                    . ' WHERE product_id = :product_id ORDER BY locale ASC'
                    . $this->forUpdate(),
                ['product_id' => $this->positiveInt($product['id'] ?? null)]
            );
            if (count($locales) !== count($paths)) {
                throw new CommerceValidationException('Every active locale needs a public path.');
            }
            foreach ($locales as $row) {
                $locale = is_string($row['locale'] ?? null)
                    ? CommerceInput::locale($row['locale'])
                    : throw new CommercePersistenceException();
                if (!isset($paths[$locale])) {
                    throw new CommerceValidationException('Every active locale needs a public path.');
                }
                $this->refreshPublicPathLocked(
                    $productPublicId,
                    $locale,
                    $primaryLocale,
                    $paths[$locale],
                    $timestamp
                );
            }
            if ($this->write(
                'UPDATE ' . $this->products . ' SET editorial_status = :status, '
                    . 'lock_version = lock_version + 1, updated_at = :updated_at '
                    . 'WHERE id = :id AND lock_version = :expected',
                [
                    'status' => ProductEditorialStatus::ACTIVE->value,
                    'updated_at' => $timestamp,
                    'id' => $this->positiveInt($product['id'] ?? null),
                    'expected' => $expectedLockVersion,
                ]
            ) !== 1) {
                throw new CommerceConflictException(CommerceConflictException::STALE);
            }
        });

        $product = $this->localizedProduct($productPublicId, $primaryLocale, $primaryLocale);
        if (!$product instanceof LocalizedProduct) {
            throw new CommercePersistenceException();
        }

        return $product;
    }

    public function resolvePublicPath(
        string $path,
        string $requestedLocale,
        string $primaryLocale
    ): ?ProductPathResolution {
        $path = CommerceInput::publicPath($path);
        $requestedLocale = CommerceInput::locale($requestedLocale);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        $current = $this->one(
            'SELECT p.public_id, l.public_path FROM ' . $this->productLocalizations
                . ' l JOIN ' . $this->products . ' p ON p.id = l.product_id '
                . 'WHERE l.public_path = :path AND l.locale = :locale '
                . 'AND p.editorial_status = :active',
            [
                'path' => $path,
                'locale' => $requestedLocale,
                'active' => ProductEditorialStatus::ACTIVE->value,
            ]
        );
        if ($current !== null) {
            $product = $this->localizedProduct(
                (string) ($current['public_id'] ?? ''),
                $requestedLocale,
                $primaryLocale
            );
            if (!$product instanceof LocalizedProduct) {
                throw new CommercePersistenceException();
            }

            return ProductPathResolution::found($product, $path);
        }
        $historic = $this->one(
            'SELECT p.public_id, l.public_path FROM ' . $this->urlHistory
                . ' h JOIN ' . $this->productLocalizations
                . ' l ON l.id = h.product_localization_id '
                . 'JOIN ' . $this->products . ' p ON p.id = l.product_id '
                . 'WHERE h.old_path = :path AND l.locale = :locale '
                . 'AND l.public_path IS NOT NULL AND p.editorial_status = :active',
            [
                'path' => $path,
                'locale' => $requestedLocale,
                'active' => ProductEditorialStatus::ACTIVE->value,
            ]
        );
        if ($historic === null) {
            return null;
        }
        $currentPath = is_string($historic['public_path'] ?? null)
            ? CommerceInput::publicPath($historic['public_path'])
            : throw new CommercePersistenceException();
        $product = $this->localizedProduct(
            (string) ($historic['public_id'] ?? ''),
            $requestedLocale,
            $primaryLocale
        );
        if (!$product instanceof LocalizedProduct) {
            throw new CommercePersistenceException();
        }

        return ProductPathResolution::redirect($product, $currentPath);
    }

    /** @return list<LocalizedProduct> */
    public function listPublished(
        string $locale,
        string $primaryLocale,
        int $limit,
        int $offset = 0
    ): array {
        $locale = CommerceInput::locale($locale);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1_000_000) {
            throw new CommerceValidationException('Invalid catalog window.');
        }
        $statement = $this->prepare(
            'SELECT p.public_id FROM ' . $this->products . ' p JOIN '
                . $this->productLocalizations . ' l ON l.product_id = p.id '
                . 'WHERE l.locale = :locale AND l.public_path IS NOT NULL '
                . 'AND p.editorial_status = :active '
                . 'AND p.availability_status IN (:available, :reserved) '
                . 'ORDER BY p.updated_at DESC, p.id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':locale', $locale, \PDO::PARAM_STR);
        $statement->bindValue(':active', ProductEditorialStatus::ACTIVE->value, \PDO::PARAM_STR);
        $statement->bindValue(':available', ProductAvailabilityStatus::AVAILABLE->value, \PDO::PARAM_STR);
        $statement->bindValue(':reserved', ProductAvailabilityStatus::RESERVED->value, \PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $this->execute($statement);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            throw new CommercePersistenceException();
        }
        $products = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['public_id'] ?? null)) {
                throw new CommercePersistenceException();
            }
            $product = $this->localizedProduct($row['public_id'], $locale, $primaryLocale);
            if (!$product instanceof LocalizedProduct) {
                throw new CommercePersistenceException();
            }
            $products[] = $product;
        }

        return $products;
    }

    public function inquiryCount(string $productPublicId): int
    {
        $productPublicId = CommerceInput::uuid($productPublicId);
        $row = $this->one(
            'SELECT s.inquiry_count FROM ' . $this->productInquiryStats
                . ' s JOIN ' . $this->products . ' p ON p.id = s.product_id '
                . 'WHERE p.public_id = :public_id',
            ['public_id' => $productPublicId]
        );

        return $row === null ? 0 : $this->nonNegativeInt($row['inquiry_count'] ?? null);
    }

    public function publicProduct(
        string $productPublicId,
        string $requestedLocale,
        string $primaryLocale
    ): ?CommercePublicProduct {
        $productPublicId = CommerceInput::uuid($productPublicId);
        $requestedLocale = CommerceInput::locale($requestedLocale);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        $row = $this->productRow($productPublicId, false);
        if (
            $row === null
            || ($row['editorial_status'] ?? null)
                !== ProductEditorialStatus::ACTIVE->value
        ) {
            return null;
        }
        $product = $this->hydrateLocalizedProduct(
            $row,
            $requestedLocale,
            $primaryLocale
        );
        if ($product->publicPath() === null) {
            return null;
        }
        $productId = $this->positiveInt($row['id'] ?? null);

        return new CommercePublicProduct(
            $product,
            $this->publicCategories(
                $productId,
                $this->nullablePositiveInt($row['canonical_category_id'] ?? null),
                $requestedLocale,
                $primaryLocale
            ),
            $this->publicTags($productId, $requestedLocale, $primaryLocale),
            $this->publicAttributes(
                $productId,
                $requestedLocale,
                $primaryLocale
            ),
            $this->publicMedia(
                $productId,
                $requestedLocale,
                $primaryLocale
            )
        );
    }

    public function searchPublished(
        string $locale,
        string $primaryLocale,
        CommercePublicCatalogQuery $query
    ): CommercePublicCatalogPage {
        $locale = CommerceInput::locale($locale);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        $parameters = [
            'locale' => $locale,
            'primary_locale' => $primaryLocale,
            'active' => ProductEditorialStatus::ACTIVE->value,
            'available' => ProductAvailabilityStatus::AVAILABLE->value,
            'reserved' => ProductAvailabilityStatus::RESERVED->value,
        ];
        $where = [
            'l.public_path IS NOT NULL',
            'p.editorial_status = :active',
            'p.availability_status IN (:available, :reserved)',
        ];
        $search = $query->search();
        if ($search !== null) {
            $effective = static fn (string $column): string =>
                "CASE WHEN l.translation_status = 'fallback' "
                    . "THEN s.{$column} ELSE l.{$column} END";
            $where[] = '('
                . 'INSTR(LOWER(COALESCE(' . $effective('title')
                    . ", '')), LOWER(:search_title)) > 0 OR "
                . 'INSTR(LOWER(COALESCE(' . $effective('summary')
                    . ", '')), LOWER(:search_summary)) > 0 OR "
                . 'INSTR(LOWER(COALESCE(' . $effective('description')
                    . ", '')), LOWER(:search_description)) > 0 OR "
                . "INSTR(LOWER(COALESCE(p.sku, '')), LOWER(:search_sku)) > 0)";
            foreach (['title', 'summary', 'description', 'sku'] as $field) {
                $parameters['search_' . $field] = $search;
            }
        }
        $categorySlug = $query->categorySlug();
        if ($categorySlug !== null) {
            $categoryIds = $this->categoryDescendantIdsForSlug(
                $categorySlug,
                $locale,
                $primaryLocale
            );
            if ($categoryIds === []) {
                return new CommercePublicCatalogPage([], false, $query);
            }
            $placeholders = [];
            foreach ($categoryIds as $index => $categoryId) {
                $key = 'category_' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = $categoryId;
            }
            $where[] = 'EXISTS (SELECT 1 FROM ' . $this->productCategories
                . ' pc WHERE pc.product_id = p.id AND pc.category_id IN ('
                . implode(', ', $placeholders) . '))';
        }
        $tagSlug = $query->tagSlug();
        if ($tagSlug !== null) {
            $tagId = $this->tagIdForSlug($tagSlug, $locale, $primaryLocale);
            if ($tagId === null) {
                return new CommercePublicCatalogPage([], false, $query);
            }
            $where[] = 'EXISTS (SELECT 1 FROM ' . $this->productTags
                . ' pt WHERE pt.product_id = p.id AND pt.tag_id = :tag_id)';
            $parameters['tag_id'] = $tagId;
        }

        $statement = $this->prepare(
            'SELECT p.public_id FROM ' . $this->products . ' p INNER JOIN '
                . $this->productLocalizations . ' l '
                . 'ON l.product_id = p.id AND l.locale = :locale INNER JOIN '
                . $this->productLocalizations . ' s '
                . 'ON s.product_id = p.id AND s.locale = :primary_locale '
                . 'WHERE ' . implode(' AND ', $where)
                . ' ORDER BY p.updated_at DESC, p.id DESC '
                . 'LIMIT :result_limit OFFSET :result_offset'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue(
                ':' . $key,
                $value,
                is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR
            );
        }
        $statement->bindValue(
            ':result_limit',
            $query->limit() + 1,
            \PDO::PARAM_INT
        );
        $statement->bindValue(
            ':result_offset',
            $query->offset(),
            \PDO::PARAM_INT
        );
        $this->execute($statement);
        $rows = $statement->fetchAll(\PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            throw new CommercePersistenceException();
        }
        $hasNext = count($rows) > $query->limit();
        if ($hasNext) {
            array_pop($rows);
        }
        $items = [];
        foreach ($rows as $publicId) {
            if (!is_string($publicId)) {
                throw new CommercePersistenceException();
            }
            $item = $this->publicProduct($publicId, $locale, $primaryLocale);
            if (!$item instanceof CommercePublicProduct) {
                throw new CommercePersistenceException();
            }
            $items[] = $item;
        }

        return new CommercePublicCatalogPage($items, $hasNext, $query);
    }

    public function publicTaxonomyTerms(
        string $locale,
        string $primaryLocale,
        string $kind
    ): array {
        $locale = CommerceInput::locale($locale);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        if (!in_array($kind, [
            CommercePublicTaxonomyTerm::CATEGORY,
            CommercePublicTaxonomyTerm::TAG,
        ], true)) {
            throw new CommerceValidationException('Invalid taxonomy kind.');
        }
        $parameters = [
            'locale' => $locale,
            'product_locale' => $locale,
            'primary_locale' => $primaryLocale,
            'active' => ProductEditorialStatus::ACTIVE->value,
            'available' => ProductAvailabilityStatus::AVAILABLE->value,
            'reserved' => ProductAvailabilityStatus::RESERVED->value,
        ];
        $effective = static fn (string $column): string =>
            "CASE WHEN l.translation_status = 'fallback' "
                . "THEN s.{$column} ELSE l.{$column} END";
        $resolvedLocale = "CASE WHEN l.translation_status = 'fallback' "
            . 'THEN s.locale ELSE l.locale END';
        if ($kind === CommercePublicTaxonomyTerm::CATEGORY) {
            $rows = $this->all(
                'SELECT DISTINCT c.public_id, parent.public_id AS parent_public_id, '
                    . $effective('name') . ' AS effective_name, '
                    . $effective('slug') . ' AS effective_slug, '
                    . "CASE WHEN l.translation_status = 'fallback' THEN 1 ELSE 0 END AS is_fallback, "
                    . $resolvedLocale . ' AS resolved_locale, c.sort_order '
                    . 'FROM ' . $this->categories . ' c '
                    . 'LEFT JOIN ' . $this->categories . ' parent ON parent.id = c.parent_id '
                    . 'INNER JOIN ' . $this->categoryLocalizations . ' l '
                    . 'ON l.category_id = c.id AND l.locale = :locale '
                    . 'INNER JOIN ' . $this->categoryLocalizations . ' s '
                    . 'ON s.category_id = c.id AND s.locale = :primary_locale '
                    . 'INNER JOIN ' . $this->productCategories . ' pc ON pc.category_id = c.id '
                    . 'INNER JOIN ' . $this->products . ' p ON p.id = pc.product_id '
                    . 'INNER JOIN ' . $this->productLocalizations . ' pl '
                    . 'ON pl.product_id = p.id AND pl.locale = :product_locale '
                    . 'WHERE p.editorial_status = :active '
                    . 'AND p.availability_status IN (:available, :reserved) '
                    . 'AND pl.public_path IS NOT NULL '
                    . 'ORDER BY c.sort_order ASC, effective_name ASC, c.public_id ASC',
                $parameters
            );
        } else {
            $rows = $this->all(
                'SELECT DISTINCT t.public_id, NULL AS parent_public_id, '
                    . $effective('name') . ' AS effective_name, '
                    . $effective('slug') . ' AS effective_slug, '
                    . "CASE WHEN l.translation_status = 'fallback' THEN 1 ELSE 0 END AS is_fallback, "
                    . $resolvedLocale . ' AS resolved_locale '
                    . 'FROM ' . $this->tags . ' t '
                    . 'INNER JOIN ' . $this->tagLocalizations . ' l '
                    . 'ON l.tag_id = t.id AND l.locale = :locale '
                    . 'INNER JOIN ' . $this->tagLocalizations . ' s '
                    . 'ON s.tag_id = t.id AND s.locale = :primary_locale '
                    . 'INNER JOIN ' . $this->productTags . ' pt ON pt.tag_id = t.id '
                    . 'INNER JOIN ' . $this->products . ' p ON p.id = pt.product_id '
                    . 'INNER JOIN ' . $this->productLocalizations . ' pl '
                    . 'ON pl.product_id = p.id AND pl.locale = :product_locale '
                    . 'WHERE p.editorial_status = :active '
                    . 'AND p.availability_status IN (:available, :reserved) '
                    . 'AND pl.public_path IS NOT NULL '
                    . 'ORDER BY effective_name ASC, t.public_id ASC',
                $parameters
            );
        }
        $terms = [];
        foreach ($rows as $row) {
            $terms[] = new CommercePublicTaxonomyTerm(
                $kind,
                CommerceInput::uuid((string) ($row['public_id'] ?? '')),
                is_string($row['parent_public_id'] ?? null)
                    ? CommerceInput::uuid($row['parent_public_id'])
                    : null,
                $locale,
                CommerceInput::locale((string) ($row['resolved_locale'] ?? '')),
                (int) ($row['is_fallback'] ?? 0) === 1,
                CommerceInput::text((string) ($row['effective_name'] ?? ''), 255),
                CommerceInput::slug((string) ($row['effective_slug'] ?? ''))
            );
        }

        return $terms;
    }

    /** @return list<int> */
    private function categoryDescendantIdsForSlug(
        string $slug,
        string $locale,
        string $primaryLocale
    ): array {
        $rows = $this->all(
            'SELECT c.id FROM ' . $this->categories . ' c INNER JOIN '
                . $this->categoryLocalizations . ' l '
                . 'ON l.category_id = c.id AND l.locale = :locale INNER JOIN '
                . $this->categoryLocalizations . ' s '
                . 'ON s.category_id = c.id AND s.locale = :primary_locale '
                . "WHERE CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.slug ELSE l.slug END = :slug LIMIT 2',
            [
                'locale' => $locale,
                'primary_locale' => $primaryLocale,
                'slug' => CommerceInput::slug($slug),
            ]
        );
        if ($rows === []) {
            return [];
        }
        if (count($rows) !== 1) {
            throw new CommercePersistenceException();
        }
        $root = $this->positiveInt($rows[0]['id'] ?? null);
        $ids = [$root => true];
        $frontier = [$root];
        for ($depth = 0; $frontier !== []; ++$depth) {
            if ($depth >= self::MAX_CATEGORY_DEPTH) {
                throw new CommercePersistenceException();
            }
            $parameters = [];
            $placeholders = [];
            foreach ($frontier as $index => $parentId) {
                $key = 'parent_' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = $parentId;
            }
            $children = $this->all(
                'SELECT id FROM ' . $this->categories . ' WHERE parent_id IN ('
                    . implode(', ', $placeholders) . ') ORDER BY id ASC',
                $parameters
            );
            $frontier = [];
            foreach ($children as $child) {
                $id = $this->positiveInt($child['id'] ?? null);
                if (isset($ids[$id])) {
                    throw new CommercePersistenceException();
                }
                $ids[$id] = true;
                $frontier[] = $id;
                if (count($ids) > self::MAX_PUBLIC_CATEGORY_FILTER_IDS) {
                    throw new CommercePersistenceException();
                }
            }
        }

        return array_keys($ids);
    }

    private function tagIdForSlug(
        string $slug,
        string $locale,
        string $primaryLocale
    ): ?int {
        $rows = $this->all(
            'SELECT t.id FROM ' . $this->tags . ' t INNER JOIN '
                . $this->tagLocalizations . ' l '
                . 'ON l.tag_id = t.id AND l.locale = :locale INNER JOIN '
                . $this->tagLocalizations . ' s '
                . 'ON s.tag_id = t.id AND s.locale = :primary_locale '
                . "WHERE CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.slug ELSE l.slug END = :slug LIMIT 2',
            [
                'locale' => $locale,
                'primary_locale' => $primaryLocale,
                'slug' => CommerceInput::slug($slug),
            ]
        );
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1) {
            throw new CommercePersistenceException();
        }

        return $this->positiveInt($rows[0]['id'] ?? null);
    }

    /** @return list<CommercePublicTaxonomyTerm> */
    private function publicCategories(
        int $productId,
        ?int $canonicalCategoryId,
        string $requestedLocale,
        string $primaryLocale
    ): array {
        $rows = $this->all(
            'SELECT c.public_id, parent.public_id AS parent_public_id, '
                . 'l.translation_status, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.locale ELSE l.locale END AS resolved_locale, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.name ELSE l.name END AS resolved_name, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.slug ELSE l.slug END AS resolved_slug, '
                . 'CASE WHEN c.id = :canonical_id THEN 1 ELSE 0 END AS is_canonical '
                . 'FROM ' . $this->productCategories . ' pc INNER JOIN '
                . $this->categories . ' c ON c.id = pc.category_id LEFT JOIN '
                . $this->categories . ' parent ON parent.id = c.parent_id '
                . 'INNER JOIN ' . $this->categoryLocalizations . ' l '
                . 'ON l.category_id = c.id AND l.locale = :requested_locale '
                . 'INNER JOIN ' . $this->categoryLocalizations . ' s '
                . 'ON s.category_id = c.id AND s.locale = :primary_locale '
                . 'WHERE pc.product_id = :product_id '
                . 'ORDER BY is_canonical DESC, c.id ASC LIMIT 100',
            [
                'canonical_id' => $canonicalCategoryId,
                'requested_locale' => $requestedLocale,
                'primary_locale' => $primaryLocale,
                'product_id' => $productId,
            ]
        );
        $terms = [];
        foreach ($rows as $row) {
            $fallback = ($row['translation_status'] ?? null)
                === CommerceTranslationStatus::FALLBACK->value;
            $terms[] = new CommercePublicTaxonomyTerm(
                CommercePublicTaxonomyTerm::CATEGORY,
                is_string($row['public_id'] ?? null)
                    ? $row['public_id']
                    : throw new CommercePersistenceException(),
                is_string($row['parent_public_id'] ?? null)
                    ? $row['parent_public_id']
                    : null,
                $requestedLocale,
                is_string($row['resolved_locale'] ?? null)
                    ? $row['resolved_locale']
                    : throw new CommercePersistenceException(),
                $fallback,
                is_string($row['resolved_name'] ?? null)
                    ? $row['resolved_name']
                    : throw new CommercePersistenceException(),
                is_string($row['resolved_slug'] ?? null)
                    ? $row['resolved_slug']
                    : throw new CommercePersistenceException(),
                $this->storedBoolean($row['is_canonical'] ?? null)
            );
        }

        return $terms;
    }

    /** @return list<CommercePublicTaxonomyTerm> */
    private function publicTags(
        int $productId,
        string $requestedLocale,
        string $primaryLocale
    ): array {
        $rows = $this->all(
            'SELECT t.public_id, l.translation_status, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.locale ELSE l.locale END AS resolved_locale, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.name ELSE l.name END AS resolved_name, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.slug ELSE l.slug END AS resolved_slug FROM '
                . $this->productTags . ' pt INNER JOIN ' . $this->tags
                . ' t ON t.id = pt.tag_id INNER JOIN '
                . $this->tagLocalizations . ' l '
                . 'ON l.tag_id = t.id AND l.locale = :requested_locale '
                . 'INNER JOIN ' . $this->tagLocalizations . ' s '
                . 'ON s.tag_id = t.id AND s.locale = :primary_locale '
                . 'WHERE pt.product_id = :product_id '
                . 'ORDER BY resolved_name ASC, t.id ASC LIMIT 100',
            [
                'requested_locale' => $requestedLocale,
                'primary_locale' => $primaryLocale,
                'product_id' => $productId,
            ]
        );
        $terms = [];
        foreach ($rows as $row) {
            $fallback = ($row['translation_status'] ?? null)
                === CommerceTranslationStatus::FALLBACK->value;
            $terms[] = new CommercePublicTaxonomyTerm(
                CommercePublicTaxonomyTerm::TAG,
                is_string($row['public_id'] ?? null)
                    ? $row['public_id']
                    : throw new CommercePersistenceException(),
                null,
                $requestedLocale,
                is_string($row['resolved_locale'] ?? null)
                    ? $row['resolved_locale']
                    : throw new CommercePersistenceException(),
                $fallback,
                is_string($row['resolved_name'] ?? null)
                    ? $row['resolved_name']
                    : throw new CommercePersistenceException(),
                is_string($row['resolved_slug'] ?? null)
                    ? $row['resolved_slug']
                    : throw new CommercePersistenceException()
            );
        }

        return $terms;
    }

    /** @return list<CommercePublicAttribute> */
    private function publicAttributes(
        int $productId,
        string $requestedLocale,
        string $primaryLocale
    ): array {
        $rows = $this->all(
            'SELECT a.id, a.public_id, a.code, a.type, a.unit, '
                . 'a.is_filterable, a.sort_order, l.translation_status, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.locale ELSE l.locale END AS resolved_locale, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.name ELSE l.name END AS resolved_name FROM '
                . $this->attributes . ' a INNER JOIN '
                . $this->attributeLocalizations . ' l '
                . 'ON l.attribute_id = a.id AND l.locale = :requested_locale '
                . 'INNER JOIN ' . $this->attributeLocalizations . ' s '
                . 'ON s.attribute_id = a.id AND s.locale = :primary_locale '
                . 'WHERE EXISTS (SELECT 1 FROM '
                . $this->productAttributeValues
                . ' pav WHERE pav.product_id = :product_id '
                . 'AND pav.attribute_id = a.id) '
                . 'ORDER BY a.sort_order ASC, a.id ASC LIMIT 200',
            [
                'requested_locale' => $requestedLocale,
                'primary_locale' => $primaryLocale,
                'product_id' => $productId,
            ]
        );
        $attributes = [];
        foreach ($rows as $row) {
            $attributeId = $this->positiveInt($row['id'] ?? null);
            $type = CommerceAttributeType::from(
                is_string($row['type'] ?? null)
                    ? $row['type']
                    : throw new CommercePersistenceException()
            );
            $valueProjection = $this->publicAttributeValue(
                $productId,
                $attributeId,
                $type,
                $requestedLocale,
                $primaryLocale
            );
            if ($valueProjection === null) {
                continue;
            }
            $attributes[] = new CommercePublicAttribute(
                is_string($row['public_id'] ?? null)
                    ? $row['public_id']
                    : throw new CommercePersistenceException(),
                is_string($row['code'] ?? null)
                    ? $row['code']
                    : throw new CommercePersistenceException(),
                $type,
                $requestedLocale,
                is_string($row['resolved_locale'] ?? null)
                    ? $row['resolved_locale']
                    : throw new CommercePersistenceException(),
                ($row['translation_status'] ?? null)
                    === CommerceTranslationStatus::FALLBACK->value,
                is_string($row['resolved_name'] ?? null)
                    ? $row['resolved_name']
                    : throw new CommercePersistenceException(),
                is_string($row['unit'] ?? null) ? $row['unit'] : null,
                $this->storedBoolean($row['is_filterable'] ?? null),
                $this->nonNegativeInt($row['sort_order'] ?? null),
                $valueProjection['resolved_locale'],
                $valueProjection['fallback'],
                $valueProjection['value']
            );
        }

        return $attributes;
    }

    /**
     * @return null|array{
     *     value:string|bool|list<CommercePublicAttributeOption>,
     *     resolved_locale:?string,
     *     fallback:bool
     * }
     */
    private function publicAttributeValue(
        int $productId,
        int $attributeId,
        CommerceAttributeType $type,
        string $requestedLocale,
        string $primaryLocale
    ): ?array {
        if ($type === CommerceAttributeType::TEXT) {
            $parameters = [
                'product_id' => $productId,
                'attribute_id' => $attributeId,
                'requested_locale' => $requestedLocale,
            ];
            $localeCondition = 'locale = :requested_locale';
            $order = '';
            if ($requestedLocale !== $primaryLocale) {
                $localeCondition = 'locale IN (:requested_locale, :primary_locale)';
                $parameters['primary_locale'] = $primaryLocale;
                $order = ' ORDER BY CASE WHEN locale = :preferred_locale '
                    . 'THEN 0 ELSE 1 END';
                $parameters['preferred_locale'] = $requestedLocale;
            }
            $row = $this->one(
                'SELECT id, locale, text_value FROM '
                    . $this->productAttributeValues
                    . ' WHERE product_id = :product_id '
                    . 'AND attribute_id = :attribute_id AND '
                    . $localeCondition . $order . ' LIMIT 1',
                $parameters
            );
        } else {
            $row = $this->one(
                'SELECT id, number_value, boolean_value, date_value FROM '
                    . $this->productAttributeValues
                    . ' WHERE product_id = :product_id '
                    . "AND attribute_id = :attribute_id AND locale = '' LIMIT 1",
                ['product_id' => $productId, 'attribute_id' => $attributeId]
            );
        }
        if ($row === null) {
            return null;
        }

        $resolvedLocale = null;
        $fallback = false;
        if ($type === CommerceAttributeType::TEXT) {
            $resolvedLocale = is_string($row['locale'] ?? null)
                ? CommerceInput::locale($row['locale'])
                : throw new CommercePersistenceException();
            $fallback = $resolvedLocale !== $requestedLocale;
        }
        $value = match ($type) {
            CommerceAttributeType::TEXT => is_string($row['text_value'] ?? null)
                ? CommerceInput::text($row['text_value'], 4_000)
                : throw new CommercePersistenceException(),
            CommerceAttributeType::NUMBER => $this->storedDecimal(
                $row['number_value'] ?? null
            ),
            CommerceAttributeType::BOOLEAN => $this->storedBoolean(
                $row['boolean_value'] ?? null
            ),
            CommerceAttributeType::DATE => $this->storedDate(
                $row['date_value'] ?? null
            ),
            CommerceAttributeType::SELECT,
            CommerceAttributeType::MULTISELECT => $this->publicAttributeOptions(
                $this->positiveInt($row['id'] ?? null),
                $attributeId,
                $requestedLocale,
                $primaryLocale
            ),
        };

        return [
            'value' => $value,
            'resolved_locale' => $resolvedLocale,
            'fallback' => $fallback,
        ];
    }

    /** @return list<CommercePublicAttributeOption> */
    private function publicAttributeOptions(
        int $valueId,
        int $attributeId,
        string $requestedLocale,
        string $primaryLocale
    ): array {
        $rows = $this->all(
            'SELECT o.public_id, o.code, l.translation_status, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.locale ELSE l.locale END AS resolved_locale, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.label ELSE l.label END AS resolved_label FROM '
                . $this->productAttributeValueOptions . ' selected '
                . 'INNER JOIN ' . $this->attributeOptions
                . ' o ON o.id = selected.option_id '
                . 'AND o.attribute_id = :attribute_id INNER JOIN '
                . $this->attributeOptionLocalizations . ' l '
                . 'ON l.option_id = o.id AND l.locale = :requested_locale '
                . 'INNER JOIN ' . $this->attributeOptionLocalizations . ' s '
                . 'ON s.option_id = o.id AND s.locale = :primary_locale '
                . 'WHERE selected.value_id = :value_id '
                . 'ORDER BY o.sort_order ASC, o.id ASC LIMIT 51',
            [
                'attribute_id' => $attributeId,
                'requested_locale' => $requestedLocale,
                'primary_locale' => $primaryLocale,
                'value_id' => $valueId,
            ]
        );
        if ($rows === [] || count($rows) > 50) {
            throw new CommercePersistenceException();
        }
        $options = [];
        foreach ($rows as $row) {
            $options[] = new CommercePublicAttributeOption(
                is_string($row['public_id'] ?? null)
                    ? $row['public_id']
                    : throw new CommercePersistenceException(),
                is_string($row['code'] ?? null)
                    ? $row['code']
                    : throw new CommercePersistenceException(),
                $requestedLocale,
                is_string($row['resolved_locale'] ?? null)
                    ? $row['resolved_locale']
                    : throw new CommercePersistenceException(),
                ($row['translation_status'] ?? null)
                    === CommerceTranslationStatus::FALLBACK->value,
                is_string($row['resolved_label'] ?? null)
                    ? $row['resolved_label']
                    : throw new CommercePersistenceException()
            );
        }

        return $options;
    }

    /** @return list<CommercePublicMediaReference> */
    private function publicMedia(
        int $productId,
        string $requestedLocale,
        string $primaryLocale
    ): array
    {
        if ($this->mediaAssets === null || $this->mediaVariants === null) {
            return [];
        }
        $rows = $this->all(
            'SELECT pm.media_asset_public_id, pm.role, pm.sort_order, '
                . 'l.translation_status, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.locale ELSE l.locale END AS resolved_locale, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.alt_text ELSE l.alt_text END AS resolved_alt_text, '
                . "CASE WHEN l.translation_status = 'fallback' "
                . 'THEN s.caption ELSE l.caption END AS resolved_caption, '
                . 'v.width, v.height FROM ' . $this->productMedia
                . ' pm INNER JOIN ' . $this->mediaAssets
                . ' a ON a.public_id = pm.media_asset_public_id INNER JOIN '
                . $this->mediaVariants . ' v ON v.asset_id = a.id '
                . 'AND v.mime = :mime INNER JOIN '
                . $this->productMediaLocalizations . ' l '
                . 'ON l.media_id = pm.id AND l.locale = :requested_locale '
                . 'INNER JOIN ' . $this->productMediaLocalizations . ' s '
                . 'ON s.media_id = pm.id AND s.locale = :primary_locale '
                . 'WHERE pm.product_id = :product_id '
                . "ORDER BY CASE WHEN pm.role = 'cover' THEN 0 ELSE 1 END, "
                . 'pm.sort_order ASC, pm.id ASC, v.width ASC LIMIT 181',
            [
                'mime' => 'image/avif',
                'requested_locale' => $requestedLocale,
                'primary_locale' => $primaryLocale,
                'product_id' => $productId,
            ]
        );
        if (count($rows) > 180) {
            throw new CommercePersistenceException();
        }
        /** @var array<string, array{role:string,sort_order:int,resolved_locale:string,fallback:bool,alt_text:string,caption:?string,variants:list<array{width:int,height:int,path:string}>}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $publicId = is_string($row['media_asset_public_id'] ?? null)
                ? CommerceInput::uuid($row['media_asset_public_id'])
                : throw new CommercePersistenceException();
            if (!isset($grouped[$publicId])) {
                if (count($grouped) >= 20) {
                    throw new CommercePersistenceException();
                }
                $grouped[$publicId] = [
                    'role' => is_string($row['role'] ?? null)
                        ? $row['role']
                        : throw new CommercePersistenceException(),
                    'sort_order' => $this->nonNegativeInt(
                        $row['sort_order'] ?? null
                    ),
                    'resolved_locale' => is_string(
                        $row['resolved_locale'] ?? null
                    )
                        ? $row['resolved_locale']
                        : throw new CommercePersistenceException(),
                    'fallback' => ($row['translation_status'] ?? null)
                        === CommerceTranslationStatus::FALLBACK->value,
                    'alt_text' => is_string($row['resolved_alt_text'] ?? null)
                        ? $row['resolved_alt_text']
                        : throw new CommercePersistenceException(),
                    'caption' => is_string($row['resolved_caption'] ?? null)
                        && trim($row['resolved_caption']) !== ''
                            ? $row['resolved_caption']
                            : null,
                    'variants' => [],
                ];
            }
            if (count($grouped[$publicId]['variants']) >= 9) {
                throw new CommercePersistenceException();
            }
            $width = $this->positiveInt($row['width'] ?? null);
            $grouped[$publicId]['variants'][] = [
                'width' => $width,
                'height' => $this->positiveInt($row['height'] ?? null),
                'path' => CommercePublicMediaRoute::path($publicId, $width),
            ];
        }
        $media = [];
        foreach ($grouped as $publicId => $projection) {
            $media[] = new CommercePublicMediaReference(
                $publicId,
                $projection['role'],
                $projection['sort_order'],
                $requestedLocale,
                $projection['resolved_locale'],
                $projection['fallback'],
                $projection['alt_text'],
                $projection['caption'],
                $projection['variants']
            );
        }

        return $media;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        return $value === null ? null : $this->positiveInt($value);
    }

    private function storedBoolean(mixed $value): bool
    {
        return match ($value) {
            0, '0' => false,
            1, '1' => true,
            default => throw new CommercePersistenceException(),
        };
    }

    private function storedDecimal(mixed $value): string
    {
        if (is_int($value)) {
            $normalized = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $normalized = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        } elseif (is_string($value)) {
            $normalized = str_contains($value, '.')
                ? rtrim(rtrim($value, '0'), '.')
                : $value;
        } else {
            throw new CommercePersistenceException();
        }
        if ($normalized === '-0') {
            $normalized = '0';
        }
        if (preg_match('/\A-?(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,6})?\z/', $normalized) !== 1) {
            throw new CommercePersistenceException();
        }

        return $normalized;
    }

    private function storedDate(mixed $value): string
    {
        if (!is_string($value)) {
            throw new CommercePersistenceException();
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof \DateTimeImmutable
            || ($errors !== false
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $value
        ) {
            throw new CommercePersistenceException();
        }

        return $value;
    }

    private function refreshPublicPathLocked(
        string $productPublicId,
        string $locale,
        string $primaryLocale,
        string $basePath,
        string $timestamp
    ): string {
        if (!$this->inTransaction()) {
            throw new CommercePersistenceException();
        }
        $product = $this->productRow($productPublicId, true);
        if ($product === null || ($product['canonical_category_id'] ?? null) === null) {
            throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
        }
        $productId = $this->positiveInt($product['id'] ?? null);
        $localization = $this->resolvedLocalizationRow(
            $productId,
            $locale,
            $primaryLocale,
            true
        );
        $localizationId = $this->positiveInt($localization['requested_id'] ?? null);
        $slug = is_string($localization['slug'] ?? null)
            ? CommerceInput::slug($localization['slug'])
            : throw new CommercePersistenceException();
        $segments = $this->categoryPathSegments(
            $this->positiveInt($product['canonical_category_id']),
            $locale,
            $primaryLocale
        );
        $newPath = CommerceInput::publicPath(
            $basePath . '/' . implode('/', [...$segments, $slug])
        );
        $currentPath = $localization['public_path'] ?? null;
        if ($currentPath !== null && !is_string($currentPath)) {
            throw new CommercePersistenceException();
        }
        if ($currentPath === $newPath) {
            return $newPath;
        }
        $owner = $this->one(
            'SELECT id, product_id FROM ' . $this->productLocalizations
                . ' WHERE public_path = :path' . $this->forUpdate(),
            ['path' => $newPath]
        );
        if ($owner !== null && $this->positiveInt($owner['id'] ?? null) !== $localizationId) {
            throw new CommerceConflictException(CommerceConflictException::DUPLICATE);
        }
        $historicOwner = $this->one(
            'SELECT product_localization_id FROM ' . $this->urlHistory
                . ' WHERE old_path = :path' . $this->forUpdate(),
            ['path' => $newPath]
        );
        if ($historicOwner !== null) {
            if ($this->positiveInt($historicOwner['product_localization_id'] ?? null) !== $localizationId) {
                throw new CommerceConflictException(CommerceConflictException::DUPLICATE);
            }
            $this->write(
                'DELETE FROM ' . $this->urlHistory . ' WHERE old_path = :path',
                ['path' => $newPath]
            );
        }
        if ($currentPath !== null) {
            $this->write(
                'INSERT INTO ' . $this->urlHistory . ' ('
                    . 'product_localization_id, old_path, new_path, created_at) '
                    . 'VALUES (:localization_id, :old_path, :new_path, :created_at)',
                [
                    'localization_id' => $localizationId,
                    'old_path' => CommerceInput::publicPath($currentPath),
                    'new_path' => $newPath,
                    'created_at' => $timestamp,
                ]
            );
        }
        if ($this->write(
            'UPDATE ' . $this->productLocalizations . ' SET public_path = :path, '
                . 'updated_at = :updated_at WHERE id = :id',
            ['path' => $newPath, 'updated_at' => $timestamp, 'id' => $localizationId]
        ) !== 1) {
            throw new CommercePersistenceException();
        }

        return $newPath;
    }

    /** @return list<string> */
    private function categoryPathSegments(
        int $categoryId,
        string $locale,
        string $primaryLocale
    ): array {
        $segments = [];
        $seen = [];
        $cursor = $categoryId;
        for ($depth = 0; $depth < self::MAX_CATEGORY_DEPTH; ++$depth) {
            if (isset($seen[$cursor])) {
                throw new CommerceConflictException(CommerceConflictException::CATEGORY_CYCLE);
            }
            $seen[$cursor] = true;
            $category = $this->one(
                'SELECT id, parent_id FROM ' . $this->categories . ' WHERE id = :id'
                    . $this->forUpdate(),
                ['id' => $cursor]
            );
            if ($category === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            $requested = $this->one(
                'SELECT slug, translation_status FROM ' . $this->categoryLocalizations
                    . ' WHERE category_id = :category_id AND locale = :locale'
                    . $this->forUpdate(),
                ['category_id' => $cursor, 'locale' => $locale]
            );
            $source = $locale === $primaryLocale ? $requested : $this->one(
                'SELECT slug, translation_status FROM ' . $this->categoryLocalizations
                    . ' WHERE category_id = :category_id AND locale = :locale'
                    . $this->forUpdate(),
                ['category_id' => $cursor, 'locale' => $primaryLocale]
            );
            if ($requested === null || $source === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            $useSource = ($requested['translation_status'] ?? null)
                === CommerceTranslationStatus::FALLBACK->value;
            $slug = ($useSource ? $source : $requested)['slug'] ?? null;
            if (!is_string($slug)) {
                throw new CommercePersistenceException();
            }
            array_unshift($segments, CommerceInput::slug($slug));
            if (($category['parent_id'] ?? null) === null) {
                return $segments;
            }
            $cursor = $this->positiveInt($category['parent_id']);
        }

        throw new CommerceConflictException(CommerceConflictException::CATEGORY_CYCLE);
    }

    /** @return array<string, mixed>|null */
    private function productRow(string $publicId, bool $lock): ?array
    {
        return $this->one(
            'SELECT id, public_id, sku, editorial_status, availability_status, '
                . 'price_minor, currency, canonical_category_id, lock_version '
                . 'FROM ' . $this->products . ' WHERE public_id = :public_id'
                . ($lock ? $this->forUpdate() : ''),
            ['public_id' => $publicId]
        );
    }

    /** @return array<string, mixed>|null */
    private function categoryRow(string $publicId, bool $lock): ?array
    {
        return $this->one(
            'SELECT id, parent_id, lock_version FROM ' . $this->categories
                . ' WHERE public_id = :public_id' . ($lock ? $this->forUpdate() : ''),
            ['public_id' => $publicId]
        );
    }

    /** @return array<string, mixed>|null */
    private function attributeRow(string $publicId, bool $lock): ?array
    {
        return $this->one(
            'SELECT id, type, category_id FROM ' . $this->attributes
                . ' WHERE public_id = :public_id' . ($lock ? $this->forUpdate() : ''),
            ['public_id' => $publicId]
        );
    }

    /** @return array<string, mixed> */
    private function resolvedLocalizationRow(
        int $productId,
        string $requestedLocale,
        string $primaryLocale,
        bool $lock
    ): array {
        $requested = $this->one(
            'SELECT id, locale, title, slug, summary, description, seo_title, '
                . 'seo_description, translation_status, public_path '
                . 'FROM ' . $this->productLocalizations
                . ' WHERE product_id = :product_id AND locale = :locale'
                . ($lock ? $this->forUpdate() : ''),
            ['product_id' => $productId, 'locale' => $requestedLocale]
        );
        $source = $requestedLocale === $primaryLocale ? $requested : $this->one(
            'SELECT id, locale, title, slug, summary, description, seo_title, '
                . 'seo_description, translation_status, public_path '
                . 'FROM ' . $this->productLocalizations
                . ' WHERE product_id = :product_id AND locale = :locale'
                . ($lock ? $this->forUpdate() : ''),
            ['product_id' => $productId, 'locale' => $primaryLocale]
        );
        if ($requested === null || $source === null) {
            throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
        }
        $status = CommerceTranslationStatus::from(
            is_string($requested['translation_status'] ?? null)
                ? $requested['translation_status']
                : throw new CommercePersistenceException()
        );
        $resolved = $status === CommerceTranslationStatus::FALLBACK ? $source : $requested;
        foreach (['title', 'slug'] as $required) {
            if (!is_string($resolved[$required] ?? null)) {
                throw new CommercePersistenceException();
            }
        }

        return [
            'requested_id' => $requested['id'],
            'requested_locale' => $requestedLocale,
            'resolved_locale' => $resolved['locale'],
            'fallback' => $status === CommerceTranslationStatus::FALLBACK,
            'title' => $resolved['title'],
            'slug' => $resolved['slug'],
            'summary' => $resolved['summary'],
            'description' => $resolved['description'],
            'seo_title' => $resolved['seo_title'],
            'seo_description' => $resolved['seo_description'],
            'public_path' => $requested['public_path'],
        ];
    }

    /** @param array<string, mixed> $product */
    private function hydrateLocalizedProduct(
        array $product,
        string $requestedLocale,
        string $primaryLocale
    ): LocalizedProduct {
        try {
            $resolved = $this->resolvedLocalizationRow(
                $this->positiveInt($product['id'] ?? null),
                $requestedLocale,
                $primaryLocale,
                false
            );
            $priceMinor = $product['price_minor'] ?? null;
            $currency = $product['currency'] ?? null;
            if (($priceMinor === null) !== ($currency === null)) {
                throw new CommercePersistenceException();
            }
            $price = $priceMinor === null
                ? null
                : new Money(
                    $this->nonNegativeInt($priceMinor),
                    is_string($currency) ? $currency : throw new CommercePersistenceException()
                );

            return new LocalizedProduct(
                CommerceInput::uuid((string) ($product['public_id'] ?? '')),
                is_string($product['sku'] ?? null) ? $product['sku'] : null,
                $requestedLocale,
                CommerceInput::locale((string) ($resolved['resolved_locale'] ?? '')),
                ($resolved['fallback'] ?? false) === true,
                (string) ($resolved['title'] ?? ''),
                (string) ($resolved['slug'] ?? ''),
                is_string($resolved['summary'] ?? null) ? $resolved['summary'] : null,
                is_string($resolved['description'] ?? null) ? $resolved['description'] : null,
                is_string($resolved['seo_title'] ?? null) ? $resolved['seo_title'] : null,
                is_string($resolved['seo_description'] ?? null) ? $resolved['seo_description'] : null,
                ProductEditorialStatus::from((string) ($product['editorial_status'] ?? '')),
                ProductAvailabilityStatus::from((string) ($product['availability_status'] ?? '')),
                $price,
                is_string($resolved['public_path'] ?? null) ? $resolved['public_path'] : null,
                $this->positiveInt($product['lock_version'] ?? null)
            );
        } catch (CommercePersistenceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CommercePersistenceException();
        }
    }

    private function insertProductLocalization(
        int $productId,
        ProductLocalizationDraft $draft,
        string $timestamp
    ): void {
        $this->write(
            'INSERT INTO ' . $this->productLocalizations . ' ('
                . 'product_id, locale, title, slug, summary, description, seo_title, '
                . 'seo_description, translation_status, public_path, lock_version, '
                . 'created_at, updated_at) VALUES ('
                . ':product_id, :locale, :title, :slug, :summary, :description, '
                . ':seo_title, :seo_description, :status, NULL, 1, :created_at, :updated_at)',
            [
                'product_id' => $productId,
                'locale' => $draft->locale(),
                'title' => $draft->title(),
                'slug' => $draft->slug(),
                'summary' => $draft->summary(),
                'description' => $draft->description(),
                'seo_title' => $draft->seoTitle(),
                'seo_description' => $draft->seoDescription(),
                'status' => $draft->translationStatus()->value,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );
    }

    private function insertTagLocalization(
        int $tagId,
        TagLocalizationDraft $draft,
        string $timestamp
    ): void {
        $this->write(
            'INSERT INTO ' . $this->tagLocalizations . ' ('
                . 'tag_id, locale, name, slug, translation_status, created_at, updated_at) '
                . 'VALUES (:tag_id, :locale, :name, :slug, :status, :created_at, :updated_at)',
            [
                'tag_id' => $tagId,
                'locale' => $draft->locale(),
                'name' => $draft->name(),
                'slug' => $draft->slug(),
                'status' => $draft->translationStatus()->value,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );
    }

    private function touchProduct(int $productId, int $expected, string $timestamp): void
    {
        if ($this->write(
            'UPDATE ' . $this->products . ' SET lock_version = lock_version + 1, '
                . 'updated_at = :updated_at WHERE id = :id AND lock_version = :expected',
            ['updated_at' => $timestamp, 'id' => $productId, 'expected' => $expected]
        ) !== 1) {
            throw new CommerceConflictException(CommerceConflictException::STALE);
        }
    }

    private function touchCategory(int $categoryId, int $expected, string $timestamp): void
    {
        if ($this->write(
            'UPDATE ' . $this->categories . ' SET lock_version = lock_version + 1, '
                . 'updated_at = :updated_at WHERE id = :id AND lock_version = :expected',
            ['updated_at' => $timestamp, 'id' => $categoryId, 'expected' => $expected]
        ) !== 1) {
            throw new CommerceConflictException(CommerceConflictException::STALE);
        }
    }

    private function throwMissingOrStaleProduct(string $publicId): never
    {
        throw new CommerceConflictException(
            $this->productRow($publicId, false) === null
                ? CommerceConflictException::NOT_FOUND
                : CommerceConflictException::STALE
        );
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function uniquePublicIds(array $ids, int $max, bool $allowEmpty = false): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new CommerceValidationException('Invalid public identifier list.');
            }
            $normalized[CommerceInput::uuid($id)] = true;
        }
        if ((!$allowEmpty && $normalized === []) || count($normalized) > $max) {
            throw new CommerceValidationException('Invalid public identifier count.');
        }

        return array_keys($normalized);
    }

    /** @return array<string, mixed> */
    private function attributeValueParameters(
        int $productId,
        int $attributeId,
        AttributeValueDraft $value,
        string $timestamp,
        bool $includeCreated
    ): array {
        $parameters = [
            'product_id' => $productId,
            'attribute_id' => $attributeId,
            'locale' => $value->storageLocale(),
            'text_value' => $value->textValue(),
            'number_value' => $value->numberValue(),
            'boolean_value' => $value->booleanValue() === null
                ? null
                : ($value->booleanValue() ? 1 : 0),
            'date_value' => $value->dateValue(),
            'updated_at' => $timestamp,
        ];
        if ($includeCreated) {
            $parameters['created_at'] = $timestamp;
        }

        return $parameters;
    }
}
