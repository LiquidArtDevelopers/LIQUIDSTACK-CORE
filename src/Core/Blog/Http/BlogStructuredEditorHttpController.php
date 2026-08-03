<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogLegacyDocumentFactory;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Media\BlogEditorReferencedMediaCatalogInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorCategoryOption;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorMediaOption;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorRevisionSummary;
use App\Core\Blog\StructuredContent\Rendering\BlogRenderingException;
use App\Core\Blog\StructuredContent\Rendering\BlogStructuredEditorHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogStructuredPrivateHtmlRenderer;
use App\Core\Blog\Seo\BlogSeoAnalysis;
use App\Core\Blog\Seo\BlogSeoAnalysisService;
use App\Core\Blog\Seo\BlogSeoAnalyzer;
use App\Core\Blog\Seo\BlogSeoHttpRuntimeInterface;
use App\Core\Blog\Seo\BlogSeoStaticPageInventory;
use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Media\MediaService;
use App\Core\WebAdmin\Http\WebAdminPageAssets;
use App\Core\WebAdmin\Http\WebAdminShellContextFactory;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Security\ConstantTime;
use Throwable;

/** HTTP boundary for structured editing, previews and immutable revisions. */
final class BlogStructuredEditorHttpController
{
    private readonly BlogStructuredEditorHttpResponseFactory $responses;
    private readonly BlogStructuredPrivateHtmlRenderer $privateRenderer;
    private readonly WebAdminShellContextFactory $shells;

    /** @var array<string, mixed> */
    private readonly array $environment;

    public function __construct(
        private readonly BlogStructuredEditorHttpRuntimeInterface $runtime,
        private readonly BlogStructuredEditorRequestPolicy $requestPolicy =
            new BlogStructuredEditorRequestPolicy(),
        private readonly BlogStructuredEditorHtmlRenderer $editorRenderer =
            new BlogStructuredEditorHtmlRenderer(),
        private readonly BlogLegacyDocumentFactory $legacyFactory =
            new BlogLegacyDocumentFactory(),
        private readonly BlogDocumentCodec $codec = new BlogDocumentCodec(),
        ?BlogStructuredPrivateHtmlRenderer $privateRenderer = null,
        ?BlogStructuredEditorHttpResponseFactory $responses = null,
        private readonly PrivateRouteTransportPolicy $transportPolicy =
            new PrivateRouteTransportPolicy(),
        #[\SensitiveParameter] array $environment = []
    ) {
        $this->privateRenderer = $privateRenderer
            ?? new BlogStructuredPrivateHtmlRenderer(
                new BlogDocumentHtmlRenderer(
                    $runtime->editorImageResolver()
                )
            );
        $this->responses = $responses
            ?? new BlogStructuredEditorHttpResponseFactory(
                $runtime->webAdminConfig()
            );
        $this->shells = new WebAdminShellContextFactory(
            $runtime->webAdminConfig()->basePath(),
            $runtime->authorization(),
            method_exists($runtime, 'navigation')
                ? $runtime->navigation()
                : new WebAdminNavigationCatalog()
        );
        $this->environment = $environment;
    }

