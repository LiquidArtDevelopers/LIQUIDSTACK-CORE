<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\BasketSnapshot;
use App\Core\Commerce\CommerceBasketService;
use App\Core\Commerce\CommercePublicCatalogPage;
use App\Core\Commerce\CommercePublicCatalogQuery;
use App\Core\Commerce\CommercePublicMediaReference;
use App\Core\Commerce\CommercePublicProduct;
use App\Core\Commerce\CommercePublicTaxonomyTerm;
use App\Core\Commerce\Configuration\CommerceConfig;
use App\Core\Commerce\Media\CommercePublicMediaDelivery;
use App\Core\Commerce\Media\CommercePublicMediaFile;
use App\Core\Commerce\Persistence\CommerceCatalogRepositoryInterface;
use DateTimeImmutable;

final class CommercePublicHttpRuntime
{
    public function __construct(
        private readonly CommerceConfig $config,
        private readonly CommerceCatalogRepositoryInterface $catalog,
        private readonly string $origin,
        private readonly ?CommercePublicMediaDelivery $mediaDelivery = null,
        private readonly ?CommerceBasketService $baskets = null,
        private readonly ?CommerceBasketCookie $basketCookie = null
    ) {
    }

    public function config(): CommerceConfig
    {
        return $this->config;
    }

    public function catalog(): CommerceCatalogRepositoryInterface
    {
        return $this->catalog;
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function basketCookieName(): ?string
    {
        return $this->basketCookie?->name();
    }

    public function basket(
        ?string $token,
        DateTimeImmutable $now
    ): ?BasketSnapshot {
        if (
            $this->baskets === null
            || !is_string($token)
            || $token === ''
        ) {
            return null;
        }

        return $this->baskets->view($token, $now);
    }

    public function catalogPage(
        string $locale,
        CommercePublicCatalogQuery $query
    ): CommercePublicCatalogPage {
        return $this->catalog->searchPublished(
            $locale,
            $this->config->defaultLocale(),
            $query
        );
    }

    public function product(
        string $productPublicId,
        string $locale
    ): ?CommercePublicProduct {
        return $this->catalog->publicProduct(
            $productPublicId,
            $locale,
            $this->config->defaultLocale()
        );
    }

    /** @return list<CommercePublicTaxonomyTerm> */
    public function taxonomyTerms(string $locale, string $kind): array
    {
        return $this->catalog->publicTaxonomyTerms(
            $locale,
            $this->config->defaultLocale(),
            $kind
        );
    }

    public function mediaUrl(
        CommercePublicMediaReference $media,
        int $width
    ): string {
        foreach ($media->variants() as $variant) {
            if ($variant['width'] === $width) {
                return $this->absoluteUrl($variant['path']);
            }
        }

        throw new CommercePublicHttpRuntimeException(
            'commerce.public_media_variant_unavailable'
        );
    }

    public function mediaFile(
        string $mediaAssetPublicId,
        int $width,
        bool $metadataOnly
    ): ?CommercePublicMediaFile {
        return $this->mediaDelivery?->file(
            $mediaAssetPublicId,
            $width,
            $metadataOnly
        );
    }

    public function absoluteUrl(string $path): string
    {
        if (
            !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '?')
            || str_contains($path, '#')
        ) {
            throw new CommercePublicHttpRuntimeException(
                'commerce.public_path_invalid'
            );
        }

        return rtrim($this->origin, '/') . $path;
    }
}
