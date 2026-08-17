<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Admin\BlogAdminCatalogQuery;
use App\Core\Blog\Analytics\BlogAnalyticsCapabilities;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\Routing\BlogPublicationRouteGuard;
use App\Core\Blog\Seo\BlogUrlResolution;
use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Http\WebAdminPageAssets;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellContextFactory;
use App\Core\WebAdmin\Media\MediaService;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use App\Core\WebAdmin\Security\ConstantTime;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\SystemClock;
use Closure;
use PDO;
use Throwable;

final class BlogAdminHttpController
{
    public const VIEW_CAPABILITY = 'blog.articles.view';
    public const EDIT_CAPABILITY = 'blog.articles.edit';
    public const PUBLISH_CAPABILITY = 'blog.articles.publish';
    public const DELETE_CAPABILITY = 'blog.articles.delete';

    private readonly BlogAdminRequestPolicy $requestPolicy;
    private readonly BlogAdminHtmlRenderer $renderer;
    private readonly BlogPublicationRouteGuard $publicationRouteGuard;
    private readonly PrivateRouteTransportPolicy $transportPolicy;
    private readonly WebAdminShellContextFactory $shellContexts;
    private readonly ClockInterface $clock;

    /** @var array<string, mixed> */
    private readonly array $environment;

    public function __construct(
        private readonly BlogAdminHttpRuntimeInterface $runtime,
        ?BlogAdminRequestPolicy $requestPolicy = null,
        ?BlogAdminHtmlRenderer $renderer = null,
        ?BlogPublicationRouteGuard $publicationRouteGuard = null,
        ?PrivateRouteTransportPolicy $transportPolicy = null,
        #[\SensitiveParameter] array $environment = [],
        ?ClockInterface $clock = null
    ) {
        $this->requestPolicy = $requestPolicy
            ?? new BlogAdminRequestPolicy();
        $this->renderer = $renderer ?? new BlogAdminHtmlRenderer();
        $this->publicationRouteGuard = $publicationRouteGuard
            ?? new BlogPublicationRouteGuard();
        $this->transportPolicy = $transportPolicy
            ?? new PrivateRouteTransportPolicy();
        $this->shellContexts = new WebAdminShellContextFactory(
            $runtime->webAdminConfig()->basePath(),
            $runtime->authorization(),
            $this->navigationCatalog($runtime)
        );
        $this->environment = $environment;
        $this->clock = $clock ?? new SystemClock();
    }

    public function index(Request $request): Response
    {
        if (!$this->accepts($request, 'index')) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            self::VIEW_CAPABILITY
        );
        if ($context instanceof Response) {
            return $context;
        }

        $publicPaths = $this->activeLocalePublicPaths();
        try {
            $offsetValue = $request->query('offset');
            $searchValue = $request->query('q');
            $statusValue = $request->query('status');
            $localeValue = $request->query('locale');
            $pageSizeValue = $request->query('per_page');
            $sortValue = $request->query('sort');
            $directionValue = $request->query('dir');
            $catalogQuery = new BlogAdminCatalogQuery(
                search: is_string($searchValue) ? $searchValue : null,
                status: is_string($statusValue) ? $statusValue : null,
                locale: is_string($localeValue) ? $localeValue : null,
                offset: is_string($offsetValue) ? (int) $offsetValue : 0,
                pageSize: is_string($pageSizeValue)
                    ? (int) $pageSizeValue
                    : BlogAdminCatalogQuery::DEFAULT_PAGE_SIZE,
                sort: is_string($sortValue)
                    ? $sortValue
                    : BlogAdminCatalogQuery::DEFAULT_SORT,
                direction: is_string($directionValue)
                    ? $directionValue
                    : BlogAdminCatalogQuery::DEFAULT_DIRECTION
            );
            if (
                $catalogQuery->locale() !== null
                && !array_key_exists($catalogQuery->locale(), $publicPaths)
            ) {
                return $this->plain(400, 'Bad request');
            }
        } catch (BlogException) {
            return $this->plain(400, 'Bad request');
        }

