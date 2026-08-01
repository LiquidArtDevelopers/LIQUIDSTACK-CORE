<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModuleRouteProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModuleRouteCollection;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigException;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Http\WebAdminHttpController;
use App\Core\WebAdmin\Http\WebAdminHttpRequestPolicy;
use App\Core\WebAdmin\Http\WebAdminHttpRuntimeFactory;
use App\Core\WebAdmin\Http\WebAdminHttpRuntimeFactoryInterface;
use App\Core\WebAdmin\Http\WebAdminHttpRuntimeException;
use App\Core\WebAdmin\Http\WebAdminRuntimeIssueReporterInterface;
use App\Core\WebAdmin\Http\PhpErrorLogWebAdminRuntimeIssueReporter;
use App\Core\WebAdmin\Routing\WebAdminRoutePolicy;
use RuntimeException;

final class WebAdminRouteProvider implements ModuleRouteProviderInterface
{
    private readonly WebAdminRoutePolicy $routePolicy;
    private readonly WebAdminHttpRuntimeFactoryInterface $runtimeFactory;
    private readonly WebAdminHttpRequestPolicy $requestPolicy;
    private readonly WebAdminRuntimeIssueReporterInterface $issueReporter;
    private ?ModuleRuntimeContext $runtimeContext = null;
    private ?WebAdminConfig $configuration = null;
    private ?WebAdminHttpController $controller = null;
    private ?string $startupIssueCode = null;

