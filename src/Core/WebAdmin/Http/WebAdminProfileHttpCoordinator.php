<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use App\Core\Http\Request;
use App\Core\Http\Response;

/** SSR boundary for the authenticated actor's live public profile. */
final class WebAdminProfileHttpCoordinator
{
    public function __construct(
        private readonly WebAdminHttpRuntime $runtime,
        private readonly WebAdminHttpRequestPolicy $requestPolicy,
        private readonly WebAdminHtmlRenderer $renderer,
        private readonly WebAdminHttpResponseFactory $responses,
        private readonly WebAdminShellContextFactory $shells
    ) {
    }

    public function profile(Request $request): Response
    {
        if (!$this->runtime->profilesReady()) {
            return $this->responses->plain(404, 'Not found');
        }
        if (!$this->requestPolicy->acceptsProfileNavigation($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        $token = $request->cookie($this->runtime->config()->cookieName());
        if ($token === null) {
            return $this->responses->redirect($this->responses->loginPath());
        }
        $profile = $this->runtime->profiles()->current($token);
        $csrf = $this->runtime->authentication()
            ->authenticatedCsrfToken($token);
        if ($profile === null || $csrf === null) {
            return $this->responses->withExpiredCookie(
                $this->responses->redirect($this->responses->loginPath())
            );
        }
        if ($request->method() === 'HEAD') {
            return $this->responses->html(200, '');
        }
        return $this->responses->html(200, $this->renderer->profile(
            $this->responses->rootPath(),
            $csrf->csrfToken(),
            $profile,
            $request->query('updated') === '1',
            $this->shells->create($token, $csrf->csrfToken(), '/profile')
        ));
    }

    public function saveProfile(Request $request): Response
    {
        if (!$this->runtime->profilesReady()) {
            return $this->responses->plain(404, 'Not found');
        }
        if (!$this->requestPolicy->acceptsFormPost(
            $request,
            ['csrf', 'display_name', 'lock_version', 'time_zone']
        )) {
            return $this->responses->plain(400, 'Bad request');
        }
        $token = $request->cookie($this->runtime->config()->cookieName());
        if ($token === null) {
            return $this->responses->redirect($this->responses->loginPath());
        }
        $lock = filter_var(
            $request->form('lock_version'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );
        if (!is_int($lock)) {
            return $this->responses->plain(400, 'Bad request');
        }
        try {
            $saved = $this->runtime->profiles()->updateCurrent(
                $token,
                (string) $request->form('csrf'),
                (string) $request->form('display_name'),
                (string) $request->form('time_zone'),
                $lock
            );
        } catch (\InvalidArgumentException) {
            return $this->responses->plain(400, 'Bad request');
        }
        if (!$saved) {
            return $this->responses->plain(409, 'Conflict');
        }
        return $this->responses->redirect(
            $this->responses->rootPath() . '/profile?updated=1'
        );
    }
}
