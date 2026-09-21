<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\CommercePublicProduct;
use App\Core\Commerce\LocalizedProduct;

final class CommercePublicItemPage
{
    /**
     * @param array<string, string> $alternatePaths
     * @param list<string> $basketProductIds
     */
    public function __construct(
        private readonly CommercePublicProduct $detail,
        private readonly string $canonicalUrl,
        private readonly string $robotsDirective,
        private readonly array $alternatePaths,
        private readonly string $catalogPath,
        private readonly string $inquiryPath,
        private readonly ?int $inquiryCount,
        private readonly array $basketProductIds = []
    ) {
    }

    public function product(): LocalizedProduct
    {
        return $this->detail->product();
    }

    public function detail(): CommercePublicProduct
    {
        return $this->detail;
    }

    public function canonicalUrl(): string
    {
        return $this->canonicalUrl;
    }

    public function robotsDirective(): string
    {
        return $this->robotsDirective;
    }

    /** @return array<string, string> */
    public function alternatePaths(): array
    {
        return $this->alternatePaths;
    }

    public function catalogPath(): string
    {
        return $this->catalogPath;
    }

    public function inquiryPath(): string
    {
        return $this->inquiryPath;
    }

    public function inquiryCount(): ?int
    {
        return $this->inquiryCount;
    }

    /** @return list<string> */
    public function basketProductIds(): array
    {
        return $this->basketProductIds;
    }
}
