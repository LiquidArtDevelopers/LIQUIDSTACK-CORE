<?php

declare(strict_types=1);

namespace App\Core\Modules\Commerce;

use App\Core\Commerce\Http\CommerceAdminHttpController;
use App\Core\Commerce\Http\CommerceAdminHttpRuntimeFactory;
use App\Core\Commerce\Http\CommerceAdminHttpRuntimeFactoryInterface;
use App\Core\Commerce\Http\CommerceAdminRequestPolicy;
use App\Core\Http\PrivateRouteTransportPolicy;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModuleRouteProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Modules\WebAdmin\WebAdminRouteProvider;
use App\Core\Routing\ModuleRouteCollection;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Configuration\WebAdminConfigException;
use App\Core\WebAdmin\Configuration\WebAdminConfigLoader;
use App\Core\WebAdmin\Routing\WebAdminRoutePolicy;
use RuntimeException;
use Throwable;

final class CommerceRouteProvider implements ModuleRouteProviderInterface
{
    private ?ModuleRuntimeContext $context = null;
    private ?WebAdminConfig $config = null;
    private ?CommerceAdminHttpController $controller = null;
    private bool $blocked = false;

    public function __construct(
        private readonly CommerceAdminHttpRuntimeFactoryInterface $runtimeFactory =
            new CommerceAdminHttpRuntimeFactory(),
        private readonly CommerceAdminRequestPolicy $requestPolicy =
            new CommerceAdminRequestPolicy(),
        private readonly WebAdminConfigLoader $configLoader =
            new WebAdminConfigLoader(),
        private readonly WebAdminRoutePolicy $routePolicy =
            new WebAdminRoutePolicy(),
        private readonly PrivateRouteTransportPolicy $transportPolicy =
            new PrivateRouteTransportPolicy()
    ) {
    }

    public static function moduleId(): string
    {
        return 'commerce';
    }

    public function registerRoutes(ModuleRouteCollection $routes, ModuleRuntimeContext $context): void
    {
        try {
            $config = $this->configLoader->load($context->projectRoot(), $context->environment());
        } catch (WebAdminConfigException) {
            $config = WebAdminConfig::defaults();
            $this->blocked = true;
        }
        try {
            $resolution = $this->routePolicy->resolve(
                $context->projectRoot(),
                $config->basePath(),
                $context->languages()
            );
        } catch (Throwable) {
            return;
        }
        $webAdminPrefix = $resolution->registeredPath();
        if ($webAdminPrefix === null) {
            return;
        }
        if ($config->basePath() !== $webAdminPrefix) {
            $config = $config->withBasePath($webAdminPrefix);
        }
        $prefix = $webAdminPrefix . '/commerce';
        try {
            $routes->claimChildPrefix(
                self::moduleId(),
                WebAdminRouteProvider::moduleId(),
                $webAdminPrefix,
                $prefix,
                [$this, 'notFound'],
                [$this, 'methodNotAllowed']
            );
        } catch (RuntimeException) {
            return;
        }

        foreach ([
            ['GET', $prefix, 'index'],
            ['GET', $prefix . '/products/new', 'newProduct'],
            ['POST', $prefix . '/products/create', 'create'],
            ['GET', $prefix . '/products/edit', 'edit'],
            ['POST', $prefix . '/products/save', 'save'],
            ['POST', $prefix . '/products/taxonomies/save', 'saveProductTaxonomies'],
            ['POST', $prefix . '/products/attributes/save', 'saveAttributeValue'],
            ['POST', $prefix . '/products/media/save', 'saveProductMedia'],
            ['POST', $prefix . '/products/media/localization/save', 'saveProductMediaLocalization'],
            ['GET', $prefix . '/taxonomies', 'taxonomies'],
            ['POST', $prefix . '/taxonomies/categories/create', 'createCategory'],
            ['POST', $prefix . '/taxonomies/categories/localization/save', 'saveCategoryLocalization'],
            ['POST', $prefix . '/taxonomies/categories/parent/save', 'saveCategoryParent'],
            ['POST', $prefix . '/taxonomies/categories/order/save', 'saveCategoryOrder'],
            ['POST', $prefix . '/taxonomies/tags/create', 'createTag'],
            ['POST', $prefix . '/taxonomies/tags/localization/save', 'saveTagLocalization'],
            ['POST', $prefix . '/taxonomies/attributes/create', 'createAttribute'],
            ['POST', $prefix . '/taxonomies/attributes/options/create', 'createAttributeOption'],
            ['GET', $prefix . '/inquiries', 'inquiries'],
            ['GET', $prefix . '/inquiries/detail', 'inquiryDetail'],
            ['GET', $prefix . '/settings', 'settings'],
        ] as [$method, $path, $handler]) {
            $routes->add(self::moduleId(), $method, $path, [$this, $handler]);
        }
        $this->context = $context;
        $this->config = $config;
    }

