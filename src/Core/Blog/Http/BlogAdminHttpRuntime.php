<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Security\OpaqueSecret;
use Closure;
use PDO;
use Throwable;

final class BlogAdminHttpRuntime implements BlogAdminHttpRuntimeInterface
{
    /**
     * @param list<string> $languages
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $languages,
        private readonly BlogConfig $blogConfig,
        private readonly WebAdminConfig $webAdminConfig,
        private readonly BlogService $service,
        private readonly WebAdminAuthenticationService $authentication,
        private readonly WebAdminAuthorizationService $authorization,
        private readonly PDO $pdo,
        private readonly WebAdminMutationActorGate $actorGate
    ) {
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /** @return list<string> */
    public function languages(): array
    {
        return $this->languages;
    }

    public function blogConfig(): BlogConfig
    {
        return $this->blogConfig;
    }

    public function webAdminConfig(): WebAdminConfig
    {
        return $this->webAdminConfig;
    }

    public function service(): BlogService
    {
        return $this->service;
    }

    public function authentication(): WebAdminAuthenticationService
    {
        return $this->authentication;
    }

    public function authorization(): WebAdminAuthorizationService
    {
        return $this->authorization;
    }

    /** @return Closure(PDO): string */
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

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'project_root' => $this->projectRoot,
            'languages' => $this->languages,
            'blog_config' => $this->blogConfig->toSafeArray(),
            'webadmin_config' => $this->webAdminConfig->toSafeArray(),
        ];
    }
}
