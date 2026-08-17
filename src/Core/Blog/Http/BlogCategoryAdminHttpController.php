<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\Categories\BlogCategoryDraft;
use App\Core\Blog\Categories\BlogCategoryException;
use App\Core\Blog\Categories\BlogCategoryLocalization;
use App\Core\Blog\Categories\BlogCategoryQuickSlug;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Http\WebAdminPageAssets;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellContextFactory;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use App\Core\WebAdmin\Security\ConstantTime;

/** Separate category HTTP coordinator; the article controller stays bounded. */
final class BlogCategoryAdminHttpController
{
    public const VIEW_CAPABILITY = 'blog.categories.view';
    public const EDIT_CAPABILITY = 'blog.categories.edit';

    /** @var array<string, mixed> */
    private readonly array $environment;
    private readonly WebAdminShellContextFactory $shellContexts;

    public function __construct(
        private readonly BlogCategoryAdminHttpRuntimeInterface $runtime,
        private readonly BlogCategoryAdminRequestPolicy $requestPolicy =
            new BlogCategoryAdminRequestPolicy(),
        private readonly BlogCategoryAdminHtmlRenderer $renderer =
            new BlogCategoryAdminHtmlRenderer(),
        private readonly PrivateRouteTransportPolicy $transportPolicy =
            new PrivateRouteTransportPolicy(),
        #[\SensitiveParameter] array $environment = []
    ) {
        $this->shellContexts = new WebAdminShellContextFactory(
            $runtime->webAdminConfig()->basePath(),
            $runtime->authorization(),
            $this->navigationCatalog($runtime)
        );
        $this->environment = $environment;
    }

    public function index(Request $request): Response
    {
        if (!$this->accepts($request, 'index')) {
            return $this->plain(400, 'Bad request');
        }
        $service = $this->service();
        $context = $this->authorizedContext(
            $request,
            self::VIEW_CAPABILITY
        );
        if ($context instanceof Response) {
            return $context;
        }
        $locale = $request->query('locale');
        if (is_string($locale) && !$this->isActiveLocale($locale)) {
            return $this->plain(422, 'Unprocessable content');
        }

        try {
            $categories = $service->list(
                BlogCategoryService::MAX_ASSIGNMENTS,
                0,
                is_string($locale) ? $locale : null
            );
            if ($this->isAsyncJsonRequest($request)) {
                return $this->jsonForRequest($request, 200, [
                    'ok' => true,
                    'locale' => is_string($locale) ? $locale : null,
                    'locales' => $this->activeLocalePayload(),
                    'categories' => array_map(
                        fn (BlogCategoryLocalization $category): array =>
                            $this->categoryPayload($category),
                        $categories
                    ),
                ]);
            }

            return $this->htmlForRequest($request, 200, $this->renderer->index(
                $this->basePath(),
                $categories,
                $this->runtime->authorization()->hasCapability(
                    $context['session'],
                    self::EDIT_CAPABILITY
                ),
                $this->activeLocalePublicPaths(),
                $this->shellContext($context, '/blog/categories')
            ));
        } catch (BlogCategoryException $exception) {
            return $this->domainFailureForRequest($request, $exception);
        }
    }

    public function newCategory(Request $request): Response
    {
        if (!$this->accepts($request, 'new')) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            self::EDIT_CAPABILITY
        );
        if ($context instanceof Response) {
            return $context;
        }
        $category = $request->query('category');

        try {
            $languages = $this->activeLanguages();
            if (is_string($category)) {
                $languages = $this->missingActiveLanguages(
                    $this->service()->localesForCategory(
                        $category,
                        $languages
                    )
                );
                if ($languages === []) {
                    return $this->htmlForRequest(
                        $request,
                        200,
                        $this->renderer->localizationsComplete(
                            $this->basePath(),
                            $this->shellContext(
                                $context,
                                '/blog/categories/new'
                            )
                        )
                    );
                }
            }
        } catch (BlogCategoryException $exception) {
            return $this->domainFailure($exception);
        }

