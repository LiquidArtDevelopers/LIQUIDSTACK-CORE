<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\Admin\CommerceAdminReadRepository;
use App\Core\Commerce\Admin\CommerceAdminMediaRepository;
use App\Core\Commerce\Admin\CommerceAdminTaxonomyRepository;
use App\Core\Commerce\CommerceCatalogService;
use App\Core\Commerce\Configuration\CommerceConfig;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Support\ClockInterface;
use DateTimeImmutable;
use PDO;
use Throwable;

final class CommerceAdminHttpRuntime implements CommerceAdminHttpRuntimeInterface
{
    /** @param list<string> $languages */
    public function __construct(
        private readonly array $languages,
        private readonly CommerceConfig $commerceConfig,
        private readonly WebAdminConfig $webAdminConfig,
        private readonly CommerceCatalogService $catalog,
        private readonly CommerceAdminReadRepository $reads,
        private readonly WebAdminAuthenticationService $authentication,
        private readonly WebAdminAuthorizationService $authorization,
        private readonly WebAdminNavigationCatalog $navigation,
        private readonly PDO $pdo,
        private readonly WebAdminMutationActorGate $actorGate,
        private readonly ClockInterface $clock,
        private readonly ?CommerceAdminMediaRepository $media = null,
        private readonly ?CommerceAdminTaxonomyRepository $taxonomy = null
    ) {
    }

    public function languages(): array { return $this->languages; }
    public function commerceConfig(): CommerceConfig { return $this->commerceConfig; }
    public function webAdminConfig(): WebAdminConfig { return $this->webAdminConfig; }
    public function catalog(): CommerceCatalogService { return $this->catalog; }
    public function reads(): CommerceAdminReadRepository { return $this->reads; }
    public function authentication(): WebAdminAuthenticationService { return $this->authentication; }
    public function authorization(): WebAdminAuthorizationService { return $this->authorization; }
    public function navigation(): WebAdminNavigationCatalog { return $this->navigation; }
    public function now(): DateTimeImmutable { return $this->clock->now(); }
    public function mediaReady(): bool { return $this->media instanceof CommerceAdminMediaRepository; }

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
    ): bool {
        if (!$this->media instanceof CommerceAdminMediaRepository) {
            return false;
        }

        return $this->media->replaceProductMedia(
            $productPublicId,
            $coverPublicId,
            $galleryPublicIds,
            $expectedLockVersion,
            $sourceLocale,
            $this->languages,
            $alternativeTexts,
            $captions,
            $sessionToken,
            $csrfToken,
            $this->clock->now()
        );
    }

    public function saveProductMediaLocalization(
        string $productPublicId,
        string $mediaPublicId,
        string $locale,
        string $alternativeText,
        ?string $caption,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken
    ): bool {
        if (!$this->media instanceof CommerceAdminMediaRepository) {
            return false;
        }

        return $this->media->saveProductMediaLocalization(
            $productPublicId,
            $mediaPublicId,
            $locale,
            $this->commerceConfig->defaultLocale(),
            $alternativeText,
            $caption,
            $sessionToken,
            $csrfToken,
            $this->clock->now()
        );
    }

    public function setCategorySortOrder(
        string $categoryPublicId,
        int $sortOrder,
        int $expectedLockVersion,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken
    ): bool {
        if (!$this->taxonomy instanceof CommerceAdminTaxonomyRepository) {
            return false;
        }

        return $this->taxonomy->setCategorySortOrder(
            $categoryPublicId,
            $sortOrder,
            $expectedLockVersion,
            $sessionToken,
            $csrfToken,
            $this->clock->now()
        );
    }

    public function authorizeMutation(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $capability
    ): bool {
        $started = false;
        try {
            if ($this->pdo->inTransaction() || !$this->pdo->beginTransaction()) {
                return false;
            }
            $started = true;
            $actor = $this->actorGate->authorize(
                $sessionToken,
                $csrfToken,
                $capability
            );
            if ($actor === null || !$this->pdo->commit()) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                return false;
            }
            $started = false;

            return true;
        } catch (Throwable) {
            try {
                if ($started && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable) {
            }

            return false;
        }
    }
}
