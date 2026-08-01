<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Authentication\PreAuthenticationRateLimited;
use App\Core\WebAdmin\Authentication\SessionSecrets;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\CredentialAction\CredentialActionService;
use App\Core\WebAdmin\CredentialAction\CredentialActionSessionSecrets;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use App\Core\WebAdmin\Security\ConstantTime;
use App\Core\WebAdmin\Security\InvalidPassword;
use App\Core\WebAdmin\UserManagement\DelegableCapability;
use App\Core\WebAdmin\UserManagement\DelegableCapabilityCatalog;
use App\Core\WebAdmin\UserManagement\EditorDetail;
use App\Core\WebAdmin\UserManagement\EditorInviteResult;
use App\Core\WebAdmin\UserManagement\EditorMutationResult;
use App\Core\WebAdmin\UserManagement\EditorSummary;
use DateTimeZone;

final class WebAdminHttpController
{
    private const USERS_VIEW = 'webadmin.users.view';
    private const USERS_INVITE = 'webadmin.users.invite';
    private const USERS_SUSPEND = 'webadmin.users.suspend';
    private const USERS_CAPABILITIES_MANAGE =
        'webadmin.users.capabilities.manage';

    private readonly WebAdminHttpRequestPolicy $requestPolicy;
    private readonly WebAdminHtmlRenderer $renderer;

    public function __construct(
        private readonly WebAdminHttpRuntime $runtime,
        ?WebAdminHttpRequestPolicy $requestPolicy = null,
        ?WebAdminHtmlRenderer $renderer = null
    ) {
        $this->requestPolicy = $requestPolicy
            ?? new WebAdminHttpRequestPolicy();
        $this->renderer = $renderer ?? new WebAdminHtmlRenderer();
    }

