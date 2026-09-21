<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\Admin\CommerceAdminReadRepository;
use App\Core\Commerce\CommerceCatalogService;
use App\Core\Commerce\Configuration\CommerceConfig;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use DateTimeImmutable;

interface CommerceAdminHttpRuntimeInterface
{
    /** @return list<string> */
    public function languages(): array;

    public function commerceConfig(): CommerceConfig;

    public function webAdminConfig(): WebAdminConfig;

    public function catalog(): CommerceCatalogService;

    public function reads(): CommerceAdminReadRepository;

    public function authentication(): WebAdminAuthenticationService;

    public function authorization(): WebAdminAuthorizationService;

    public function navigation(): WebAdminNavigationCatalog;

    public function now(): DateTimeImmutable;

    public function mediaReady(): bool;

    /**
     * @param list<string> $galleryPublicIds
     * @param array<string, string> $alternativeTexts
     * @param array<string, string> $captions
     */
    public function replaceProductMedia(
        string $productPublicId,
        ?string $coverPublicId,
        array $galleryPublicIds,
        int $expectedLockVersion,
        string $sourceLocale,
        array $alternativeTexts,
        array $captions,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken
    ): bool;

    public function saveProductMediaLocalization(
        string $productPublicId,
        string $mediaPublicId,
        string $locale,
        string $alternativeText,
        ?string $caption,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken
    ): bool;

    public function setCategorySortOrder(
        string $categoryPublicId,
        int $sortOrder,
        int $expectedLockVersion,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken
    ): bool;

    public function authorizeMutation(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $capability
    ): bool;
}