        try {
            $summaries = $this->runtime->service()->searchPosts($catalogQuery);
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }
        $offset = $catalogQuery->offset();
        $pageSize = $catalogQuery->pageSize();
        $hasNext = count($summaries) > $pageSize
            && $offset <= BlogService::MAX_LIST_OFFSET
                - $pageSize;
        $visibleSummaries = array_slice(
            $summaries,
            0,
            $pageSize
        );
        $authorization = $this->runtime->authorization();
        $canEdit = $authorization->hasCapability(
            $context['session'],
            self::EDIT_CAPABILITY
        );
        $canViewMedia = $authorization->hasCapability(
            $context['session'],
            MediaService::VIEW_CAPABILITY
        );
        $canAddLocalization = $canEdit && $canViewMedia;
        $canDuplicate = $canAddLocalization
            && $authorization->hasCapability(
                $context['session'],
                BlogCategoryAdminHttpController::EDIT_CAPABILITY
            );
        $localesByPost = [];
        if ($canAddLocalization) {
            try {
                $postPublicIds = [];
                foreach ($visibleSummaries as $summary) {
                    $postPublicIds[$summary->postPublicId()] = true;
                }
                $localesByPost = $this->runtime->service()->localesForPosts(
                    array_keys($postPublicIds)
                );
            } catch (BlogException $exception) {
                return $this->domainFailure($exception);
            }
        }
        $periodValue = $request->query('period');
        $analyticsPeriodDays = is_string($periodValue)
            ? (int) $periodValue
            : 30;
        $showAnalytics = $this->runtime instanceof
                BlogAnalyticsAdminHttpRuntimeInterface
            && $authorization->hasCapability(
                $context['session'],
                BlogAnalyticsCapabilities::VIEW
            );
        $analyticsByLocalization = [];
        if ($showAnalytics) {
            try {
                $toExclusive = $this->clock->now();
                $fromInclusive = $toExclusive->modify(
                    '-' . $analyticsPeriodDays . ' days'
                );
                $analyticsByLocalization = $this->runtime
                    ->analyticsReport()
                    ->summariesForLocalizations(
                        array_map(
                            static fn (BlogPostSummary $summary): string =>
                                $summary->localizationPublicId(),
                            $visibleSummaries
                        ),
                        $fromInclusive,
                        $toExclusive
                    );
            } catch (Throwable) {
                // Analytics is additive: an unavailable report never hides
                // or breaks the editorial list.
                $analyticsByLocalization = [];
            }
        }