    public function root(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsSafeNavigation($request)) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->html(200, '');
        }

        $token = $request->cookie($this->runtime->config()->cookieName());
        if ($token === null) {
            return $this->redirect($this->loginPath());
        }
        $session = $this->runtime->authentication()
            ->resolveAuthenticatedSession($token);
        if ($session === null) {
            return $this->withExpiredCookie(
                $this->redirect($this->loginPath())
            );
        }
        if (!$this->runtime->authorization()->mayAccessWebAdmin($token)) {
            $this->runtime->authentication()->revokeSession($token);

            return $this->withExpiredCookie(
                $this->redirect($this->loginPath())
            );
        }

        $csrf = $this->runtime->authentication()
            ->authenticatedCsrfToken($token);
        if ($csrf === null) {
            return $this->withExpiredCookie(
                $this->redirect($this->loginPath())
            );
        }

        return $this->html(200, $this->renderer->dashboard(
            $this->rootPath(),
            $csrf->csrfToken(),
            $this->runtime->authorization()->hasCapability(
                $token,
                self::USERS_VIEW
            ),
            $this->visibleModuleNavigation($token)
        ));
    }

    public function loginForm(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsSafeNavigation($request)) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->html(200, '');
        }

        $authCookie = $request->cookie(
            $this->runtime->config()->cookieName()
        );
        $expireAuthCookie = false;
        if ($authCookie !== null) {
            $session = $this->runtime->authentication()
                ->resolveAuthenticatedSession($authCookie);
            if (
                $session !== null
                && $this->runtime->authorization()->mayAccessWebAdmin(
                    $authCookie
                )
            ) {
                return $this->redirect($this->rootPath());
            }
            if ($session !== null) {
                $this->runtime->authentication()->revokeSession($authCookie);
            }
            $expireAuthCookie = true;
        }

        $existing = $request->cookie(
            $this->runtime->config()->preAuthenticationCookieName()
        );

        try {
            $secrets = $this->runtime->authentication()
                ->openPreAuthenticationSession(
                    $existing,
                    $request->clientIp() ?? ''
                );
        } catch (PreAuthenticationRateLimited) {
            return $this->plain(429, 'Too many requests', [
                'Retry-After' => '900',
            ]);
        }

        $response = $this->withSessionCookie(
            $this->html(200, $this->renderer->login(
                $this->rootPath(),
                $secrets->csrfToken(),
                false
            )),
            $secrets
        );

        return $expireAuthCookie
            ? $this->withExpiredCookie($response)
            : $response;
    }

    public function login(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsFormPost(
            $request,
            ['csrf', 'email', 'password']
        )) {
            return $this->plain(400, 'Bad request');
        }
        $token = $request->cookie(
            $this->runtime->config()->preAuthenticationCookieName()
        );
        if ($token === null) {
            return $this->plain(400, 'Bad request');
        }
        $authToken = $request->cookie(
            $this->runtime->config()->cookieName()
        );
        $expireAuthCookie = false;
        if ($authToken !== null) {
            $authenticated = $this->runtime->authentication()
                ->resolveAuthenticatedSession($authToken);
            if (
                $authenticated !== null
                && $this->runtime->authorization()->mayAccessWebAdmin(
                    $authToken
                )
            ) {
                if ($this->runtime->authentication()
                    ->validatePreAuthenticationCsrf(
                        $token,
                        (string) $request->form('csrf')
                    )) {
                    $this->runtime->authentication()->revokeSession($token);
                }

                return $this->withExpiredPreAuthenticationCookie(
                    $this->redirect($this->rootPath())
                );
            }
            if ($authenticated !== null) {
                $this->runtime->authentication()->revokeSession($authToken);
            }
            $expireAuthCookie = true;
        }

        try {
            $attempt = $this->runtime->authentication()->authenticate(
                $token,
                (string) $request->form('csrf'),
                (string) $request->form('email'),
                (string) $request->form('password'),
                $request->clientIp() ?? '',
                $request->header('user-agent')
            );
        } catch (PreAuthenticationRateLimited) {
            $response = $this->withExpiredPreAuthenticationCookie($this->plain(
                429,
                'Too many requests',
                ['Retry-After' => '900']
            ));

            return $expireAuthCookie
                ? $this->withExpiredCookie($response)
                : $response;
        }
        $secrets = $attempt->nextSession();
        if (!$attempt->isSuccessful()) {
            $response = $this->withSessionCookie(
                $this->html(
                    401,
                    $this->renderer->login(
                        $this->rootPath(),
                        $secrets->csrfToken(),
                        true
                    )
                ),
                $secrets
            );

            return $expireAuthCookie
                ? $this->withExpiredCookie($response)
                : $response;
        }

        if (!$this->runtime->authorization()->mayAccessWebAdmin(
            $secrets->sessionToken()
        )) {
            $this->runtime->authentication()->revokeSession(
                $secrets->sessionToken()
            );

            try {
                $replacement = $this->runtime->authentication()
                    ->openPreAuthenticationSession(
                        null,
                        $request->clientIp() ?? ''
                    );
            } catch (PreAuthenticationRateLimited) {
                return $this->withExpiredPreAuthenticationCookie($this->plain(
                    429,
                    'Too many requests',
                    ['Retry-After' => '900']
                ));
            }

            return $this->withExpiredCookie($this->withSessionCookie(
                $this->html(
                    401,
                    $this->renderer->login(
                        $this->rootPath(),
                        $replacement->csrfToken(),
                        true
                    )
                ),
                $replacement
            ));
        }

        return $this->withSessionCookie(
            $this->redirect($this->rootPath()),
            $secrets
        );
    }

    public function logout(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsFormPost($request, ['csrf'])) {
            return $this->plain(400, 'Bad request');
        }
        $token = $request->cookie($this->runtime->config()->cookieName());
        $revoked = is_string($token)
            && $this->runtime->authentication()->logout(
                $token,
                (string) $request->form('csrf'),
                $request->clientIp() ?? '',
                $request->header('user-agent')
            );

        $response = $this->redirect($this->loginPath());

        return $revoked ? $this->withExpiredCookie($response) : $response;
    }

    public function users(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsUserListNavigation($request)) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->html(200, '');
        }

        $context = $this->managementContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->hasCapability($context['token'], self::USERS_VIEW)) {
            return $this->plain(403, 'Forbidden');
        }

        $cursor = $request->query('after');
        $page = $this->runtime->userManagement()->listEditors(
            $context['token'],
            50,
            is_string($cursor) ? $cursor : null
        );
        if ($page === null) {
            return $this->hasCapability($context['token'], self::USERS_VIEW)
                ? $this->plain(400, 'Bad request')
                : $this->plain(403, 'Forbidden');
        }

        return $this->html(200, $this->renderer->editorList(
            $this->rootPath(),
            array_map(
                fn (EditorSummary $editor): array =>
                    $this->editorRow($editor),
                $page->editors()
            ),
            $page->nextCursor(),
            $this->hasCapability($context['token'], self::USERS_INVITE)
        ));
    }

    public function inviteEditorForm(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsSafeNavigation($request)) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->html(200, '');
        }

        $context = $this->managementContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->hasCapability($context['token'], self::USERS_INVITE)) {
            return $this->plain(403, 'Forbidden');
        }

        $capabilities = $this->delegableCapabilityRows(
            $context['token'],
            []
        );
        if ($capabilities === null) {
            return $this->plain(403, 'Forbidden');
        }

        return $this->html(200, $this->renderer->editorInvite(
            $this->rootPath(),
            $context['csrf'],
            $capabilities
        ));
    }

    public function inviteEditor(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsCapabilitiesFormPost(
            $request,
            ['csrf', 'display_name', 'email']
        )) {
            return $this->plain(400, 'Bad request');
        }

        $context = $this->managementContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->validSubmittedCsrf($request, $context['csrf'])) {
            return $this->plain(403, 'Forbidden');
        }
        if (!$this->hasCapability($context['token'], self::USERS_INVITE)) {
            return $this->plain(403, 'Forbidden');
        }

        $capabilities = $this->submittedCapabilities($request);
        $result = $this->runtime->userManagement()->inviteEditor(
            $context['token'],
            (string) $request->form('csrf'),
            (string) $request->form('display_name'),
            (string) $request->form('email'),
            $capabilities
        );
        if ($result->status() === EditorInviteResult::INVITED) {
            return $this->redirect($this->rootPath() . '/users/updated');
        }
        if ($result->status() === EditorInviteResult::DENIED) {
            return $this->plain(403, 'Forbidden');
        }

        $rows = $this->delegableCapabilityRows(
            $context['token'],
            $capabilities
        );
        if ($rows === null) {
            return $this->plain(403, 'Forbidden');
        }

        return $this->html(422, $this->renderer->editorInvite(
            $this->rootPath(),
            $context['csrf'],
            $rows,
            true
        ));
    }

    public function editEditor(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsUserDetailNavigation($request)) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->html(200, '');
        }

        $context = $this->managementContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->hasCapability($context['token'], self::USERS_VIEW)) {
            return $this->plain(403, 'Forbidden');
        }

        $target = (string) $request->query('user');
        $editor = $this->runtime->userManagement()->editorDetail(
            $context['token'],
            $target
        );
        if ($editor === null) {
            return $this->hasCapability($context['token'], self::USERS_VIEW)
                ? $this->plain(404, 'Not found')
                : $this->plain(403, 'Forbidden');
        }

        $canManage = $this->hasCapability(
            $context['token'],
            self::USERS_CAPABILITIES_MANAGE
        );
        $capabilities = $this->delegableCapabilityRows(
            $context['token'],
            $editor->directCapabilities()
        );
        if ($capabilities === null) {
            return $this->plain(403, 'Forbidden');
        }
        $isSelf = ConstantTime::equals(
            $context['user_public_id'],
            $editor->publicId()
        );
        $canInvite = $this->hasCapability(
            $context['token'],
            self::USERS_INVITE
        );
        $canSuspend = $this->hasCapability(
            $context['token'],
            self::USERS_SUSPEND
        );
        if (
            $editor->status() === 'suspended'
            && $editor->activatedAt() === null
            && !$canInvite
        ) {
            $canSuspend = false;
        }

        return $this->html(200, $this->renderer->editorDetail(
            $this->rootPath(),
            $context['csrf'],
            $this->editorRow($editor),
            $capabilities,
            $canManage && !$isSelf,
            $canSuspend && !$isSelf,
            $canInvite && !$isSelf,
        ));
    }

    public function replaceEditorCapabilities(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsCapabilitiesFormPost(
            $request,
            ['csrf', 'target']
        )) {
            return $this->plain(400, 'Bad request');
        }

        $context = $this->managementContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->validSubmittedCsrf($request, $context['csrf'])) {
            return $this->plain(403, 'Forbidden');
        }
        if (!$this->hasCapability(
            $context['token'],
            self::USERS_CAPABILITIES_MANAGE
        )) {
            return $this->plain(403, 'Forbidden');
        }

        return $this->mutationResponse(
            $this->runtime->userManagement()->replaceCapabilities(
                $context['token'],
                (string) $request->form('csrf'),
                (string) $request->form('target'),
                $this->submittedCapabilities($request)
            )
        );
    }

    public function suspendEditor(Request $request): Response
    {
        return $this->stateMutation(
            $request,
            self::USERS_SUSPEND,
            fn (string $token, string $csrf, string $target):
                EditorMutationResult => $this->runtime->userManagement()
                    ->suspendEditor($token, $csrf, $target)
        );
    }

    public function resumeEditor(Request $request): Response
    {
        return $this->stateMutation(
            $request,
            self::USERS_SUSPEND,
            fn (string $token, string $csrf, string $target):
                EditorMutationResult => $this->runtime->userManagement()
                    ->resumeEditor($token, $csrf, $target)
        );
    }

    public function resendEditorInvitation(Request $request): Response
    {
        return $this->stateMutation(
            $request,
            self::USERS_INVITE,
            fn (string $token, string $csrf, string $target):
                EditorMutationResult => $this->runtime->userManagement()
                    ->resendInvitation($token, $csrf, $target)
        );
    }

    public function usersUpdated(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsSafeNavigation($request)) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->html(200, '');
        }

        $context = $this->managementContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        return $this->html(200, $this->renderer->editorOperationCompleted(
            $this->rootPath(),
            $this->hasCapability($context['token'], self::USERS_VIEW)
        ));
    }

    public function forgotPasswordForm(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsSafeNavigation($request)) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->html(200, '');
        }

        $authCookie = $request->cookie(
            $this->runtime->config()->cookieName()
        );
        $expireAuthCookie = false;
        if ($authCookie !== null) {
            $session = $this->runtime->authentication()
                ->resolveAuthenticatedSession($authCookie);
            if (
                $session !== null
                && $this->runtime->authorization()->mayAccessWebAdmin(
                    $authCookie
                )
            ) {
                return $this->redirect($this->rootPath());
            }
            if ($session !== null) {
                $this->runtime->authentication()->revokeSession($authCookie);
            }
            $expireAuthCookie = true;
        }

        $existing = $request->cookie(
            $this->runtime->config()->preAuthenticationCookieName()
        );

        try {
            $secrets = $this->runtime->authentication()
                ->openPreAuthenticationSession(
                    $existing,
                    $request->clientIp() ?? ''
                );
        } catch (PreAuthenticationRateLimited) {
            return $this->plain(429, 'Too many requests', [
                'Retry-After' => '900',
            ]);
        }

        $response = $this->withSessionCookie(
            $this->html(200, $this->renderer->forgotPassword(
                $this->rootPath(),
                $secrets->csrfToken()
            )),
            $secrets
        );

        return $expireAuthCookie
            ? $this->withExpiredCookie($response)
            : $response;
    }

    public function requestPasswordReset(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsFormPost(
            $request,
            ['csrf', 'email']
        )) {
            return $this->plain(400, 'Bad request');
        }
        $sessionToken = $request->cookie(
            $this->runtime->config()->preAuthenticationCookieName()
        );
        if (
            $sessionToken === null
            || !$this->runtime->authentication()
                ->validatePreAuthenticationCsrf(
                    $sessionToken,
                    (string) $request->form('csrf')
                )
        ) {
            return $this->withExpiredPreAuthenticationCookie(
                $this->plain(400, 'Bad request')
            );
        }

        $authToken = $request->cookie(
            $this->runtime->config()->cookieName()
        );
        $expireAuthCookie = false;
        if ($authToken !== null) {
            $authenticated = $this->runtime->authentication()
                ->resolveAuthenticatedSession($authToken);
            if (
                $authenticated !== null
                && $this->runtime->authorization()->mayAccessWebAdmin(
                    $authToken
                )
            ) {
                $this->runtime->authentication()->revokeSession(
                    $sessionToken
                );

                return $this->withExpiredPreAuthenticationCookie(
                    $this->redirect($this->rootPath())
                );
            }
            if ($authenticated !== null) {
                $this->runtime->authentication()->revokeSession($authToken);
            }
            $expireAuthCookie = true;
        }

        $this->runtime->credentialActions()->requestPasswordReset(
            (string) $request->form('email'),
            $request->clientIp() ?? '',
            $request->header('user-agent'),
            'und'
        );
        // The form session is single-use at the HTTP boundary. Repetition
        // starts from a new generic form and remains rate-limited in domain.
        $this->runtime->authentication()->revokeSession($sessionToken);

        $response = $this->withExpiredPreAuthenticationCookie($this->redirect(
            $this->rootPath() . '/password/forgot/sent'
        ));

        return $expireAuthCookie
            ? $this->withExpiredCookie($response)
            : $response;
    }

    public function forgotPasswordSent(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsSafeNavigation($request)) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->html(200, '');
        }

        return $this->html(200, $this->renderer->forgotPasswordSent(
            $this->rootPath()
        ));
    }

    public function activate(Request $request): Response
    {
        return $this->credentialActionNavigation(
            $request,
            CredentialActionService::INVITATION,
            $this->rootPath() . '/activate'
        );
    }

    public function completeActivation(Request $request): Response
    {
        return $this->completeCredentialAction(
            $request,
            CredentialActionService::INVITATION
        );
    }

    public function resetPassword(Request $request): Response
    {
        return $this->credentialActionNavigation(
            $request,
            CredentialActionService::PASSWORD_RESET,
            $this->rootPath() . '/password/reset'
        );
    }

    public function completePasswordReset(Request $request): Response
    {
        return $this->completeCredentialAction(
            $request,
            CredentialActionService::PASSWORD_RESET
        );
    }

    public function actionUnavailable(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsSafeNavigation($request)) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->html(200, '');
        }

        return $this->withExpiredActionCookie($this->html(
            200,
            $this->renderer->actionUnavailable($this->rootPath())
        ));
    }

    public function activationCompleted(Request $request): Response
    {
        return $this->loginFormWithNotice(
            $request,
            'Tu acceso se ha activado. Ya puedes iniciar sesión.'
        );
    }

    public function passwordResetCompleted(Request $request): Response
    {
        return $this->loginFormWithNotice(
            $request,
            'Tu contraseña se ha actualizado. Ya puedes iniciar sesión.'
        );
    }

    /**
     * @return array{token: string, csrf: string, user_public_id: string}|Response
     */
    private function managementContext(
        Request $request,
        bool $requiresCsrf = false
    ): array|Response {
        $token = $request->cookie($this->runtime->config()->cookieName());
        if ($token === null) {
            return $this->redirect($this->loginPath());
        }
        $session = $this->runtime->authentication()
            ->resolveAuthenticatedSession($token);
        if ($session === null) {
            return $this->withExpiredCookie(
                $this->redirect($this->loginPath())
            );
        }
        if (!$this->runtime->authorization()->mayAccessWebAdmin($token)) {
            $this->runtime->authentication()->revokeSession($token);

            return $this->withExpiredCookie(
                $this->redirect($this->loginPath())
            );
        }

        $csrf = '';
        if ($requiresCsrf) {
            $secrets = $this->runtime->authentication()
                ->authenticatedCsrfToken($token);
            if ($secrets === null) {
                return $this->withExpiredCookie(
                    $this->redirect($this->loginPath())
                );
            }
            $csrf = $secrets->csrfToken();
        }

        return [
            'token' => $token,
            'csrf' => $csrf,
            'user_public_id' => $session->userPublicId(),
        ];
    }

    private function hasCapability(string $token, string $capability): bool
    {
        return $this->runtime->authorization()->hasCapability(
            $token,
            $capability
        );
    }

    /** @return list<WebAdminNavigationItem> */
    private function visibleModuleNavigation(string $token): array
    {
        $visible = [];

        foreach ($this->runtime->navigation()->items() as $item) {
            if ($this->hasCapability(
                $token,
                $item->requiredCapability()
            )) {
                $visible[] = $item;
            }
        }

        return $visible;
    }

    private function validSubmittedCsrf(
        Request $request,
        string $expected
    ): bool {
        $submitted = $request->form('csrf');

        return is_string($submitted)
            && ConstantTime::equals($expected, $submitted);
    }

    /** @return list<string> */
    private function submittedCapabilities(Request $request): array
    {
        $submitted = $request->form('capabilities', []);
        if (!is_array($submitted) || !array_is_list($submitted)) {
            return [];
        }

        return array_values(array_filter(
            $submitted,
            static fn (mixed $value): bool => is_string($value)
        ));
    }

    /**
     * @param list<string> $selected
     * @return list<array{code: string, label: string, selected: bool}>|null
     */
    private function delegableCapabilityRows(
        string $token,
        array $selected
    ): ?array {
        if (!$this->hasCapability(
            $token,
            self::USERS_CAPABILITIES_MANAGE
        )) {
            return [];
        }
        $catalog = $this->runtime->userManagement()
            ->delegableCapabilities($token);
        if (!$catalog instanceof DelegableCapabilityCatalog) {
            return null;
        }
        $selected = array_fill_keys($selected, true);

        return array_map(
            fn (DelegableCapability $capability): array => [
                'code' => $capability->code(),
                'label' => $this->capabilityLabel($capability),
                'selected' => isset($selected[$capability->code()]),
            ],
            $catalog->capabilities()
        );
    }

    private function capabilityLabel(DelegableCapability $capability): string
    {
        return match ($capability->labelKey()) {
            'webadmin.capabilities.users_view' => 'Consultar editores',
            'blog.capabilities.articles_view' =>
                'Consultar artículos del Blog',
            'blog.capabilities.articles_edit' =>
                'Crear y editar artículos del Blog',
            'blog.capabilities.articles_publish' =>
                'Publicar y retirar artículos del Blog',
            default => $capability->code(),
        };
    }

    /**
     * @return array{
     *     public_id: string,
     *     email: string,
     *     display_name: ?string,
     *     status: string
     * }
     */
    private function editorRow(EditorSummary $editor): array
    {
        return [
            'public_id' => $editor->publicId(),
            'email' => $editor->emailCanonical(),
            'display_name' => $editor->displayName(),
            'status' => $editor->status(),
        ];
    }

    /**
     * @param callable(string, string, string): EditorMutationResult $mutation
     */
    private function stateMutation(
        Request $request,
        string $requiredCapability,
        callable $mutation
    ): Response {
        if (!$this->requestPolicy->acceptsFormPost(
            $request,
            ['csrf', 'target']
        )) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->managementContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->validSubmittedCsrf($request, $context['csrf'])) {
            return $this->plain(403, 'Forbidden');
        }
        if (!$this->hasCapability($context['token'], $requiredCapability)) {
            return $this->plain(403, 'Forbidden');
        }

        return $this->mutationResponse($mutation(
            $context['token'],
            (string) $request->form('csrf'),
            (string) $request->form('target')
        ));
    }

    private function mutationResponse(EditorMutationResult $result): Response
    {
        return match ($result->status()) {
            EditorMutationResult::APPLIED,
            EditorMutationResult::UNCHANGED,
            EditorMutationResult::ALREADY_QUEUED =>
                $this->redirect($this->rootPath() . '/users/updated'),
            EditorMutationResult::DENIED => $this->plain(403, 'Forbidden'),
            EditorMutationResult::NOT_FOUND => $this->plain(404, 'Not found'),
            EditorMutationResult::INVALID => $this->plain(400, 'Bad request'),
            EditorMutationResult::STATE_CONFLICT =>
                $this->plain(409, 'Conflict'),
        };
    }

    private function credentialActionNavigation(
        Request $request,
        string $purpose,
        string $cleanPath
    ): Response {
        if (!$this->requestPolicy->acceptsCredentialActionNavigation(
            $request
        )) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->html(200, '');
        }

        $query = $request->queryParams();
        if ($query !== []) {
            $secrets = $this->runtime->credentialActions()->bindActionToken(
                (string) $query['token'],
                $purpose
            );
            if ($secrets === null) {
                return $this->withExpiredActionCookie($this->redirect(
                    $this->rootPath() . '/action-unavailable'
                ));
            }

            return $this->withActionCookie(
                $this->redirect($cleanPath),
                $secrets
            );
        }

        $actionSession = $request->cookie(
            $this->runtime->config()->actionCookieName()
        );
        $csrf = $actionSession === null
            ? null
            : $this->runtime->credentialActions()->boundActionCsrfToken(
                $actionSession,
                $purpose
            );
        if ($csrf === null) {
            return $this->withExpiredActionCookie($this->redirect(
                $this->rootPath() . '/action-unavailable'
            ));
        }

        return $this->html(200, $this->renderer->credentialAction(
            $this->rootPath(),
            $purpose,
            $csrf->csrfToken(),
            false
        ));
    }

    private function completeCredentialAction(
        Request $request,
        string $purpose
    ): Response {
        if (!$this->requestPolicy->acceptsFormPost(
            $request,
            ['csrf', 'password', 'password_confirmation']
        )) {
            return $this->plain(400, 'Bad request');
        }
        $actionSession = $request->cookie(
            $this->runtime->config()->actionCookieName()
        );
        if ($actionSession === null) {
            return $this->withExpiredActionCookie($this->redirect(
                $this->rootPath() . '/action-unavailable'
            ));
        }

        $csrf = $this->runtime->credentialActions()->boundActionCsrfToken(
            $actionSession,
            $purpose
        );
        if (
            $csrf === null
            || !ConstantTime::equals(
                $csrf->csrfToken(),
                (string) $request->form('csrf')
            )
        ) {
            return $this->withExpiredActionCookie($this->redirect(
                $this->rootPath() . '/action-unavailable'
            ));
        }

        $password = (string) $request->form('password');
        $confirmation = (string) $request->form('password_confirmation');
        if (!ConstantTime::equals($password, $confirmation)) {
            return $this->renderCredentialActionFailure(
                $actionSession,
                $purpose
            );
        }

        try {
            $completion = $purpose === CredentialActionService::INVITATION
                ? $this->runtime->credentialActions()->completeInvitation(
                    $actionSession,
                    (string) $request->form('csrf'),
                    $password,
                    $request->clientIp() ?? '',
                    $request->header('user-agent')
                )
                : $this->runtime->credentialActions()->completePasswordReset(
                    $actionSession,
                    (string) $request->form('csrf'),
                    $password,
                    $request->clientIp() ?? '',
                    $request->header('user-agent')
                );
        } catch (InvalidPassword) {
            return $this->renderCredentialActionFailure(
                $actionSession,
                $purpose
            );
        }

        if (!$completion->isCompleted()) {
            return $this->withExpiredActionCookie($this->redirect(
                $this->rootPath() . '/action-unavailable'
            ));
        }

        $target = $purpose === CredentialActionService::INVITATION
            ? $this->rootPath() . '/login/activated'
            : $this->rootPath() . '/login/password-reset';
        return $this->withExpiredActionCookie($this->redirect($target));
    }

    private function renderCredentialActionFailure(
        string $actionSession,
        string $purpose
    ): Response {
        $csrf = $this->runtime->credentialActions()->boundActionCsrfToken(
            $actionSession,
            $purpose
        );
        if ($csrf === null) {
            return $this->withExpiredActionCookie($this->redirect(
                $this->rootPath() . '/action-unavailable'
            ));
        }

        return $this->html(422, $this->renderer->credentialAction(
            $this->rootPath(),
            $purpose,
            $csrf->csrfToken(),
            true
        ));
    }

    private function loginFormWithNotice(
        Request $request,
        string $notice
    ): Response {
        if (!$this->requestPolicy->acceptsSafeNavigation($request)) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->html(200, '');
        }

        $authCookie = $request->cookie(
            $this->runtime->config()->cookieName()
        );
        $expireAuthCookie = false;
        if ($authCookie !== null) {
            $session = $this->runtime->authentication()
                ->resolveAuthenticatedSession($authCookie);
            if (
                $session !== null
                && $this->runtime->authorization()->mayAccessWebAdmin(
                    $authCookie
                )
            ) {
                return $this->redirect($this->rootPath());
            }
            if ($session !== null) {
                $this->runtime->authentication()->revokeSession($authCookie);
            }
            $expireAuthCookie = true;
        }
        $existing = $request->cookie(
            $this->runtime->config()->preAuthenticationCookieName()
        );
        try {
            $secrets = $this->runtime->authentication()
                ->openPreAuthenticationSession(
                    $existing,
                    $request->clientIp() ?? ''
                );
        } catch (PreAuthenticationRateLimited) {
            return $this->plain(429, 'Too many requests', [
                'Retry-After' => '900',
            ]);
        }

        $response = $this->withSessionCookie(
            $this->html(200, $this->renderer->login(
                $this->rootPath(),
                $secrets->csrfToken(),
                false,
                $notice
            )),
            $secrets
        );

        return $expireAuthCookie
            ? $this->withExpiredCookie($response)
            : $response;
    }

    private function rootPath(): string
    {
        return $this->runtime->config()->basePath();
    }

    private function loginPath(): string
    {
        return $this->rootPath() . '/login';
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

    private function withSessionCookie(
        Response $response,
        SessionSecrets $secrets
    ): Response {
        $expires = $secrets->absoluteExpiresAt()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('D, d M Y H:i:s \G\M\T');
        $authenticated = $secrets->isAuthenticated();
        $value = ($authenticated
                ? $this->runtime->config()->cookieName()
                : $this->runtime->config()->preAuthenticationCookieName())
            . '=' . rawurlencode($secrets->sessionToken())
            . '; Path=' . $this->runtime->config()->cookiePath()
            . '; Expires=' . $expires
            . '; Secure; HttpOnly; SameSite='
            . ($authenticated
                ? WebAdminConfig::COOKIE_SAME_SITE
                : WebAdminConfig::PREAUTH_COOKIE_SAME_SITE);

        $response = $response->withAddedHeader('Set-Cookie', $value);

        return $authenticated
            ? $this->withExpiredPreAuthenticationCookie($response)
            : $response;
    }

    private function withExpiredCookie(Response $response): Response
    {
        $value = $this->runtime->config()->cookieName()
            . '=; Path=' . $this->runtime->config()->cookiePath()
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0'
            . '; Secure; HttpOnly; SameSite='
            . WebAdminConfig::COOKIE_SAME_SITE;

        return $response->withAddedHeader('Set-Cookie', $value);
    }

    private function withExpiredPreAuthenticationCookie(
        Response $response
    ): Response {
        $value = $this->runtime->config()->preAuthenticationCookieName()
            . '=; Path=' . $this->runtime->config()->cookiePath()
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0'
            . '; Secure; HttpOnly; SameSite='
            . WebAdminConfig::PREAUTH_COOKIE_SAME_SITE;

        return $response->withAddedHeader('Set-Cookie', $value);
    }

    private function withActionCookie(
        Response $response,
        CredentialActionSessionSecrets $secrets
    ): Response {
        $expires = $secrets->absoluteExpiresAt()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('D, d M Y H:i:s \G\M\T');
        $value = $this->runtime->config()->actionCookieName()
            . '=' . rawurlencode($secrets->sessionToken())
            . '; Path=' . $this->runtime->config()->cookiePath()
            . '; Expires=' . $expires
            . '; Secure; HttpOnly; SameSite='
            . WebAdminConfig::ACTION_COOKIE_SAME_SITE;

        return $response->withAddedHeader('Set-Cookie', $value);
    }

    private function withExpiredActionCookie(Response $response): Response
    {
        $value = $this->runtime->config()->actionCookieName()
            . '=; Path=' . $this->runtime->config()->cookiePath()
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0'
            . '; Secure; HttpOnly; SameSite='
            . WebAdminConfig::ACTION_COOKIE_SAME_SITE;

        return $response->withAddedHeader('Set-Cookie', $value);
    }
}
