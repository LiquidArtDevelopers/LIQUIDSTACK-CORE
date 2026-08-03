<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Security\OpaqueSecret;
use Closure;
use PDO;
use Throwable;

final class BlogCategoryAdminHttpRuntime implements
    BlogCategoryAdminHttpRuntimeInterface
{
    private readonly WebAdminNavigationCatalog $navigation;

    /** @param list<string> $languages */
    public function __construct(
        private readonly array $languages,
        private readonly BlogConfig $blogConfig,
        private readonly WebAdminConfig $webAdminConfig,
        private readonly BlogService $blogService,
        private readonly BlogCategoryService $categoryService,
        private readonly WebAdminAuthenticationService $authentication,
        private readonly WebAdminAuthorizationService $authorization,
        private readonly PDO $pdo,
        private readonly WebAdminMutationActorGate $actorGate,
        ?WebAdminNavigationCatalog $navigation = null
    ) {
        $this->navigation = $navigation ?? new WebAdminNavigationCatalog();
    }

    public function languages(): array { return $this->languages; }
    public function blogConfig(): BlogConfig { return $this->blogConfig; }
    public function webAdminConfig(): WebAdminConfig { return $this->webAdminConfig; }
    public function blogService(): BlogService { return $this->blogService; }
    public function categoryService(): BlogCategoryService { return $this->categoryService; }
    public function authentication(): WebAdminAuthenticationService { return $this->authentication; }
    public function authorization(): WebAdminAuthorizationService { return $this->authorization; }
    public function navigation(): WebAdminNavigationCatalog { return $this->navigation; }

    public function mutationGate(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $capability
    ): Closure {
        $session = OpaqueSecret::fromString($sessionToken);
        $csrf = OpaqueSecret::fromString($csrfToken);
        $expectedPdo = $this->pdo;
        $actorGate = $this->actorGate;

        return static function (PDO $pdo) use (
            $session,
            $csrf,
            $capability,
            $expectedPdo,
            $actorGate
        ): string {
            if ($pdo !== $expectedPdo) {
                throw new BlogException(BlogException::ACTOR_GATE_FAILED);
            }
            try {
                $actor = $actorGate->authorize(
                    $session->reveal(),
                    $csrf->reveal(),
                    $capability
                );
                if ($actor === null) {
                    throw new BlogException(
                        BlogException::ACTOR_GATE_FAILED
                    );
                }

                return $actor->userPublicId();
            } catch (Throwable) {
                throw new BlogException(BlogException::ACTOR_GATE_FAILED);
            }
        };
    }

    public function __debugInfo(): array
    {
        return [
            'languages' => $this->languages,
            'blog_config' => $this->blogConfig->toSafeArray(),
            'webadmin_config' => $this->webAdminConfig->toSafeArray(),
        ];
    }
}
