<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\Routing\BlogPublicationRouteGuard;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentException;
use App\Core\Blog\StructuredContent\Document\BlogDocumentV2Projector;
use App\Core\Blog\StructuredContent\Document\BlogLegacyDocumentFactory;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Editing\BlogEditorSubmissionValidator;
use App\Core\Blog\StructuredContent\Editing\BlogEditorValidationIssue;
use App\Core\Blog\StructuredContent\Media\BlogEditorReferencedMediaCatalogInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogDocumentHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorPreviewSandboxPolicy;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorCategoryOption;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorTagOption;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorMediaOption;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorRevisionSummary;
use App\Core\Blog\StructuredContent\Rendering\BlogRenderingException;
use App\Core\Blog\StructuredContent\Rendering\BlogStructuredEditorHtmlRenderer;
use App\Core\Blog\StructuredContent\Rendering\BlogStructuredPrivateHtmlRenderer;
use App\Core\Blog\StructuredContent\Categories\BlogEditorReservedCategoryCatalogInterface;
use App\Core\Blog\Preview\BlogPreviewAssetAdapterLoader;
use App\Core\Blog\Preview\BlogPreviewAssetContext;
use App\Core\Blog\Preview\BlogPreviewAssetSet;
use App\Core\Blog\Seo\BlogSeoAnalysis;
use App\Core\Blog\Seo\BlogSeoAnalysisService;
use App\Core\Blog\Seo\BlogSeoAnalyzer;
use App\Core\Blog\Seo\BlogSeoHttpRuntimeInterface;
use App\Core\Blog\Seo\BlogSeoStaticPageInventory;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Blog\Tags\BlogTagCapabilities;
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
    private readonly BlogPublicationRouteGuard $publicationRouteGuard;
    private readonly BlogPreviewAssetAdapterLoader $previewAssetLoader;

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
        #[\SensitiveParameter] array $environment = [],
        private readonly BlogDocumentV2Projector $layoutProjector =
            new BlogDocumentV2Projector(),
        ?BlogPublicationRouteGuard $publicationRouteGuard = null,
        private readonly BlogEditorSubmissionValidator $submissionValidator =
            new BlogEditorSubmissionValidator(),
        ?BlogPreviewAssetAdapterLoader $previewAssetLoader = null
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
        $this->publicationRouteGuard = $publicationRouteGuard
            ?? new BlogPublicationRouteGuard();
        $this->previewAssetLoader = $previewAssetLoader
            ?? new BlogPreviewAssetAdapterLoader();
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
            $previewSandbox = BlogEditorPreviewSandboxPolicy::random();
            $html = $this->editorPage(
                (string) $request->query('post'),
                (string) $request->query('locale'),
                $context['csrf'],
                $context['session'],
                $previewSandbox,
                $this->editorStylesheets()
            );

            return $this->responses->editorHtml(
                200,
                $request->method() === 'HEAD' ? '' : $html,
                $previewSandbox
            );
        } catch (BlogException|BlogStructuredContentException $exception) {
            return $this->domainFailure($request, $exception);
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
            $issue = $this->submissionValidator->firstIssue(
                $request->formParams()
            );
            if ($issue !== null) {
                return $this->invalidDraftResponse($request, $issue);
            }
            $draft = new BlogStructuredDraft(
                (string) $request->form('h1'),
                $this->projectLayoutDocument(
                    $this->codec->decodeDraft(
                        (string) $request->form('document_json')
                    )
                ),
                $this->nullableForm($request, 'slug'),
                $this->nullableForm($request, 'seo_title'),
                $this->nullableForm($request, 'meta_description'),
                $this->nullableForm($request, 'excerpt'),
                robotsPreferences:
                    $this->robotsPreferencesFromRequest($request)
            );
            $stored = $this->runtime->structuredEditor()->save(
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

            if ($this->isAsyncEditorRequest($request)) {
                return $this->responses->json(200, [
                    'ok' => true,
                    'lock_version' => $stored->lockVersion(),
                    'document_sha256' => $draft->documentSha256(),
                    'document' => $draft->document()->toArray(),
                ]);
            }

            return $this->editorRedirect(
                (string) $request->form('post'),
                (string) $request->form('locale')
            );
        } catch (BlogDocumentException $exception) {
            return $this->invalidDraftResponse(
                $request,
                new BlogEditorValidationIssue(
                    'document',
                    'document_json',
                    $exception->issueCode() === BlogDocumentException::DOCUMENT_TOO_LARGE
                        ? 'bytes_exceeded'
                        : 'invalid_structure',
                    $exception->issueCode() === BlogDocumentException::DOCUMENT_TOO_LARGE
                        ? \App\Core\Blog\StructuredContent\Document\BlogDocument::MAX_JSON_BYTES
                        : null
                )
            );
        } catch (BlogException|BlogStructuredContentException $exception) {
            return $this->domainFailure($request, $exception);
        } catch (Throwable) {
            if ($this->isAsyncEditorRequest($request)) {
                return $this->responses->json(503, [
                    'ok' => false,
                    'error' => 'unavailable',
                ]);
            }

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

    public function publish(Request $request): Response
    {
        if (!$this->acceptsTransport($request)
            || !$this->requestPolicy->acceptsPublish($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext($request, [
            BlogAdminHttpController::EDIT_CAPABILITY,
            BlogAdminHttpController::PUBLISH_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
        ], true);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $post = (string) $request->form('post');
            $locale = (string) $request->form('locale');
            $stored = $this->runtime->structuredEditor()->publishSaved(
                $this->runtime->mutationGateAll(
                    $context['session'],
                    (string) $request->form('csrf'),
                    [
                        BlogAdminHttpController::EDIT_CAPABILITY,
                        BlogAdminHttpController::PUBLISH_CAPABILITY,
                        MediaService::VIEW_CAPABILITY,
                    ]
                ),
                $post,
                $locale,
                (int) $request->form('lock_version'),
                (int) $request->form('category_workspace_version'),
                function (string $guardLocale, string $slug): void {
                    $this->publicationRouteGuard->assertAvailable(
                        $this->runtime->projectRoot(),
                        $this->runtime->blogConfig(),
                        $guardLocale,
                        $slug
                    );
                },
                (int) $request->form('tag_workspace_version')
            );
            if ($this->isAsyncEditorRequest($request)) {
                return $this->responses->json(200, [
                    'ok' => true,
                    'status' => $stored->status(),
                    'lock_version' => $stored->lockVersion(),
                    'category_workspace_version' => 0,
                    'tag_workspace_version' => 0,
                ]);
            }

            return $this->editorRedirect($post, $locale);
        } catch (BlogException|BlogStructuredContentException $exception) {
            return $this->domainFailure($request, $exception);
        } catch (Throwable) {
            return $this->responses->plain(503, 'Service unavailable');
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
            $working = $state->workingSnapshot();
            $document = $working?->document()
                ?? $this->legacyFactory->create(
                    $state->variant()->draft()->bodyText()
                );
            $document = $this->projectLayoutDocument($document);
            $assets = $this->previewAssets();
            $styleNonce = base64_encode(random_bytes(18));
            $html = $this->privateRenderer->preview(
                $this->basePath(),
                $state->variant(),
                $document,
                $working?->compatibilityDraft(),
                $assets,
                $styleNonce
            );

            return $this->responses->previewHtml(
                200,
                $request->method() === 'HEAD' ? '' : $html,
                $state->variant()->locale(),
                $assets,
                $styleNonce
            );
        } catch (BlogDocumentException) {
            return $this->responses->plain(422, 'Unprocessable content');
        } catch (BlogException|BlogStructuredContentException $exception) {
            return $this->domainFailure($request, $exception);
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
                    $shell,
                    $context['csrf'],
                    $this->runtime->authorization()->hasCapability(
                        $context['session'],
                        BlogAdminHttpController::EDIT_CAPABILITY
                    )
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
            return $this->domainFailure($request, $exception);
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

        $post = (string) $request->form('post');
        $locale = (string) $request->form('locale');
        try {
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
            if ($exception->issueCode() !== BlogException::ACTOR_GATE_FAILED) {
                return $this->restoreFailureResponse(
                    $context,
                    $post,
                    $locale,
                    $exception
                );
            }
            return $this->domainFailure($request, $exception);
        } catch (Throwable) {
            return $this->responses->plain(503, 'Service unavailable');
        }
    }

    /** @param array{session: string, csrf: string} $context */
    private function restoreFailureResponse(
        #[\SensitiveParameter] array $context,
        string $post,
        string $locale,
        BlogException|BlogStructuredContentException $exception
    ): Response {
        $status = match ($exception->issueCode()) {
            BlogException::LOCK_CONFLICT,
            BlogException::INVALID_STATE,
            BlogStructuredContentException::PLAIN_SAVE_BLOCKED => 409,
            BlogException::POST_NOT_FOUND,
            BlogException::VARIANT_NOT_FOUND,
            BlogStructuredContentException::REVISION_NOT_FOUND => 404,
            BlogException::INVALID_INPUT,
            BlogStructuredContentException::INVALID_INPUT,
            BlogStructuredContentException::MEDIA_NOT_FOUND => 422,
            default => 503,
        };
        $shell = $this->shells->create(
            $context['session'],
            $context['csrf'],
            '/blog/editor',
            assets: new WebAdminPageAssets([
                BlogStructuredEditorHtmlRenderer::STYLESHEET_PATH,
            ])
        );

        return $this->responses->html(
            $status,
            $this->privateRenderer->restoreFailure(
                $this->basePath(),
                $post,
                $locale,
                $exception->issueCode(),
                $shell
            )
        );
    }

    private function editorPage(
        string $postPublicId,
        string $locale,
        string $csrf,
        string $sessionToken,
        BlogEditorPreviewSandboxPolicy $previewSandbox,
        array $editorStylesheets = []
    ): string {
        $state = $this->runtime->structuredEditor()->loadEditor(
            $postPublicId,
            $locale
        );
        $snapshot = $state->workingSnapshot();
        $document = $snapshot?->document()
            ?? $this->legacyFactory->create(
                $state->variant()->draft()->bodyText()
            );
        $document = $this->projectLayoutDocument($document);
        $canonicalJson = $this->codec->encode($document);
        $workingMetadata = $snapshot?->compatibilityDraft()
            ?? $state->variant()->draft();
        $analysisDraft = new BlogStructuredDraft(
            $workingMetadata->h1(),
            $document,
            $workingMetadata->slug(),
            $workingMetadata->seoTitle(),
            $workingMetadata->metaDescription(),
            $workingMetadata->excerpt(),
            robotsPreferences: $this->effectiveRobotsPreferences(
                $workingMetadata->robotsPreferences(),
                $postPublicId,
                $locale
            )
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
        $tagPresentation = $this->tagPresentation(
            $sessionToken,
            $postPublicId,
            $locale
        );
        $headingDefaults = $this->headingDefaults();

        return $this->editorRenderer->render(
            $this->basePath(),
            $csrf,
            $state->variant(),
            $document,
            $canonicalJson,
            $this->mediaOptions($analysisDraft->mediaAssetPublicIds()),
            [],
            canPublish: $this->runtime->authorization()->hasCapability(
                $sessionToken,
                BlogAdminHttpController::PUBLISH_CAPABILITY
            ),
            canAssignCategories: $categoryPresentation['enabled'],
            seoAnalysis: $analysis,
            publicPath: $this->runtime->blogConfig()->publicPath($locale),
            shellFactory: $this->shells,
            sessionToken: $sessionToken,
            categoryOptions: $categoryPresentation['options'],
            categoryWorkspaceVersion:
                $categoryPresentation['workspace_version'],
            layoutEditorReady: $this->layoutEditorReady(),
            headingDefaults: $headingDefaults,
            workingSnapshot: $analysisDraft,
            privateDraftPublicationReady:
                $this->privateDraftPublicationReady(),
            dummyCategoryAssigned:
                $categoryPresentation['dummy_assigned'],
            canUploadMedia: $this->runtime->authorization()->hasCapability(
                $sessionToken,
                MediaService::UPLOAD_CAPABILITY
            ),
            previewSandbox: $previewSandbox,
            editorStylesheets: $editorStylesheets,
            canAssignTags: $tagPresentation['enabled'],
            tagOptions: $tagPresentation['options'],
            tagWorkspaceVersion: $tagPresentation['workspace_version'],
            canViewTags: $tagPresentation['visible']
        );
    }

    private function privateDraftPublicationReady(): bool
    {
        return method_exists($this->runtime, 'privateDraftPublicationReady')
            && $this->runtime->privateDraftPublicationReady() === true;
    }

    /** @return array<string, array<string, string>> */
    private function headingDefaults(): array
    {
        if (
            !$this->runtime instanceof
                BlogEditorPreferencesHttpRuntimeInterface
            || !$this->runtime->editorPreferencesReady()
        ) {
            return [];
        }

        try {
            return $this->runtime
                ->editorPreferences()
                ->current()
                ->preferences()
                ->headingDefaults();
        } catch (Throwable) {
            // Global defaults are additive. Code-owned defaults keep the
            // editor available when this optional feature is unavailable.
            return [];
        }
    }

    /**
     * @return array{
     *   visible: bool,
     *   enabled: bool,
     *   options: list<BlogEditorCategoryOption>,
     *   workspace_version: int,
     *   dummy_assigned: bool
     * }
     */
    private function categoryPresentation(
        #[\SensitiveParameter] string $sessionToken,
        string $postPublicId,
        string $locale
    ): array {
        if (!$this->runtime instanceof
            BlogStructuredEditorCategoryHttpRuntimeInterface) {
            return [
                'enabled' => false,
                'options' => [],
                'workspace_version' => 0,
                'dummy_assigned' => false,
            ];
        }

        try {
            $catalog = $this->runtime->editorCategoryCatalog();
            if ($catalog === null) {
                return [
                    'enabled' => false,
                    'options' => [],
                    'workspace_version' => 0,
                    'dummy_assigned' => false,
                ];
            }

            $canAssign = $this->runtime->authorization()->hasCapability(
                $sessionToken,
                BlogCategoryAdminHttpController::EDIT_CAPABILITY
            );
            $dummyAssigned = $catalog instanceof
                BlogEditorReservedCategoryCatalogInterface
                && $catalog->reservedCategoryAssigned(
                    $postPublicId,
                    $locale
                );

            return [
                'enabled' => $canAssign,
                'options' => $canAssign
                    ? $catalog->forPost($postPublicId, $locale)
                    : [],
                'workspace_version' => $catalog->workspaceVersion(
                    $postPublicId
                ),
                'dummy_assigned' => $dummyAssigned,
            ];
        } catch (Throwable) {
            // Categories are additive: a failed projection keeps editing safe.
            return [
                'enabled' => false,
                'options' => [],
                'workspace_version' => 0,
                'dummy_assigned' => false,
            ];
        }
    }

    /**
     * @return array{
     *   visible: bool,
     *   enabled: bool,
     *   options: list<BlogEditorTagOption>,
     *   workspace_version: int
     * }
     */
    private function tagPresentation(
        #[\SensitiveParameter] string $sessionToken,
        string $postPublicId,
        string $locale
    ): array {
        $disabled = [
            'visible' => false,
            'enabled' => false,
            'options' => [],
            'workspace_version' => 0,
        ];
        if (
            !$this->runtime instanceof BlogTagAdminHttpRuntimeInterface
            || !$this->runtime->tagsReady()
        ) {
            return $disabled;
        }

        try {
            $service = $this->runtime->tagService();
            if ($service === null) {
                return $disabled;
            }
            $workspaceVersion = $service->workspaceVersion(
                $postPublicId,
                $locale
            );
            $canView = $this->runtime->authorization()->hasCapability(
                $sessionToken,
                BlogTagCapabilities::VIEW
            );
            if (!$canView) {
                return [
                    'visible' => false,
                    'enabled' => false,
                    'options' => [],
                    'workspace_version' => $workspaceVersion,
                ];
            }
            $canAssign = $this->runtime->authorization()->hasCapability(
                $sessionToken,
                BlogTagCapabilities::EDIT
            );
            $options = [];
            foreach ($service->assignedToVariant($postPublicId, $locale) as $tag) {
                $options[] = new BlogEditorTagOption(
                    $tag->name(),
                    $tag->slug()
                );
            }

            return [
                'visible' => true,
                'enabled' => $canAssign,
                'options' => $options,
                'workspace_version' => $workspaceVersion,
            ];
        } catch (Throwable) {
            // Tags are additive: their projection never takes down editing.
            return $disabled;
        }
    }

    private function draftFromRequest(Request $request): BlogStructuredDraft
    {
        return new BlogStructuredDraft(
            (string) $request->form('h1'),
            $this->projectLayoutDocument(
                $this->codec->decodeDraft(
                    (string) $request->form('document_json')
                )
            ),
            $this->nullableForm($request, 'slug'),
            $this->nullableForm($request, 'seo_title'),
            $this->nullableForm($request, 'meta_description'),
            $this->nullableForm($request, 'excerpt'),
            robotsPreferences:
                $this->robotsPreferencesFromRequest($request)
        );
    }

    private function projectLayoutDocument(BlogDocument $document): BlogDocument
    {
        if (!$this->layoutEditorReady()) {
            return $document;
        }

        return $this->layoutProjector->tryProject($document) ?? $document;
    }

    private function robotsPreferencesFromRequest(
        Request $request
    ): BlogRobotsPreferences {
        $index = $request->form('robots_index');
        $follow = $request->form('robots_follow');
        if ($index === null && $follow === null) {
            $preferences = $this->runtime
                ->structuredEditor()
                ->loadEditor(
                    (string) $request->form('post'),
                    (string) $request->form('locale')
                )
                ->robotsPreferences();
        } else {
            $preferences = new BlogRobotsPreferences(
                $index === '1',
                $follow === '1'
            );
        }

        return $this->effectiveRobotsPreferences(
            $preferences,
            (string) $request->form('post'),
            (string) $request->form('locale')
        );
    }

    private function effectiveRobotsPreferences(
        BlogRobotsPreferences $preferences,
        string $postPublicId,
        string $locale
    ): BlogRobotsPreferences {
        if (
            $this->runtime instanceof
                BlogStructuredEditorCategoryHttpRuntimeInterface
        ) {
            try {
                $catalog = $this->runtime->editorCategoryCatalog();
                if (
                    $catalog instanceof
                        BlogEditorReservedCategoryCatalogInterface
                    && $catalog->reservedCategoryAssigned(
                        $postPublicId,
                        $locale
                    )
                ) {
                    return BlogRobotsPreferences::noIndexNoFollow();
                }
            } catch (Throwable) {
                // Internal category state is security-sensitive: fail closed.
                return BlogRobotsPreferences::noIndexNoFollow();
            }
        }

        return $preferences;
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
            return $this->sessionExpiredResponse($request);
        }
        $session = $this->runtime->authentication()
            ->resolveAuthenticatedSession($sessionToken);
        if ($session === null) {
            return $this->sessionExpiredResponse($request, true);
        }
        if (!$this->runtime->authorization()->mayAccessWebAdmin($sessionToken)) {
            $this->runtime->authentication()->revokeSession($sessionToken);
            return $this->sessionExpiredResponse($request, true);
        }
        foreach ($capabilities as $capability) {
            if (!$this->runtime->authorization()->hasCapability(
                $sessionToken,
                $capability
            )) {
                return $this->forbiddenResponse($request);
            }
        }
        $csrf = $this->runtime->authentication()
            ->authenticatedCsrfToken($sessionToken);
        if ($csrf === null) {
            return $this->sessionExpiredResponse($request, true);
        }
        $csrfToken = $csrf->csrfToken();
        if (
            $validateSubmittedCsrf
            && !ConstantTime::equals(
                $csrfToken,
                (string) $request->form('csrf')
            )
        ) {
            if ($this->isAsyncEditorRequest($request)) {
                return $this->responses->json(409, [
                    'ok' => false,
                    'error' => 'csrf_stale',
                    'csrf' => $csrfToken,
                ]);
            }

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
        Request $request,
        BlogException|BlogStructuredContentException $exception
    ): Response {
        $code = $exception->issueCode();

        if ($this->isAsyncEditorRequest($request)) {
            return match ($code) {
                BlogException::ACTOR_GATE_FAILED =>
                    $this->responses->json(403, [
                        'ok' => false,
                        'error' => 'forbidden',
                    ]),
                BlogException::INVALID_INPUT,
                BlogStructuredContentException::INVALID_INPUT =>
                    $this->invalidDraftResponse(
                        $request,
                        new BlogEditorValidationIssue(
                            'document',
                            'document_json',
                            'invalid_structure'
                        )
                    ),
                BlogException::PUBLISH_INCOMPLETE =>
                    $this->invalidDraftResponse(
                        $request,
                        new BlogEditorValidationIssue(
                            'entry',
                            'publication',
                            'incomplete'
                        )
                    ),
                BlogStructuredContentException::MEDIA_NOT_FOUND =>
                    $this->invalidDraftResponse(
                        $request,
                        new BlogEditorValidationIssue(
                            'document',
                            'media',
                            'unavailable'
                        )
                    ),
                BlogException::LOCK_CONFLICT =>
                    $this->responses->json(409, [
                        'ok' => false,
                        'error' => 'lock_conflict',
                    ]),
                BlogException::LOCALE_CONFLICT,
                BlogException::SLUG_CONFLICT,
                BlogException::INVALID_STATE,
                BlogStructuredContentException::PLAIN_SAVE_BLOCKED =>
                    $this->responses->json(409, [
                        'ok' => false,
                        'error' => 'conflict',
                    ]),
                BlogException::POST_NOT_FOUND,
                BlogException::VARIANT_NOT_FOUND,
                BlogStructuredContentException::REVISION_NOT_FOUND =>
                    $this->responses->json(404, [
                        'ok' => false,
                        'error' => 'not_found',
                    ]),
                default => $this->responses->json(503, [
                    'ok' => false,
                    'error' => 'unavailable',
                ]),
            };
        }

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

    private function invalidDraftResponse(
        Request $request,
        BlogEditorValidationIssue $issue
    ): Response
    {
        if ($this->isAsyncEditorRequest($request)) {
            return $this->responses->json(422, [
                'ok' => false,
                'error' => 'invalid_draft',
                'issue' => $issue->toSafeArray(),
            ]);
        }

        return $this->responses->plain(422, 'Unprocessable content');
    }

    private function forbiddenResponse(Request $request): Response
    {
        if ($this->isAsyncEditorRequest($request)) {
            return $this->responses->json(403, [
                'ok' => false,
                'error' => 'forbidden',
            ]);
        }

        return $this->responses->plain(403, 'Forbidden');
    }

    private function sessionExpiredResponse(
        Request $request,
        bool $expireCookie = false
    ): Response {
        $response = $this->isAsyncEditorRequest($request)
            ? $this->responses->json(401, [
                'ok' => false,
                'error' => 'session_expired',
            ])
            : $this->redirectToLogin();

        return $expireCookie
            ? $this->responses->expireSession($response)
            : $response;
    }

    private function acceptsTransport(Request $request): bool
    {
        return $this->transportPolicy->accepts(
            $request,
            $this->environment
        );
    }

    private function previewAssets(): BlogPreviewAssetSet
    {
        $context = BlogPreviewAssetContext::fromEnvironment(
            $this->runtime->projectRoot(),
            $this->environment
        );

        return $this->previewAssetLoader->resolve(
            $this->runtime->blogConfig()->previewAssetAdapterPath(),
            $context
        );
    }

    /** @return list<string> */
    private function editorStylesheets(): array
    {
        try {
            return $this->previewAssets()->editorStylesheets();
        } catch (Throwable) {
            // Project styling is additive. The CORE fallback must keep the
            // editor available when an optional adapter/build is unavailable.
            return [];
        }
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

    private function layoutEditorReady(): bool
    {
        return $this->runtime instanceof
            BlogStructuredLayoutEditorHttpRuntimeInterface
            && $this->runtime->layoutEditorReady();
    }

    private function isAsyncEditorRequest(Request $request): bool
    {
        return strtolower(trim((string) $request->header(
            'x-liquidstack-editor'
        ))) === 'async'
            && preg_match(
                '/(?:^|,)\s*application\/json(?:\s*;[^,]*)?(?:,|$)/i',
                (string) $request->header('accept')
            ) === 1;
    }
}
