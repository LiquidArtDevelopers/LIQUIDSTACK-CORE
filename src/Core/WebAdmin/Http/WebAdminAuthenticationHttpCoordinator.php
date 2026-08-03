<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Authentication\PreAuthenticationRateLimited;

/**
 * Coordinates the authenticated shell, login and logout HTTP flows.
 *
 * The public route-facing API remains on WebAdminHttpController. This class
 * exists only to keep authentication orchestration isolated from credential
 * actions and editor management.
 */
final class WebAdminAuthenticationHttpCoordinator
{
    public function __construct(
        private readonly WebAdminHttpRuntime $runtime,
        private readonly WebAdminHttpRequestPolicy $requestPolicy,
        private readonly WebAdminHtmlRenderer $renderer,
        private readonly WebAdminHttpResponseFactory $responses,
        private readonly WebAdminShellContextFactory $shells
    ) {
    }

    public function root(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsSafeNavigation($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->responses->html(200, '');
        }

        $token = $request->cookie($this->runtime->config()->cookieName());
        if ($token === null) {
            return $this->responses->redirect($this->responses->loginPath());
        }
        $session = $this->runtime->authentication()
            ->resolveAuthenticatedSession($token);
        if ($session === null) {
            return $this->responses->withExpiredCookie(
                $this->responses->redirect($this->responses->loginPath())
            );
        }
        if (!$this->runtime->authorization()->mayAccessWebAdmin($token)) {
            $this->runtime->authentication()->revokeSession($token);

            return $this->responses->withExpiredCookie(
                $this->responses->redirect($this->responses->loginPath())
            );
        }

        $csrf = $this->runtime->authentication()
            ->authenticatedCsrfToken($token);
        if ($csrf === null) {
            return $this->responses->withExpiredCookie(
                $this->responses->redirect($this->responses->loginPath())
            );
        }

        return $this->responses->html(200, $this->renderer->dashboard(
            basePath: $this->responses->rootPath(),
            csrf: $csrf->csrfToken(),
            shell: $this->shells->create(
                $token,
                $csrf->csrfToken(),
                ''
            )
        ));
    }

    public function loginForm(Request $request): Response
    {
        return $this->loginFormWithNotice($request, null);
    }

    public function login(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsFormPost(
            $request,
            ['csrf', 'email', 'password']
        )) {
            return $this->responses->plain(400, 'Bad request');
        }
        $token = $request->cookie(
            $this->runtime->config()->preAuthenticationCookieName()
        );
        if ($token === null) {
            return $this->responses->plain(400, 'Bad request');
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

                return $this->responses->withExpiredPreAuthenticationCookie(
                    $this->responses->redirect($this->responses->rootPath())
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
            $response = $this->responses
                ->withExpiredPreAuthenticationCookie($this->responses->plain(
                    429,
                    'Too many requests',
                    ['Retry-After' => '900']
                ));

            return $expireAuthCookie
                ? $this->responses->withExpiredCookie($response)
                : $response;
        }
        $secrets = $attempt->nextSession();
        if (!$attempt->isSuccessful()) {
            $response = $this->responses->withSessionCookie(
                $this->responses->html(
                    401,
                    $this->renderer->login(
                        $this->responses->rootPath(),
                        $secrets->csrfToken(),
                        true
                    )
                ),
                $secrets
            );

            return $expireAuthCookie
                ? $this->responses->withExpiredCookie($response)
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
                return $this->responses
                    ->withExpiredPreAuthenticationCookie(
                        $this->responses->plain(
                            429,
                            'Too many requests',
                            ['Retry-After' => '900']
                        )
                    );
            }

            return $this->responses->withExpiredCookie(
                $this->responses->withSessionCookie(
                    $this->responses->html(
                        401,
                        $this->renderer->login(
                            $this->responses->rootPath(),
                            $replacement->csrfToken(),
                            true
                        )
                    ),
                    $replacement
                )
            );
        }

        return $this->responses->withSessionCookie(
            $this->responses->redirect($this->responses->rootPath()),
            $secrets
        );
    }

    public function logout(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsFormPost($request, ['csrf'])) {
            return $this->responses->plain(400, 'Bad request');
        }
        $token = $request->cookie($this->runtime->config()->cookieName());
        $revoked = is_string($token)
            && $this->runtime->authentication()->logout(
                $token,
                (string) $request->form('csrf'),
                $request->clientIp() ?? '',
                $request->header('user-agent')
            );

        $response = $this->responses->redirect(
            $this->responses->loginPath()
        );

        return $revoked
            ? $this->responses->withExpiredCookie($response)
            : $response;
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

    private function loginFormWithNotice(
        Request $request,
        ?string $notice
    ): Response {
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
            $this->responses->html(200, $this->renderer->login(
                $this->responses->rootPath(),
                $secrets->csrfToken(),
                false,
                $notice
            )),
            $secrets
        );

        return $expireAuthCookie
            ? $this->responses->withExpiredCookie($response)
            : $response;
    }
}
