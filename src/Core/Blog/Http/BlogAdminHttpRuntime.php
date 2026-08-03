<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Seo\BlogSeoAnalysisService;
use App\Core\Blog\Seo\BlogSeoHttpRuntimeInterface;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredEditorService;
use App\Core\Blog\StructuredContent\Media\BlogEditorMediaCatalogInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Security\OpaqueSecret;
use Closure;
use PDO;
use Throwable;

final class BlogAdminHttpRuntime implements
    BlogAdminHttpRuntimeInterface,
    BlogStructuredEditorHttpRuntimeInterface,
    BlogSeoHttpRuntimeInterface
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
        private readonly WebAdminMutationActorGate $actorGate,
        private readonly ?BlogStructuredEditorService
            $structuredEditor = null,
        private readonly ?BlogEditorMediaCatalogInterface
            $editorMediaCatalog = null,
        private readonly ?BlogImageResolverInterface
            $editorImageResolver = null,
        private readonly ?BlogSeoAnalysisService $seoAnalysis = null
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

    public function structuredEditor(): BlogStructuredEditorService
    {
        if ($this->structuredEditor === null) {
            throw new BlogAdminHttpRuntimeException(
                'blog.structured_editor_unavailable'
            );
        }

        return $this->structuredEditor;
    }

    public function editorMediaCatalog(): BlogEditorMediaCatalogInterface
    {
        if ($this->editorMediaCatalog === null) {
            throw new BlogAdminHttpRuntimeException(
                'blog.structured_editor_media_unavailable'
            );
        }

        return $this->editorMediaCatalog;
    }

    public function editorImageResolver(): BlogImageResolverInterface
    {
        if ($this->editorImageResolver === null) {
            throw new BlogAdminHttpRuntimeException(
                'blog.structured_editor_media_unavailable'
            );
        }

        return $this->editorImageResolver;
    }

    public function seoAnalysis(): BlogSeoAnalysisService
    {
        if ($this->seoAnalysis === null) {
            throw new BlogAdminHttpRuntimeException(
                'blog.seo_analysis_unavailable'
            );
        }

        return $this->seoAnalysis;
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

    public function mutationGateAll(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        array $capabilities
    ): Closure {
        if (
            !array_is_list($capabilities)
            || $capabilities === []
            || count($capabilities) > 16
        ) {
            throw new BlogException(BlogException::ACTOR_GATE_FAILED);
        }
        foreach ($capabilities as $capability) {
            if (
                !is_string($capability)
                || preg_match('/\A[a-z][a-z0-9_.-]{2,127}\z/', $capability)
                    !== 1
            ) {
                throw new BlogException(BlogException::ACTOR_GATE_FAILED);
            }
        }

        $session = OpaqueSecret::fromString($sessionToken);
        $csrf = OpaqueSecret::fromString($csrfToken);
        $expectedPdo = $this->pdo;
        $actorGate = $this->actorGate;

        return static function (PDO $pdo) use (
            $session,
            $csrf,
            $capabilities,
            $expectedPdo,
            $actorGate
        ): string {
            if ($pdo !== $expectedPdo) {
                throw new BlogException(BlogException::ACTOR_GATE_FAILED);
            }

            try {
                $actor = $actorGate->authorizeAll(
                    $session->reveal(),
                    $csrf->reveal(),
                    $capabilities
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
