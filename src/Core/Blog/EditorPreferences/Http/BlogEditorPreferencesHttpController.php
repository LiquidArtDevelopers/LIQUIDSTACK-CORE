<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences\Http;

use App\Core\Blog\EditorPreferences\BlogEditorPreferencesException;
use App\Core\Blog\EditorPreferences\BlogSettingsCapabilities;
use App\Core\Blog\Http\BlogEditorPreferencesHttpRuntimeInterface;
use App\Core\Blog\Http\BlogStructuredEditorHttpResponseFactory;
use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Http\WebAdminPageAssets;
use App\Core\WebAdmin\Http\WebAdminShellContextFactory;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Security\ConstantTime;
use Throwable;

/** Private HTTP boundary for project-wide Blog heading defaults. */
final class BlogEditorPreferencesHttpController
{
    private readonly BlogStructuredEditorHttpResponseFactory $responses;
    private readonly WebAdminShellContextFactory $shells;

    /** @var array<string, mixed> */
    private readonly array $environment;

    public function __construct(
        private readonly BlogEditorPreferencesHttpRuntimeInterface $runtime,
        private readonly BlogEditorPreferencesRequestPolicy $requestPolicy =
            new BlogEditorPreferencesRequestPolicy(),
        private readonly BlogEditorPreferencesHtmlRenderer $renderer =
            new BlogEditorPreferencesHtmlRenderer(),
        ?BlogStructuredEditorHttpResponseFactory $responses = null,
        private readonly PrivateRouteTransportPolicy $transportPolicy =
            new PrivateRouteTransportPolicy(),
        #[\SensitiveParameter] array $environment = []
    ) {
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

    public function index(Request $request): Response
    {
        if (
            !$this->acceptsTransport($request)
            || !$this->requestPolicy->acceptsIndex($request)
        ) {
            return $this->responses->plain(400, 'Bad request');
        }
        if (!$this->runtime->editorPreferencesReady()) {
            return $this->responses->plain(503, 'Service unavailable');
        }
        $context = $this->authorizedContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $state = $this->runtime->editorPreferences()->current();
            $shell = $this->shells->create(
                $context['session'],
                $context['csrf'],
                '/blog/settings/presentation',
                assets: new WebAdminPageAssets([
                    '/assets/modules/blog/blog-admin.css',
                ])
            );
            $html = $this->renderer->render(
                $this->basePath(),
                $context['csrf'],
                $state,
                $shell
            );

            return $this->responses->html(
                200,
                $request->method() === 'HEAD' ? '' : $html
            );
        } catch (Throwable) {
            return $this->responses->plain(503, 'Service unavailable');
        }
    }

    public function save(Request $request): Response
    {
        if (
            !$this->acceptsTransport($request)
            || !$this->requestPolicy->acceptsSave($request)
        ) {
            return $this->responses->plain(400, 'Bad request');
        }
        if (!$this->runtime->editorPreferencesReady()) {
            return $this->responses->plain(503, 'Service unavailable');
        }
        $context = $this->authorizedContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $this->runtime->editorPreferences()->save(
                $this->runtime->mutationGate(
                    $context['session'],
                    $context['csrf'],
                    BlogSettingsCapabilities::MANAGE
                ),
                (int) $request->form('lock_version'),
                $this->requestPolicy->preferences($request)
            );

            return $this->responses->redirect(
                $this->basePath() . '/settings/presentation'
            );
        } catch (BlogEditorPreferencesException $exception) {
            return match ($exception->issueCode()) {
                BlogEditorPreferencesException::INVALID_INPUT =>
                    $this->responses->plain(422, 'Unprocessable content'),
                BlogEditorPreferencesException::ACTOR_GATE_FAILED =>
                    $this->responses->plain(403, 'Forbidden'),
                BlogEditorPreferencesException::LOCK_CONFLICT =>
                    $this->responses->plain(409, 'Conflict'),
                default =>
                    $this->responses->plain(503, 'Service unavailable'),
            };
        } catch (Throwable) {
            return $this->responses->plain(503, 'Service unavailable');
        }
    }

    /** @return array{session: string, csrf: string}|Response */
    private function authorizedContext(
        Request $request,
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
        if (!$this->runtime->authorization()->hasCapability(
            $sessionToken,
            BlogSettingsCapabilities::MANAGE
        )) {
            return $this->responses->plain(403, 'Forbidden');
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

    private function acceptsTransport(Request $request): bool
    {
        return $this->transportPolicy->accepts($request, $this->environment);
    }

    private function redirectToLogin(): Response
    {
        return $this->responses->redirect(
            $this->runtime->webAdminConfig()->basePath() . '/login'
        );
    }

    private function basePath(): string
    {
        return rtrim($this->runtime->webAdminConfig()->basePath(), '/')
            . '/blog';
    }
}
