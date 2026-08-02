<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\Categories\BlogCategoryDraft;
use App\Core\Blog\Categories\BlogCategoryException;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Security\ConstantTime;

/** Separate category HTTP coordinator; the article controller stays bounded. */
final class BlogCategoryAdminHttpController
{
    public const VIEW_CAPABILITY = 'blog.categories.view';
    public const EDIT_CAPABILITY = 'blog.categories.edit';

    /** @var array<string, mixed> */
    private readonly array $environment;

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

        try {
            return $this->htmlForRequest($request, 200, $this->renderer->index(
                $this->basePath(),
                $service->list(),
                $this->runtime->authorization()->hasCapability(
                    $context['session'],
                    self::EDIT_CAPABILITY
                )
            ));
        } catch (BlogCategoryException) {
            return $this->plain(503, 'Service unavailable');
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

        return $this->htmlForRequest($request, 200, $this->renderer->createForm(
            $this->basePath(),
            $context['csrf'],
            $this->activeLanguages(),
            is_string($category) ? $category : null
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
                $service->create($gate, $locale, $draft);
            } else {
                $service->addLocalization(
                    $gate,
                    $category,
                    $locale,
                    $draft
                );
            }

            return $this->updatedRedirect();
        } catch (BlogCategoryException|BlogException $exception) {
            return $this->domainFailure($exception);
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

            return $this->htmlForRequest($request, 200, $this->renderer->editForm(
                $this->basePath(),
                $context['csrf'],
                $category
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
            $service->save(
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

            return $this->updatedRedirect();
        } catch (BlogCategoryException|BlogException $exception) {
            return $this->domainFailure($exception);
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
            $this->runtime->blogService()->loadPost($post, $locale);

            return $this->htmlForRequest($request, 200, $this->renderer->assignmentForm(
                $this->basePath(),
                $context['csrf'],
                $post,
                $locale,
                $service->list(BlogCategoryService::MAX_ASSIGNMENTS, 0, $locale),
                $service->assignedToPost($post)
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
            $service->assignToPost(
                $this->runtime->mutationGate(
                    $context['session'],
                    (string) $request->form('csrf'),
                    self::EDIT_CAPABILITY
                ),
                (string) $request->form('post'),
                is_array($categories) ? array_values($categories) : []
            );

            return $this->updatedRedirect();
        } catch (BlogCategoryException|BlogException $exception) {
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
            $this->renderer->completed($this->basePath())
        );
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
        return new BlogCategoryDraft(
            (string) $request->form('name'),
            (string) $request->form('slug')
        );
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
            BlogCategoryException::LOCK_CONFLICT =>
                $this->plain(409, 'Conflict'),
            default => $this->plain(503, 'Service unavailable'),
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
            "default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
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
