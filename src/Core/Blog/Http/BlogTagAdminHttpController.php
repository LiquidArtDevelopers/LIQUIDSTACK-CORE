<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\Tags\BlogTag;
use App\Core\Blog\Tags\BlogTagCapabilities;
use App\Core\Blog\Tags\BlogTagException;
use App\Core\Blog\Tags\BlogTagService;
use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Http\WebAdminPageAssets;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellContextFactory;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Security\ConstantTime;
use Closure;
use PDO;
use Throwable;

/** Localized tag assignment endpoint for the structured Blog editor. */
final class BlogTagAdminHttpController
{
    public const EDIT_CAPABILITY = BlogTagCapabilities::EDIT;
    public const VIEW_CAPABILITY = BlogTagCapabilities::VIEW;

    /** @var array<string, mixed> */
    private readonly array $environment;
    private readonly WebAdminShellContextFactory $shellContexts;

    public function __construct(
        private readonly BlogTagAdminHttpRuntimeInterface $runtime,
        private readonly BlogTagAdminRequestPolicy $requestPolicy =
            new BlogTagAdminRequestPolicy(),
        private readonly BlogTagAdminHtmlRenderer $renderer =
            new BlogTagAdminHtmlRenderer(),
        private readonly PrivateRouteTransportPolicy $transportPolicy =
            new PrivateRouteTransportPolicy(),
        #[\SensitiveParameter] array $environment = []
    ) {
        $this->shellContexts = new WebAdminShellContextFactory(
            $runtime->webAdminConfig()->basePath(),
            $runtime->authorization(),
            $this->navigationCatalog($runtime)
        );
        $this->environment = $environment;
    }

