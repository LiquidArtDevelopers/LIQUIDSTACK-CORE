<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Analytics\BlogAnalyticsPageGrantCodec;
use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\BlogCategoryPublicProjectionService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogAnalyticsConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use App\Core\Blog\PublicDelivery\BlogPublicMediaDelivery;
use App\Core\Blog\PublicDelivery\BlogPublicMediaFile;
use App\Core\Blog\PublicDelivery\BlogUnavailableImageResolver;
use App\Core\Blog\PublicFeed\BlogPublicCatalogRepositoryInterface;
use App\Core\Blog\PublicFeed\BlogPublicCardMediaRepositoryInterface;
use App\Core\Blog\PublicFeed\BlogPublicDiscoveryRepositoryInterface;
use App\Core\Blog\PublicFeed\BlogPublicFeed;
use App\Core\Blog\PublicShell\BlogPublicShellDefaultSecurityPolicy;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityPolicyInterface;
use App\Core\Blog\Seo\BlogUrlHistoryRepositoryInterface;
use App\Core\Blog\Seo\BlogUrlResolution;
use App\Core\Blog\Seo\BlogPublicRobotsPolicy;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredContentRepositoryInterface;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredDocumentRecord;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\WebAdmin\Profile\PdoWebAdminProfileRepository;
use App\Core\WebAdmin\Profile\WebAdminPublicProfile;

final class BlogPublicHttpRuntime
{
    private ?BlogPublicFeed $publicFeed = null;

    public function __construct(
        private readonly BlogConfig $config,
        private readonly BlogPublicOrigin $origin,
        private readonly BlogService $service,
        private readonly ?BlogStructuredContentRepositoryInterface
            $structuredContent = null,
        private readonly ?BlogPublicMediaDelivery $mediaDelivery = null,
        private readonly ?BlogCategoryPublicProjectionService
            $categoryProjection = null,
        private readonly ?BlogPublicCatalogRepositoryInterface
            $catalogRepository = null,
        private readonly bool $analyticsCollectionReady = false,
        private readonly ?BlogAnalyticsPageGrantCodec $analyticsPageGrants =
            null,
        private readonly ?BlogUrlHistoryRepositoryInterface $urlHistory = null,
        private readonly ?PdoWebAdminProfileRepository $profiles = null,
        private readonly BlogPublicRobotsPolicy $robotsPolicy =
            new BlogPublicRobotsPolicy(),
        private readonly ?BlogPublicCardMediaRepositoryInterface
            $cardMediaRepository = null,
        private readonly BlogPublicShellSecurityPolicyInterface
            $publicShellSecurityPolicy =
                new BlogPublicShellDefaultSecurityPolicy()
    ) {
    }

    public function authorProfile(string $userPublicId): ?WebAdminPublicProfile
    {
        return $this->profiles?->liveByPublicId($userPublicId);
    }

    public function config(): BlogConfig
    {
        return $this->config;
    }

    public function origin(): BlogPublicOrigin
    {
        return $this->origin;
    }

    public function service(): BlogService
    {
        return $this->service;
    }

    public function robotsPolicy(): BlogPublicRobotsPolicy
    {
        return $this->robotsPolicy;
    }

    public function publicShellSecurityPolicy():
        BlogPublicShellSecurityPolicyInterface
    {
        return $this->publicShellSecurityPolicy;
    }

    public function urlResolution(
        string $locale,
        string $slug
    ): ?BlogUrlResolution {
        return $this->urlHistory?->resolve($locale, $slug);
    }

    public function categoryProjection(): ?BlogCategoryPublicProjectionService
    {
        return $this->categoryProjection;
    }

    public function catalogRepository(): ?BlogPublicCatalogRepositoryInterface
    {
        return $this->catalogRepository;
    }

    public function publicAnalyticsConfig(): ?BlogAnalyticsConfig
    {
        return $this->analyticsCollectionReady
            && $this->analyticsPageGrants !== null
            ? $this->config->analytics()
            : null;
    }

    public function analyticsPageGrant(
        string $localizationPublicId,
        string $canonicalPath
    ): ?string {
        if (
            !$this->analyticsCollectionReady
            || $this->analyticsPageGrants === null
        ) {
            return null;
        }

        try {
            return $this->analyticsPageGrants->issue(
                $localizationPublicId,
                $canonicalPath
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reuses this runtime's service and read adapters; it never opens a
     * second database connection.
     */
    public function publicFeed(): BlogPublicFeed
    {
        $discoveryRepository = $this->catalogRepository instanceof
            BlogPublicDiscoveryRepositoryInterface
                ? $this->catalogRepository
                : null;

        return $this->publicFeed ??= new BlogPublicFeed(
            $this->config,
            $this->service,
            $this->categoryProjection,
            $this->catalogRepository,
            $discoveryRepository,
            $this->cardMediaRepository
        );
    }

    public function structuredDocument(
        string $localizationPublicId
    ): ?BlogStructuredDocumentRecord {
        return $this->structuredContent?->current($localizationPublicId);
    }

    public function imageResolver(
        string $localizationPublicId
    ): BlogImageResolverInterface {
        return $this->mediaDelivery?->imageResolver($localizationPublicId)
            ?? new BlogUnavailableImageResolver();
    }

    public function mediaFile(
        string $mediaAssetPublicId,
        int $width,
        bool $metadataOnly
    ): ?BlogPublicMediaFile {
        return $this->mediaDelivery?->file(
            $mediaAssetPublicId,
            $width,
            $metadataOnly
        );
    }

    /** @return array<string, string|bool> */
    public function __debugInfo(): array
    {
        return [
            'config' => '[redacted]',
            'origin' => '[redacted]',
            'structured_content' => $this->structuredContent !== null,
            'public_media' => $this->mediaDelivery !== null,
            'category_projection' => $this->categoryProjection !== null,
            'catalog_repository' => $this->catalogRepository !== null,
            'card_media' => $this->cardMediaRepository !== null,
            'public_feed' => $this->publicFeed !== null,
            'analytics_collection' => $this->analyticsCollectionReady,
            'analytics_page_grants' => $this->analyticsPageGrants !== null,
            'url_history' => $this->urlHistory !== null,
            'live_author_profiles' => $this->profiles !== null,
            'public_shell_security' => '[redacted]',
        ];
    }
}