        return $this->htmlForRequest($request, 200, $this->renderer->createForm(
            $this->basePath(),
            $context['csrf'],
            $languages,
            is_string($category) ? $category : null,
            $this->shellContext($context, '/blog/categories/new')
        ));
    }

    public function create(Request $request): Response
    {
        if (!$this->accepts($request, 'create')) {
            return $this->plain(400, 'Bad request');
        }
        $service = $this->service();
        $context = $this->authorizedContext(
            $request,
            self::EDIT_CAPABILITY,
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
            $gate = $this->runtime->mutationGate(
                $context['session'],
                (string) $request->form('csrf'),
                self::EDIT_CAPABILITY
            );
            $category = (string) $request->form('category');
            $draft = $this->draft($request);
            if ($category === '') {
                $stored = $service->create($gate, $locale, $draft);
            } else {
                $stored = $service->addLocalization(
                    $gate,
                    $category,
                    $locale,
                    $draft
                );
            }
            if ($this->isAsyncJsonRequest($request)) {
                return $this->json(200, [
                    'ok' => true,
                    'category' => $this->categoryPayload($stored),
                    'category_locales' => $service->localesForCategory(
                        $stored->categoryPublicId(),
                        $this->activeLanguages()
                    ),
                ]);
            }

            return $this->updatedRedirect();
        } catch (BlogCategoryException|BlogException $exception) {
            return $this->domainFailureForRequest($request, $exception);
        }
    }

    public function edit(Request $request): Response
    {
        if (!$this->accepts($request, 'edit')) {
            return $this->plain(400, 'Bad request');
        }
        $service = $this->service();
        $context = $this->authorizedContext(
            $request,
            self::EDIT_CAPABILITY
        );
        if ($context instanceof Response) {
            return $context;
        }
        try {
            $category = $service->load(
                (string) $request->query('category'),
                (string) $request->query('locale')
            );
            $missingLanguages = $this->missingActiveLanguages(
                $service->localesForCategory(
                    $category->categoryPublicId(),
                    $this->activeLanguages()
                )
            );

            return $this->htmlForRequest($request, 200, $this->renderer->editForm(
                $this->basePath(),
                $context['csrf'],
                $category,
                $missingLanguages !== [],
                $this->shellContext($context, '/blog/categories/edit')
            ));
        } catch (BlogCategoryException $exception) {
            return $this->domainFailure($exception);
        }
    }

    public function save(Request $request): Response
    {
        if (!$this->accepts($request, 'save')) {
            return $this->plain(400, 'Bad request');
        }
        $service = $this->service();
        $context = $this->authorizedContext(
            $request,
            self::EDIT_CAPABILITY,
            true
        );
        if ($context instanceof Response) {
            return $context;
        }
        try {
            $stored = $service->save(
                $this->runtime->mutationGate(
                    $context['session'],
                    (string) $request->form('csrf'),
                    self::EDIT_CAPABILITY
                ),
                (string) $request->form('category'),
                (string) $request->form('locale'),
                (int) $request->form('lock_version'),
                $this->draft($request)
            );
            if ($this->isAsyncJsonRequest($request)) {
                return $this->json(200, [
                    'ok' => true,
                    'category' => $this->categoryPayload($stored),
                ]);
            }

            return $this->updatedRedirect();
        } catch (BlogCategoryException|BlogException $exception) {
            return $this->domainFailureForRequest($request, $exception);
        }
    }

    public function delete(Request $request): Response
    {
        if (!$this->accepts($request, 'delete')) {
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
        $categoryPublicId = (string) $request->form('category');
        $locale = (string) $request->form('locale');
        if (!$this->isActiveLocale($locale)) {
            return $this->plain(422, 'Unprocessable content');
        }
        try {
            $aggregateDeleted = $this->service()->deleteLocalization(
                $this->runtime->mutationGate(
                    $context['session'],
                    (string) $request->form('csrf'),
                    self::EDIT_CAPABILITY
                ),
                $categoryPublicId,
                $locale,
                (int) $request->form('lock_version')
            );
            if ($this->isAsyncJsonRequest($request)) {
                return $this->json(200, [
                    'ok' => true,
                    'category_public_id' => $categoryPublicId,
                    'locale' => $locale,
                    'aggregate_deleted' => $aggregateDeleted,
                ]);
            }

            return $this->updatedRedirect();
        } catch (BlogCategoryException|BlogException $exception) {
            return $this->domainFailureForRequest($request, $exception);
        }
    }

    public function assignment(Request $request): Response
    {
        if (!$this->accepts($request, 'assignment')) {
            return $this->plain(400, 'Bad request');
        }
        $service = $this->service();
        $context = $this->authorizedContext(
            $request,
            self::EDIT_CAPABILITY
        );
        if ($context instanceof Response) {
            return $context;
        }
        $locale = (string) $request->query('locale');
        if (!$this->isActiveLocale($locale)) {
            return $this->plain(422, 'Unprocessable content');
        }
        try {
            $post = (string) $request->query('post');
            // Loading the post enforces existence without exposing its DB ID.
            $variant = $this->runtime->blogService()->loadPost($post, $locale);

            return $this->htmlForRequest($request, 200, $this->renderer->assignmentForm(
                $this->basePath(),
                $context['csrf'],
                $post,
                $locale,
                $variant->lockVersion(),
                $service->categoryWorkspaceVersion($post),
                $service->list(BlogCategoryService::MAX_ASSIGNMENTS, 0, $locale),
                $service->assignedToVariant($post, $locale),
                $this->shellContext($context, '/blog/categories/assign')
            ));
        } catch (BlogCategoryException|BlogException $exception) {
            return $this->domainFailure($exception);
        }
    }

    public function saveAssignment(Request $request): Response
    {
        if (!$this->accepts($request, 'assignment_save')) {
            return $this->plain(400, 'Bad request');
        }
        $service = $this->service();
        $context = $this->authorizedContext(
            $request,
            self::EDIT_CAPABILITY,
            true
        );
        if ($context instanceof Response) {
            return $context;
        }
        $categories = $request->form('categories', []);
        try {
            $gate = $this->runtime->mutationGate(
                $context['session'],
                (string) $request->form('csrf'),
                self::EDIT_CAPABILITY
            );
            $values = is_array($categories) ? array_values($categories) : [];
            $workspaceVersion = null;
            if (
                is_string($request->form('locale'))
                && is_string($request->form('lock_version'))
                && is_string($request->form('category_workspace_version'))
            ) {
                $workspaceVersion = $service->assignToVariant(
                    $gate,
                    (string) $request->form('post'),
                    (string) $request->form('locale'),
                    (int) $request->form('lock_version'),
                    (int) $request->form('category_workspace_version'),
                    $values
                );
            } else {
                if ($service->privateWorkflowEnabled()) {
                    throw new BlogCategoryException(
                        BlogCategoryException::INVALID_INPUT
                    );
                }
                $service->assignToPost(
                    $gate,
                    (string) $request->form('post'),
                    $values
                );
            }

            if ($this->isAsyncJsonRequest($request)) {
                if (!is_int($workspaceVersion)) {
                    return $this->json(422, [
                        'ok' => false,
                        'error' => 'variant_identity_required',
                    ]);
                }

                return $this->json(200, [
                    'ok' => true,
                    'lock_version' => (int) $request->form('lock_version'),
                    'category_workspace_version' => $workspaceVersion,
                ]);
            }

            return $this->updatedRedirect();
        } catch (BlogCategoryException|BlogException $exception) {
            return $this->domainFailureForRequest($request, $exception);
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
            $this->renderer->completed(
                $this->basePath(),
                $this->shellContext($context, '/blog/categories')
            )
        );
    }

    /** @param array{session: string, csrf: string} $context */
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
        BlogCategoryAdminHttpRuntimeInterface $runtime
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
                'Categor&iacute;as',
                '/blog/categories',
                self::VIEW_CAPABILITY
            ),
        ]);
    }

    private function service(): BlogCategoryService
    {
        return $this->runtime->categoryService();
    }

    /** @return array{session: string, csrf: string}|Response */
    private function authorizedContext(
        Request $request,
        string $capability,
        bool $submittedCsrf = false
    ): array|Response {
        $sessionToken = $request->cookie(
            $this->runtime->webAdminConfig()->cookieName()
        );
        if ($sessionToken === null) {
            return $this->redirectToLogin();
        }
        if ($this->runtime->authentication()->resolveAuthenticatedSession(
            $sessionToken
        ) === null) {
            return $this->withExpiredCookie($this->redirectToLogin());
        }
        if (!$this->runtime->authorization()->mayAccessWebAdmin($sessionToken)) {
            $this->runtime->authentication()->revokeSession($sessionToken);

            return $this->withExpiredCookie($this->redirectToLogin());
        }
        if (!$this->runtime->authorization()->hasCapability(
            $sessionToken,
            $capability
        )) {
            return $this->plain(403, 'Forbidden');
        }
        $csrf = $this->runtime->authentication()->authenticatedCsrfToken(
            $sessionToken
        );
        if ($csrf === null) {
            return $this->withExpiredCookie($this->redirectToLogin());
        }
        if ($submittedCsrf && !ConstantTime::equals(
            $csrf->csrfToken(),
            (string) $request->form('csrf')
        )) {
            return $this->plain(403, 'Forbidden');
        }

        return [
            'session' => $sessionToken,
            'csrf' => $csrf->csrfToken(),
        ];
    }

    private function draft(Request $request): BlogCategoryDraft
    {
        $name = (string) $request->form('name');
        $slug = $request->form('slug');

        return new BlogCategoryDraft(
            $name,
            is_string($slug) ? $slug : BlogCategoryQuickSlug::fromName($name)
        );
    }

    /** @return array<string, mixed> */
    private function categoryPayload(
        BlogCategoryLocalization $category
    ): array {
        return [
            'category_public_id' => $category->categoryPublicId(),
            'locale' => $category->locale(),
            'name' => $category->draft()->name(),
            'slug' => $category->draft()->slug(),
            'lock_version' => $category->lockVersion(),
            'updated_at' => $category->updatedAt()->format(
                'Y-m-d\TH:i:s.u\Z'
            ),
        ];
    }

    /** @return list<array{locale: string, public_path: string}> */
    private function activeLocalePayload(): array
    {
        $result = [];
        foreach ($this->activeLocalePublicPaths() as $locale => $path) {
            $result[] = ['locale' => $locale, 'public_path' => $path];
        }

        return $result;
    }

    private function isActiveLocale(string $locale): bool
    {
        return in_array($locale, $this->runtime->languages(), true)
            && $this->runtime->blogConfig()->publicPath($locale) !== null;
    }

    /** @return list<string> */
    private function activeLanguages(): array
    {
        return array_values(array_filter(
            $this->runtime->languages(),
            fn (string $locale): bool => $this->isActiveLocale($locale)
        ));
    }

    /** @return array<string, string> */
    private function activeLocalePublicPaths(): array
    {
        $paths = [];
        foreach ($this->activeLanguages() as $locale) {
            $publicPath = $this->runtime->blogConfig()->publicPath($locale);
            if (is_string($publicPath)) {
                $paths[$locale] = $publicPath;
            }
        }

        return $paths;
    }

    /**
     * @param list<string> $associatedLanguages
     * @return list<string>
     */
    private function missingActiveLanguages(array $associatedLanguages): array
    {
        $associated = array_fill_keys($associatedLanguages, true);

        return array_values(array_filter(
            $this->activeLanguages(),
            static fn (string $locale): bool => !isset($associated[$locale])
        ));
    }

    private function accepts(Request $request, string $operation): bool
    {
        if (!$this->transportPolicy->accepts($request, $this->environment)) {
            return false;
        }

        return match ($operation) {
            'index' => $this->requestPolicy->acceptsIndex($request),
            'new' => $this->requestPolicy->acceptsNew($request),
            'create' => $this->requestPolicy->acceptsCreate($request),
            'edit' => $this->requestPolicy->acceptsEdit($request),
            'save' => $this->requestPolicy->acceptsSave($request),
            'delete' => $this->requestPolicy->acceptsDelete($request),
            'assignment' => $this->requestPolicy->acceptsAssign($request),
            'assignment_save' =>
                $this->requestPolicy->acceptsAssignmentSave($request),
            'updated' => $this->requestPolicy->acceptsUpdated($request),
            default => false,
        };
    }

    private function domainFailure(
        BlogCategoryException|BlogException $exception
    ): Response {
        if ($exception instanceof BlogException) {
            return match ($exception->issueCode()) {
                BlogException::ACTOR_GATE_FAILED =>
                    $this->plain(403, 'Forbidden'),
                BlogException::POST_NOT_FOUND,
                BlogException::VARIANT_NOT_FOUND =>
                    $this->plain(404, 'Not found'),
                BlogException::INVALID_INPUT =>
                    $this->plain(422, 'Unprocessable content'),
                default => $this->plain(503, 'Service unavailable'),
            };
        }

        return match ($exception->issueCode()) {
            BlogCategoryException::INVALID_INPUT =>
                $this->plain(422, 'Unprocessable content'),
            BlogCategoryException::NOT_FOUND,
            BlogCategoryException::POST_NOT_FOUND =>
                $this->plain(404, 'Not found'),
            BlogCategoryException::LOCALE_CONFLICT,
            BlogCategoryException::SLUG_CONFLICT,
            BlogCategoryException::LOCK_CONFLICT,
            BlogCategoryException::IN_USE,
            BlogCategoryException::RESERVED =>
                $this->plain(409, 'Conflict'),
            default => $this->plain(503, 'Service unavailable'),
        };
    }

    private function domainFailureForRequest(
        Request $request,
        BlogCategoryException|BlogException $exception
    ): Response {
        if (!$this->isAsyncJsonRequest($request)) {
            return $this->domainFailure($exception);
        }
        [$status, $code] = $this->domainIssue($exception);

        return $this->json($status, [
            'ok' => false,
            'error' => $code,
        ]);
    }

    /** @return array{int, string} */
    private function domainIssue(
        BlogCategoryException|BlogException $exception
    ): array {
        if ($exception instanceof BlogException) {
            return match ($exception->issueCode()) {
                BlogException::ACTOR_GATE_FAILED => [403, 'forbidden'],
                BlogException::POST_NOT_FOUND,
                BlogException::VARIANT_NOT_FOUND => [404, 'not_found'],
                BlogException::INVALID_INPUT => [422, 'invalid_input'],
                default => [503, 'unavailable'],
            };
        }

        return match ($exception->issueCode()) {
            BlogCategoryException::INVALID_INPUT => [422, 'invalid_input'],
            BlogCategoryException::NOT_FOUND,
            BlogCategoryException::POST_NOT_FOUND => [404, 'not_found'],
            BlogCategoryException::IN_USE => [409, 'category_in_use'],
            BlogCategoryException::RESERVED => [409, 'category_reserved'],
            BlogCategoryException::LOCALE_CONFLICT,
            BlogCategoryException::SLUG_CONFLICT,
            BlogCategoryException::LOCK_CONFLICT => [409, 'conflict'],
            default => [503, 'unavailable'],
        };
    }

    private function basePath(): string
    {
        return rtrim($this->runtime->webAdminConfig()->basePath(), '/')
            . '/blog/categories';
    }

    private function updatedRedirect(): Response
    {
        return $this->redirect($this->basePath() . '/updated');
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
        return $response->withAddedHeader(
            'Set-Cookie',
            $config->cookieName() . '=; Path=' . $config->cookiePath()
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0'
            . '; Secure; HttpOnly; SameSite=' . WebAdminConfig::COOKIE_SAME_SITE
        );
    }

    /** @param array<string, string> $headers */
    private function html(int $status, string $body, array $headers = []): Response
    {
        return new Response($status, $body, $headers + $this->headers(
            "default-src 'none'; style-src 'self'; script-src 'self'; "
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
    private function plain(int $status, string $body, array $headers = []): Response
    {
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

    /** @param array<string, mixed> $payload */
    private function json(int $status, array $payload): Response
    {
        return new Response(
            $status,
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            ),
            $this->headers(
                "default-src 'none'; form-action 'none'; frame-ancestors "
                    . "'none'; base-uri 'none'"
            ) + ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /** @param array<string, mixed> $payload */
    private function jsonForRequest(
        Request $request,
        int $status,
        array $payload
    ): Response {
        $response = $this->json($status, $payload);
        if ($request->method() !== 'HEAD') {
            return $response;
        }

        return new Response($status, '', $response->headers());
    }

    private function isAsyncJsonRequest(Request $request): bool
    {
        $editor = strtolower(trim((string) $request->header(
            'x-liquidstack-editor'
        )));
        $manager = strtolower(trim((string) $request->header(
            'x-liquidstack-category-manager'
        )));

        return in_array('async', [$editor, $manager], true)
            && preg_match(
                '/(?:^|,)\s*application\/json(?:\s*;[^,]*)?(?:,|$)/i',
                (string) $request->header('accept')
            ) === 1;
    }

    /** @return array<string, string> */
    private function headers(string $csp): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => $csp,
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }
}