    public function saveAssignment(Request $request): Response
    {
        if (
            !$this->transportPolicy->accepts($request, $this->environment)
            || !$this->requestPolicy->acceptsAssignmentSave($request)
        ) {
            return $this->failure($request, 400, 'bad_request');
        }
        if (!$this->runtime->tagsReady()) {
            return $this->failure($request, 503, 'unavailable');
        }
        $service = $this->runtime->tagService();
        if (!$service instanceof BlogTagService) {
            return $this->failure($request, 503, 'unavailable');
        }
        $context = $this->authorizedContext($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $locale = (string) $request->form('locale');
        if (!$this->isActiveLocale($locale)) {
            return $this->failure($request, 422, 'invalid_input');
        }

        try {
            $result = $service->assignToVariant(
                $this->tagMutationGate(
                    $context['session'],
                    (string) $request->form('csrf')
                ),
                (string) $request->form('post'),
                $locale,
                (int) $request->form('lock_version'),
                (int) $request->form('tag_workspace_version'),
                (string) $request->form('tags')
            );
            if ($this->isAsyncJsonRequest($request)) {
                return $this->json(200, [
                    'ok' => true,
                    'lock_version' => $result->lockVersion(),
                    'tag_workspace_version' => $result->workspaceVersion(),
                    'tags' => array_map(
                        static fn (BlogTag $tag): array => [
                            'name' => $tag->name(),
                            'slug' => $tag->slug(),
                        ],
                        $result->tags()
                    ),
                ]);
            }

            return $this->redirect($this->editorLocation(
                (string) $request->form('post'),
                $locale
            ));
        } catch (BlogTagException|BlogException $exception) {
            return $this->domainFailure($request, $context, $exception);
        } catch (Throwable) {
            return $this->failure($request, 503, 'unavailable');
        }
    }

    /**
     * @param array{session: string, csrf: string} $context
     */
    private function domainFailure(
        Request $request,
        #[\SensitiveParameter] array $context,
        BlogTagException|BlogException $exception
    ): Response {
        [$status, $code] = $this->domainIssue($exception);
        if ($this->isAsyncJsonRequest($request)) {
            return $this->json($status, ['ok' => false, 'error' => $code]);
        }
        if (!in_array($status, [409, 422, 503], true)) {
            return $this->plain($status, $this->statusText($status));
        }
        $message = match ($status) {
            409 => 'Las etiquetas han cambiado en otra sesi&oacute;n. '
                . 'Conservamos el texto enviado para que puedas revisarlo.',
            422 => 'Revisa las etiquetas: alguna no cumple el formato o los '
                . 'l&iacute;mites permitidos.',
            default => 'No se han podido guardar las etiquetas. Conservamos '
                . 'el texto enviado para reintentarlo.',
        };

        return $this->html(
            $status,
            $this->renderer->assignmentFailure(
                $this->basePath(),
                $context['csrf'],
                (string) $request->form('post'),
                (string) $request->form('locale'),
                (int) $request->form('lock_version'),
                (int) $request->form('tag_workspace_version'),
                (string) $request->form('tags'),
                html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $status !== 409,
                $this->shellContext($context)
            )
        );
    }

    /** @return array{int, string} */
    private function domainIssue(
        BlogTagException|BlogException $exception
    ): array {
        if ($exception instanceof BlogException) {
            return match ($exception->issueCode()) {
                BlogException::ACTOR_GATE_FAILED => [403, 'forbidden'],
                BlogException::POST_NOT_FOUND,
                BlogException::VARIANT_NOT_FOUND => [404, 'not_found'],
                BlogException::INVALID_INPUT => [422, 'invalid_input'],
                default => [503, 'unavailable'],
            };
        }

        return match ($exception->issueCode()) {
            BlogTagException::INVALID_INPUT => [422, 'invalid_input'],
            BlogTagException::VARIANT_NOT_FOUND => [404, 'not_found'],
            BlogTagException::LOCK_CONFLICT => [409, 'conflict'],
            default => [503, 'unavailable'],
        };
    }

    /** @return array{session: string, csrf: string}|Response */
    private function authorizedContext(
        Request $request,
        bool $submittedCsrf
    ): array|Response {
        $sessionToken = $request->cookie(
            $this->runtime->webAdminConfig()->cookieName()
        );
        if ($sessionToken === null) {
            return $this->redirectToLogin();
        }
        if ($this->runtime->authentication()->resolveAuthenticatedSession(
            $sessionToken
        ) === null) {
            return $this->withExpiredCookie($this->redirectToLogin());
        }
        if (!$this->runtime->authorization()->mayAccessWebAdmin($sessionToken)) {
            $this->runtime->authentication()->revokeSession($sessionToken);

            return $this->withExpiredCookie($this->redirectToLogin());
        }
        foreach ([self::VIEW_CAPABILITY, self::EDIT_CAPABILITY] as $capability) {
            if (!$this->runtime->authorization()->hasCapability(
                $sessionToken,
                $capability
            )) {
                return $this->failure($request, 403, 'forbidden');
            }
        }
        $csrf = $this->runtime->authentication()->authenticatedCsrfToken(
            $sessionToken
        );
        if ($csrf === null) {
            return $this->withExpiredCookie($this->redirectToLogin());
        }
        if ($submittedCsrf && !ConstantTime::equals(
            $csrf->csrfToken(),
            (string) $request->form('csrf')
        )) {
            return $this->failure($request, 403, 'forbidden');
        }

        return ['session' => $sessionToken, 'csrf' => $csrf->csrfToken()];
    }

    /** @return Closure(PDO): string */
    private function tagMutationGate(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken
    ): Closure {
        $capabilities = [self::VIEW_CAPABILITY, self::EDIT_CAPABILITY];
        if ($this->runtime instanceof BlogStructuredEditorHttpRuntimeInterface) {
            return $this->runtime->mutationGateAll(
                $sessionToken,
                $csrfToken,
                $capabilities
            );
        }

        $gates = array_map(
            fn (string $capability): Closure => $this->runtime->mutationGate(
                $sessionToken,
                $csrfToken,
                $capability
            ),
            $capabilities
        );

        return static function (PDO $pdo) use ($gates): string {
            try {
                $actor = null;
                foreach ($gates as $gate) {
                    $candidate = $gate($pdo);
                    if ($actor !== null && !hash_equals($actor, $candidate)) {
                        throw new BlogException(
                            BlogException::ACTOR_GATE_FAILED
                        );
                    }
                    $actor = $candidate;
                }
                if ($actor === null) {
                    throw new BlogException(BlogException::ACTOR_GATE_FAILED);
                }

                return $actor;
            } catch (Throwable) {
                throw new BlogException(BlogException::ACTOR_GATE_FAILED);
            }
        };
    }

    /** @param array{session: string, csrf: string} $context */
    private function shellContext(
        #[\SensitiveParameter] array $context
    ): WebAdminShellContext {
        return $this->shellContexts->create(
            $context['session'],
            $context['csrf'],
            '/blog/editor',
            assets: new WebAdminPageAssets([
                '/assets/modules/blog/blog-admin.css',
            ], [
                '/assets/modules/blog/blog-editor.js',
            ])
        );
    }

    private function navigationCatalog(
        BlogTagAdminHttpRuntimeInterface $runtime
    ): WebAdminNavigationCatalog {
        if (method_exists($runtime, 'navigation')) {
            $navigation = $runtime->navigation();
            if ($navigation instanceof WebAdminNavigationCatalog) {
                return $navigation;
            }
        }

        return new WebAdminNavigationCatalog();
    }

    private function isActiveLocale(string $locale): bool
    {
        return in_array($locale, $this->runtime->languages(), true)
            && $this->runtime->blogConfig()->publicPath($locale) !== null;
    }

    private function basePath(): string
    {
        return rtrim($this->runtime->webAdminConfig()->basePath(), '/')
            . '/blog/tags';
    }

    private function editorLocation(string $post, string $locale): string
    {
        $base = rtrim($this->runtime->webAdminConfig()->basePath(), '/');

        return $base . '/blog/editor?'
            . http_build_query([
                'post' => $post,
                'locale' => $locale,
            ], '', '&', PHP_QUERY_RFC3986)
            . '#blog-editor-tags-title';
    }

    private function failure(
        Request $request,
        int $status,
        string $code
    ): Response {
        if ($this->isAsyncJsonRequest($request)) {
            return $this->json($status, ['ok' => false, 'error' => $code]);
        }

        return $this->plain($status, $this->statusText($status));
    }

    private function statusText(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            403 => 'Forbidden',
            404 => 'Not found',
            409 => 'Conflict',
            422 => 'Unprocessable content',
            default => 'Service unavailable',
        };
    }

