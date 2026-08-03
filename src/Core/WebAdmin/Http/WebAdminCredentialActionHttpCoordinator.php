<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Authentication\PreAuthenticationRateLimited;
use App\Core\WebAdmin\CredentialAction\CredentialActionService;
use App\Core\WebAdmin\Security\ConstantTime;
use App\Core\WebAdmin\Security\InvalidPassword;

/** Coordinates password recovery, invitation and reset HTTP flows. */
final class WebAdminCredentialActionHttpCoordinator
{
    public function __construct(
        private readonly WebAdminHttpRuntime $runtime,
        private readonly WebAdminHttpRequestPolicy $requestPolicy,
        private readonly WebAdminHtmlRenderer $renderer,
        private readonly WebAdminHttpResponseFactory $responses
    ) {
    }

    public function forgotPasswordForm(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsSafeNavigation($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->responses->html(200, '');
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
                return $this->responses->redirect(
                    $this->responses->rootPath()
                );
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
            return $this->responses->plain(429, 'Too many requests', [
                'Retry-After' => '900',
            ]);
        }

        $response = $this->responses->withSessionCookie(
            $this->responses->html(200, $this->renderer->forgotPassword(
                $this->responses->rootPath(),
                $secrets->csrfToken()
            )),
            $secrets
        );

        return $expireAuthCookie
            ? $this->responses->withExpiredCookie($response)
            : $response;
    }

    public function requestPasswordReset(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsFormPost(
            $request,
            ['csrf', 'email']
        )) {
            return $this->responses->plain(400, 'Bad request');
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
            return $this->responses->withExpiredPreAuthenticationCookie(
                $this->responses->plain(400, 'Bad request')
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

                return $this->responses
                    ->withExpiredPreAuthenticationCookie(
                        $this->responses->redirect(
                            $this->responses->rootPath()
                        )
                    );
            }
            if ($authenticated !== null) {
                $this->runtime->authentication()->revokeSession($authToken);
            }
            $expireAuthCookie = true;
        }

        // Consume the form session before contacting SMTP. A slow transport
        // must not leave the same CSRF/session pair reusable for a second POST.
        $this->runtime->authentication()->revokeSession($sessionToken);
        $result = $this->runtime->credentialActions()->requestPasswordReset(
            (string) $request->form('email'),
            $request->clientIp() ?? '',
            $request->header('user-agent'),
            'und'
        );

        $response = $this->responses->withExpiredPreAuthenticationCookie(
            $this->responses->redirect(
                $this->responses->rootPath()
                    . ($result->deliveryFailed()
                        ? '/password/forgot/unavailable'
                        : '/password/forgot/sent')
            )
        );

        return $expireAuthCookie
            ? $this->responses->withExpiredCookie($response)
            : $response;
    }

    public function forgotPasswordSent(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsSafeNavigation($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->responses->html(200, '');
        }

        return $this->responses->html(
            200,
            $this->renderer->forgotPasswordSent(
                $this->responses->rootPath()
            )
        );
    }

    public function forgotPasswordUnavailable(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsSafeNavigation($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->responses->html(200, '');
        }

        return $this->responses->html(
            200,
            $this->renderer->forgotPasswordUnavailable(
                $this->responses->rootPath()
            )
        );
    }

    public function activate(Request $request): Response
    {
        return $this->credentialActionNavigation(
            $request,
            CredentialActionService::INVITATION,
            $this->responses->rootPath() . '/activate'
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
            $this->responses->rootPath() . '/password/reset'
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
            return $this->responses->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->responses->html(200, '');
        }

        return $this->responses->withExpiredActionCookie(
            $this->responses->html(
                200,
                $this->renderer->actionUnavailable(
                    $this->responses->rootPath()
                )
            )
        );
    }

    private function credentialActionNavigation(
        Request $request,
        string $purpose,
        string $cleanPath
    ): Response {
        if (!$this->requestPolicy->acceptsCredentialActionNavigation(
            $request
        )) {
            return $this->responses->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->responses->html(200, '');
        }

        $query = $request->queryParams();
        if ($query !== []) {
            $secrets = $this->runtime->credentialActions()->bindActionToken(
                (string) $query['token'],
                $purpose
            );
            if ($secrets === null) {
                return $this->responses->withExpiredActionCookie(
                    $this->responses->redirect(
                        $this->responses->rootPath() . '/action-unavailable'
                    )
                );
            }

            return $this->responses->withActionCookie(
                $this->responses->redirect($cleanPath),
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
            return $this->responses->withExpiredActionCookie(
                $this->responses->redirect(
                    $this->responses->rootPath() . '/action-unavailable'
                )
            );
        }

        return $this->responses->html(200, $this->renderer->credentialAction(
            $this->responses->rootPath(),
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
            return $this->responses->plain(400, 'Bad request');
        }
        $actionSession = $request->cookie(
            $this->runtime->config()->actionCookieName()
        );
        if ($actionSession === null) {
            return $this->responses->withExpiredActionCookie(
                $this->responses->redirect(
                    $this->responses->rootPath() . '/action-unavailable'
                )
            );
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
            return $this->responses->withExpiredActionCookie(
                $this->responses->redirect(
                    $this->responses->rootPath() . '/action-unavailable'
                )
            );
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
                : $this->runtime->credentialActions()
                    ->completePasswordReset(
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
            return $this->responses->withExpiredActionCookie(
                $this->responses->redirect(
                    $this->responses->rootPath() . '/action-unavailable'
                )
            );
        }

        $target = $purpose === CredentialActionService::INVITATION
            ? $this->responses->rootPath() . '/login/activated'
            : $this->responses->rootPath() . '/login/password-reset';

        return $this->responses->withExpiredActionCookie(
            $this->responses->redirect($target)
        );
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
            return $this->responses->withExpiredActionCookie(
                $this->responses->redirect(
                    $this->responses->rootPath() . '/action-unavailable'
                )
            );
        }

        return $this->responses->html(422, $this->renderer->credentialAction(
            $this->responses->rootPath(),
            $purpose,
            $csrf->csrfToken(),
            true
        ));
    }
}