    public function index(Request $request): Response { return $this->handle('index', $request); }
    public function newProduct(Request $request): Response { return $this->handle('newProduct', $request); }
    public function create(Request $request): Response { return $this->handle('create', $request); }
    public function edit(Request $request): Response { return $this->handle('edit', $request); }
    public function save(Request $request): Response { return $this->handle('save', $request); }
    public function saveProductTaxonomies(Request $request): Response { return $this->handle('saveProductTaxonomies', $request); }
    public function saveAttributeValue(Request $request): Response { return $this->handle('saveAttributeValue', $request); }
    public function saveProductMedia(Request $request): Response { return $this->handle('saveProductMedia', $request); }
    public function saveProductMediaLocalization(Request $request): Response { return $this->handle('saveProductMediaLocalization', $request); }
    public function taxonomies(Request $request): Response { return $this->handle('taxonomies', $request); }
    public function createCategory(Request $request): Response { return $this->handle('createCategory', $request); }
    public function saveCategoryLocalization(Request $request): Response { return $this->handle('saveCategoryLocalization', $request); }
    public function saveCategoryParent(Request $request): Response { return $this->handle('saveCategoryParent', $request); }
    public function saveCategoryOrder(Request $request): Response { return $this->handle('saveCategoryOrder', $request); }
    public function createTag(Request $request): Response { return $this->handle('createTag', $request); }
    public function saveTagLocalization(Request $request): Response { return $this->handle('saveTagLocalization', $request); }
    public function createAttribute(Request $request): Response { return $this->handle('createAttribute', $request); }
    public function createAttributeOption(Request $request): Response { return $this->handle('createAttributeOption', $request); }
    public function inquiries(Request $request): Response { return $this->handle('inquiries', $request); }
    public function inquiryDetail(Request $request): Response { return $this->handle('inquiryDetail', $request); }
    public function settings(Request $request): Response { return $this->handle('settings', $request); }

    private function handle(string $operation, Request $request): Response
    {
        if ($this->context === null || $this->config === null || $this->blocked) {
            return $this->plain(503, 'Service unavailable');
        }
        if (!$this->allowed($operation, $request)
            || !$this->transportPolicy->accepts($request, $this->context->environment())
        ) {
            return $this->plain(400, 'Bad request');
        }
        if ($request->cookie($this->config->cookieName()) === null) {
            return $this->redirect($this->config->basePath() . '/login');
        }
        if (!$this->context->environmentIsUsable()) {
            return $this->plain(503, 'Service unavailable');
        }
        try {
            $this->controller ??= new CommerceAdminHttpController(
                $this->runtimeFactory->create($this->context, $this->config),
                $this->requestPolicy
            );
            return $this->controller->{$operation}($request);
        } catch (Throwable) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    private function allowed(string $operation, Request $request): bool
    {
        return match ($operation) {
            'index', 'newProduct', 'taxonomies', 'inquiries', 'settings' =>
                $this->requestPolicy->acceptsIndex($request),
            'edit' => $this->requestPolicy->acceptsEdit($request),
            'inquiryDetail' => $this->requestPolicy->acceptsInquiryDetail($request),
            'create' => $this->requestPolicy->acceptsCreate($request),
            'save' => $this->requestPolicy->acceptsSave($request),
            'saveProductTaxonomies' => $this->requestPolicy->acceptsProductTaxonomiesSave($request),
            'saveAttributeValue' => $this->requestPolicy->acceptsAttributeValueSave($request),
            'saveProductMedia' => $this->requestPolicy->acceptsProductMediaSave($request),
            'saveProductMediaLocalization' => $this->requestPolicy->acceptsProductMediaLocalizationSave($request),
            'createCategory' => $this->requestPolicy->acceptsCategoryCreate($request),
            'saveCategoryLocalization' => $this->requestPolicy->acceptsCategoryLocalizationSave($request),
            'saveCategoryParent' => $this->requestPolicy->acceptsCategoryParentSave($request),
            'saveCategoryOrder' => $this->requestPolicy->acceptsCategoryOrderSave($request),
            'createTag' => $this->requestPolicy->acceptsTagCreate($request),
            'saveTagLocalization' => $this->requestPolicy->acceptsTagLocalizationSave($request),
            'createAttribute' => $this->requestPolicy->acceptsAttributeCreate($request),
            'createAttributeOption' => $this->requestPolicy->acceptsAttributeOptionCreate($request),
            default => false,
        };
    }

    public function notFound(Request $request): Response
    {
        return $this->plain(404, 'Not found');
    }

    /** @param list<string> $allowed */
    public function methodNotAllowed(Request $request, array $allowed): Response
    {
        return new Response(405, 'Method not allowed', ['Allow' => implode(', ', $allowed)]
            + $this->headers());
    }

    private function redirect(string $path): Response
    {
        return new Response(303, '', ['Location' => $path] + $this->headers());
    }

    private function plain(int $status, string $body): Response
    {
        return new Response($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']
            + $this->headers());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ];
    }
}
