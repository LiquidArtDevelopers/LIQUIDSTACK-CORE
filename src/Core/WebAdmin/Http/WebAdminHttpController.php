<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * Stable route-facing facade for WebAdmin HTTP operations.
 *
 * Route providers and consumers keep depending on this class while focused
 * coordinators own authentication, credential-action and user-management
 * orchestration internally.
 */
final class WebAdminHttpController
{
    private readonly WebAdminAuthenticationHttpCoordinator $authentication;
    private readonly WebAdminCredentialActionHttpCoordinator $credentials;
    private readonly WebAdminUserManagementHttpCoordinator $users;

    public function __construct(
        WebAdminHttpRuntime $runtime,
        ?WebAdminHttpRequestPolicy $requestPolicy = null,
        ?WebAdminHtmlRenderer $renderer = null
    ) {
        $requestPolicy ??= new WebAdminHttpRequestPolicy();
        $renderer ??= new WebAdminHtmlRenderer();
        $responses = new WebAdminHttpResponseFactory($runtime->config());

        $this->authentication = new WebAdminAuthenticationHttpCoordinator(
            $runtime,
            $requestPolicy,
            $renderer,
            $responses
        );
        $this->credentials = new WebAdminCredentialActionHttpCoordinator(
            $runtime,
            $requestPolicy,
            $renderer,
            $responses
        );
        $this->users = new WebAdminUserManagementHttpCoordinator(
            $runtime,
            $requestPolicy,
            $renderer,
            $responses
        );
    }

    public function root(Request $request): Response
    {
        return $this->authentication->root($request);
    }

    public function loginForm(Request $request): Response
    {
        return $this->authentication->loginForm($request);
    }

    public function login(Request $request): Response
    {
        return $this->authentication->login($request);
    }

    public function logout(Request $request): Response
    {
        return $this->authentication->logout($request);
    }

    public function users(Request $request): Response
    {
        return $this->users->users($request);
    }

    public function inviteEditorForm(Request $request): Response
    {
        return $this->users->inviteEditorForm($request);
    }

    public function inviteEditor(Request $request): Response
    {
        return $this->users->inviteEditor($request);
    }

    public function editEditor(Request $request): Response
    {
        return $this->users->editEditor($request);
    }

    public function replaceEditorCapabilities(Request $request): Response
    {
        return $this->users->replaceEditorCapabilities($request);
    }

    public function suspendEditor(Request $request): Response
    {
        return $this->users->suspendEditor($request);
    }

    public function resumeEditor(Request $request): Response
    {
        return $this->users->resumeEditor($request);
    }

    public function resendEditorInvitation(Request $request): Response
    {
        return $this->users->resendEditorInvitation($request);
    }

    public function usersUpdated(Request $request): Response
    {
        return $this->users->usersUpdated($request);
    }

    public function forgotPasswordForm(Request $request): Response
    {
        return $this->credentials->forgotPasswordForm($request);
    }

    public function requestPasswordReset(Request $request): Response
    {
        return $this->credentials->requestPasswordReset($request);
    }

    public function forgotPasswordSent(Request $request): Response
    {
        return $this->credentials->forgotPasswordSent($request);
    }

    public function activate(Request $request): Response
    {
        return $this->credentials->activate($request);
    }

    public function completeActivation(Request $request): Response
    {
        return $this->credentials->completeActivation($request);
    }

    public function resetPassword(Request $request): Response
    {
        return $this->credentials->resetPassword($request);
    }

    public function completePasswordReset(Request $request): Response
    {
        return $this->credentials->completePasswordReset($request);
    }

    public function actionUnavailable(Request $request): Response
    {
        return $this->credentials->actionUnavailable($request);
    }

    public function activationCompleted(Request $request): Response
    {
        return $this->authentication->activationCompleted($request);
    }

    public function passwordResetCompleted(Request $request): Response
    {
        return $this->authentication->passwordResetCompleted($request);
    }
}
