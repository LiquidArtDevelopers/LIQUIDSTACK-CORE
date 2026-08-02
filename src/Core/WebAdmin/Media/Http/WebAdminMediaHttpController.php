<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaService;
use App\Core\WebAdmin\Security\ConstantTime;

final class WebAdminMediaHttpController
{
    private readonly WebAdminMediaHttpResponseFactory $responses;

    public function __construct(
        private readonly WebAdminMediaHttpRuntime $runtime,
        private readonly WebAdminMediaHttpRequestPolicy $requestPolicy =
            new WebAdminMediaHttpRequestPolicy(),
        private readonly WebAdminMediaHtmlRenderer $renderer =
            new WebAdminMediaHtmlRenderer(),
        ?WebAdminMediaHttpResponseFactory $responses = null
    ) {
        $this->responses = $responses
            ?? new WebAdminMediaHttpResponseFactory($runtime->config());
    }

    public function index(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsIndex($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            [MediaService::VIEW_CAPABILITY]
        );
        if ($context instanceof Response) {
            return $context;
        }
        $page = $request->query('page');
        $page = is_string($page) ? (int) $page : 1;

        try {
            $assets = $this->runtime->media()->list($page);

            if ($request->method() === 'HEAD') {
                return $this->responses->html(200, '');
            }

            return $this->responses->html(
                200,
                $this->renderer->index(
                    $this->runtime->config()->basePath(),
                    $context['csrf'],
                    $assets,
                    $this->runtime->authorization()->hasCapability(
                        $context['session'],
                        MediaService::UPLOAD_CAPABILITY
                    )
                )
            );
        } catch (MediaException) {
            return $this->responses->plain(503, 'Service unavailable');
        }
    }

    public function upload(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsUpload($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            [MediaService::VIEW_CAPABILITY, MediaService::UPLOAD_CAPABILITY],
            true
        );
        if ($context instanceof Response) {
            return $context;
        }
        $upload = $request->uploadedFile('image');
        if ($upload === null) {
            return $this->responses->plain(400, 'Bad request');
        }

        try {
            $this->runtime->media()->upload(
                $upload,
                (string) $request->form('label'),
                $context['session'],
                $context['csrf'],
                $request->clientIp()
            );

            return $this->responses->redirect(
                $this->runtime->config()->basePath() . '/media/updated'
            );
        } catch (MediaException $exception) {
            return match ($exception->issueCode()) {
                'webadmin.media.upload_forbidden' =>
                    $this->responses->plain(403, 'Forbidden'),
                'webadmin.media.upload_rate_limited' =>
                    $this->responses->plain(429, 'Too many requests'),
                'webadmin.media.storage_quota_exceeded' =>
                    $this->responses->plain(507, 'Insufficient storage'),
                'webadmin.media.label_invalid',
                'webadmin.media.source_type_rejected',
                'webadmin.media.source_signature_mismatch',
                'webadmin.media.source_polyglot_rejected',
                'webadmin.media.source_animation_rejected',
                'webadmin.media.source_container_invalid',
                'webadmin.media.source_multiframe_rejected',
                'webadmin.media.source_contract_rejected',
                'webadmin.media.processing_failed' =>
                    $this->responses->plain(
                        422,
                        'The image could not be processed'
                    ),
                default => $this->responses->plain(
                    503,
                    'Service unavailable'
                ),
            };
        }
    }

    public function updated(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsUpdated($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            [MediaService::VIEW_CAPABILITY]
        );
        if ($context instanceof Response) {
            return $context;
        }

        return $this->responses->html(
            200,
            $request->method() === 'HEAD' ? '' : $this->renderer->updated(
                $this->runtime->config()->basePath()
            )
        );
    }

    public function file(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsFile($request)) {
            return $this->responses->plain(400, 'Bad request');
        }
        $context = $this->authorizedContext(
            $request,
            [MediaService::VIEW_CAPABILITY]
        );
        if ($context instanceof Response) {
            return $context;
        }
        try {
            if ($request->method() === 'HEAD') {
                $metadata = $this->runtime->media()->fileMetadata(
                    (string) $request->query('asset'),
                    (int) $request->query('width')
                );

                return $metadata === null
                    ? $this->responses->plain(404, 'Not found')->withoutBody()
                    : $this->responses->avifMetadata($metadata);
            }
            $file = $this->runtime->media()->file(
                (string) $request->query('asset'),
                (int) $request->query('width')
            );
            if ($file === null) {
                return $this->responses->plain(404, 'Not found');
            }

            return $this->responses->avif($file);
        } catch (MediaException) {
            return $this->responses->plain(404, 'Not found');
        }
    }

    /**
     * @param list<string> $capabilities
     * @return array{session: string, csrf: string}|Response
     */
    private function authorizedContext(
        Request $request,
        array $capabilities,
        bool $validateSubmittedCsrf = false
    ): array|Response {
        $sessionToken = $request->cookie(
            $this->runtime->config()->cookieName()
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
        foreach ($capabilities as $capability) {
            if (!$this->runtime->authorization()->hasCapability(
                $sessionToken,
                $capability
            )) {
                return $this->responses->plain(403, 'Forbidden');
            }
        }
        $csrf = $this->runtime->authentication()
            ->authenticatedCsrfToken($sessionToken);
        if ($csrf === null) {
            return $this->responses->expireSession($this->redirectToLogin());
        }
        $csrfToken = $csrf->csrfToken();
        if ($validateSubmittedCsrf && !ConstantTime::equals(
            $csrfToken,
            (string) $request->form('csrf')
        )) {
            return $this->responses->plain(403, 'Forbidden');
        }

        return ['session' => $sessionToken, 'csrf' => $csrfToken];
    }

    private function redirectToLogin(): Response
    {
        return $this->responses->redirect(
            $this->runtime->config()->basePath() . '/login'
        );
    }
}