    public function __construct(
        ?WebAdminRoutePolicy $routePolicy = null,
        ?WebAdminHttpRuntimeFactoryInterface $runtimeFactory = null,
        ?WebAdminHttpRequestPolicy $requestPolicy = null,
        ?WebAdminRuntimeIssueReporterInterface $issueReporter = null
    ) {
        $this->routePolicy = $routePolicy ?? new WebAdminRoutePolicy();
        $this->runtimeFactory = $runtimeFactory
            ?? new WebAdminHttpRuntimeFactory();
        $this->requestPolicy = $requestPolicy
            ?? new WebAdminHttpRequestPolicy();
        $this->issueReporter = $issueReporter
            ?? new PhpErrorLogWebAdminRuntimeIssueReporter();
    }

    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public function registerRoutes(
        ModuleRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void {
        $requestedPath = WebAdminConfig::DEFAULT_BASE_PATH;
        try {
            $configuration = (new WebAdminConfigLoader())->load(
                $context->projectRoot()
            );
            $requestedPath = $configuration->basePath();
            $this->configuration = $configuration;
        } catch (WebAdminConfigException) {
            /*
             * Una configuración local inválida no puede derribar la web
             * pública. Se reserva solo el prefijo seguro y se responde de
             * forma genérica, sin exponer la causa ni el valor rechazado.
            */
            $requestedPath = WebAdminConfig::DEFAULT_BASE_PATH;
            $this->startupIssueCode =
                'webadmin.configuration_invalid';
        }

        try {
            $languages = $context->languages();
        } catch (RuntimeException) {
            /*
             * Un catálogo de idiomas inválido nunca debe exponer salida ni
             * derribar rutas no relacionadas. Se limita el intento al default.
            */
            $requestedPath = WebAdminConfig::DEFAULT_BASE_PATH;
            $languages = [];
            $this->startupIssueCode ??= 'webadmin.languages_invalid';
        }

        $resolution = $this->routePolicy->resolve(
            $context->projectRoot(),
            $requestedPath,
            $languages
        );
        $prefix = $resolution->registeredPath();
        if ($prefix === null) {
            return;
        }
        if (
            $this->configuration !== null
            && $this->configuration->basePath() !== $prefix
        ) {
            $configured = $this->configuration;
            $this->configuration = new WebAdminConfig(
                $prefix,
                $configured->tablePrefix(),
                $configured->cookieName(),
                $configured->idleTtlSeconds(),
                $configured->absoluteTtlSeconds(),
                $configured->source(),
                $configured->databaseConnection()
            );
        }

        $routes->claimPrefix(
            self::moduleId(),
            $prefix,
            [$this, 'notFound'],
            [$this, 'methodNotAllowed']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix,
            [$this, 'root']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/',
            [$this, 'root']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/login',
            [$this, 'loginForm']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            $prefix . '/login',
            [$this, 'login']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            $prefix . '/logout',
            [$this, 'logout']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/users',
            [$this, 'users']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/users/invite',
            [$this, 'inviteEditorForm']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            $prefix . '/users/invite',
            [$this, 'inviteEditor']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/users/edit',
            [$this, 'editEditor']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            $prefix . '/users/capabilities',
            [$this, 'replaceEditorCapabilities']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            $prefix . '/users/suspend',
            [$this, 'suspendEditor']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            $prefix . '/users/resume',
            [$this, 'resumeEditor']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            $prefix . '/users/invite/resend',
            [$this, 'resendEditorInvitation']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/users/updated',
            [$this, 'usersUpdated']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/password/forgot',
            [$this, 'forgotPasswordForm']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            $prefix . '/password/forgot',
            [$this, 'requestPasswordReset']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/password/forgot/sent',
            [$this, 'forgotPasswordSent']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/activate',
            [$this, 'activate']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            $prefix . '/activate',
            [$this, 'completeActivation']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/password/reset',
            [$this, 'resetPassword']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            $prefix . '/password/reset',
            [$this, 'completePasswordReset']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/action-unavailable',
            [$this, 'actionUnavailable']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/login/activated',
            [$this, 'activationCompleted']
        );
        $routes->add(
            self::moduleId(),
            'GET',
            $prefix . '/login/password-reset',
            [$this, 'passwordResetCompleted']
        );

        $this->runtimeContext = $context;
    }

    public function root(Request $request): Response
    {
        return $this->handle('root', $request);
    }

    public function loginForm(Request $request): Response
    {
        return $this->handle('loginForm', $request);
    }

    public function login(Request $request): Response
    {
        return $this->handle('login', $request);
    }

    public function logout(Request $request): Response
    {
        return $this->handle('logout', $request);
    }

    public function users(Request $request): Response
    {
        return $this->handle('users', $request);
    }

    public function inviteEditorForm(Request $request): Response
    {
        return $this->handle('inviteEditorForm', $request);
    }

    public function inviteEditor(Request $request): Response
    {
        return $this->handle('inviteEditor', $request);
    }

    public function editEditor(Request $request): Response
    {
        return $this->handle('editEditor', $request);
    }

    public function replaceEditorCapabilities(Request $request): Response
    {
        return $this->handle('replaceEditorCapabilities', $request);
    }

    public function suspendEditor(Request $request): Response
    {
        return $this->handle('suspendEditor', $request);
    }

    public function resumeEditor(Request $request): Response
    {
        return $this->handle('resumeEditor', $request);
    }

    public function resendEditorInvitation(Request $request): Response
    {
        return $this->handle('resendEditorInvitation', $request);
    }

    public function usersUpdated(Request $request): Response
    {
        return $this->handle('usersUpdated', $request);
    }

    public function forgotPasswordForm(Request $request): Response
    {
        return $this->handle('forgotPasswordForm', $request);
    }

    public function requestPasswordReset(Request $request): Response
    {
        return $this->handle('requestPasswordReset', $request);
    }

    public function forgotPasswordSent(Request $request): Response
    {
        return $this->handle('forgotPasswordSent', $request);
    }

    public function activate(Request $request): Response
    {
        return $this->handle('activate', $request);
    }

    public function completeActivation(Request $request): Response
    {
        return $this->handle('completeActivation', $request);
    }

    public function resetPassword(Request $request): Response
    {
        return $this->handle('resetPassword', $request);
    }

    public function completePasswordReset(Request $request): Response
    {
        return $this->handle('completePasswordReset', $request);
    }

    public function actionUnavailable(Request $request): Response
    {
        return $this->handle('actionUnavailable', $request);
    }

    public function activationCompleted(Request $request): Response
    {
        return $this->handle('activationCompleted', $request);
    }

    public function passwordResetCompleted(Request $request): Response
    {
        return $this->handle('passwordResetCompleted', $request);
    }

    public function unavailable(Request $request): Response
    {
        return $this->response(503, 'Service unavailable');
    }

    private function handle(string $method, Request $request): Response
    {
        if ($this->runtimeContext === null || $this->configuration === null) {
            $this->reportStartupIssue();

            return $this->unavailable($request);
        }
        if (!$this->requestIsAllowed($method, $request)) {
            return $this->response(400, 'Bad request');
        }
        if (!$request->isSecureTransport()) {
            return $this->response(400, 'Bad request');
        }
        $sessionToken = $request->cookie(
            in_array($method, ['login', 'requestPasswordReset'], true)
                ? $this->configuration->preAuthenticationCookieName()
                : $this->configuration->cookieName()
        );
        if (
            in_array($method, ['login', 'requestPasswordReset'], true)
            && $sessionToken === null
        ) {
            return $this->response(400, 'Bad request');
        }
        if ($method === 'root' && $sessionToken === null) {
            return $this->response(303, '', [
                'Location' => $this->configuration->basePath() . '/login',
            ]);
        }
        if (
            in_array($method, [
                'users',
                'inviteEditorForm',
                'inviteEditor',
                'editEditor',
                'replaceEditorCapabilities',
                'suspendEditor',
                'resumeEditor',
                'resendEditorInvitation',
                'usersUpdated',
            ], true)
            && $sessionToken === null
        ) {
            return $this->response(303, '', [
                'Location' => $this->configuration->basePath() . '/login',
            ]);
        }
        if (!$this->runtimeContext->environmentIsUsable()) {
            $this->issueReporter->report(
                'webadmin.environment_unusable'
            );

            return $this->unavailable($request);
        }

        try {
            $this->controller ??= new WebAdminHttpController(
                $this->runtimeFactory->create(
                    $this->runtimeContext,
                    $this->configuration
                )
            );

            return $this->controller->{$method}($request);
        } catch (\Throwable $exception) {
            $this->issueReporter->report(
                $exception instanceof WebAdminHttpRuntimeException
                    ? $exception->issueCode()
                    : 'webadmin.runtime_unavailable'
            );

            return $this->unavailable($request);
        }
    }

    private function reportStartupIssue(): void
    {
        if ($this->startupIssueCode === null) {
            return;
        }

        $issueCode = $this->startupIssueCode;
        $this->startupIssueCode = null;
        $this->issueReporter->report($issueCode);
    }

    private function requestIsAllowed(
        string $method,
        Request $request
    ): bool {
        return match ($method) {
            'root',
            'loginForm',
            'forgotPasswordForm',
            'forgotPasswordSent',
            'inviteEditorForm',
            'usersUpdated',
            'actionUnavailable',
            'activationCompleted',
            'passwordResetCompleted' =>
                $this->requestPolicy->acceptsSafeNavigation($request),
            'users' => $this->requestPolicy->acceptsUserListNavigation(
                $request
            ),
            'editEditor' => $this->requestPolicy->acceptsUserDetailNavigation(
                $request
            ),
            'activate', 'resetPassword' =>
                $this->requestPolicy->acceptsCredentialActionNavigation(
                    $request
                ),
            'login' => $this->requestPolicy->acceptsFormPost(
                $request,
                ['csrf', 'email', 'password']
            ),
            'requestPasswordReset' => $this->requestPolicy->acceptsFormPost(
                $request,
                ['csrf', 'email']
            ),
            'completeActivation', 'completePasswordReset' =>
                $this->requestPolicy->acceptsFormPost(
                    $request,
                    ['csrf', 'password', 'password_confirmation']
                ),
            'logout' => $this->requestPolicy->acceptsFormPost(
                $request,
                ['csrf']
            ),
            'inviteEditor' =>
                $this->requestPolicy->acceptsCapabilitiesFormPost(
                    $request,
                    ['csrf', 'display_name', 'email']
                ),
            'replaceEditorCapabilities' =>
                $this->requestPolicy->acceptsCapabilitiesFormPost(
                    $request,
                    ['csrf', 'target']
                ),
            'suspendEditor', 'resumeEditor', 'resendEditorInvitation' =>
                $this->requestPolicy->acceptsFormPost(
                    $request,
                    ['csrf', 'target']
                ),
            default => false,
        };
    }

    public function notFound(Request $request): Response
    {
        return $this->response(404, 'Not found');
    }

    /** @param list<string> $allowed */
    public function methodNotAllowed(Request $request, array $allowed): Response
    {
        return $this->response(405, 'Method not allowed', [
            'Allow' => implode(', ', $allowed),
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function response(
        int $status,
        string $body,
        array $headers = []
    ): Response {
        return new Response($status, $body, $headers + [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
