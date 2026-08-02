<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Security\ConstantTime;
use App\Core\WebAdmin\UserManagement\DelegableCapability;
use App\Core\WebAdmin\UserManagement\DelegableCapabilityCatalog;
use App\Core\WebAdmin\UserManagement\EditorInviteResult;
use App\Core\WebAdmin\UserManagement\EditorMutationResult;
use App\Core\WebAdmin\UserManagement\EditorSummary;

/** Coordinates the private editor-management HTTP surface. */
final class WebAdminUserManagementHttpCoordinator
{
    private const USERS_VIEW = 'webadmin.users.view';
    private const USERS_INVITE = 'webadmin.users.invite';
    private const USERS_SUSPEND = 'webadmin.users.suspend';
    private const USERS_CAPABILITIES_MANAGE =
        'webadmin.users.capabilities.manage';

    public function __construct(
        private readonly WebAdminHttpRuntime $runtime,
        private readonly WebAdminHttpRequestPolicy $requestPolicy,
        private readonly WebAdminHtmlRenderer $renderer,
        private readonly WebAdminHttpResponseFactory $responses
    ) {
    }

    public function users(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsUserListNavigation($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->responses->html(200, '');
        }

        $context = $this->managementContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->hasCapability($context['token'], self::USERS_VIEW)) {
            return $this->responses->plain(403, 'Forbidden');
        }

        $cursor = $request->query('after');
        $page = $this->runtime->userManagement()->listEditors(
            $context['token'],
            50,
            is_string($cursor) ? $cursor : null
        );
        if ($page === null) {
            return $this->hasCapability($context['token'], self::USERS_VIEW)
                ? $this->responses->plain(400, 'Bad request')
                : $this->responses->plain(403, 'Forbidden');
        }

        return $this->responses->html(200, $this->renderer->editorList(
            $this->responses->rootPath(),
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
            return $this->responses->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->responses->html(200, '');
        }

        $context = $this->managementContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->hasCapability($context['token'], self::USERS_INVITE)) {
            return $this->responses->plain(403, 'Forbidden');
        }

        $capabilities = $this->delegableCapabilityRows(
            $context['token'],
            []
        );
        if ($capabilities === null) {
            return $this->responses->plain(403, 'Forbidden');
        }

        return $this->responses->html(200, $this->renderer->editorInvite(
            $this->responses->rootPath(),
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
            return $this->responses->plain(400, 'Bad request');
        }

        $context = $this->managementContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->validSubmittedCsrf($request, $context['csrf'])) {
            return $this->responses->plain(403, 'Forbidden');
        }
        if (!$this->hasCapability($context['token'], self::USERS_INVITE)) {
            return $this->responses->plain(403, 'Forbidden');
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
            return $this->responses->redirect(
                $this->responses->rootPath() . '/users/updated'
            );
        }
        if ($result->status() === EditorInviteResult::DENIED) {
            return $this->responses->plain(403, 'Forbidden');
        }

        $rows = $this->delegableCapabilityRows(
            $context['token'],
            $capabilities
        );
        if ($rows === null) {
            return $this->responses->plain(403, 'Forbidden');
        }

        return $this->responses->html(422, $this->renderer->editorInvite(
            $this->responses->rootPath(),
            $context['csrf'],
            $rows,
            true
        ));
    }

    public function editEditor(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsUserDetailNavigation($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->responses->html(200, '');
        }

        $context = $this->managementContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->hasCapability($context['token'], self::USERS_VIEW)) {
            return $this->responses->plain(403, 'Forbidden');
        }

        $target = (string) $request->query('user');
        $editor = $this->runtime->userManagement()->editorDetail(
            $context['token'],
            $target
        );
        if ($editor === null) {
            return $this->hasCapability($context['token'], self::USERS_VIEW)
                ? $this->responses->plain(404, 'Not found')
                : $this->responses->plain(403, 'Forbidden');
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
            return $this->responses->plain(403, 'Forbidden');
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

        return $this->responses->html(200, $this->renderer->editorDetail(
            $this->responses->rootPath(),
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
            return $this->responses->plain(400, 'Bad request');
        }

        $context = $this->managementContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->validSubmittedCsrf($request, $context['csrf'])) {
            return $this->responses->plain(403, 'Forbidden');
        }
        if (!$this->hasCapability(
            $context['token'],
            self::USERS_CAPABILITIES_MANAGE
        )) {
            return $this->responses->plain(403, 'Forbidden');
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
            return $this->responses->plain(400, 'Bad request');
        }
        if ($request->method() === 'HEAD') {
            return $this->responses->html(200, '');
        }

        $context = $this->managementContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        return $this->responses->html(
            200,
            $this->renderer->editorOperationCompleted(
                $this->responses->rootPath(),
                $this->hasCapability(
                    $context['token'],
                    self::USERS_VIEW
                )
            )
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

        $csrf = '';
        if ($requiresCsrf) {
            $secrets = $this->runtime->authentication()
                ->authenticatedCsrfToken($token);
            if ($secrets === null) {
                return $this->responses->withExpiredCookie(
                    $this->responses->redirect(
                        $this->responses->loginPath()
                    )
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
            'webadmin.capabilities.media_view' =>
                'Consultar la biblioteca de medios',
            'webadmin.capabilities.media_upload' =>
                'Subir imágenes a la biblioteca',
            'blog.capabilities.articles_view' =>
                'Consultar artículos del Blog',
            'blog.capabilities.articles_edit' =>
                'Crear y editar artículos del Blog',
            'blog.capabilities.articles_publish' =>
                'Publicar y retirar artículos del Blog',
            'blog.capabilities.categories_view' =>
                'Consultar categorías del Blog',
            'blog.capabilities.categories_edit' =>
                'Crear y editar categorías del Blog',
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
            return $this->responses->plain(400, 'Bad request');
        }
        $context = $this->managementContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->validSubmittedCsrf($request, $context['csrf'])) {
            return $this->responses->plain(403, 'Forbidden');
        }
        if (!$this->hasCapability($context['token'], $requiredCapability)) {
            return $this->responses->plain(403, 'Forbidden');
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
                $this->responses->redirect(
                    $this->responses->rootPath() . '/users/updated'
                ),
            EditorMutationResult::DENIED =>
                $this->responses->plain(403, 'Forbidden'),
            EditorMutationResult::NOT_FOUND =>
                $this->responses->plain(404, 'Not found'),
            EditorMutationResult::INVALID =>
                $this->responses->plain(400, 'Bad request'),
            EditorMutationResult::STATE_CONFLICT =>
                $this->responses->plain(409, 'Conflict'),
        };
    }
}
