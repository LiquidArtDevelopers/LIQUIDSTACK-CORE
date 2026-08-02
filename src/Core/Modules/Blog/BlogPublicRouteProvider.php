<?php

declare(strict_types=1);

namespace App\Core\Modules\Blog;

use App\Core\Blog\Configuration\BlogConfigLoader;
use App\Core\Blog\Http\BlogPublicHttpController;
use App\Core\Blog\Http\BlogPublicMediaHttpResponseFactory;
use App\Core\Blog\Http\BlogPublicHttpRuntimeFactory;
use App\Core\Blog\Http\BlogPublicHttpRuntimeFactoryInterface;
use App\Core\Blog\PublicDelivery\BlogPublicMediaRoute;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModulePublicRouteProviderInterface;
use App\Core\Modules\ModulePreBootstrapPublicRouteProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModulePublicRouteCollection;
use Throwable;

final class BlogPublicRouteProvider implements
    ModulePublicRouteProviderInterface,
    ModulePreBootstrapPublicRouteProviderInterface
{
    private ?BlogPublicHttpController $controller = null;
    private ?ModuleRuntimeContext $context = null;

    public function __construct(
        private readonly BlogPublicHttpRuntimeFactoryInterface $runtimeFactory =
            new BlogPublicHttpRuntimeFactory(),
        private readonly BlogConfigLoader $configLoader =
            new BlogConfigLoader(),
        private readonly BlogPublicMediaHttpResponseFactory
            $mediaResponseFactory = new BlogPublicMediaHttpResponseFactory()
    ) {
    }

    public static function moduleId(): string
    {
        return 'blog';
    }

    /** @return list<string> */
    public static function publicRoutePrefixes(
        ModuleRuntimeContext $context
    ): array {
        try {
            $config = (new BlogConfigLoader())->load(
                $context->projectRoot(),
                $context->languages()
            );

            return array_values(array_unique(array_merge(
                array_values($config->publicPaths()),
                [
                    $config->sitemapPath(),
                    BlogPublicMediaRoute::PREFIX,
                ]
            )));
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    public static function preBootstrapPublicRoutePaths(
        ModuleRuntimeContext $context
    ): array {
        try {
            $config = (new BlogConfigLoader())->load(
                $context->projectRoot(),
                $context->languages()
            );

            return [$config->sitemapPath()];
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
        $this->context = $context;

        foreach ($config->publicPaths() as $locale => $base) {
            $routes->addGet(
                self::moduleId(),
                $base,
                fn (Request $request): ?Response =>
                    $this->article($request, $locale, $base)
            );
        }
        $routes->addGet(
            self::moduleId(),
            $config->sitemapPath(),
            fn (Request $request): ?Response => $this->sitemap($request)
        );
        $routes->addGet(
            self::moduleId(),
            BlogPublicMediaRoute::PREFIX,
            fn (Request $request): Response => $this->media($request)
        );
    }

    private function article(
        Request $request,
        string $locale,
        string $base
    ): ?Response {
        $path = $request->path();
        if ($path === $base || !str_starts_with($path, $base . '/')) {
            return null;
        }
        $slug = substr($path, strlen($base) + 1);
        if (
            $slug === ''
            || str_contains($slug, '/')
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
        ) {
            return null;
        }

        return $this->controller()->article($locale, $slug);
    }

    private function sitemap(Request $request): ?Response
    {
        $context = $this->requiredContext();
        $config = $this->configLoader->load(
            $context->projectRoot(),
            $context->languages()
        );
        if ($request->path() !== $config->sitemapPath()) {
            return null;
        }

        return $this->controller()->sitemap();
    }

    private function media(Request $request): Response
    {
        $head = $request->method() === 'HEAD';
        $match = BlogPublicMediaRoute::match($request->path());
        if ($match === null) {
            return $this->mediaResponseFactory->notFound($head);
        }

        return $this->controller()->media(
            $match['public_id'],
            $match['width'],
            $head
        );
    }

    private function controller(): BlogPublicHttpController
    {
        return $this->controller ??= new BlogPublicHttpController(
            $this->runtimeFactory->create($this->requiredContext())
        );
    }

    private function requiredContext(): ModuleRuntimeContext
    {
        if (!$this->context instanceof ModuleRuntimeContext) {
            throw new \RuntimeException('Blog public routes are unavailable.');
        }

        return $this->context;
    }
}