        return $this->htmlForRequest($request, 200, $this->renderer->index(
            basePath: $this->basePath(),
            summaries: $visibleSummaries,
            canEdit: $canEdit,
            offset: $offset,
            hasNext: $hasNext,
            canPublish: $authorization->hasCapability(
                $context['session'],
                self::PUBLISH_CAPABILITY
            ),
            canViewMedia: $canViewMedia,
            shell: $this->shellContext($context, '/blog'),
            publicPaths: $publicPaths,
            csrf: $context['csrf'],
            canDelete: $authorization->hasCapability(
                $context['session'],
                self::DELETE_CAPABILITY
            ) && $this->runtime->service()->trashAvailable(),
            canDuplicate: $canDuplicate,
            analyticsByLocalization: $analyticsByLocalization,
            showAnalytics: $showAnalytics,
            analyticsPeriodDays: $analyticsPeriodDays,
            catalogQuery: $catalogQuery,
            localesByPost: $localesByPost,
            canAddLocalization: $canAddLocalization,
            viewerProfile: $this->viewerProfile($context['session'])
        ));
    }

    public function trash(Request $request): Response
    {
        if (!$this->accepts($request, 'trash_index')) {
            return $this->plain(400, 'Bad request');
        }
        if (!$this->runtime->service()->trashAvailable()) {
            return $this->plain(404, 'Not found');
        }
        $context = $this->authorizedDeletionContext($request, false);
        if ($context instanceof Response) {
            return $context;
        }

        $offset = $request->query('offset');
        $offset = is_string($offset) ? (int) $offset : 0;
        try {
            $summaries = $this->runtime->service()->listTrashedPosts(
                BlogService::DEFAULT_LIST_LIMIT + 1,
                $offset
            );
            $hasNext = count($summaries) > BlogService::DEFAULT_LIST_LIMIT
                && $offset <= BlogService::MAX_LIST_OFFSET
                    - BlogService::DEFAULT_LIST_LIMIT;

            return $this->htmlForRequest(
                $request,
                200,
                $this->renderer->trash(
                    basePath: $this->basePath(),
                    summaries: array_slice(
                        $summaries,
                        0,
                        BlogService::DEFAULT_LIST_LIMIT
                    ),
                    offset: $offset,
                    hasNext: $hasNext,
                    csrf: $context['csrf'],
                    shell: $this->shellContext($context, '/blog/trash'),
                    viewerProfile: $this->viewerProfile($context['session'])
                )
            );
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }
    }

    public function newPost(Request $request): Response
    {
        if (!$this->accepts($request, 'new')) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorizedEditorCreationContext(
            $request,
            false
        );
        if ($context instanceof Response) {
            return $context;
        }
        $post = $request->query('post');

        try {
            $localePublicPaths = $this->activeLocalePublicPaths();
            if (is_string($post)) {
                $existingLocales = array_fill_keys(
                    $this->runtime->service()->localesForPost($post),
                    true
                );
                $localePublicPaths = array_diff_key(
                    $localePublicPaths,
                    $existingLocales
                );
                if ($localePublicPaths === []) {
                    return $this->htmlForRequest(
                        $request,
                        200,
                        $this->renderer->localizationsComplete(
                            $this->basePath(),
                            $this->shellContext($context, '/blog/posts/new')
                        )
                    );
                }
            }
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }

        return $this->htmlForRequest($request, 200, $this->renderer->createForm(
            $this->basePath(),
            $context['csrf'],
            $localePublicPaths,
            is_string($post) ? $post : null,
            shell: $this->shellContext($context, '/blog/posts/new')
        ));
    }

    public function create(Request $request): Response
    {
        if (!$this->accepts($request, 'create')) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorizedEditorCreationContext(
            $request,
            true
        );
        if ($context instanceof Response) {
            return $context;
        }
        $locale = (string) $request->form('locale');
        if (!$this->isActiveLocale($locale)) {
            return $this->plain(422, 'Unprocessable content');
        }

        try {
            $gate = $this->editorCreationMutationGate(
                $context['session'],
                (string) $request->form('csrf')
            );
            $draft = $this->draft($request);
            $post = (string) $request->form('post');
            if ($post === '') {
                $created = $this->runtime->service()->createPost(
                    $gate,
                    $locale,
                    $draft
                );
            } else {
                $created = $this->runtime->service()->addLocalization(
                    $gate,
                    $post,
                    $locale,
                    $draft
                );
            }

            return $this->redirect(
                $this->basePath() . '/editor?'
                    . http_build_query([
                        'post' => $created->postPublicId(),
                        'locale' => $created->locale(),
                    ], '', '&', PHP_QUERY_RFC3986)
            );
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }
    }

    public function edit(Request $request): Response
    {
        if (!$this->accepts($request, 'edit')) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            self::EDIT_CAPABILITY
        );
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $variant = $this->runtime->service()->loadPost(
                (string) $request->query('post'),
                (string) $request->query('locale')
            );
            $existingLocales = array_fill_keys(
                $this->runtime->service()->localesForPost(
                    $variant->postPublicId()
                ),
                true
            );
            $canAddLocalization = array_diff_key(
                $this->activeLocalePublicPaths(),
                $existingLocales
            ) !== [];

            return $this->htmlForRequest($request, 200, $this->renderer->editForm(
                $this->basePath(),
                $context['csrf'],
                $variant,
                $this->runtime->authorization()->hasCapability(
                    $context['session'],
                    self::PUBLISH_CAPABILITY
                ),
                canAddLocalization: $canAddLocalization,
                shell: $this->shellContext($context, '/blog/posts/edit'),
                privateDraftPublicationReady:
                    $this->privateDraftPublicationReady()
            ));
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }
    }

    public function urlManager(Request $request): Response
    {
        if (!$this->accepts($request, 'url_manager')) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            self::PUBLISH_CAPABILITY
        );
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $variant = $this->runtime->service()->loadPost(
                (string) $request->query('post'),
                (string) $request->query('locale')
            );
            $slug = $variant->draft()->slug();
            $resolution = $slug === null
                ? null
                : $this->runtime->service()->urlResolution(
                    $variant->locale(),
                    $slug
                );
            $replacements = $this->runtime->service()->searchPosts(
                new BlogAdminCatalogQuery(
                    status: BlogPostVariant::PUBLISHED,
                    locale: $variant->locale(),
                    pageSize: 50
                )
            );

            return $this->htmlForRequest(
                $request,
                200,
                $this->renderer->urlManager(
                    $this->basePath(),
                    $context['csrf'],
                    $variant,
                    $resolution,
                    $replacements,
                    $this->shellContext($context, '/blog/posts/url')
                )
            );
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }
    }

    public function preview(Request $request): Response
    {
        if (!$this->accepts($request, 'preview')) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            self::VIEW_CAPABILITY
        );
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $variant = $this->runtime->service()->loadPost(
                (string) $request->query('post'),
                (string) $request->query('locale')
            );
            $authorization = $this->runtime->authorization();
            $canOpenEditor = $authorization->hasCapability(
                $context['session'],
                self::EDIT_CAPABILITY
            ) && $authorization->hasCapability(
                $context['session'],
                MediaService::VIEW_CAPABILITY
            );

            return $this->htmlForRequest($request, 200, $this->renderer->preview(
                $this->basePath(),
                $variant,
                $canOpenEditor,
                $this->shellContext($context, '/blog/posts/preview')
            ));
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }
    }

    public function save(Request $request): Response
    {
        if (!$this->accepts($request, 'save')) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            self::EDIT_CAPABILITY,
            true
        );
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $this->runtime->service()->saveDraft(
                $this->runtime->mutationGate(
                    $context['session'],
                    (string) $request->form('csrf'),
                    self::EDIT_CAPABILITY
                ),
                (string) $request->form('post'),
                (string) $request->form('locale'),
                (int) $request->form('lock_version'),
                $this->draft($request)
            );

            return $this->updatedRedirect();
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }
    }

    public function duplicate(Request $request): Response
    {
        if (!$this->accepts($request, 'duplicate')) {
            return $this->plain(400, 'Bad request');
        }
        $sourceLocale = (string) $request->form('locale');
        $destinationLocale = (string) $request->form(
            'destination_locale'
        );
        if (!$this->isActiveLocale($destinationLocale)) {
            return $this->plain(422, 'Unprocessable content');
        }
        $independentPost = hash_equals(
            $sourceLocale,
            $destinationLocale
        );
        $context = $this->authorizedDuplicateContext(
            $request,
            $independentPost
        );
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $gate = $independentPost
                ? $this->duplicateMutationGate(
                    $context['session'],
                    (string) $request->form('csrf')
                )
                : $this->editorCreationMutationGate(
                    $context['session'],
                    (string) $request->form('csrf')
                );
            $created = $independentPost
                ? $this->runtime->service()->duplicatePost(
                    $gate,
                    (string) $request->form('post'),
                    $sourceLocale,
                    (int) $request->form('lock_version'),
                    (string) $request->form('operation_id')
                )
                : $this->runtime->service()->addLocalizationCopy(
                    $gate,
                    (string) $request->form('post'),
                    $sourceLocale,
                    $destinationLocale,
                    (int) $request->form('lock_version'),
                    (string) $request->form('operation_id')
                );

            return $this->redirect(
                $this->basePath() . '/editor?'
                    . http_build_query([
                        'post' => $created->postPublicId(),
                        'locale' => $created->locale(),
                    ], '', '&', PHP_QUERY_RFC3986)
            );
        } catch (BlogException $exception) {
            $status = match ($exception->issueCode()) {
                BlogException::ACTOR_GATE_FAILED => 403,
                BlogException::LOCALE_CONFLICT,
                BlogException::LOCK_CONFLICT,
                BlogException::INVALID_STATE,
                BlogException::IDEMPOTENCY_CONFLICT,
                BlogException::COPY_RESULT_TRASHED => 409,
                BlogException::POST_NOT_FOUND,
                BlogException::VARIANT_NOT_FOUND => 404,
                BlogException::INVALID_INPUT => 422,
                default => 503,
            };

            return $this->html(
                $status,
                $this->renderer->copyOperationFailed(
                    $this->basePath(),
                    (string) $request->form('post'),
                    $sourceLocale,
                    $exception->issueCode(),
                    $this->shellContext($context, '/blog')
                )
            );
        }
    }

    public function trashPost(Request $request): Response
    {
        if (!$this->accepts($request, 'trash')) {
            return $this->plain(400, 'Bad request');
        }
        if (!$this->runtime->service()->trashAvailable()) {
            return $this->plain(404, 'Not found');
        }
        $context = $this->authorizedDeletionContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $this->runtime->service()->trashPost(
                $this->mutationGateAll(
                    $context['session'],
                    (string) $request->form('csrf'),
                    [self::VIEW_CAPABILITY, self::DELETE_CAPABILITY]
                ),
                (string) $request->form('post'),
                (string) $request->form('locale'),
                (int) $request->form('lock_version')
            );

            return $this->redirect($this->basePath() . '/trash');
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }
    }

    public function restoreFromTrash(Request $request): Response
    {
        if (!$this->accepts($request, 'restore_from_trash')) {
            return $this->plain(400, 'Bad request');
        }
        if (!$this->runtime->service()->trashAvailable()) {
            return $this->plain(404, 'Not found');
        }
        $context = $this->authorizedDeletionContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $this->runtime->service()->restoreTrashedPost(
                $this->mutationGateAll(
                    $context['session'],
                    (string) $request->form('csrf'),
                    [self::VIEW_CAPABILITY, self::DELETE_CAPABILITY]
                ),
                (string) $request->form('post'),
                (string) $request->form('locale'),
                (int) $request->form('lock_version')
            );

            return $this->redirect($this->basePath() . '/trash');
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }
    }

    public function publish(Request $request): Response
    {
        if (!$this->accepts($request, 'transition')) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            self::PUBLISH_CAPABILITY,
            true
        );
        if ($context instanceof Response) {
            return $context;
        }
        $locale = (string) $request->form('locale');
        if (!$this->isActiveLocale($locale)) {
            return $this->plain(422, 'Unprocessable content');
        }

        try {
            $post = (string) $request->form('post');
            if ($this->privateDraftPublicationReady()) {
                return $this->redirect(
                    $this->basePath() . '/editor?'
                        . http_build_query([
                            'post' => $post,
                            'locale' => $locale,
                        ], '', '&', PHP_QUERY_RFC3986)
                );
            }
            $variant = $this->runtime->service()->loadPost($post, $locale);
            $slug = $variant->draft()->slug();
            if ($slug === null) {
                throw new BlogException(BlogException::PUBLISH_INCOMPLETE);
            }
            $this->publicationRouteGuard->assertAvailable(
                $this->runtime->projectRoot(),
                $this->runtime->blogConfig(),
                $locale,
                $slug
            );
            $this->runtime->service()->publish(
                $this->runtime->mutationGate(
                    $context['session'],
                    (string) $request->form('csrf'),
                    self::PUBLISH_CAPABILITY
                ),
                $post,
                $locale,
                (int) $request->form('lock_version')
            );

            return $this->updatedRedirect();
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }
    }

    private function privateDraftPublicationReady(): bool
    {
        return method_exists($this->runtime, 'privateDraftPublicationReady')
            && $this->runtime->privateDraftPublicationReady() === true;
    }

    public function unpublish(Request $request): Response
    {
        if (!$this->accepts($request, 'transition')) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            self::PUBLISH_CAPABILITY,
            true
        );
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $variant = $this->runtime->service()->unpublish(
                $this->runtime->mutationGate(
                    $context['session'],
                    (string) $request->form('csrf'),
                    self::PUBLISH_CAPABILITY
                ),
                (string) $request->form('post'),
                (string) $request->form('locale'),
                (int) $request->form('lock_version')
            );

            return $this->redirectToUrlManager($variant);
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }
    }

    public function finalizeUrl(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsUrlResolution($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            self::PUBLISH_CAPABILITY,
            true
        );
        if ($context instanceof Response) {
            return $context;
        }
        $locale = (string) $request->form('locale');
        if (!$this->isActiveLocale($locale)) {
            return $this->plain(422, 'Unprocessable content');
        }
        try {
            $resolution = (string) $request->form('resolution');
            $replacement = (string) $request->form('replacement_post');
            $variant = $this->runtime->service()->finalizeRetiredUrl(
                $this->runtime->mutationGate(
                    $context['session'],
                    (string) $request->form('csrf'),
                    self::PUBLISH_CAPABILITY
                ),
                (string) $request->form('post'),
                $locale,
                (int) $request->form('lock_version'),
                (string) $request->form('historical_slug'),
                $resolution === BlogUrlResolution::REDIRECT
                    ? BlogUrlResolution::REDIRECT : BlogUrlResolution::GONE,
                $replacement === '' ? null : $replacement
            );

            return $this->redirectToUrlManager($variant);
        } catch (BlogException $exception) {
            return $this->domainFailure($exception);
        }
    }

    public function updated(Request $request): Response
    {
        if (!$this->accepts($request, 'updated')) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            self::VIEW_CAPABILITY
        );
        if ($context instanceof Response) {
            return $context;
        }

        return $this->htmlForRequest(
            $request,
            200,
            $this->renderer->operationCompleted(
                $this->basePath(),
                $this->shellContext($context, '/blog')
            )
        );
    }

    /**
     * @param array{session: string, csrf: string} $context
     */
    private function shellContext(
        #[\SensitiveParameter] array $context,
        string $activePath
    ): WebAdminShellContext {
        return $this->shellContexts->create(
            $context['session'],
            $context['csrf'],
            $activePath,
            assets: new WebAdminPageAssets([
                '/assets/modules/blog/blog-admin.css',
            ], [
                '/assets/modules/blog/blog-admin-list.js',
            ])
        );
    }

    private function navigationCatalog(
        BlogAdminHttpRuntimeInterface $runtime
    ): WebAdminNavigationCatalog {
        if (method_exists($runtime, 'navigation')) {
            $navigation = $runtime->navigation();
            if ($navigation instanceof WebAdminNavigationCatalog) {
                return $navigation;
            }
        }

        return new WebAdminNavigationCatalog([
            new WebAdminNavigationItem(
                'blog',
                'Art&iacute;culos',
                '/blog',
                self::VIEW_CAPABILITY
            ),
        ]);
    }

    private function viewerProfile(
        #[\SensitiveParameter] string $sessionToken
    ): ?\App\Core\WebAdmin\Profile\WebAdminPublicProfile {
        if (!$this->runtime instanceof BlogAdminProfileHttpRuntimeInterface) {
            return null;
        }

        try {
            return $this->runtime->profileForSession($sessionToken);
        } catch (Throwable) {
            // Profile preferences are additive. Their temporary absence must
            // not make the Blog administration unavailable.
            return null;
        }
    }

    /**
     * @return array{session: string, csrf: string}|Response
     */
    private function authorizedContext(
        Request $request,
        string $capability,
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
            return $this->withExpiredCookie($this->redirectToLogin());
        }
        if (!$this->runtime->authorization()->mayAccessWebAdmin(
            $sessionToken
        )) {
            $this->runtime->authentication()->revokeSession($sessionToken);

            return $this->withExpiredCookie($this->redirectToLogin());
        }
        if (!$this->runtime->authorization()->hasCapability(
            $sessionToken,
            $capability
        )) {
            return $this->plain(403, 'Forbidden');
        }

        $csrf = $this->runtime->authentication()
            ->authenticatedCsrfToken($sessionToken);
        if ($csrf === null) {
            return $this->withExpiredCookie($this->redirectToLogin());
        }
        $csrfToken = $csrf->csrfToken();
        if (
            $validateSubmittedCsrf
            && !ConstantTime::equals(
                $csrfToken,
                (string) $request->form('csrf')
            )
        ) {
            return $this->plain(403, 'Forbidden');
        }

        return [
            'session' => $sessionToken,
            'csrf' => $csrfToken,
        ];
    }

    /**
     * @return array{session: string, csrf: string}|Response
     */
    private function authorizedEditorCreationContext(
        Request $request,
        bool $validateSubmittedCsrf
    ): array|Response {
        $context = $this->authorizedContext(
            $request,
            self::EDIT_CAPABILITY,
            $validateSubmittedCsrf
        );
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->runtime->authorization()->hasCapability(
            $context['session'],
            MediaService::VIEW_CAPABILITY
        )) {
            return $this->plain(403, 'Forbidden');
        }

        return $context;
    }

    /**
     * Duplicating preserves category assignments, so it requires the same
     * category mutation capability as an explicit assignment change.
     *
     * @return array{session: string, csrf: string}|Response
     */
    private function authorizedDuplicateContext(
        Request $request,
        bool $requiresCategoryEdit
    ): array|Response {
        $context = $this->authorizedEditorCreationContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        if ($requiresCategoryEdit && !$this->runtime->authorization()->hasCapability(
            $context['session'],
            BlogCategoryAdminHttpController::EDIT_CAPABILITY
        )) {
            return $this->plain(403, 'Forbidden');
        }

        return $context;
    }

    /**
     * @return array{session: string, csrf: string}|Response
     */
    private function authorizedDeletionContext(
        Request $request,
        bool $validateSubmittedCsrf
    ): array|Response {
        $context = $this->authorizedContext(
            $request,
            self::VIEW_CAPABILITY,
            $validateSubmittedCsrf
        );
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->runtime->authorization()->hasCapability(
            $context['session'],
            self::DELETE_CAPABILITY
        )) {
            return $this->plain(403, 'Forbidden');
        }

        return $context;
    }

    /** @return Closure(PDO): string */
    private function editorCreationMutationGate(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken
    ): Closure {
        return $this->mutationGateAll($sessionToken, $csrfToken, [
            self::EDIT_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
        ]);
    }

    /** @return Closure(PDO): string */
    private function duplicateMutationGate(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken
    ): Closure {
        return $this->mutationGateAll($sessionToken, $csrfToken, [
            self::EDIT_CAPABILITY,
            MediaService::VIEW_CAPABILITY,
            BlogCategoryAdminHttpController::EDIT_CAPABILITY,
        ]);
    }

    /** @param list<string> $capabilities @return Closure(PDO): string */
    private function mutationGateAll(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        array $capabilities
    ): Closure {
        if ($this->runtime instanceof BlogStructuredEditorHttpRuntimeInterface) {
            return $this->runtime->mutationGateAll(
                $sessionToken,
                $csrfToken,
                $capabilities
            );
        }

        // Compatibilidad con adapters que implementan solo el contrato base:
        // ambas puertas se ejecutan dentro de la misma transacción Blog y
        // deben resolver exactamente la misma identidad.
        $gates = [];
        foreach ($capabilities as $capability) {
            $gates[] = $this->runtime->mutationGate(
                $sessionToken,
                $csrfToken,
                $capability
            );
        }

        return static function (PDO $pdo) use ($gates): string {
            try {
                $actor = null;
                foreach ($gates as $gate) {
                    $candidate = $gate($pdo);
                    if ($actor !== null && !hash_equals($actor, $candidate)) {
                        throw new BlogException(
                            BlogException::ACTOR_GATE_FAILED
                        );
                    }
                    $actor = $candidate;
                }
                if ($actor === null) {
                    throw new BlogException(BlogException::ACTOR_GATE_FAILED);
                }

                return $actor;
            } catch (Throwable) {
                throw new BlogException(BlogException::ACTOR_GATE_FAILED);
            }
        };
    }

    private function draft(Request $request): BlogDraft
    {
        return new BlogDraft(
            (string) $request->form('h1'),
            (string) $request->form('body_text'),
            $this->nullableForm($request, 'slug'),
            $this->nullableForm($request, 'seo_title'),
            $this->nullableForm($request, 'meta_description'),
            $this->nullableForm($request, 'excerpt')
        );
    }

    private function nullableForm(Request $request, string $key): ?string
    {
        $value = (string) $request->form($key);

        return trim($value) === '' ? null : $value;
    }

    private function isActiveLocale(string $locale): bool
    {
        return in_array($locale, $this->runtime->languages(), true)
            && $this->runtime->blogConfig()->publicPath($locale) !== null;
    }

    /** @return array<string, string> */
    private function activeLocalePublicPaths(): array
    {
        $paths = [];
        foreach ($this->runtime->languages() as $locale) {
            if (!$this->isActiveLocale($locale)) {
                continue;
            }
            $publicPath = $this->runtime->blogConfig()->publicPath($locale);
            if (is_string($publicPath)) {
                $paths[$locale] = $publicPath;
            }
        }

        return $paths;
    }

    private function accepts(Request $request, string $operation): bool
    {
        if (!$this->transportPolicy->accepts(
            $request,
            $this->environment
        )) {
            return false;
        }

        return match ($operation) {
            'index' => $this->requestPolicy->acceptsIndex($request),
            'trash_index' =>
                $this->requestPolicy->acceptsTrashIndex($request),
            'updated' => $this->requestPolicy->acceptsUpdated($request),
            'new' => $this->requestPolicy->acceptsNew($request),
            'create' => $this->requestPolicy->acceptsCreate($request),
            'edit' => $this->requestPolicy->acceptsEdit($request),
            'url_manager' =>
                $this->requestPolicy->acceptsUrlManager($request),
            'preview' => $this->requestPolicy->acceptsPreview($request),
            'save' => $this->requestPolicy->acceptsSave($request),
            'transition' => $this->requestPolicy->acceptsTransition($request),
            'duplicate' => $this->requestPolicy->acceptsDuplicate($request),
            'trash' => $this->requestPolicy->acceptsTrash($request),
            'restore_from_trash' =>
                $this->requestPolicy->acceptsRestoreFromTrash($request),
            default => false,
        };
    }

    private function domainFailure(BlogException $exception): Response
    {
        return match ($exception->issueCode()) {
            BlogException::ACTOR_GATE_FAILED =>
                $this->plain(403, 'Forbidden'),
            BlogException::INVALID_INPUT,
            BlogException::PUBLISH_INCOMPLETE =>
                $this->plain(422, 'Unprocessable content'),
            BlogException::LOCALE_CONFLICT,
            BlogException::SLUG_CONFLICT,
            BlogException::LOCK_CONFLICT,
            BlogException::IDEMPOTENCY_CONFLICT,
            BlogException::INVALID_STATE =>
                $this->plain(409, 'Conflict'),
            BlogException::POST_NOT_FOUND,
            BlogException::VARIANT_NOT_FOUND =>
                $this->plain(404, 'Not found'),
            BlogException::STORAGE_UNAVAILABLE =>
                $this->plain(503, 'Service unavailable'),
            default => $this->plain(503, 'Service unavailable'),
        };
    }

    private function basePath(): string
    {
        return rtrim(
            $this->runtime->webAdminConfig()->basePath(),
            '/'
        ) . '/blog';
    }

    private function updatedRedirect(): Response
    {
        return $this->redirect($this->basePath() . '/posts/updated');
    }

    private function redirectToUrlManager(BlogPostVariant $variant): Response
    {
        return $this->redirect(
            $this->basePath() . '/posts/url?'
                . http_build_query([
                    'post' => $variant->postPublicId(),
                    'locale' => $variant->locale(),
                ], '', '&', PHP_QUERY_RFC3986)
        );
    }

    private function redirectToLogin(): Response
    {
        return $this->redirect(
            $this->runtime->webAdminConfig()->basePath() . '/login'
        );
    }

    private function withExpiredCookie(Response $response): Response
    {
        $config = $this->runtime->webAdminConfig();
        $value = $config->cookieName()
            . '=; Path=' . $config->cookiePath()
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0'
            . '; Secure; HttpOnly; SameSite='
            . WebAdminConfig::COOKIE_SAME_SITE;

        return $response->withAddedHeader('Set-Cookie', $value);
    }

    /** @param array<string, string> $headers */
    private function html(
        int $status,
        string $body,
        array $headers = []
    ): Response {
        return new Response($status, $body, $headers + $this->headers(
            "default-src 'none'; style-src 'self'; script-src 'self'; "
            . "img-src 'self'; frame-src 'self'; connect-src 'self'; "
            . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
        ) + [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Language' => 'es',
        ]);
    }

    /** @param array<string, string> $headers */
    private function htmlForRequest(
        Request $request,
        int $status,
        string $body,
        array $headers = []
    ): Response {
        return $this->html(
            $status,
            $request->method() === 'HEAD' ? '' : $body,
            $headers
        );
    }

    /** @param array<string, string> $headers */
    private function plain(
        int $status,
        string $body,
        array $headers = []
    ): Response {
        return new Response($status, $body, $headers + $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'"
        ) + ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function redirect(string $path): Response
    {
        return new Response(303, '', ['Location' => $path] + $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'"
        ));
    }

    /** @return array<string, string> */
    private function headers(string $csp): array
    {
        return [
            'Cache-Control' =>
                'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => $csp,
            'Permissions-Policy' =>
                'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }
}
