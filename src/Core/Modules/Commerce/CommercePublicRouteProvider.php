<?php

declare(strict_types=1);

namespace App\Core\Modules\Commerce;

use App\Core\Commerce\Configuration\CommerceConfig;
use App\Core\Commerce\Configuration\CommerceConfigLoader;
use App\Core\Commerce\Http\CommerceBasketCookie;
use App\Core\Commerce\Http\CommercePublicHttpController;
use App\Core\Commerce\Http\CommercePublicHttpRuntimeFactory;
use App\Core\Commerce\Http\CommercePublicItemRenderer;
use App\Core\Commerce\Http\CommercePublicMediaHttpResponseFactory;
use App\Core\Commerce\Http\CommercePublicMediaRoute;
use App\Core\Commerce\Http\CommercePublicShellRenderer;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModulePreBootstrapPublicRouteProviderInterface;
use App\Core\Modules\ModulePublicRouteProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModulePublicRouteCollection;
use RuntimeException;
use Throwable;

final class CommercePublicRouteProvider implements
    ModulePublicRouteProviderInterface,
    ModulePreBootstrapPublicRouteProviderInterface
{
    private ?CommercePublicHttpController $controller = null;
    private ?ModuleRuntimeContext $context = null;
    private ?CommercePublicShellRenderer $shellRenderer = null;
    private ?CommerceConfig $config = null;

    public function __construct(
        private readonly CommerceConfigLoader $configLoader =
            new CommerceConfigLoader(),
        private readonly CommercePublicHttpRuntimeFactory $runtimeFactory =
            new CommercePublicHttpRuntimeFactory()
    ) {
    }

    public static function moduleId(): string
    {
        return 'commerce';
    }

    /** @return list<string> */
    public static function publicRoutePrefixes(
        ModuleRuntimeContext $context
    ): array {
        try {
            $config = (new CommerceConfigLoader())->load(
                $context->projectRoot(),
                $context->languages()
            );
            if (!$config->publicEnabled()) {
                return [];
            }

            return array_values(array_unique([
                ...array_values($config->publicPaths()),
                $config->sitemapPath(),
                CommercePublicMediaRoute::PREFIX,
            ]));
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    public static function preBootstrapPublicRoutePrefixes(
        ModuleRuntimeContext $context
    ): array {
        try {
            $config = (new CommerceConfigLoader())->load(
                $context->projectRoot(),
                $context->languages()
            );

            return $config->publicEnabled()
                ? [CommercePublicMediaRoute::PREFIX]
                : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    public static function preBootstrapPublicRoutePaths(
        ModuleRuntimeContext $context
    ): array {
        try {
            $config = (new CommerceConfigLoader())->load(
                $context->projectRoot(),
                $context->languages()
            );

            return $config->publicEnabled() ? [$config->sitemapPath()] : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function registerPublicRoutes(
        ModulePublicRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void {
        $config = $this->configLoader->load(
            $context->projectRoot(),
            $context->languages()
        );
        if (!$config->publicEnabled()) {
            return;
        }
        $this->context = $context;
        $this->config = $config;
        foreach ($config->publicPaths() as $locale => $basePath) {
            $routes->addGet(
                self::moduleId(),
                $basePath,
                fn (Request $request): ?Response => $this->item(
                    $request,
                    $locale,
                    $basePath
                )
            );
        }
        $routes->addGet(
            self::moduleId(),
            $config->sitemapPath(),
            fn (Request $request): Response =>
                $this->controller()->sitemap($request)
        );
        $routes->addGet(
            self::moduleId(),
            CommercePublicMediaRoute::PREFIX,
            fn (Request $request): Response => $this->media($request)
        );
    }

    private function media(Request $request): Response
    {
        $head = $request->method() === 'HEAD';
        $match = CommercePublicMediaRoute::match($request->path());
        if ($match === null) {
            return (new CommercePublicMediaHttpResponseFactory())
                ->notFound($head);
        }

        return $this->controller()->media(
            $match['public_id'],
            $match['width'],
            $head
        );
    }

    private function item(
        Request $request,
        string $locale,
        string $basePath
    ): ?Response {
        $path = $request->path();
        $basketToken = $this->basketToken($request);
        if ($path === $basePath) {
            return $this->shellResponse(
                $this->shellRenderer()->renderCatalog($locale),
                $basketToken !== null
            );
        }
        if (!str_starts_with($path, $basePath . '/')) {
            return null;
        }

        $inquiryPath = $this->config?->inquiryPath($locale);
        if ($inquiryPath !== null && $path === $inquiryPath) {
            return $this->shellResponse(
                $this->shellRenderer()->renderInquiry($locale),
                true
            );
        }

        return $this->controller()->item(
            $locale,
            $path,
            $basketToken
        );
    }

    private function controller(): CommercePublicHttpController
    {
        if ($this->controller instanceof CommercePublicHttpController) {
            return $this->controller;
        }
        if (!$this->context instanceof ModuleRuntimeContext) {
            throw new RuntimeException('Commerce routes are unavailable.');
        }

        return $this->controller = new CommercePublicHttpController(
            $this->runtimeFactory->create($this->context),
            new CommercePublicItemRenderer($this->context->projectRoot())
        );
    }

    private function shellRenderer(): CommercePublicShellRenderer
    {
        if ($this->shellRenderer instanceof CommercePublicShellRenderer) {
            return $this->shellRenderer;
        }
        if (!$this->context instanceof ModuleRuntimeContext) {
            throw new RuntimeException('Commerce routes are unavailable.');
        }

        return $this->shellRenderer = new CommercePublicShellRenderer(
            $this->context->projectRoot()
        );
    }

    private function shellResponse(string $html, bool $private): Response
    {
        return new Response(200, $html, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => $private
                ? 'no-store, no-cache, must-revalidate, max-age=0'
                : 'public, max-age=60, must-revalidate',
            'X-Robots-Tag' => $private
                ? 'noindex, follow'
                : 'index, follow',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ]);
    }

    private function basketToken(Request $request): ?string
    {
        if (
            !$this->context instanceof ModuleRuntimeContext
            || !$this->config instanceof CommerceConfig
        ) {
            return null;
        }
        $cookie = CommerceBasketCookie::forProject(
            $this->context->projectRoot(),
            $this->context->environment(),
            $this->config->basketTtlSeconds()
        );

        return $request->cookie($cookie->name());
    }
}
