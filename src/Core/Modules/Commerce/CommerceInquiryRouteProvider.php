<?php

declare(strict_types=1);

namespace App\Core\Modules\Commerce;

use App\Core\Commerce\Http\CommerceInquiryHttpController;
use App\Core\Commerce\Http\CommerceInquiryHttpRuntimeFactory;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Modules\ModuleRouteProviderInterface;
use App\Core\Modules\ModuleRuntimeContext;
use App\Core\Routing\ModuleRouteCollection;
use Throwable;

final class CommerceInquiryRouteProvider implements ModuleRouteProviderInterface
{
    public const PREFIX = '/_liquidstack/commerce';

    private ?ModuleRuntimeContext $context = null;
    private ?CommerceInquiryHttpController $controller = null;

    public function __construct(
        private readonly CommerceInquiryHttpRuntimeFactory $runtimeFactory =
            new CommerceInquiryHttpRuntimeFactory()
    ) {
    }

    public static function moduleId(): string
    {
        return 'commerce';
    }

    public function registerRoutes(
        ModuleRouteCollection $routes,
        ModuleRuntimeContext $context
    ): void {
        $routes->claimPrefix(
            self::moduleId(),
            self::PREFIX,
            [$this, 'notFound'],
            [$this, 'methodNotAllowed']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            self::PREFIX . '/basket/add',
            [$this, 'add']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            self::PREFIX . '/basket/remove',
            [$this, 'remove']
        );
        $routes->add(
            self::moduleId(),
            'POST',
            self::PREFIX . '/inquiry/submit',
            [$this, 'submit']
        );
        $this->context = $context;
    }

    public function add(Request $request): Response
    {
        return $this->handle('add', $request);
    }

    public function remove(Request $request): Response
    {
        return $this->handle('remove', $request);
    }

    public function submit(Request $request): Response
    {
        return $this->handle('submit', $request);
    }

    public function notFound(Request $request): Response
    {
        return $this->plain(404, 'Not found');
    }

    /** @param list<string> $allowed */
    public function methodNotAllowed(Request $request, array $allowed): Response
    {
        return new Response(405, 'Method not allowed', [
            'Allow' => implode(', ', $allowed),
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function controller(): CommerceInquiryHttpController
    {
        if ($this->controller instanceof CommerceInquiryHttpController) {
            return $this->controller;
        }
        if (!$this->context instanceof ModuleRuntimeContext) {
            throw new \RuntimeException('Commerce inquiry routes unavailable.');
        }
        try {
            return $this->controller = new CommerceInquiryHttpController(
                $this->runtimeFactory->create($this->context)
            );
        } catch (Throwable) {
            throw new \RuntimeException('Commerce inquiry routes unavailable.');
        }
    }

    private function handle(string $operation, Request $request): Response
    {
        try {
            return match ($operation) {
                'add' => $this->controller()->add($request),
                'remove' => $this->controller()->remove($request),
                'submit' => $this->controller()->submit($request),
                default => $this->plain(404, 'Not found'),
            };
        } catch (Throwable) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    private function plain(int $status, string $message): Response
    {
        return new Response($status, $message, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
