<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaPickerQuery;
use App\Core\WebAdmin\Media\MediaService;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellContextFactory;
use App\Core\WebAdmin\Security\ConstantTime;

final class WebAdminMediaHttpController
{
    private readonly WebAdminMediaHttpResponseFactory $responses;
    private readonly WebAdminShellContextFactory $shellContexts;
    private readonly WebAdminMediaPickerHtmlRenderer $pickerRenderer;

    public function __construct(
        private readonly WebAdminMediaHttpRuntime $runtime,
        private readonly WebAdminMediaHttpRequestPolicy $requestPolicy =
            new WebAdminMediaHttpRequestPolicy(),
        private readonly WebAdminMediaHtmlRenderer $renderer =
            new WebAdminMediaHtmlRenderer(),
        ?WebAdminMediaHttpResponseFactory $responses = null,
        ?WebAdminMediaPickerHtmlRenderer $pickerRenderer = null
    ) {
        $this->responses = $responses
            ?? new WebAdminMediaHttpResponseFactory($runtime->config());
        $this->shellContexts = new WebAdminShellContextFactory(
            $runtime->config()->basePath(),
            $runtime->authorization(),
            $runtime->navigation()
        );
        $this->pickerRenderer = $pickerRenderer
            ?? new WebAdminMediaPickerHtmlRenderer();
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
            $canDelete = $this->runtime->supportsQuarantineDeletion()
                && $this->runtime->authorization()->hasCapability(
                    $context['session'],
                    MediaService::DELETE_CAPABILITY
                );

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
                    ),
                    $this->shellContext($context),
                    $this->runtime->acceptsAvifSource(),
                    $canDelete
                )
            );
        } catch (MediaException) {
            return $this->responses->plain(503, 'Service unavailable');
        }
    }

    public function upload(Request $request): Response
    {
        $async = $this->isAsyncUploadRequest($request);
        if (!$this->requestPolicy->acceptsUpload($request)) {
            return $this->uploadFailure(
                $async,
                400,
                'invalid_request',
                'La subida no cumple el contrato esperado.'
            );
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
            return $this->uploadFailure(
                $async,
                400,
                'invalid_request',
                'Selecciona una imagen válida.'
            );
        }

        try {
            $publicId = $this->runtime->media()->upload(
                $upload,
                (string) $request->form('label'),
                $context['session'],
                $context['csrf'],
                $request->clientIp(),
                (string) $request->form('idempotency_key')
            );

            if ($async) {
                $asset = $this->runtime->media()->catalogAsset($publicId);
                if ($asset === null) {
                    throw new MediaException(
                        'webadmin.media.catalog_lookup_failed'
                    );
                }
                $thumbnailUrl = rtrim(
                    $this->runtime->config()->basePath(),
                    '/'
                ) . '/media/file?' . http_build_query([
                    'asset' => $asset->publicId(),
                    'width' => (string) $asset->thumbnailWidth(),
                ], '', '&', PHP_QUERY_RFC3986);

                return $this->responses->json(200, [
                    'ok' => true,
                    'media' => [
                        'public_id' => $asset->publicId(),
                        'label' => $asset->label(),
                        'thumbnail_width' => $asset->thumbnailWidth(),
                        'thumbnail_url' => $thumbnailUrl,
                    ],
                ]);
            }

            return $this->responses->redirect(
                $this->runtime->config()->basePath() . '/media/updated'
            );
        } catch (MediaException $exception) {
            [$status, $code, $message] = match ($exception->issueCode()) {
                'webadmin.media.upload_forbidden' =>
                    [403, 'forbidden', 'No tienes permiso para subir imágenes.'],
                'webadmin.media.upload_rate_limited' =>
                    [429, 'rate_limited', 'Espera antes de volver a intentarlo.'],
                'webadmin.media.avif_source_schema_pending' =>
                    [409, 'avif_source_pending', 'La subida AVIF todavía no está disponible.'],
                'webadmin.media.idempotency_conflict' =>
                    [409, 'idempotency_conflict', 'La subida ya fue procesada con otros datos.'],
                'webadmin.media.storage_quota_exceeded' =>
                    [507, 'storage_quota_exceeded', 'No queda espacio disponible para la imagen.'],
                'webadmin.media.label_invalid',
                'webadmin.media.source_type_rejected',
                'webadmin.media.source_signature_mismatch',
                'webadmin.media.source_polyglot_rejected',
                'webadmin.media.source_animation_rejected',
                'webadmin.media.source_container_invalid',
                'webadmin.media.source_multiframe_rejected',
                'webadmin.media.source_contract_rejected',
                'webadmin.media.processing_failed' =>
                    [422, 'image_rejected', 'La imagen no se pudo procesar.'],
                default => [503, 'media_unavailable', 'La biblioteca no está disponible temporalmente.'],
            };

            return $this->uploadFailure($async, $status, $code, $message);
        }
    }

    public function catalog(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsCatalog($request)) {
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
            $query = new MediaPickerQuery(
                is_string($request->query('q'))
                    ? $request->query('q') : null,
                is_string($request->query('page'))
                    ? (int) $request->query('page') : 1,
                is_string($request->query('per_page'))
                    ? (int) $request->query('per_page')
                    : MediaPickerQuery::DEFAULT_PAGE_SIZE
            );
            $canUpload = $this->runtime->authorization()->hasCapability(
                $context['session'],
                MediaService::UPLOAD_CAPABILITY
            );
            $payload = $this->pickerRenderer->pagePayload(
                $this->runtime->config()->basePath(),
                $this->runtime->media()->picker($query),
                $canUpload,
                $this->runtime->acceptsAvifSource()
            );
            $response = $this->responses->json(200, $payload);

            return $request->method() === 'HEAD'
                ? $response->withoutBody() : $response;
        } catch (MediaException $exception) {
            return $this->responses->plain(
                $exception->issueCode()
                    === 'webadmin.media.picker_query_invalid' ? 400 : 503,
                $exception->issueCode()
                    === 'webadmin.media.picker_query_invalid'
                        ? 'Bad request' : 'Service unavailable'
            );
        }
    }

    public function delete(Request $request): Response
    {
        $async = $this->isAsyncDeleteRequest($request);
        if (!$this->requestPolicy->acceptsDelete($request)) {
            return $this->deleteFailure(
                $async,
                400,
                'invalid_request',
                'No se pudo validar la operaci&oacute;n solicitada.'
            );
        }
        $context = $this->authorizedContext(
            $request,
            [MediaService::VIEW_CAPABILITY, MediaService::DELETE_CAPABILITY],
            true
        );
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->runtime->supportsQuarantineDeletion()) {
            return $this->deleteFailure(
                $async,
                409,
                'feature_pending',
                'La retirada segura todav&iacute;a no est&aacute; disponible.'
            );
        }

        try {
            $publicId = $this->runtime->media()->delete(
                (string) $request->form('asset'),
                (string) $request->form('asset_version'),
                $context['session'],
                $context['csrf'],
                $request->clientIp(),
                (string) $request->form('idempotency_key')
            );
            if ($async) {
                $pageNumber = (int) $request->form('page');
                $page = $this->runtime->media()->list($pageNumber);
                if ($page->items() === [] && $pageNumber > 1) {
                    $page = $this->runtime->media()->list($pageNumber - 1);
                }

                return $this->responses->json(200, [
                    'ok' => true,
                    'status' => 'quarantined',
                    'asset' => $publicId,
                    'page' => $page->page(),
                    'catalog_html' => $this->renderer->catalogRegionFragment(
                        $this->runtime->config()->basePath(),
                        $context['csrf'],
                        $page,
                        true
                    ),
                ]);
            }

            return $this->responses->redirect(
                $this->runtime->config()->basePath() . '/media/deleted'
            );
        } catch (MediaException $exception) {
            [$status, $code, $message] = match ($exception->issueCode()) {
                'webadmin.media.delete_forbidden' =>
                    [403, 'forbidden', 'No tienes permiso para retirar im&aacute;genes.'],
                'webadmin.media.delete_not_found' =>
                    [404, 'not_found', 'La imagen ya no est&aacute; disponible.'],
                'webadmin.media.delete_asset_in_use' =>
                    [409, 'asset_in_use', 'La imagen est&aacute; siendo utilizada y no puede retirarse.'],
                'webadmin.media.delete_stale',
                'webadmin.media.idempotency_conflict' =>
                    [409, 'conflict', 'El estado de la imagen ha cambiado. Actualiza la biblioteca.'],
                'webadmin.media.delete_usage_unavailable' =>
                    [503, 'usage_unavailable', 'No se pudo comprobar el uso de la imagen.'],
                default =>
                    [503, 'media_unavailable', 'La retirada no est&aacute; disponible temporalmente.'],
            };

            return $this->deleteFailure($async, $status, $code, $message);
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
                $this->runtime->config()->basePath(),
                $this->shellContext($context)
            )
        );
    }

    public function deleted(Request $request): Response
    {
        if (!$this->requestPolicy->acceptsDeleted($request)) {
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
            $request->method() === 'HEAD' ? '' : $this->renderer->deleted(
                $this->runtime->config()->basePath(),
                $this->shellContext($context)
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

    private function isAsyncUploadRequest(Request $request): bool
    {
        return strtolower(trim((string) $request->header(
            'x-liquidstack-media-manager'
        ))) === 'async'
            && preg_match(
                '/(?:^|,)\s*application\/json(?:\s*;[^,]*)?(?:,|$)/i',
                (string) $request->header('accept')
            ) === 1;
    }

    private function uploadFailure(
        bool $async,
        int $status,
        string $code,
        string $message
    ): Response {
        return $async
            ? $this->responses->json($status, [
                'ok' => false,
                'error' => $code,
                'message' => $message,
            ])
            : $this->responses->plain($status, match ($status) {
                400 => 'Bad request',
                403 => 'Forbidden',
                409 => $code === 'avif_source_pending'
                    ? 'AVIF source upload is not enabled yet'
                    : 'Upload already processed',
                422 => 'The image could not be processed',
                429 => 'Too many requests',
                507 => 'Insufficient storage',
                default => 'Service unavailable',
            });
    }

    private function isAsyncDeleteRequest(Request $request): bool
    {
        return $this->isAsyncUploadRequest($request);
    }

    private function deleteFailure(
        bool $async,
        int $status,
        string $code,
        string $message
    ): Response {
        return $async
            ? $this->responses->json($status, [
                'ok' => false,
                'error' => $code,
                'message' => html_entity_decode(
                    $message,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                ),
            ])
            : $this->responses->plain($status, match ($status) {
                400 => 'Bad request',
                403 => 'Forbidden',
                404 => 'Not found',
                409 => 'Conflict',
                default => 'Service unavailable',
            });
    }

    /** @param array{session: string, csrf: string} $context */
    private function shellContext(
        #[\SensitiveParameter] array $context
    ): WebAdminShellContext {
        return $this->shellContexts->create(
            $context['session'],
            $context['csrf'],
            '/media'
        );
    }
}