    public function edit(Request $request): Response
    {
        if (!$this->acceptsTransport($request)
            || !$this->requestPolicy->acceptsEditor($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext($request, [
            BlogAdminHttpController::EDIT_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
        ]);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $html = $this->editorPage(
                (string) $request->query('post'),
                (string) $request->query('locale'),
                $context['csrf'],
                $context['session']
            );

            return $this->responses->html(
                200,
                $request->method() === 'HEAD' ? '' : $html
            );
        } catch (BlogException|BlogStructuredContentException $exception) {
            return $this->domainFailure($exception);
        } catch (Throwable) {
            return $this->responses->plain(503, 'Service unavailable');
        }
    }

    public function save(Request $request): Response
    {
        if (!$this->acceptsTransport($request)
            || !$this->requestPolicy->acceptsSave($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext($request, [
            BlogAdminHttpController::EDIT_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
        ], true);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $draft = new BlogStructuredDraft(
                (string) $request->form('h1'),
                $this->codec->decode(
                    (string) $request->form('document_json')
                ),
                $this->nullableForm($request, 'slug'),
                $this->nullableForm($request, 'seo_title'),
                $this->nullableForm($request, 'meta_description'),
                $this->nullableForm($request, 'excerpt')
            );
            $this->runtime->structuredEditor()->save(
                $this->runtime->mutationGateAll(
                    $context['session'],
                    (string) $request->form('csrf'),
                    [
                        BlogAdminHttpController::EDIT_CAPABILITY,
                        MediaService::VIEW_CAPABILITY,
                    ]
                ),
                (string) $request->form('post'),
                (string) $request->form('locale'),
                (int) $request->form('lock_version'),
                $draft
            );

            return $this->editorRedirect(
                (string) $request->form('post'),
                (string) $request->form('locale')
            );
        } catch (BlogDocumentException) {
            return $this->responses->plain(422, 'Unprocessable content');
        } catch (BlogException|BlogStructuredContentException $exception) {
            return $this->domainFailure($exception);
        } catch (Throwable) {
            return $this->responses->plain(503, 'Service unavailable');
        }
    }

    public function seoAnalysis(Request $request): Response
    {
        if (!$this->acceptsTransport($request)
            || !$this->requestPolicy->acceptsSeoAnalysis($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext($request, [
            BlogAdminHttpController::EDIT_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
        ], true);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $draft = $this->draftFromRequest($request);
            $analysis = $this->analyze(
                $draft,
                (string) $request->form('post'),
                (string) $request->form('locale')
            );

            return $this->responses->json(200, $analysis->toArray());
        } catch (BlogDocumentException|BlogException) {
            return $this->responses->json(422, [
                'error' => 'unprocessable_content',
                'message' => 'No se puede analizar hasta completar los campos válidos.',
            ]);
        } catch (Throwable) {
            return $this->responses->json(503, [
                'error' => 'analysis_unavailable',
                'message' => 'El análisis no está disponible temporalmente.',
            ]);
        }
    }

    public function preview(Request $request): Response
    {
        if (!$this->acceptsTransport($request)
            || !$this->requestPolicy->acceptsPreview($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext($request, [
            BlogAdminHttpController::VIEW_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
        ]);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $state = $this->runtime->structuredEditor()->loadEditor(
                (string) $request->query('post'),
                (string) $request->query('locale')
            );
            $document = $state->current()?->snapshot()->document()
                ?? $this->legacyFactory->create(
                    $state->variant()->draft()->bodyText()
                );
            $html = $this->privateRenderer->preview(
                $this->basePath(),
                $state->variant(),
                $document
            );

            return $this->responses->html(
                200,
                $request->method() === 'HEAD' ? '' : $html
            );
        } catch (BlogException|BlogStructuredContentException $exception) {
            return $this->domainFailure($exception);
        } catch (BlogDocumentException) {
            return $this->responses->plain(422, 'Unprocessable content');
        } catch (BlogRenderingException|Throwable) {
            return $this->responses->plain(503, 'Service unavailable');
        }
    }

    public function revisions(Request $request): Response
    {
        if (!$this->acceptsTransport($request)
            || !$this->requestPolicy->acceptsRevisions($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext($request, [
            BlogAdminHttpController::VIEW_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
        ]);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $post = (string) $request->query('post');
            $locale = (string) $request->query('locale');
            $state = $this->runtime->structuredEditor()->loadEditor(
                $post,
                $locale
            );
            $shell = $this->shells->create(
                $context['session'],
                $context['csrf'],
                '/blog/editor',
                assets: new WebAdminPageAssets([
                    BlogStructuredEditorHtmlRenderer::STYLESHEET_PATH,
                ])
            );
            $revisionId = $request->query('revision');
            if (is_string($revisionId)) {
                $html = $this->privateRenderer->revision(
                    $this->basePath(),
                    $state->variant(),
                    $this->runtime->structuredEditor()->loadRevision(
                        $post,
                        $locale,
                        $revisionId
                    ),
                    $shell
                );
            } else {
                $html = $this->privateRenderer->revisions(
                    $this->basePath(),
                    $state->variant(),
                    $this->revisionOptions($post, $locale),
                    $shell
                );
            }

            return $this->responses->html(
                200,
                $request->method() === 'HEAD' ? '' : $html
            );
        } catch (BlogException|BlogStructuredContentException $exception) {
            return $this->domainFailure($exception);
        } catch (BlogRenderingException|Throwable) {
            return $this->responses->plain(503, 'Service unavailable');
        }
    }

    public function restore(Request $request): Response
    {
        if (!$this->acceptsTransport($request)
            || !$this->requestPolicy->acceptsRestore($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext($request, [
            BlogAdminHttpController::EDIT_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
        ], true);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $post = (string) $request->form('post');
            $locale = (string) $request->form('locale');
            $this->runtime->structuredEditor()->restore(
                $this->runtime->mutationGateAll(
                    $context['session'],
                    (string) $request->form('csrf'),
                    [
                        BlogAdminHttpController::EDIT_CAPABILITY,
                        MediaService::VIEW_CAPABILITY,
                    ]
                ),
                $post,
                $locale,
                (int) $request->form('lock_version'),
                (string) $request->form('revision')
            );

            return $this->editorRedirect($post, $locale);
        } catch (BlogException|BlogStructuredContentException $exception) {
            return $this->domainFailure($exception);
        } catch (Throwable) {
            return $this->responses->plain(503, 'Service unavailable');
        }
    }

    private function editorPage(
        string $postPublicId,
        string $locale,
        string $csrf,
        string $sessionToken
    ): string {
        $state = $this->runtime->structuredEditor()->loadEditor(
            $postPublicId,
            $locale
        );
        $snapshot = $state->current()?->snapshot();
        $document = $snapshot?->document()
            ?? $this->legacyFactory->create(
                $state->variant()->draft()->bodyText()
            );
        $canonicalJson = $snapshot?->canonicalJson()
            ?? $this->codec->encode($document);
        $analysisDraft = $snapshot ?? new BlogStructuredDraft(
            $state->variant()->draft()->h1(),
            $document,
            $state->variant()->draft()->slug(),
            $state->variant()->draft()->seoTitle(),
            $state->variant()->draft()->metaDescription(),
            $state->variant()->draft()->excerpt()
        );
        try {
            $analysis = $this->analyze(
                $analysisDraft,
                $postPublicId,
                $locale
            );
        } catch (Throwable) {
            // SEO is additive: its failure never takes the editor down.
            $analysis = null;
        }
        $categoryPresentation = $this->categoryPresentation(
            $sessionToken,
            $postPublicId,
            $locale
        );

        return $this->editorRenderer->render(
            $this->basePath(),
            $csrf,
            $state->variant(),
            $document,
            $canonicalJson,
            $this->mediaOptions($analysisDraft->mediaAssetPublicIds()),
            $this->revisionOptions($postPublicId, $locale),
            canPublish: $this->runtime->authorization()->hasCapability(
                $sessionToken,
                BlogAdminHttpController::PUBLISH_CAPABILITY
            ),
            canAssignCategories: $categoryPresentation['enabled'],
            seoAnalysis: $analysis,
            publicPath: $this->runtime->blogConfig()->publicPath($locale),
            shellFactory: $this->shells,
            sessionToken: $sessionToken,
            categoryOptions: $categoryPresentation['options']
        );
    }

    /**
     * @return array{
     *   enabled: bool,
     *   options: list<BlogEditorCategoryOption>
     * }
     */
    private function categoryPresentation(
        #[\SensitiveParameter] string $sessionToken,
        string $postPublicId,
        string $locale
    ): array {
        if (
            !$this->runtime->authorization()->hasCapability(
                $sessionToken,
                BlogCategoryAdminHttpController::EDIT_CAPABILITY
            )
            || !$this->runtime instanceof
                BlogStructuredEditorCategoryHttpRuntimeInterface
        ) {
            return ['enabled' => false, 'options' => []];
        }

        try {
            $catalog = $this->runtime->editorCategoryCatalog();
            if ($catalog === null) {
                return ['enabled' => false, 'options' => []];
            }

            return [
                'enabled' => true,
                'options' => $catalog->forPost($postPublicId, $locale),
            ];
        } catch (Throwable) {
            // Categories are additive: a failed projection keeps editing safe.
            return ['enabled' => false, 'options' => []];
        }
    }

    private function draftFromRequest(Request $request): BlogStructuredDraft
    {
        return new BlogStructuredDraft(
            (string) $request->form('h1'),
            $this->codec->decode((string) $request->form('document_json')),
            $this->nullableForm($request, 'slug'),
            $this->nullableForm($request, 'seo_title'),
            $this->nullableForm($request, 'meta_description'),
            $this->nullableForm($request, 'excerpt')
        );
    }

    private function analyze(
        BlogStructuredDraft $draft,
        string $postPublicId,
        string $locale
    ): BlogSeoAnalysis {
        $service = null;
        if ($this->runtime instanceof BlogSeoHttpRuntimeInterface) {
            try {
                $service = $this->runtime->seoAnalysis();
            } catch (Throwable) {
                // Compatibility runtimes keep the advisory panel available.
            }
        }
        $service ??= new BlogSeoAnalysisService(
                new BlogSeoAnalyzer(),
                null,
                new BlogSeoStaticPageInventory($this->runtime->projectRoot())
            );
        $publicPath = $this->runtime->blogConfig()->publicPath($locale);
        if ($publicPath === null) {
            throw new BlogException(BlogException::INVALID_INPUT);
        }

        return $service->analyze(
            $draft,
            $postPublicId,
            $locale,
            $publicPath
        );
    }

    /**
     * @param list<string> $requiredPublicIds
     * @return list<BlogEditorMediaOption>
     */
    private function mediaOptions(array $requiredPublicIds): array
    {
        $mediaFilePath = rtrim(
            $this->runtime->webAdminConfig()->basePath(),
            '/'
        ) . '/media/file';

        $catalog = $this->runtime->editorMediaCatalog();
        $assets = $catalog instanceof BlogEditorReferencedMediaCatalogInterface
            ? $catalog->recentIncluding(48, $requiredPublicIds)
            : $catalog->recent(48);

        return array_map(
            static function ($asset) use ($mediaFilePath): BlogEditorMediaOption {
                $thumbnailWidth = $asset->thumbnailWidth();
                $thumbnailUrl = $thumbnailWidth === null
                    ? null
                    : $mediaFilePath . '?' . http_build_query([
                        'asset' => $asset->publicId(),
                        'width' => (string) $thumbnailWidth,
                    ], '', '&', PHP_QUERY_RFC3986);

                return new BlogEditorMediaOption(
                    $asset->publicId(),
                    $asset->label(),
                    $thumbnailUrl
                );
            },
            $assets
        );
    }

    /** @return list<BlogEditorRevisionSummary> */
    private function revisionOptions(string $post, string $locale): array
    {
        return array_map(
            static fn ($summary): BlogEditorRevisionSummary =>
                new BlogEditorRevisionSummary(
                    $summary->revisionPublicId(),
                    $summary->revisionNumber(),
                    $summary->variantLockVersion(),
                    $summary->createdAt()
                ),
            $this->runtime->structuredEditor()->listRevisions(
                $post,
                $locale
            )
        );
    }

    /**
     * @param list<string> $capabilities
     * @return array{session: string, csrf: string}|Response
     */
    private function authorizedContext(
        Request $request,
        array $capabilities,
        bool $validateSubmittedCsrf = false
    ): array|Response {
        $sessionToken = $request->cookie(
            $this->runtime->webAdminConfig()->cookieName()
        );
        if ($sessionToken === null) {
            return $this->redirectToLogin();
        }
        $session = $this->runtime->authentication()
            ->resolveAuthenticatedSession($sessionToken);
        if ($session === null) {
            return $this->responses->expireSession($this->redirectToLogin());
        }
        if (!$this->runtime->authorization()->mayAccessWebAdmin($sessionToken)) {
            $this->runtime->authentication()->revokeSession($sessionToken);
            return $this->responses->expireSession($this->redirectToLogin());
        }
        foreach ($capabilities as $capability) {
            if (!$this->runtime->authorization()->hasCapability(
                $sessionToken,
                $capability
            )) {
                return $this->responses->plain(403, 'Forbidden');
            }
        }
        $csrf = $this->runtime->authentication()
            ->authenticatedCsrfToken($sessionToken);
        if ($csrf === null) {
            return $this->responses->expireSession($this->redirectToLogin());
        }
        $csrfToken = $csrf->csrfToken();
        if (
            $validateSubmittedCsrf
            && !ConstantTime::equals(
                $csrfToken,
                (string) $request->form('csrf')
            )
        ) {
            return $this->responses->plain(403, 'Forbidden');
        }

        return ['session' => $sessionToken, 'csrf' => $csrfToken];
    }

    private function nullableForm(Request $request, string $key): ?string
    {
        $value = (string) $request->form($key);

        return trim($value) === '' ? null : $value;
    }

    private function domainFailure(
        BlogException|BlogStructuredContentException $exception
    ): Response {
        $code = $exception->issueCode();

        return match ($code) {
            BlogException::ACTOR_GATE_FAILED =>
                $this->responses->plain(403, 'Forbidden'),
            BlogException::INVALID_INPUT,
            BlogException::PUBLISH_INCOMPLETE,
            BlogStructuredContentException::INVALID_INPUT,
            BlogStructuredContentException::MEDIA_NOT_FOUND =>
                $this->responses->plain(422, 'Unprocessable content'),
            BlogException::LOCALE_CONFLICT,
            BlogException::SLUG_CONFLICT,
            BlogException::LOCK_CONFLICT,
            BlogException::INVALID_STATE,
            BlogStructuredContentException::PLAIN_SAVE_BLOCKED =>
                $this->responses->plain(409, 'Conflict'),
            BlogException::POST_NOT_FOUND,
            BlogException::VARIANT_NOT_FOUND,
            BlogStructuredContentException::REVISION_NOT_FOUND =>
                $this->responses->plain(404, 'Not found'),
            default => $this->responses->plain(503, 'Service unavailable'),
        };
    }

    private function acceptsTransport(Request $request): bool
    {
        return $this->transportPolicy->accepts(
            $request,
            $this->environment
        );
    }

    private function basePath(): string
    {
        return rtrim(
            $this->runtime->webAdminConfig()->basePath(),
            '/'
        ) . '/blog';
    }

    private function editorRedirect(string $post, string $locale): Response
    {
        return $this->responses->redirect(
            $this->basePath() . '/editor?'
                . http_build_query([
                    'post' => $post,
                    'locale' => $locale,
                ], '', '&', PHP_QUERY_RFC3986)
        );
    }

    private function redirectToLogin(): Response
    {
        return $this->responses->redirect(
            $this->runtime->webAdminConfig()->basePath() . '/login'
        );
    }
}