    private function isAsyncJsonRequest(Request $request): bool
    {
        return strtolower(trim((string) $request->header(
            'x-liquidstack-tag-editor'
        ))) === 'async'
            && preg_match(
                '/(?:^|,)\s*application\/json(?:\s*;[^,]*)?(?:,|$)/i',
                (string) $request->header('accept')
            ) === 1;
    }

    private function redirectToLogin(): Response
    {
        return $this->redirect(
            $this->runtime->webAdminConfig()->basePath() . '/login'
        );
    }

    private function withExpiredCookie(Response $response): Response
    {
        $config = $this->runtime->webAdminConfig();

        return $response->withAddedHeader(
            'Set-Cookie',
            $config->cookieName() . '=; Path=' . $config->cookiePath()
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0'
            . '; Secure; HttpOnly; SameSite=' . WebAdminConfig::COOKIE_SAME_SITE
        );
    }

    private function html(int $status, string $body): Response
    {
        return new Response($status, $body, $this->headers(
            "default-src 'none'; style-src 'self'; script-src 'self'; "
            . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
        ) + [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Language' => 'es',
        ]);
    }

    private function plain(int $status, string $body): Response
    {
        return new Response($status, $body, $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; "
            . "base-uri 'none'"
        ) + ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** @param array<string, mixed> $payload */
    private function json(int $status, array $payload): Response
    {
        return new Response(
            $status,
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            ),
            $this->headers(
                "default-src 'none'; form-action 'none'; "
                . "frame-ancestors 'none'; base-uri 'none'"
            ) + ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    private function redirect(string $path): Response
    {
        return new Response(303, '', ['Location' => $path] + $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; "
            . "base-uri 'none'"
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
        ];
    }
}
