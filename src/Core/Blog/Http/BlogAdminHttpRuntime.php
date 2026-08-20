<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Analytics\BlogAnalyticsReportInterface;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\EditorPreferences\BlogEditorPreferencesService;
use App\Core\Blog\Seo\BlogSeoAnalysisService;
use App\Core\Blog\Seo\BlogSeoCatalogProjectionService;
use App\Core\Blog\Seo\BlogSeoHttpRuntimeInterface;
use App\Core\Blog\StructuredContent\Categories\BlogEditorCategoryCatalogInterface;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredEditorService;
use App\Core\Blog\StructuredContent\Media\BlogEditorMediaCatalogInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\Tags\BlogTagService;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Security\OpaqueSecret;
use App\Core\WebAdmin\Profile\PdoWebAdminProfileRepository;
use App\Core\WebAdmin\Profile\WebAdminPublicProfile;
use Closure;
use PDO;
use Throwable;

class BlogAdminHttpRuntime implements
    BlogAdminHttpRuntimeInterface,
    BlogStructuredLayoutEditorHttpRuntimeInterface,
    BlogStructuredEditorCategoryHttpRuntimeInterface,
    BlogSeoHttpRuntimeInterface,
    BlogSeoCatalogHttpRuntimeInterface,
    BlogEditorPreferencesHttpRuntimeInterface,
    BlogAdminProfileHttpRuntimeInterface,
    BlogTagAdminHttpRuntimeInterface
{
    private readonly WebAdminNavigationCatalog $navigation;

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
        private readonly ?BlogSeoAnalysisService $seoAnalysis = null,
        ?WebAdminNavigationCatalog $navigation = null,
        private readonly ?BlogEditorCategoryCatalogInterface
            $editorCategoryCatalog = null,
        protected readonly ?BlogAnalyticsReportInterface
            $optionalAnalyticsReport = null,
        private readonly bool $layoutEditorReady = false,
        private readonly ?BlogEditorPreferencesService
            $optionalEditorPreferences = null,
        private readonly bool $privateDraftPublicationReady = false,
        private readonly ?PdoWebAdminProfileRepository $profiles = null,
        private readonly ?BlogTagService $optionalTagService = null,
        private readonly ?BlogSeoCatalogProjectionService
            $optionalSeoCatalog = null
    ) {
        $this->navigation = $navigation ?? new WebAdminNavigationCatalog();
    }

    public function profileForSession(string $sessionToken): ?WebAdminPublicProfile
    {
        $session = $this->authentication->resolveAuthenticatedSession(
            $sessionToken
        );
        if ($session === null || $this->profiles === null) {
            return null;
        }
        return $this->profiles->liveByPublicId($session->userPublicId());
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

    public function tagService(): ?BlogTagService
    {
        return $this->optionalTagService;
    }

    public function tagsReady(): bool
    {
        return $this->optionalTagService !== null;
    }

    public function authentication(): WebAdminAuthenticationService
    {
        return $this->authentication;
    }

    public function authorization(): WebAdminAuthorizationService
    {
        return $this->authorization;
    }

    public function navigation(): WebAdminNavigationCatalog
    {
        return $this->navigation;
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

    public function layoutEditorReady(): bool
    {
        return $this->layoutEditorReady;
    }

    public function editorPreferencesReady(): bool
    {
        return $this->optionalEditorPreferences !== null;
    }

    public function editorPreferences(): BlogEditorPreferencesService
    {
        return $this->optionalEditorPreferences
            ?? throw new BlogAdminHttpRuntimeException(
                'blog.editor_preferences_unavailable'
            );
    }

    public function privateDraftPublicationReady(): bool
    {
        return $this->privateDraftPublicationReady;
    }

    public function editorCategoryCatalog(): ?BlogEditorCategoryCatalogInterface
    {
        return $this->editorCategoryCatalog;
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

    public function seoCatalog(): BlogSeoCatalogProjectionService
    {
        return $this->optionalSeoCatalog
            ?? throw new BlogAdminHttpRuntimeException(
                'blog.seo_catalog_unavailable'
            );
    }

    protected function configuredAnalyticsReport(): ?BlogAnalyticsReportInterface
    {
        return $this->optionalAnalyticsReport;
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
            'layout_editor_ready' => $this->layoutEditorReady,
            'editor_preferences_ready' =>
                $this->optionalEditorPreferences !== null,
            'private_draft_publication_ready' =>
                $this->privateDraftPublicationReady,
            'tags_ready' => $this->optionalTagService !== null,
        ];
    }
}
